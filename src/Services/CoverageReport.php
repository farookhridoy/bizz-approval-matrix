<?php

namespace Bizzsol\ApprovalMatrix\Services;

use Illuminate\Support\Facades\DB;

/**
 * "Would this document type work for everybody?" - simulates the workflow for every employee who has a user account
 * (at their own unit / department, with a typical amount) and flags the ones where no workflow applies or a mandatory step
 * has nobody to approve. Run it before activating a workflow or switching a document type to the matrix.
 */
class CoverageReport
{
    public const OK = 'ok';

    public const NO_WORKFLOW = 'no-workflow';

    public const NO_APPROVER = 'no-approver';

    public function __construct(private ApprovalSimulator $simulator)
    {
    }

    /**
     * @return array{rows:array<int,array>,summary:array{ok:int,'no-workflow':int,'no-approver':int,total:int}}
     */
    public function report(string $documentType, ?float $amount = null, array $attributes = []): array
    {
        $users = DB::table('hrms_employee_users as eu')
            ->join('users as u', 'u.id', '=', 'eu.user_id')
            ->join('hrms_employees as e', 'e.id', '=', 'eu.employee_id')
            ->whereNull('eu.deleted_at')->whereNull('u.deleted_at')->whereNull('e.deleted_at')
            ->orderBy('u.name')
            ->get(['u.id as user_id', 'u.name', 'e.main_unit_id', 'e.main_department_id']);

        $units = DB::table('hr_unit')->pluck('hr_unit_name', 'id');
        $departments = DB::table('hr_department')->pluck('hr_department_name', 'id');

        $rows = [];
        $summary = [self::OK => 0, self::NO_WORKFLOW => 0, self::NO_APPROVER => 0, 'total' => 0];

        foreach ($users as $u) {
            $r = $this->simulator->simulate(array_filter([
                'document_type' => $documentType, 'requester_id' => $u->user_id, 'amount' => $amount, 'attributes' => $attributes,
            ], fn ($v) => $v !== null));

            $blocked = collect($r['steps'])->where('status', 'no-approver')->pluck('name')->values()->all();
            $status = ! $r['workflow'] ? self::NO_WORKFLOW : ($blocked ? self::NO_APPROVER : self::OK);

            $summary[$status]++;
            $summary['total']++;
            $rows[] = [
                'user_id' => (int) $u->user_id, 'name' => $u->name,
                'unit' => $units[$u->main_unit_id] ?? '—', 'department' => $departments[$u->main_department_id] ?? '—',
                'status' => $status, 'workflow' => $r['workflow']->name ?? null,
                'problem' => $status === self::NO_WORKFLOW ? 'No active workflow applies' : ($blocked ? 'No approver for: '.implode(', ', $blocked) : null),
                'chain' => collect($r['steps'])->map(fn ($s) => $s['name'].': '.($s['status'] === 'skipped' ? 'skipped' : (implode(', ', $s['approvers']) ?: '—')))->implode(' → '),
            ];
        }

        return ['rows' => $rows, 'summary' => $summary];
    }
}
