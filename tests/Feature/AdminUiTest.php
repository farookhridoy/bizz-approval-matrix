<?php

namespace Bizzsol\ApprovalMatrix\Tests\Feature;

use App\Models\User;
use Bizzsol\ApprovalMatrix\Models\ApprovalRequest;
use Bizzsol\ApprovalMatrix\Models\ApprovalStep;
use Bizzsol\ApprovalMatrix\Models\ApprovalWorkflow;
use Bizzsol\ApprovalMatrix\Services\ApprovalEngine;
use Bizzsol\ApprovalMatrix\Tests\Support\ApprovalTestCase;
use Bizzsol\ApprovalMatrix\Tests\Support\TestDocument;
use Spatie\Permission\Models\Permission;

class AdminUiTest extends ApprovalTestCase
{
    private const ADMIN_PERMS = ['approval-matrix-index', 'approval-matrix-create', 'approval-matrix-edit', 'approval-matrix-delete', 'approval-matrix-simulator', 'approval-inbox'];

    private function admin(): User
    {
        $u = $this->makeUser('admin');
        foreach (self::ADMIN_PERMS as $p) {
            Permission::findOrCreate($p, 'web');
        }
        $u->givePermissionTo(self::ADMIN_PERMS);

        return $u;
    }

    private function as(User $u): static
    {
        return $this->actingAs($u);
    }

    private function payload(array $over = []): array
    {
        $approver = $this->makeUser('custom-approver');

        return array_replace_recursive([
            'name' => 'Requisition – Unit A',
            'document_type' => self::DOC_TYPE,
            'state' => 'draft',
            'priority' => 0,
            'steps' => [
                ['name' => 'Dept head', 'approver_type' => 'reporting_head', 'approver_ref' => 1, 'mode' => 'any', 'is_mandatory' => 1, 'on_reject' => 'terminate'],
                ['name' => 'Management', 'approver_type' => 'custom_user', 'mode' => 'any', 'is_mandatory' => 1, 'on_reject' => 'terminate', 'skip_amount_max' => 50000,
                    'custom' => [['unit_id' => '', 'department_id' => '', 'user_id' => $approver->id]]],
            ],
        ], $over);
    }

    // ------------------------------------------------------------------ access

