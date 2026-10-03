<?php

namespace Bizzsol\ApprovalMatrix\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Bizzsol\ApprovalMatrix\Services\ApproverResolver;
use Bizzsol\ApprovalMatrix\Tests\Support\ApprovalTestCase;
use Spatie\Permission\Models\Role;

class ApproverResolverTest extends ApprovalTestCase
{
    private function resolver(): ApproverResolver
    {
        return app(ApproverResolver::class);
    }

    public function test_reporting_head_comes_from_employee_reporting_manager(): void
    {
        $head = $this->makeUser('head');
        $headEmp = $this->makeEmployee($head);
        $staff = $this->makeUser('staff');
        $this->makeEmployee($staff, $headEmp);

        $ids = $this->resolver()->resolve(['approver_type' => 'reporting_head'], $staff->id, []);

        $this->assertSame([$head->id], $ids);
    }

    public function test_reporting_head_hops_climb_the_chain(): void
    {
        $ceo = $this->makeUser('ceo');
        $ceoEmp = $this->makeEmployee($ceo);
        $mgr = $this->makeUser('mgr');
        $mgrEmp = $this->makeEmployee($mgr, $ceoEmp);
        $staff = $this->makeUser('staff');
        $this->makeEmployee($staff, $mgrEmp);

        $this->assertSame([$mgr->id], $this->resolver()->resolve(['approver_type' => 'reporting_head', 'approver_ref' => '1'], $staff->id, []));
        $this->assertSame([$ceo->id], $this->resolver()->resolve(['approver_type' => 'reporting_head', 'approver_ref' => '2'], $staff->id, []));
    }

    public function test_reporting_head_with_multiple_users_returns_all(): void
    {
        $head = $this->makeUser('head1');
        $headEmp = $this->makeEmployee($head);
        $head2 = $this->makeUser('head2');
        DB::table('hrms_employee_users')->insert(['employee_id' => $headEmp, 'user_id' => $head2->id, 'created_at' => now(), 'updated_at' => now()]);
        $staff = $this->makeUser('staff');
        $this->makeEmployee($staff, $headEmp);

        $ids = $this->resolver()->resolve(['approver_type' => 'reporting_head'], $staff->id, []);

        $this->assertEqualsCanonicalizing([$head->id, $head2->id], $ids);
    }

    public function test_self_reporting_employee_has_no_head_and_falls_back_to_user_reporting_heads(): void
    {
        $boss = $this->makeUser('boss');
        $staff = $this->makeUser('staff');
        $this->makeEmployee($staff); // reports to self => no head

        $this->assertSame([], $this->resolver()->resolve(['approver_type' => 'reporting_head'], $staff->id, []));

        DB::table('user_reporting_heads')->insert(['user_id' => $staff->id, 'reporting_head_id' => $boss->id]);
        $this->assertSame([$boss->id], $this->resolver()->resolve(['approver_type' => 'reporting_head'], $staff->id, []));
    }

    public function test_requester_is_never_their_own_approver(): void
    {
        $staff = $this->makeUser('staff');
        $this->assertSame([], $this->resolver()->resolve(['approver_type' => 'specific_user', 'user_id' => $staff->id], $staff->id, []));
    }

    public function test_custom_user_most_specific_tier_wins(): void
    {
        $o = $this->org();
        [$any, $unitOnly, $deptOnly, $both] = [$this->makeUser('any'), $this->makeUser('unit'), $this->makeUser('dept'), $this->makeUser('both')];
        $requester = $this->makeUser('req');
        $rows = [
            ['unit_id' => null, 'department_id' => null, 'user_id' => $any->id],
            ['unit_id' => $o['unit'], 'department_id' => null, 'user_id' => $unitOnly->id],
            ['unit_id' => null, 'department_id' => $o['dept'], 'user_id' => $deptOnly->id],
            ['unit_id' => $o['unit'], 'department_id' => $o['dept'], 'user_id' => $both->id],
        ];
        $step = ['approver_type' => 'custom_user', 'custom_users' => $rows];

        $r = $this->resolver();
        $this->assertSame([$both->id], $r->resolve($step, $requester->id, ['unit_id' => $o['unit'], 'department_id' => $o['dept']]));
        $this->assertSame([$unitOnly->id], $r->resolve($step, $requester->id, ['unit_id' => $o['unit'], 'department_id' => $o['dept2']]));
        $this->assertSame([$deptOnly->id], $r->resolve($step, $requester->id, ['unit_id' => $o['unit2'], 'department_id' => $o['dept']]));
        $this->assertSame([$any->id], $r->resolve($step, $requester->id, ['unit_id' => $o['unit2'], 'department_id' => $o['dept2']]));
    }

    public function test_custom_user_without_matching_tier_resolves_nobody(): void
    {
        $o = $this->org();
        $u = $this->makeUser('only-unit-a');
        $step = ['approver_type' => 'custom_user', 'custom_users' => [['unit_id' => $o['unit'], 'department_id' => null, 'user_id' => $u->id]]];

        $this->assertSame([], $this->resolver()->resolve($step, 1, ['unit_id' => $o['unit2'], 'department_id' => null]));
    }

    public function test_role_approver_works_for_a_brand_new_role_without_code_changes(): void
    {
        $role = Role::create(['name' => 'Deputy Manager '.uniqid(), 'guard_name' => 'web']);
        $deputy = $this->makeUser('deputy');
        $deputy->assignRole($role);
        $requester = $this->makeUser('req');

        $ids = $this->resolver()->resolve(['approver_type' => 'role', 'approver_ref' => $role->name], $requester->id, []);

        $this->assertSame([$deputy->id], $ids);
    }

    public function test_requester_context_reads_employee_org(): void
    {
        $o = $this->org();
        $u = $this->makeUser('ctx');
        $this->makeEmployee($u, null, ['company_id' => $o['company'], 'unit_id' => $o['unit'], 'department_id' => $o['dept']]);

        $ctx = $this->resolver()->requesterContext($u->id);

        $this->assertSame(
            ['company_id' => $o['company'], 'unit_id' => $o['unit'], 'department_id' => $o['dept']],
            array_intersect_key($ctx, array_flip(['company_id', 'unit_id', 'department_id']))
        );
        $this->assertArrayHasKey('master_department_id', $ctx);
    }
}
