<?php

namespace Bizzsol\ApprovalMatrix\Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Event;
use Bizzsol\ApprovalMatrix\Events\ApprovalFinished;
use Bizzsol\ApprovalMatrix\Events\ApprovalStepAssigned;
use Bizzsol\ApprovalMatrix\Exceptions\ApprovalException;
use Bizzsol\ApprovalMatrix\Exceptions\NoApproverException;
use Bizzsol\ApprovalMatrix\Models\ApprovalAction;
use Bizzsol\ApprovalMatrix\Models\ApprovalRequest;
use Bizzsol\ApprovalMatrix\Models\ApprovalStep;
use Bizzsol\ApprovalMatrix\Services\ApprovalEngine;
use Bizzsol\ApprovalMatrix\Tests\Support\ApprovalTestCase;
use Bizzsol\ApprovalMatrix\Tests\Support\TestDocument;

class ApprovalEngineTest extends ApprovalTestCase
{
    private User $requester;

    private User $head;

    private User $mgmt;

    private TestDocument $doc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->head = $this->makeUser('head');
        $headEmp = $this->makeEmployee($this->head);
        $this->requester = $this->makeUser('requester');
        $this->makeEmployee($this->requester, $headEmp);
        $this->mgmt = $this->makeUser('mgmt');
        $this->doc = TestDocument::find($this->requester->id);
    }

    private function engine(): ApprovalEngine
    {
        return app(ApprovalEngine::class);
    }

    /** Requisition shape: reporting head, then a custom "management" user. */
    private function requisitionWorkflow(array $mgmtStepExtra = []): void
    {
        $this->makeWorkflow([
            ['approver_type' => ApprovalStep::TYPE_REPORTING_HEAD, 'name' => 'Department head', 'stage_key' => 'dept_head'],
            array_merge([
                'approver_type' => ApprovalStep::TYPE_CUSTOM_USER, 'name' => 'Management', 'stage_key' => 'management',
                'custom' => [['unit_id' => null, 'department_id' => null, 'user_id' => $this->mgmt->id]],
            ], $mgmtStepExtra),
        ]);
    }

    public function test_full_requisition_flow_reporting_head_then_custom_user(): void
    {
        Event::fake([ApprovalStepAssigned::class, ApprovalFinished::class]);
        $this->requisitionWorkflow();

        $req = $this->doc->submitForApproval($this->requester->id);

        $this->assertSame(ApprovalRequest::PENDING, $req->status);
        $this->assertSame(1, $req->current_level);
        $this->assertTrue($this->engine()->inbox($this->head->id)->whereKey($req->id)->exists());
        $this->assertFalse($this->engine()->inbox($this->mgmt->id)->exists());

        $req = $this->engine()->approve($req, $this->head->id, 'ok from head');
        $this->assertSame(2, $req->current_level);
        $this->assertSame(ApprovalRequest::PENDING, $req->status);
        $this->assertFalse($this->engine()->inbox($this->head->id)->whereKey($req->id)->exists());
        $this->assertTrue($this->engine()->inbox($this->mgmt->id)->whereKey($req->id)->exists());

        $req = $this->engine()->approve($req, $this->mgmt->id);
        $this->assertSame(ApprovalRequest::APPROVED, $req->status);
        $this->assertNotNull($req->completed_at);

        Event::assertDispatchedTimes(ApprovalStepAssigned::class, 2);
        Event::assertDispatched(ApprovalFinished::class, fn ($e) => $e->request->status === ApprovalRequest::APPROVED);
        $this->assertSame('ok from head', ApprovalAction::where('request_id', $req->id)->where('level', 1)->value('comments'));
    }

    public function test_only_the_assigned_approver_can_act(): void
    {
        $this->requisitionWorkflow();
        $req = $this->doc->submitForApproval($this->requester->id);

        $this->expectException(ApprovalException::class);
        $this->engine()->approve($req, $this->mgmt->id); // mgmt is level 2, not current
    }

    public function test_requester_cannot_approve_own_request(): void
    {
        $this->requisitionWorkflow();
        $req = $this->doc->submitForApproval($this->requester->id);

        $this->expectException(ApprovalException::class);
        $this->engine()->approve($req, $this->requester->id);
    }

    public function test_reject_terminates_the_request(): void
    {
        $this->requisitionWorkflow();
        $req = $this->doc->submitForApproval($this->requester->id);

        $req = $this->engine()->reject($req, $this->head->id, 'no budget');

        $this->assertSame(ApprovalRequest::REJECTED, $req->status);
        $this->assertFalse($this->engine()->inbox($this->mgmt->id)->exists());
        $this->expectException(ApprovalException::class);
        $this->engine()->approve($req, $this->mgmt->id);
    }

    public function test_reject_with_return_to_requester_sets_returned(): void
    {
        $this->makeWorkflow([['approver_type' => 'reporting_head', 'on_reject' => 'return_to_requester']]);
        $req = $this->doc->submitForApproval($this->requester->id);

        $req = $this->engine()->reject($req, $this->head->id, 'fix quantities');

        $this->assertSame(ApprovalRequest::RETURNED, $req->status);
    }

    public function test_resubmission_after_rejection_creates_next_revision(): void
    {
        $this->makeWorkflow([['approver_type' => 'reporting_head']]);
        $first = $this->doc->submitForApproval($this->requester->id);
        $this->engine()->reject($first, $this->head->id);

        $second = $this->doc->submitForApproval($this->requester->id);

        $this->assertSame(0, $first->revision);
        $this->assertSame(1, $second->revision);
    }

    public function test_cannot_submit_twice_while_pending(): void
    {
        $this->requisitionWorkflow();
        $this->doc->submitForApproval($this->requester->id);

        $this->expectException(ApprovalException::class);
        $this->doc->submitForApproval($this->requester->id);
    }

    public function test_recall_only_by_requester(): void
    {
        $this->requisitionWorkflow();
        $req = $this->doc->submitForApproval($this->requester->id);

        try {
            $this->engine()->recall($req, $this->head->id);
            $this->fail('non-requester recall should fail');
        } catch (ApprovalException $e) {
            $this->assertStringContainsString('requester', $e->getMessage());
        }

        $req = $this->engine()->recall($req, $this->requester->id);
        $this->assertSame(ApprovalRequest::RECALLED, $req->status);
        $this->assertFalse($this->engine()->inbox($this->head->id)->exists());
    }

    public function test_no_workflow_throws_so_callers_can_fall_back_to_legacy(): void
    {
        $this->expectException(ApprovalException::class);
        $this->expectExceptionMessage('No active approval workflow');
        $this->doc->submitForApproval($this->requester->id);
    }

    public function test_mandatory_step_with_no_reporting_head_fails_loudly_and_rolls_back(): void
    {
        $this->makeWorkflow([['approver_type' => 'reporting_head']]);
        $orphan = $this->makeUser('orphan');
        $this->makeEmployee($orphan); // reports to self
        $doc = TestDocument::find($orphan->id);

        try {
            $doc->submitForApproval($orphan->id);
            $this->fail('expected NoApproverException');
        } catch (NoApproverException) {
            $this->assertSame(0, ApprovalRequest::where('approvable_id', $orphan->id)->count(), 'failed submit must leave no half-created request');
        }
    }

    public function test_optional_step_with_no_approver_is_skipped(): void
    {
        $this->makeWorkflow([
            ['approver_type' => 'reporting_head', 'is_mandatory' => 0],
            ['approver_type' => 'specific_user', 'user_id' => $this->mgmt->id],
        ]);
        $orphan = $this->makeUser('orphan');
        $this->makeEmployee($orphan);
        $req = TestDocument::find($orphan->id)->submitForApproval($orphan->id);

        $this->assertSame(2, $req->current_level);
        $this->assertSame(ApprovalAction::SKIPPED, ApprovalAction::where('request_id', $req->id)->where('level', 1)->value('action'));
    }

    public function test_management_step_is_skipped_for_small_amounts(): void
    {
        $this->requisitionWorkflow(['skip_condition' => ['amount_max' => 49999]]);
        $this->doc->fakeAmount = 1000;

        $req = $this->doc->submitForApproval($this->requester->id);
        $req = $this->engine()->approve($req, $this->head->id);

        $this->assertSame(ApprovalRequest::APPROVED, $req->status, 'amount below threshold: department head approval is final');
    }

    public function test_management_step_applies_for_large_amounts(): void
    {
        $this->requisitionWorkflow(['skip_condition' => ['amount_max' => 49999]]);
        $this->doc->fakeAmount = 250000;

        $req = $this->doc->submitForApproval($this->requester->id);
        $req = $this->engine()->approve($req, $this->head->id);

        $this->assertSame(ApprovalRequest::PENDING, $req->status);
        $this->assertSame(2, $req->current_level);
    }

    public function test_mode_any_first_approval_closes_the_step_for_the_others(): void
    {
        $a = $this->makeUser('a');
        $b = $this->makeUser('b');
        $this->makeWorkflow([[
            'approver_type' => 'custom_user', 'mode' => 'any',
            'custom' => [['user_id' => $a->id], ['user_id' => $b->id]],
        ]]);
        $req = $this->doc->submitForApproval($this->requester->id);

        $req = $this->engine()->approve($req, $a->id);

        $this->assertSame(ApprovalRequest::APPROVED, $req->status);
        $this->assertSame(ApprovalAction::SUPERSEDED, ApprovalAction::where('request_id', $req->id)->where('assigned_to', $b->id)->value('action'));
    }

    public function test_mode_all_needs_every_approver(): void
    {
        $a = $this->makeUser('a');
        $b = $this->makeUser('b');
        $this->makeWorkflow([[
            'approver_type' => 'custom_user', 'mode' => 'all',
            'custom' => [['user_id' => $a->id], ['user_id' => $b->id]],
        ]]);
        $req = $this->doc->submitForApproval($this->requester->id);

        $req = $this->engine()->approve($req, $a->id);
        $this->assertSame(ApprovalRequest::PENDING, $req->status);

        $req = $this->engine()->approve($req, $b->id);
        $this->assertSame(ApprovalRequest::APPROVED, $req->status);
    }

    public function test_mode_n_of_m(): void
    {
        $users = [$this->makeUser('a'), $this->makeUser('b'), $this->makeUser('c')];
        $this->makeWorkflow([[
            'approver_type' => 'custom_user', 'mode' => 'n_of_m', 'min_approvals' => 2,
            'custom' => array_map(fn ($u) => ['user_id' => $u->id], $users),
        ]]);
        $req = $this->doc->submitForApproval($this->requester->id);

        $req = $this->engine()->approve($req, $users[0]->id);
        $this->assertSame(ApprovalRequest::PENDING, $req->status);

        $req = $this->engine()->approve($req, $users[2]->id);
        $this->assertSame(ApprovalRequest::APPROVED, $req->status);
    }

    public function test_in_flight_request_is_immune_to_later_workflow_edits(): void
    {
        $this->requisitionWorkflow();
        $req = $this->doc->submitForApproval($this->requester->id);

        // Admin rewrites the live workflow after submission.
        ApprovalStep::where('workflow_id', $req->workflow_id)->delete();

        $req = $this->engine()->approve($req->refresh(), $this->head->id);
        $this->assertSame(2, $req->current_level, 'snapshot must still contain the management step');
        $req = $this->engine()->approve($req, $this->mgmt->id);
        $this->assertSame(ApprovalRequest::APPROVED, $req->status);
    }

    public function test_request_captures_requester_org_and_amount(): void
    {
        $o = $this->org();
        $this->makeEmployee($this->makeUser('org'), null, []); // noise
        $u = $this->makeUser('orgreq');
        $this->makeEmployee($u, null, ['company_id' => $o['company'], 'unit_id' => $o['unit'], 'department_id' => $o['dept']]);
        $this->makeWorkflow([['approver_type' => 'specific_user', 'user_id' => $this->mgmt->id]], ['unit_id' => $o['unit']]);
        $doc = TestDocument::find($u->id);
        $doc->fakeAmount = 1234.5;

        $req = $doc->submitForApproval($u->id);

        $this->assertSame($o['unit'], (int) $req->unit_id);
        $this->assertSame($o['dept'], (int) $req->department_id);
        $this->assertEquals(1234.5, $req->amount);
    }
}