    public function test_pages_are_forbidden_without_permission(): void
    {
        $nobody = $this->makeUser('nobody');

        $this->as($nobody)->get(route('approval-matrix.workflows.index'))->assertForbidden();
        $this->as($nobody)->get(route('approval-matrix.simulator.index'))->assertForbidden();
        $this->as($nobody)->get(route('approval-matrix.inbox.index'))->assertForbidden();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('approval-matrix.workflows.index'))->assertRedirect();
    }

    public function test_view_only_user_cannot_create(): void
    {
        Permission::findOrCreate('approval-matrix-index', 'web');
        $u = $this->makeUser('viewer');
        $u->givePermissionTo('approval-matrix-index');

        $this->as($u)->get(route('approval-matrix.workflows.create'))->assertForbidden();
        $this->as($u)->post(route('approval-matrix.workflows.store'), $this->payload())->assertForbidden();
    }

    // ------------------------------------------------------------------ workflows

    public function test_list_and_form_pages_render(): void
    {
        $admin = $this->admin();
        $wf = $this->makeWorkflow([['approver_type' => 'reporting_head']], ['name' => 'Listed WF']);

        $this->as($admin)->get(route('approval-matrix.workflows.index'))->assertOk()->assertSee('Approval workflows');
        $this->as($admin)->get(route('approval-matrix.workflows.create'))->assertOk()->assertSee('Approval steps');
        $this->as($admin)->get(route('approval-matrix.workflows.edit', $wf->id))->assertOk()->assertSee('Listed WF');
    }

    public function test_datatable_json_lists_workflows_with_filters(): void
    {
        $admin = $this->admin();
        $this->makeWorkflow([['approver_type' => 'reporting_head']], ['name' => 'Active One', 'state' => 'active']);
        $this->makeWorkflow([['approver_type' => 'reporting_head']], ['name' => 'Draft One', 'state' => 'draft']);
        $ajax = ['X-Requested-With' => 'XMLHttpRequest'];

        $all = $this->as($admin)->get(route('approval-matrix.workflows.index', ['draw' => 1]), $ajax)->assertOk()->json('data');
        $names = collect($all)->pluck('name');
        $this->assertTrue($names->contains('Active One') && $names->contains('Draft One'));

        $drafts = $this->as($admin)->get(route('approval-matrix.workflows.index', ['draw' => 1, 'state' => 'draft']), $ajax)->json('data');
        $this->assertSame(['Draft One'], collect($drafts)->pluck('name')->intersect(['Active One', 'Draft One'])->values()->all());
    }

    public function test_store_creates_workflow_with_steps_and_custom_users(): void
    {
        $admin = $this->admin();
        $o = $this->org();

        $this->as($admin)->post(route('approval-matrix.workflows.store'), $this->payload([
            'unit_id' => $o['unit'], 'amount_min' => 1000, 'amount_max' => 900000, 'attributes' => "purchase_type=foreign,local\n",
        ]))->assertRedirect(route('approval-matrix.workflows.index'));

        $wf = ApprovalWorkflow::where('name', 'Requisition – Unit A')->firstOrFail();
        $this->assertSame('procurement', $wf->module);
        $this->assertSame($o['unit'], (int) $wf->unit_id);
        $this->assertSame((int) \Illuminate\Support\Facades\DB::table('hr_unit')->where('id', $o['unit'])->value('company_id'), (int) $wf->company_id, 'a unit implies its company');
        $this->assertEqualsCanonicalizing(['amount_min' => 1000, 'amount_max' => 900000, 'attributes' => ['purchase_type' => ['foreign', 'local']]], $wf->conditions);
        $this->assertCount(2, $wf->steps);
        $this->assertSame([1, 2], $wf->steps->pluck('level')->all());
        $this->assertEquals(['amount_max' => 50000], $wf->steps[1]->skip_condition);
        $this->assertCount(1, $wf->steps[1]->customUsers);
    }

    public function test_store_validation_errors(): void
    {
        $admin = $this->admin();
        $route = route('approval-matrix.workflows.store');

        $noSteps = $this->payload();
        $noSteps['steps'] = [];
        $this->as($admin)->post($route, $noSteps)->assertSessionHasErrors('steps');
        $this->as($admin)->post($route, $this->payload(['document_type' => 'nope.nothing']))->assertSessionHasErrors('document_type');
        $this->as($admin)->post($route, $this->payload(['amount_min' => 500, 'amount_max' => 100]))->assertSessionHasErrors('amount_min');

        $badCustom = $this->payload();
        $badCustom['steps'][1]['custom'] = [['unit_id' => '', 'department_id' => '', 'user_id' => '']];
        $this->as($admin)->post($route, $badCustom)->assertSessionHasErrors('steps');

        $badRole = $this->payload();
        $badRole['steps'][0] = ['name' => 'x', 'approver_type' => 'role', 'approver_ref' => 'No Such Role'];
        $this->as($admin)->post($route, $badRole)->assertSessionHasErrors('steps');

        $this->assertSame(0, ApprovalWorkflow::where('name', 'Requisition – Unit A')->count());
    }

    public function test_activating_an_ambiguous_workflow_is_blocked(): void
    {
        $admin = $this->admin();
        $this->makeWorkflow([['approver_type' => 'reporting_head']], ['name' => 'Existing active']);

        $this->as($admin)->post(route('approval-matrix.workflows.store'), $this->payload(['state' => 'active']))
            ->assertSessionHasErrors('state');

        $this->assertSame(0, ApprovalWorkflow::where('name', 'Requisition – Unit A')->count(), 'failed activation must roll back');
    }

    public function test_update_without_history_edits_in_place(): void
    {
        $admin = $this->admin();
        $wf = $this->makeWorkflow([['approver_type' => 'reporting_head'], ['approver_type' => 'reporting_head']], ['name' => 'Old', 'state' => 'draft']);

        $payload = $this->payload(['name' => 'Renamed']);
        $this->as($admin)->put(route('approval-matrix.workflows.update', $wf->id), $payload)->assertRedirect();

        $wf->refresh();
        $this->assertSame('Renamed', $wf->name);
        $this->assertSame(1, $wf->version);
        $this->assertSame(2, $wf->steps()->count());
        $this->assertSame('custom_user', $wf->steps[1]->approver_type);
        $this->assertSame(1, ApprovalWorkflow::count() - ApprovalWorkflow::where('id', '!=', $wf->id)->count());
    }

    public function test_update_with_history_creates_new_version_and_keeps_old_intact(): void
    {
        $admin = $this->admin();
        $head = $this->makeUser('head');
        $headEmp = $this->makeEmployee($head);
        $requester = $this->makeUser('req');
        $this->makeEmployee($requester, $headEmp);
        $wf = $this->makeWorkflow([['approver_type' => 'reporting_head']], ['name' => 'Live', 'state' => 'active']);
        $req = TestDocument::find($requester->id)->submitForApproval($requester->id);
        $this->assertSame($wf->id, $req->workflow_id);

        $this->as($admin)->put(route('approval-matrix.workflows.update', $wf->id), $this->payload(['name' => 'Live', 'state' => 'active']))
            ->assertRedirect()->assertSessionHas('message');

        $v2 = ApprovalWorkflow::where('parent_id', $wf->id)->firstOrFail();
        $this->assertSame(2, $v2->version);
        $this->assertSame('active', $v2->state);
        $this->assertSame('archived', $wf->refresh()->state);
        $this->assertSame(1, $wf->steps()->count(), 'old version steps untouched');
        $this->assertSame(2, $v2->steps()->count());

        // the in-flight request still completes on its snapshot
        $this->assertSame('approved', app(ApprovalEngine::class)->approve($req->refresh(), $head->id)->status);
    }

    public function test_activate_archive_delete_endpoints(): void
    {
        $admin = $this->admin();
        $wf = $this->makeWorkflow([['approver_type' => 'reporting_head']], ['state' => 'draft']);

        $this->as($admin)->postJson(route('approval-matrix.workflows.activate', $wf->id))->assertOk()->assertJson(['success' => true]);
        $this->assertSame('active', $wf->refresh()->state);

        $this->as($admin)->postJson(route('approval-matrix.workflows.archive', $wf->id))->assertOk();
        $this->assertSame('archived', $wf->refresh()->state);

        $this->as($admin)->deleteJson(route('approval-matrix.workflows.destroy', $wf->id))->assertOk()->assertJsonPath('message', 'Workflow deleted.');
        $this->assertNull(ApprovalWorkflow::find($wf->id));
    }

    public function test_delete_of_used_workflow_archives_instead(): void
    {
        $admin = $this->admin();
        $head = $this->makeUser('head');
        $headEmp = $this->makeEmployee($head);
        $requester = $this->makeUser('req');
        $this->makeEmployee($requester, $headEmp);
        $wf = $this->makeWorkflow([['approver_type' => 'reporting_head']]);
        TestDocument::find($requester->id)->submitForApproval($requester->id);

        $this->as($admin)->deleteJson(route('approval-matrix.workflows.destroy', $wf->id))->assertOk();

        $this->assertNotNull(ApprovalWorkflow::find($wf->id));
        $this->assertSame('archived', $wf->refresh()->state);
    }

    public function test_activating_a_workflow_with_a_clash_is_blocked(): void
    {
        $admin = $this->admin();
        $this->makeWorkflow([['approver_type' => 'reporting_head']]);
        $draft = $this->makeWorkflow([['approver_type' => 'reporting_head']], ['state' => 'draft']);

        $this->as($admin)->postJson(route('approval-matrix.workflows.activate', $draft->id))->assertStatus(422);
        $this->assertSame('draft', $draft->refresh()->state);
    }

    // ------------------------------------------------------------------ simulator

    public function test_simulator_shows_resolved_approvers_and_skips(): void
    {
        $admin = $this->admin();
        $head = $this->makeUser('Reporting Boss');
        $headEmp = $this->makeEmployee($head);
        $requester = $this->makeUser('req');
        $this->makeEmployee($requester, $headEmp);
        $mgmt = $this->makeUser('Management Guy');
        $this->makeWorkflow([
            ['approver_type' => 'reporting_head'],
            ['approver_type' => 'custom_user', 'skip_condition' => ['amount_max' => 50000], 'custom' => [['user_id' => $mgmt->id]]],
        ], ['name' => 'Sim WF']);

        $small = $this->as($admin)->get(route('approval-matrix.simulator.index', ['document_type' => self::DOC_TYPE, 'requester_id' => $requester->id, 'amount' => 1000]));
        $small->assertOk()->assertSee('Sim WF')->assertSee('Test Reporting Boss')->assertSee('Skipped');

        $big = $this->as($admin)->get(route('approval-matrix.simulator.index', ['document_type' => self::DOC_TYPE, 'requester_id' => $requester->id, 'amount' => 900000]));
        $big->assertSee('Test Management Guy');
    }

    public function test_simulator_warns_when_no_workflow_or_no_approver(): void
    {
        $admin = $this->admin();
        $this->as($admin)->get(route('approval-matrix.simulator.index', ['document_type' => self::DOC_TYPE]))
            ->assertOk()->assertSee('No active workflow matches');

        $this->makeWorkflow([['approver_type' => 'reporting_head']]);
        $orphan = $this->makeUser('orphan');
        $this->makeEmployee($orphan);
        $this->as($admin)->get(route('approval-matrix.simulator.index', ['document_type' => self::DOC_TYPE, 'requester_id' => $orphan->id]))
            ->assertSee('has no approver');
    }

    public function test_simulator_service_exposes_approver_ids(): void
    {
        $head = $this->makeUser('head');
        $headEmp = $this->makeEmployee($head);
        $requester = $this->makeUser('req');
        $this->makeEmployee($requester, $headEmp);
        $this->makeWorkflow([['approver_type' => 'reporting_head']]);

        $r = app(\Bizzsol\ApprovalMatrix\Services\ApprovalSimulator::class)
            ->simulate(['document_type' => self::DOC_TYPE, 'requester_id' => $requester->id]);

        $this->assertSame([$head->id], $r['steps'][0]['approver_ids']);
    }

    public function test_simulator_writes_nothing(): void
    {
        $admin = $this->admin();
        $this->makeWorkflow([['approver_type' => 'reporting_head']]);
        $before = ApprovalRequest::count();

        $this->as($admin)->get(route('approval-matrix.simulator.index', ['document_type' => self::DOC_TYPE, 'requester_id' => $admin->id]));

        $this->assertSame($before, ApprovalRequest::count());
    }

    // ------------------------------------------------------------------ inbox

    private function pendingRequestFor(User $head, User $requester): ApprovalRequest
    {
        $this->makeWorkflow([['approver_type' => ApprovalStep::TYPE_SPECIFIC_USER, 'user_id' => $head->id, 'name' => 'Boss']]);

        return TestDocument::find($requester->id)->submitForApproval($requester->id);
    }

    public function test_inbox_lists_only_my_pending_requests_and_approver_can_approve(): void
    {
        Permission::findOrCreate('approval-inbox', 'web');
        $head = $this->makeUser('head');
        $head->givePermissionTo('approval-inbox');
        $requester = $this->makeUser('req');
        $req = $this->pendingRequestFor($head, $requester);
        $ajax = ['X-Requested-With' => 'XMLHttpRequest'];

        $this->as($head)->get(route('approval-matrix.inbox.index'))->assertOk()->assertSee('Waiting for my approval');
        $rows = $this->as($head)->get(route('approval-matrix.inbox.index', ['draw' => 1]), $ajax)->json('data');
        $this->assertCount(1, $rows);
        $this->assertStringContainsString((string) $req->id, $rows[0]['actions']);

        $this->as($head)->get(route('approval-matrix.inbox.show', $req->id))->assertOk()->assertSee('Approve')->assertSee('Boss');

        $this->as($head)->post(route('approval-matrix.inbox.approve', $req->id), ['comments' => 'fine'])
            ->assertRedirect(route('approval-matrix.inbox.index'));
        $this->assertSame('approved', $req->refresh()->status);

        $this->assertCount(0, $this->as($head)->get(route('approval-matrix.inbox.index', ['draw' => 1]), $ajax)->json('data'));
    }

    public function test_reject_requires_a_comment_and_non_approver_cannot_act(): void
    {
        Permission::findOrCreate('approval-inbox', 'web');
        $head = $this->makeUser('head');
        $head->givePermissionTo('approval-inbox');
        $other = $this->makeUser('other');
        $other->givePermissionTo('approval-inbox');
        $requester = $this->makeUser('req');
        $req = $this->pendingRequestFor($head, $requester);

        $this->as($head)->post(route('approval-matrix.inbox.reject', $req->id), [])->assertSessionHasErrors('comments');
        $this->assertSame('pending', $req->refresh()->status);

        $this->as($other)->post(route('approval-matrix.inbox.approve', $req->id))->assertSessionHas('alert-type', 'error');
        $this->assertSame('pending', $req->refresh()->status);

        $this->as($other)->get(route('approval-matrix.inbox.show', $req->id))->assertForbidden();

        $this->as($head)->post(route('approval-matrix.inbox.reject', $req->id), ['comments' => 'no'])->assertRedirect();
        $this->assertSame('rejected', $req->refresh()->status);
    }

    public function test_requester_can_view_and_recall(): void
    {
        Permission::findOrCreate('approval-inbox', 'web');
        $head = $this->makeUser('head');
        $requester = $this->makeUser('req');
        $requester->givePermissionTo('approval-inbox');
        $req = $this->pendingRequestFor($head, $requester);

        $this->as($requester)->get(route('approval-matrix.inbox.show', $req->id))->assertOk()->assertSee('Recall my request');
        $this->as($requester)->post(route('approval-matrix.inbox.recall', $req->id))->assertRedirect();

        $this->assertSame('recalled', $req->refresh()->status);
    }
}
