<?php

namespace Bizzsol\ApprovalMatrix\Tests\Feature;

use Bizzsol\ApprovalMatrix\Models\ApprovalAction;
use Bizzsol\ApprovalMatrix\Models\ApprovalRequest;
use Bizzsol\ApprovalMatrix\Services\ApprovalEngine;
use Bizzsol\ApprovalMatrix\Tests\Support\ApprovalTestCase;
use Bizzsol\ApprovalMatrix\Tests\Support\TestDocument;

/** A revised document goes back to the approver who rejected it; earlier approvals are kept (resume = true). */
class ResumeAfterRejectionTest extends ApprovalTestCase
{
    private array $u = [];

    private TestDocument $doc;

    private $requester;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['a', 'b', 'c'] as $k) {
            $this->u[$k] = $this->makeUser($k);
        }
        $this->requester = $this->makeUser('req');
        $this->doc = TestDocument::find($this->requester->id);
    }

    private function engine(): ApprovalEngine
    {
        return app(ApprovalEngine::class);
    }

    private function threeLevels(): void
    {
        $this->makeWorkflow(array_map(
            fn ($k) => ['approver_type' => 'specific_user', 'user_id' => $this->u[$k]->id, 'name' => "L-$k"],
            ['a', 'b', 'c']
        ));
    }

    private function submit(bool $resume): ApprovalRequest
    {
        return $this->engine()->submit($this->doc, $this->requester->id, self::DOC_TYPE, [], $resume);
    }

    public function test_resume_goes_straight_back_to_the_rejecting_level_and_keeps_earlier_approvals(): void
    {
        $this->threeLevels();
        $r1 = $this->submit(false);
        $r1 = $this->engine()->approve($r1, $this->u['a']->id);
        $this->engine()->reject($r1, $this->u['b']->id, 'revise the quantities');

        $r2 = $this->submit(true);

        $this->assertSame(1, $r2->revision);
        $this->assertSame(2, (int) $r2->current_level, 'resumes at the rejecting level');
        $this->assertSame([$this->u['b']->id], $this->engine()->currentApprovers($r2));
        $carried = ApprovalAction::where('request_id', $r2->id)->where('action', ApprovalAction::CARRIED)->get();
        $this->assertCount(1, $carried);
        $this->assertSame($this->u['a']->id, (int) $carried[0]->assigned_to);
        $this->assertStringContainsString('revision 0', $carried[0]->comments);

        // finishes normally from there
        $r2 = $this->engine()->approve($r2, $this->u['b']->id);
        $r2 = $this->engine()->approve($r2, $this->u['c']->id);
        $this->assertSame(ApprovalRequest::APPROVED, $r2->status);
    }

    public function test_without_resume_the_chain_restarts_from_the_top(): void
    {
        $this->threeLevels();
        $r1 = $this->engine()->approve($this->submit(false), $this->u['a']->id);
        $this->engine()->reject($r1, $this->u['b']->id);

        $r2 = $this->submit(false);

        $this->assertSame(1, (int) $r2->current_level);
        $this->assertSame(0, ApprovalAction::where('request_id', $r2->id)->where('action', ApprovalAction::CARRIED)->count());
    }

    public function test_rejection_at_the_first_level_resumes_at_the_first_level(): void
    {
        $this->threeLevels();
        $this->engine()->reject($this->submit(false), $this->u['a']->id);

        $r2 = $this->submit(true);

        $this->assertSame(1, (int) $r2->current_level);
        $this->assertSame(0, ApprovalAction::where('request_id', $r2->id)->where('action', ApprovalAction::CARRIED)->count());
    }

    public function test_a_changed_workflow_version_never_resumes(): void
    {
        $this->threeLevels();
        $r1 = $this->engine()->approve($this->submit(false), $this->u['a']->id);
        $this->engine()->reject($r1, $this->u['b']->id);
        \Bizzsol\ApprovalMatrix\Models\ApprovalWorkflow::query()->update(['version' => 2]); // admin published a new version

        $r2 = $this->submit(true);

        $this->assertSame(1, (int) $r2->current_level, 'different workflow version: full chain again');
    }

    public function test_resume_with_nothing_to_resume_is_a_normal_first_submit(): void
    {
        $this->threeLevels();

        $r = $this->submit(true);

        $this->assertSame(0, $r->revision);
        $this->assertSame(1, (int) $r->current_level);
    }

    public function test_a_returned_request_resumes_too(): void
    {
        $this->makeWorkflow([
            ['approver_type' => 'specific_user', 'user_id' => $this->u['a']->id],
            ['approver_type' => 'specific_user', 'user_id' => $this->u['b']->id, 'on_reject' => 'return_to_requester'],
        ]);
        $r1 = $this->engine()->approve($this->submit(false), $this->u['a']->id);
        $r1 = $this->engine()->reject($r1, $this->u['b']->id);
        $this->assertSame(ApprovalRequest::RETURNED, $r1->status);

        $this->assertSame(2, (int) $this->submit(true)->current_level);
    }
}
