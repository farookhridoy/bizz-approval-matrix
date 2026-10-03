<?php

namespace Bizzsol\ApprovalMatrix\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Bizzsol\ApprovalMatrix\Models\ApprovalStep;

/**
 * Turns a step definition into concrete user ids. Works on the snapshot array form of a step
 * (so in-flight requests are immune to later workflow edits).
 */
class ApproverResolver
{
    /**
     * @param  array  $step  snapshot step (approver_type, approver_ref, user_id, custom_users[])
     * @param  array  $ctx  unit_id / department_id of the document
     * @return int[]
     */
    public function resolve(array $step, int $requesterId, array $ctx): array
    {
        $ids = match ($step['approver_type']) {
            ApprovalStep::TYPE_REPORTING_HEAD => $this->reportingHead($requesterId, max(1, (int) ($step['approver_ref'] ?? 1))),
            ApprovalStep::TYPE_CUSTOM_USER => $this->customUsers($step['custom_users'] ?? [], $ctx),
            ApprovalStep::TYPE_SPECIFIC_USER => array_filter([(int) ($step['user_id'] ?? 0)]),
            ApprovalStep::TYPE_ROLE => User::role($step['approver_ref'])->pluck('users.id')->all(),
            ApprovalStep::TYPE_PERMISSION => User::permission($step['approver_ref'])->pluck('users.id')->all(),
            default => [],
        };

        // Nobody approves their own request.
        return array_values(array_unique(array_filter(
            array_map('intval', $ids),
            fn ($id) => $id > 0 && $id !== $requesterId
        )));
    }

    /**
     * Requester user -> employee -> reporting_manager_id (an employee) -> that employee's user(s).
     * $hops > 1 climbs the chain. Falls back to the user-level `user_reporting_heads` table
     * when the employee chain yields nobody.
     *
     * @return int[]
     */
    public function reportingHead(int $userId, int $hops = 1): array
    {
        $employeeId = DB::table('hrms_employee_users')->where('user_id', $userId)->whereNull('deleted_at')->value('employee_id');

        $visited = [];
        while ($employeeId && $hops > 0) {
            $visited[] = $employeeId;
            $managerId = (int) DB::table('hrms_employees')->where('id', $employeeId)->whereNull('deleted_at')->value('reporting_manager_id');
            if ($managerId < 1 || in_array($managerId, $visited, true)) {
                $employeeId = null; // no manager, self-report or loop

                break;
            }
            $employeeId = $managerId;
            $hops--;
        }

        if ($employeeId && $hops === 0) {
            $users = DB::table('hrms_employee_users as eu')
                ->join('users as u', 'u.id', '=', 'eu.user_id')
                ->where('eu.employee_id', $employeeId)
                ->whereNull('eu.deleted_at')->whereNull('u.deleted_at')
                ->pluck('u.id')->all();

            if ($users) {
                return $users;
            }
        }

        return DB::table('user_reporting_heads as h')
            ->join('users as u', 'u.id', '=', 'h.reporting_head_id')
            ->where('h.user_id', $userId)
            ->whereNull('h.deleted_at')->whereNull('u.deleted_at')
            ->pluck('u.id')->all();
    }

    /**
     * Custom approvers picked per unit / department. Most specific non-empty tier wins:
     * unit+department > unit only > department only (master or unit-level) > unit-less & department-less default.
     *
     * @return int[]
     */
    public function customUsers(array $rows, array $ctx): array
    {
        $unit = $ctx['unit_id'] ?? null;
        $dept = $ctx['department_id'] ?? null;
        $master = $ctx['master_department_id'] ?? null;

        // A row's department is a master department (any unit) and/or a unit's own department row.
        $hasDept = fn ($r) => ! empty($r['master_department_id']) || ! empty($r['department_id']);
        $deptMatch = fn ($r) => (! empty($r['master_department_id']) && $r['master_department_id'] == $master)
            || (! empty($r['department_id']) && $r['department_id'] == $dept);

        $tiers = [
            fn ($r) => $r['unit_id'] && $hasDept($r) && $r['unit_id'] == $unit && $deptMatch($r),
            fn ($r) => $r['unit_id'] && ! $hasDept($r) && $r['unit_id'] == $unit,
            fn ($r) => ! $r['unit_id'] && $hasDept($r) && $deptMatch($r),
            fn ($r) => ! $r['unit_id'] && ! $hasDept($r),
        ];

        foreach ($tiers as $tier) {
            $match = array_values(array_filter($rows, $tier));
            if ($match) {
                $ids = array_column($match, 'user_id');

                return User::whereIn('id', $ids)->pluck('id')->all(); // drops soft-deleted users
            }
        }

        return [];
    }

    /** Org context (company / unit / department) of a user's employee record. */
    public function requesterContext(int $userId): array
    {
        $row = DB::table('hrms_employee_users as eu')
            ->join('hrms_employees as e', 'e.id', '=', 'eu.employee_id')
            ->where('eu.user_id', $userId)->whereNull('eu.deleted_at')
            ->first(['e.main_company_id', 'e.main_unit_id', 'e.main_department_id']);

        $unit = $row->main_unit_id ?? null;
        $dept = $row->main_department_id ?? null;

        return [
            'company_id' => $row->main_company_id ?? ($unit ? app(OrgDirectory::class)->companyOfUnit((int) $unit) : null),
            'unit_id' => $unit,
            'department_id' => $dept,
            'master_department_id' => app(OrgDirectory::class)->masterOfDepartment($dept ? (int) $dept : null, $unit ? (int) $unit : null),
        ];
    }
}
