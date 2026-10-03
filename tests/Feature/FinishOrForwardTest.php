<?php

namespace Bizzsol\ApprovalMatrix\Tests\Feature;

use Bizzsol\ApprovalMatrix\Exceptions\ApprovalException;
use Bizzsol\ApprovalMatrix\Models\ApprovalAction;
use Bizzsol\ApprovalMatrix\Models\ApprovalRequest;
use Bizzsol\ApprovalMatrix\Services\ApprovalEngine;
use Bizzsol\ApprovalMatrix\Services\ApprovalSimulator;
use Bizzsol\ApprovalMatrix\Services\WorkflowService;
use Bizzsol\ApprovalMatrix\Tests\Support\ApprovalTestCase;
use Bizzsol\ApprovalMatrix\Tests\Support\TestDocument;

/** "Acknowledge" (finish at this step) vs "Send to next" (forward), as in the PMS requisition flow. */
class FinishOrForwardTest extends ApprovalTestCase
{
    private $head;

    private $mgmt;

    private $requester;

    private TestDocument $doc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->head = $this->makeUser('head');
        $headEmp = $this->makeEmployee($this->head);
        $this->requester = $this->makeUser('req');
        $this->makeEmployee($this->requester, $headEmp);
        $this->mgmt = $this->makeUser('mgmt');
        $this->doc = TestDocument::find($this->requester->id);
    }

    private function engine(): ApprovalEngine
    {
        return app(ApprovalEngine::class);
    }

    private function workflow(bool $headCanFinish = true): void
    {
        $this->makeWorkflow([
            ['approver_type' => 'reporting_head', 'name' => 'Dept head', 'can_finish' => $headCanFinish ? 1 : 0],
            ['approver_type' => 'specific_user', 'user_id' => $this->mgmt->id, 'name' => 'Management'],
        ]);
    }

    public function test_finish_completes_the_request_and_records_remaining_steps_as_skipped(): void
    {
        $this->workflow();
        $req = $this->doc->submitForApproval($this->requester->id);

        $req = $this->engine()->approve($req, $this->head->id, 'ack', true);

        $this->assertSame(ApprovalRequest::APPROVED, $req->status);
        $this->assertFalse($this->engine()->inbox($this->mgmt->id)->exists(), 'management is never asked');
        $skipped = ApprovalAction::where('request_id', $req->id)->where('level', 2)->first();
        $this->assertSame(ApprovalAction::SKIPPED, $skipped->action);
        $this->assertStringContainsString('earlier step', $skipped->comments);
    }

    public function test_forward_moves_to_the_next_step(): void
    {
        $this->workflow();
        $req = $this->doc->submitForApproval($this->requester->id);

        $req = $this->engine()->approve($req, $this->head->id, 'send up', false);

        $this->assertSame(ApprovalRequest::PENDING, $req->status);
        $this->assertSame(2, $req->current_level);
        $this->assertSame([$this->mgmt->id], $this->engine()->currentApprovers($req));
    }

    public function test_finish_is_refused_on_a_step_that_cannot_finish(): void
    {
        $this->workflow(false);
        $req = $this->doc->submitForApproval($this->requester->id);

        try {
            $this->engine()->approve($req, $this->head->id, null, true);
            $this->fail('expected ApprovalException');
        } catch (ApprovalException $e) {
            $this->assertStringContainsString('cannot complete', $e->getMessage());
        }
        $this->assertSame(ApprovalAction::PENDING, ApprovalAction::where('request_id', $req->id)->where('assigned_to', $this->head->id)->value('action'), 'refused finish must not consume the approval');
    }

    public function test_finish_on_a_group_step_waits_until_the_step_is_satisfied(): void
    {
        $a = $this->makeUser('a');
        $b = $this->makeUser('b');
        $this->makeWorkflow([
            ['approver_type' => 'custom_user', 'mode' => 'all', 'can_finish' => 1, 'custom' => [['user_id' => $a->id], ['user_id' => $b->id]]],
            ['approver_type' => 'specific_user', 'user_id' => $this->mgmt->id],
        ]);
        $req = $this->doc->submitForApproval($this->requester->id);

        $req = $this->engine()->approve($req, $a->id, null, true);
        $this->assertSame(ApprovalRequest::PENDING, $req->status, 'second approver of an "all" step still has to act');

        $req = $this->engine()->approve($req, $b->id, null, true);
        $this->assertSame(ApprovalRequest::APPROVED, $req->status);
    }

    public function test_open_request_and_assignment_helpers(): void
    {
        $this->workflow();
        $this->assertNull($this->engine()->openRequestFor($this->doc));

        $req = $this->doc->submitForApproval($this->requester->id);
        $open = $this->engine()->openRequestFor($this->doc);

        $this->assertSame($req->id, $open->id);
        $this->assertTrue($this->engine()->isAssigned($open, $this->head->id));
        $this->assertFalse($this->engine()->isAssigned($open, $this->mgmt->id));
        $this->assertFalse($this->engine()->isAssigned($open, $this->requester->id));

        $this->engine()->approve($open, $this->head->id, null, true);
        $this->assertNull($this->engine()->openRequestFor($this->doc), 'finished requests are no longer open');
    }

    public function test_can_finish_is_saved_by_the_workflow_service_and_shown_by_the_simulator(): void
    {
        [$wf] = app(WorkflowService::class)->save(null, [
            'name' => 'CF', 'document_type' => self::DOC_TYPE, 'state' => 'active',
            'steps' => [
                ['name' => 'Head', 'approver_type' => 'reporting_head', 'approver_ref' => 1, 'mode' => 'any', 'is_mandatory' => 1, 'can_finish' => 1],
                ['name' => 'Mgmt', 'approver_type' => 'specific_user', 'user_id' => $this->mgmt->id, 'mode' => 'any', 'is_mandatory' => 1],
            ],
        ], $this->head->id);

        $this->assertSame([true, false], $wf->steps->map->can_finish->all());

        $r = app(ApprovalSimulator::class)->simulate(['document_type' => self::DOC_TYPE, 'requester_id' => $this->requester->id]);
        $this->assertSame([true, false], array_column($r['steps'], 'can_finish'));
    }

    public function test_cancel_closes_an_open_request_for_a_voided_document(): void
    {
        $this->workflow();
        $req = $this->doc->submitForApproval($this->requester->id);

        $req = $this->engine()->cancel($req, $this->mgmt->id, 'PO cancelled');

        $this->assertSame(ApprovalRequest::RECALLED, $req->status);
        $this->assertFalse($this->engine()->inbox($this->head->id)->exists());
        $this->assertSame(0, \Bizzsol\ApprovalMatrix\Models\ApprovalAction::where('request_id', $req->id)->where('action', 'pending')->count());
        $this->expectException(ApprovalException::class);
        $this->engine()->cancel($req, $this->mgmt->id); // already closed
    }
}
