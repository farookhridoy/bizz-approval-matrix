<?php

namespace Bizzsol\ApprovalMatrix\Tests\Support;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Bizzsol\ApprovalMatrix\Models\ApprovalStep;
use Bizzsol\ApprovalMatrix\Models\ApprovalStepUser;
use Bizzsol\ApprovalMatrix\Models\ApprovalWorkflow;
use Tests\TestCase;

/**
 * Runs inside a transaction that is rolled back after every test (no RefreshDatabase / migrate:fresh),
 * and refuses to run against anything but a *_testing database.
 */
abstract class ApprovalTestCase extends TestCase
{
    use DatabaseTransactions;

    public const DOC_TYPE = 'procurement.requisition';

    protected function setUp(): void
    {
        parent::setUp();

        $db = DB::connection()->getDatabaseName();
        if (! Str::endsWith($db, '_testing')) {
            $this->fail("Refusing to run: connected to [{$db}], not a dedicated *_testing database.");
        }
    }

    protected function makeUser(string $label = 'u'): User
    {
        return User::create([
            'name' => "Test {$label}",
            'email' => Str::random(10)."+{$label}@example.test",
            'password' => bcrypt('secret'),
        ]);
    }

    /** Employee cloned from an existing row (keeps all NOT NULL FKs valid), linked to $user. */
    protected function makeEmployee(User $user, ?int $managerEmployeeId = null, array $org = []): int
    {
        $template = (array) DB::table('hrms_employees')->orderBy('id')->first();
        unset($template['id']);
        $template = array_merge($template, [
            'uid' => 'T'.Str::random(8),
            'card_no' => null, 'machine_id' => null,
            'reporting_manager_id' => $managerEmployeeId ?? 0,
            'main_company_id' => $org['company_id'] ?? null,
            'main_unit_id' => $org['unit_id'] ?? null,
            'main_department_id' => $org['department_id'] ?? null,
        ]);
        $id = DB::table('hrms_employees')->insertGetId($template);
        DB::table('hrms_employee_users')->insert([
            'employee_id' => $id, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($managerEmployeeId === null) {
            DB::table('hrms_employees')->where('id', $id)->update(['reporting_manager_id' => $id]); // self = no manager
        }

        return $id;
    }

    /** @return array{unit:int,unit2:int,dept:int,dept2:int,company:int} real org ids */
    protected function org(): array
    {
        $units = DB::table('hr_unit')->orderBy('id')->limit(2)->pluck('id');
        $depts = DB::table('hr_department')->orderBy('id')->limit(2)->pluck('id');

        return [
            'unit' => $units[0], 'unit2' => $units[1],
            'dept' => $depts[0], 'dept2' => $depts[1],
            'company' => DB::table('companies')->orderBy('id')->value('id'),
        ];
    }

    /**
     * @param  array<int,array>  $steps  each: approver_type, [approver_ref, user_id, mode, min_approvals, is_mandatory, skip_condition, on_reject, custom=>[[unit_id,department_id,user_id]]]
     */
    protected function makeWorkflow(array $steps, array $attrs = []): ApprovalWorkflow
    {
        $wf = ApprovalWorkflow::create(array_merge([
            'name' => 'WF '.Str::random(5), 'module' => 'procurement', 'document_type' => self::DOC_TYPE,
            'company_id' => null, 'unit_id' => null, 'department_id' => null,
            'priority' => 0, 'version' => 1, 'state' => 'active', 'status' => 1,
        ], $attrs));

        foreach (array_values($steps) as $i => $s) {
            $custom = $s['custom'] ?? [];
            unset($s['custom']);
            $step = ApprovalStep::create(array_merge([
                'workflow_id' => $wf->id, 'level' => $i + 1, 'name' => 'Step '.($i + 1),
                'step_kind' => 'approval', 'mode' => 'any', 'is_mandatory' => 1, 'on_reject' => 'terminate',
            ], $s));
            foreach ($custom as $c) {
                ApprovalStepUser::create(['step_id' => $step->id] + $c);
            }
        }

        return $wf;
    }
}
