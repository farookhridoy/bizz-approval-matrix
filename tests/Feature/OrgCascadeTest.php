<?php

namespace Bizzsol\ApprovalMatrix\Tests\Feature;

use Bizzsol\ApprovalMatrix\Models\ApprovalRequest;
use Bizzsol\ApprovalMatrix\Models\ApprovalWorkflow;
use Bizzsol\ApprovalMatrix\Services\ApprovalSimulator;
use Bizzsol\ApprovalMatrix\Services\ApproverResolver;
use Bizzsol\ApprovalMatrix\Services\OrgDirectory;
use Bizzsol\ApprovalMatrix\Services\WorkflowResolver;
use Bizzsol\ApprovalMatrix\Tests\Support\ApprovalTestCase;
use Bizzsol\ApprovalMatrix\Tests\Support\TestDocument;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

/** Company > Unit > Master department (hr_department.hr_unit_id / master_department_id, as in the PMS store filter). */
class OrgCascadeTest extends ApprovalTestCase
{
    private OrgDirectory $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = app(OrgDirectory::class);
    }

    /**
     * A master department offered in 2+ units, with each unit's own hr_department row,
     * plus a unit that does NOT offer it. Real data, picked dynamically.
     */
    private function fixture(): array
    {
        $master = DB::table('hr_department')->whereNull('deleted_at')->whereNotNull('master_department_id')
            ->select('master_department_id')->groupBy('master_department_id')->havingRaw('count(distinct hr_unit_id) >= 2')->value('master_department_id');
        $this->assertNotNull($master, 'fixture needs a master department present in 2+ units');

        $rows = DB::table('hr_department')->whereNull('deleted_at')->where('master_department_id', $master)->orderBy('hr_unit_id')->get(['id', 'hr_unit_id']);
        $unitA = $rows[0];
        $unitB = $rows->firstWhere(fn ($r) => $r->hr_unit_id != $unitA->hr_unit_id);
        $outsideUnit = DB::table('hr_unit')->whereNotIn('id', $rows->pluck('hr_unit_id'))->value('id');

        return [
            'master' => (int) $master,
            'unitA' => (int) $unitA->hr_unit_id, 'deptA' => (int) $unitA->id,
            'unitB' => (int) $unitB->hr_unit_id, 'deptB' => (int) $unitB->id,
            'outsideUnit' => (int) $outsideUnit,
            'companyA' => $this->org->companyOfUnit((int) $unitA->hr_unit_id),
        ];
    }

    private function admin()
    {
        $u = $this->makeUser('admin');
        foreach (['approval-matrix-index', 'approval-matrix-create', 'approval-matrix-edit', 'approval-matrix-simulator'] as $p) {
            Permission::findOrCreate($p, 'web');
            $u->givePermissionTo($p);
        }

        return $u;
    }

    // ------------------------------------------------------------------ OrgDirectory

    public function test_units_are_filtered_by_company(): void
    {
        $f = $this->fixture();
        $units = $this->org->units($f['companyA']);

        $this->assertTrue($units->has($f['unitA']));
        $this->assertTrue($units->keys()->every(fn ($id) => $this->org->companyOfUnit($id) === $f['companyA']));
    }

    public function test_master_departments_follow_the_unit_and_company(): void
    {
        $f = $this->fixture();

        $this->assertTrue($this->org->masterDepartments($f['unitA'])->has($f['master']));
        $this->assertFalse($this->org->masterDepartments($f['outsideUnit'])->has($f['master']));
        $this->assertTrue($this->org->masterDepartments(null, $f['companyA'])->has($f['master']));
        $this->assertTrue($this->org->masterDepartments()->has($f['master']), 'no filter = every active master department');
        $this->assertTrue($this->org->unitHasMasterDepartment($f['unitA'], $f['master']));
        $this->assertFalse($this->org->unitHasMasterDepartment($f['outsideUnit'], $f['master']));
    }

    public function test_every_unit_department_maps_to_its_master(): void
    {
        $f = $this->fixture();

        $this->assertSame($f['master'], $this->org->masterOfDepartment($f['deptA']));
        $this->assertSame($f['master'], $this->org->masterOfDepartment($f['deptB'], $f['unitB']));
        $this->assertNull($this->org->masterOfDepartment($f['deptA'], $f['outsideUnit']));
        $this->assertNull($this->org->masterOfDepartment(null));
    }

    // ------------------------------------------------------------------ resolution

    public function test_master_department_workflow_covers_that_department_in_every_unit(): void
    {
        $f = $this->fixture();
        $wf = $this->makeWorkflow([['approver_type' => 'reporting_head']], ['master_department_id' => $f['master']]);
        $resolver = app(WorkflowResolver::class);

        foreach (['A', 'B'] as $x) {
            $this->assertSame($wf->id, $resolver->resolve(self::DOC_TYPE, ['unit_id' => $f['unit'.$x], 'master_department_id' => $f['master']])?->id);
        }
        $this->assertNull($resolver->resolve(self::DOC_TYPE, ['unit_id' => $f['unitA'], 'master_department_id' => 999999]));
        $this->assertNull($resolver->resolve(self::DOC_TYPE, ['unit_id' => $f['unitA']]), 'no department on the document: a department-scoped workflow must not match');
    }

    public function test_specificity_company_then_unit_then_master_department(): void
    {
        $f = $this->fixture();
        $company = $this->makeWorkflow([['approver_type' => 'reporting_head']], ['company_id' => $f['companyA']]);
        $unit = $this->makeWorkflow([['approver_type' => 'reporting_head']], ['company_id' => $f['companyA'], 'unit_id' => $f['unitA']]);
        $dept = $this->makeWorkflow([['approver_type' => 'reporting_head']], ['company_id' => $f['companyA'], 'unit_id' => $f['unitA'], 'master_department_id' => $f['master']]);
        $ctx = ['company_id' => $f['companyA'], 'unit_id' => $f['unitA'], 'master_department_id' => $f['master']];
        $resolver = app(WorkflowResolver::class);

        $this->assertSame($dept->id, $resolver->resolve(self::DOC_TYPE, $ctx)->id);
        $this->assertSame($unit->id, $resolver->resolve(self::DOC_TYPE, ['master_department_id' => null] + $ctx)->id);
        $this->assertSame($company->id, $resolver->resolve(self::DOC_TYPE, ['unit_id' => null, 'master_department_id' => null] + $ctx)->id);
    }

    public function test_ambiguity_includes_master_department(): void
    {
        $f = $this->fixture();
        $a = $this->makeWorkflow([['approver_type' => 'reporting_head']], ['master_department_id' => $f['master']]);
        $sameScope = $this->makeWorkflow([['approver_type' => 'reporting_head']], ['master_department_id' => $f['master']]);
        $otherScope = $this->makeWorkflow([['approver_type' => 'reporting_head']]);

        $ids = app(WorkflowResolver::class)->ambiguousWith($a)->pluck('id');
        $this->assertTrue($ids->contains($sameScope->id));
        $this->assertFalse($ids->contains($otherScope->id));
    }

    public function test_custom_user_rows_can_target_a_master_department(): void
    {
        $f = $this->fixture();
        [$anyDept, $master, $unitMaster] = [$this->makeUser('any'), $this->makeUser('master'), $this->makeUser('unitmaster')];
        $step = ['approver_type' => 'custom_user', 'custom_users' => [
            ['unit_id' => null, 'department_id' => null, 'master_department_id' => null, 'user_id' => $anyDept->id],
            ['unit_id' => null, 'department_id' => null, 'master_department_id' => $f['master'], 'user_id' => $master->id],
            ['unit_id' => $f['unitA'], 'department_id' => null, 'master_department_id' => $f['master'], 'user_id' => $unitMaster->id],
        ]];
        $r = app(ApproverResolver::class);
        $req = $this->makeUser('req')->id;

        $this->assertSame([$unitMaster->id], $r->resolve($step, $req, ['unit_id' => $f['unitA'], 'master_department_id' => $f['master']]));
        $this->assertSame([$master->id], $r->resolve($step, $req, ['unit_id' => $f['unitB'], 'master_department_id' => $f['master']]));
        $this->assertSame([$anyDept->id], $r->resolve($step, $req, ['unit_id' => $f['unitB'], 'master_department_id' => 999999]));
    }

    public function test_requester_context_derives_company_and_master_department(): void
    {
        $f = $this->fixture();
        $u = $this->makeUser('ctx');
        $this->makeEmployee($u, null, ['unit_id' => $f['unitA'], 'department_id' => $f['deptA']]); // no company on the employee

        $ctx = app(ApproverResolver::class)->requesterContext($u->id);

        $this->assertSame($f['companyA'], (int) $ctx['company_id']);
        $this->assertSame($f['master'], $ctx['master_department_id']);
    }

    public function test_submit_picks_the_master_department_workflow_and_records_it(): void
    {
        $f = $this->fixture();
        $head = $this->makeUser('head');
        $headEmp = $this->makeEmployee($head);
        $requester = $this->makeUser('req');
        $this->makeEmployee($requester, $headEmp, ['unit_id' => $f['unitB'], 'department_id' => $f['deptB']]);
        $this->makeWorkflow([['approver_type' => 'specific_user', 'user_id' => $this->makeUser('generic')->id]], ['name' => 'generic']);
        $deptWf = $this->makeWorkflow([['approver_type' => 'reporting_head']], ['name' => 'dept-wide', 'master_department_id' => $f['master']]);

        $req = TestDocument::find($requester->id)->submitForApproval($requester->id);

        $this->assertSame($deptWf->id, $req->workflow_id);
        $this->assertSame($f['master'], (int) $req->master_department_id);
        $this->assertSame($f['unitB'], (int) $req->unit_id);
    }

    public function test_callers_that_only_know_the_unit_department_get_the_master_derived(): void
    {
        $f = $this->fixture();
        $head = $this->makeUser('head');
        $headEmp = $this->makeEmployee($head);
        $requester = $this->makeUser('req');
        $this->makeEmployee($requester, $headEmp);
        $wf = $this->makeWorkflow([['approver_type' => 'reporting_head']], ['master_department_id' => $f['master']]);

        // like erp-pms: passes unit + the unit's own department id only
        $r = app(ApprovalSimulator::class)->simulate([
            'document_type' => self::DOC_TYPE, 'requester_id' => $requester->id, 'unit_id' => $f['unitA'], 'department_id' => $f['deptA'],
        ]);

        $this->assertSame($wf->id, $r['workflow']?->id);
        $this->assertSame($f['master'], $r['context']['master_department_id']);
        $this->assertSame($f['companyA'], (int) $r['context']['company_id']);
    }

    // ------------------------------------------------------------------ admin screens

    public function test_cascade_endpoints(): void
    {
        $f = $this->fixture();
        $admin = $this->admin();

        $units = $this->actingAs($admin)->getJson(route('approval-matrix.org.units', ['company_id' => $f['companyA']]))->assertOk()->json();
        $this->assertTrue(collect($units)->every(fn ($u) => $u['company_id'] == $f['companyA']));
        $this->assertTrue(collect($units)->pluck('id')->contains($f['unitA']));

        $masters = $this->actingAs($admin)->getJson(route('approval-matrix.org.master-departments', ['unit_id' => $f['unitA']]))->assertOk()->json();
        $this->assertTrue(collect($masters)->pluck('id')->contains($f['master']));
        $this->assertFalse(collect($this->actingAs($admin)->getJson(route('approval-matrix.org.master-departments', ['unit_id' => $f['outsideUnit']]))->json())->pluck('id')->contains($f['master']));
    }

    public function test_cascade_endpoints_require_permission(): void
    {
        $this->actingAs($this->makeUser('nobody'))->getJson(route('approval-matrix.org.units'))->assertForbidden();
        $this->actingAs($this->makeUser('nobody2'))->getJson(route('approval-matrix.org.master-departments'))->assertForbidden();
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'name' => 'Cascade WF', 'document_type' => self::DOC_TYPE, 'state' => 'draft',
            'steps' => [['name' => 'Head', 'approver_type' => 'reporting_head', 'approver_ref' => 1, 'mode' => 'any', 'is_mandatory' => 1, 'on_reject' => 'terminate']],
        ], $over);
    }

    public function test_store_saves_master_department_and_a_unit_implies_its_company(): void
    {
        $f = $this->fixture();

        $this->actingAs($this->admin())->post(route('approval-matrix.workflows.store'), $this->payload([
            'unit_id' => $f['unitA'], 'master_department_id' => $f['master'],
        ]))->assertRedirect();

        $wf = ApprovalWorkflow::where('name', 'Cascade WF')->firstOrFail();
        $this->assertSame($f['master'], (int) $wf->master_department_id);
        $this->assertSame($f['unitA'], (int) $wf->unit_id);
        $this->assertSame($f['companyA'], (int) $wf->company_id, 'company is derived from the unit');
    }

    public function test_inconsistent_company_unit_department_is_rejected(): void
    {
        $f = $this->fixture();
        $admin = $this->admin();
        $otherCompany = DB::table('companies')->where('id', '!=', $f['companyA'])->value('id');
        $route = route('approval-matrix.workflows.store');

        $this->actingAs($admin)->post($route, $this->payload(['company_id' => $otherCompany, 'unit_id' => $f['unitA']]))
            ->assertSessionHasErrors('unit_id');
        $this->actingAs($admin)->post($route, $this->payload(['unit_id' => $f['outsideUnit'], 'master_department_id' => $f['master']]))
            ->assertSessionHasErrors('master_department_id');

        $bad = $this->payload();
        $bad['steps'] = [['name' => 'c', 'approver_type' => 'custom_user', 'mode' => 'any', 'custom' => [
            ['unit_id' => $f['outsideUnit'], 'master_department_id' => $f['master'], 'user_id' => $this->makeUser('x')->id],
        ]]];
        $this->actingAs($admin)->post($route, $bad)->assertSessionHasErrors('steps');

        $this->assertSame(0, ApprovalWorkflow::where('name', 'Cascade WF')->count());
    }

    public function test_list_shows_the_master_department_scope_and_form_prefills_cascade(): void
    {
        $f = $this->fixture();
        $admin = $this->admin();
        $wf = $this->makeWorkflow([['approver_type' => 'reporting_head']], [
            'name' => 'Scoped', 'company_id' => $f['companyA'], 'unit_id' => $f['unitA'], 'master_department_id' => $f['master'],
        ]);
        $masterName = DB::table('master_departments')->where('id', $f['master'])->value('name');

        $rows = $this->actingAs($admin)->get(route('approval-matrix.workflows.index', ['draw' => 1]), ['X-Requested-With' => 'XMLHttpRequest'])->json('data');
        $row = collect($rows)->first(fn ($r) => str_contains($r['workflow'], 'Scoped'));
        $this->assertStringContainsString($masterName, $row['applies_to']);

        $this->actingAs($admin)->get(route('approval-matrix.workflows.edit', $wf->id))
            ->assertOk()->assertSee('am-master', false)->assertSee('selected', false)->assertSee($masterName);
    }

    public function test_simulator_page_cascades_and_resolves_for_a_master_department(): void
    {
        $f = $this->fixture();
        $admin = $this->admin();
        $head = $this->makeUser('Dept Boss');
        $headEmp = $this->makeEmployee($head);
        $requester = $this->makeUser('req');
        $this->makeEmployee($requester, $headEmp);
        $this->makeWorkflow([['approver_type' => 'reporting_head']], ['name' => 'Dept WF', 'master_department_id' => $f['master']]);

        $this->actingAs($admin)->get(route('approval-matrix.simulator.index', [
            'document_type' => self::DOC_TYPE, 'requester_id' => $requester->id, 'unit_id' => $f['unitA'], 'master_department_id' => $f['master'],
        ]))->assertOk()->assertSee('Dept WF')->assertSee('Test Dept Boss');
    }

    public function test_no_requests_are_created_by_org_lookups(): void
    {
        $before = ApprovalRequest::count();
        $this->org->masterDepartments();
        $this->assertSame($before, ApprovalRequest::count());
    }
}
