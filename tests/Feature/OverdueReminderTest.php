<?php

namespace Bizzsol\ApprovalMatrix\Tests\Feature;

use Bizzsol\ApprovalMatrix\Events\ApprovalOverdue;
use Bizzsol\ApprovalMatrix\Models\ApprovalAction;
use Bizzsol\ApprovalMatrix\Models\ApprovalRequest;
use Bizzsol\ApprovalMatrix\Services\ApprovalEngine;
use Bizzsol\ApprovalMatrix\Services\WorkflowService;
use Bizzsol\ApprovalMatrix\Tests\Support\ApprovalTestCase;
use Bizzsol\ApprovalMatrix\Tests\Support\TestDocument;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;

/** approval:remind - SLA hours of a step turn into a one-off (or repeating) reminder event. */
class OverdueReminderTest extends ApprovalTestCase
{
    private $approver;

    private ApprovalRequest $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->approver = $this->makeUser('approver');
        $requester = $this->makeUser('requester');
        $this->makeWorkflow([['approver_type' => 'specific_user', 'user_id' => $this->approver->id, 'sla_hours' => 24]]);
        $this->request = TestDocument::find($requester->id)->submitForApproval($requester->id);
    }

    private function age(int $hours): void
    {
        ApprovalAction::where('request_id', $this->request->id)->update(['created_at' => now()->subHours($hours)]);
    }

    public function test_nothing_is_reminded_before_the_sla_has_passed(): void
    {
        Event::fake([ApprovalOverdue::class]);
        $this->age(23);

        Artisan::call('approval:remind');

        Event::assertNotDispatched(ApprovalOverdue::class);
    }

    public function test_an_overdue_assignment_is_reminded_once(): void
    {
        $this->age(30);
        Event::fake([ApprovalOverdue::class]);

        Artisan::call('approval:remind');
        Artisan::call('approval:remind');

        Event::assertDispatchedTimes(ApprovalOverdue::class, 1);
        Event::assertDispatched(ApprovalOverdue::class, fn ($e) => $e->action->assigned_to === $this->approver->id && $e->hoursWaiting >= 30 && $e->slaHours === 24);
        $this->assertNotNull(ApprovalAction::where('request_id', $this->request->id)->value('reminded_at'));
    }

    public function test_repeat_hours_reminds_again_later(): void
    {
        $this->age(30);
        Event::fake([ApprovalOverdue::class]);
        Artisan::call('approval:remind', ['--repeat-hours' => 12]);
        Event::assertDispatchedTimes(ApprovalOverdue::class, 1);

        Artisan::call('approval:remind', ['--repeat-hours' => 12]);
        Event::assertDispatchedTimes(ApprovalOverdue::class, 1);

        ApprovalAction::where('request_id', $this->request->id)->update(['reminded_at' => now()->subHours(13)]);
        Artisan::call('approval:remind', ['--repeat-hours' => 12]);
        Event::assertDispatchedTimes(ApprovalOverdue::class, 2);
    }

    public function test_dry_run_neither_fires_nor_marks(): void
    {
        $this->age(30);
        Event::fake([ApprovalOverdue::class]);

        Artisan::call('approval:remind', ['--dry-run' => true]);

        Event::assertNotDispatched(ApprovalOverdue::class);
        $this->assertNull(ApprovalAction::where('request_id', $this->request->id)->value('reminded_at'));
    }

    public function test_finished_requests_and_steps_without_an_sla_are_ignored(): void
    {
        $this->age(100);
        app(ApprovalEngine::class)->approve($this->request, $this->approver->id);
        Event::fake([ApprovalOverdue::class]);
        Artisan::call('approval:remind');
        Event::assertNotDispatched(ApprovalOverdue::class);

        // a workflow whose step has no SLA never reminds
        $u = $this->makeUser('u2');
        $r2 = $this->makeUser('r2');
        $this->makeWorkflow([['approver_type' => 'specific_user', 'user_id' => $u->id]], ['priority' => 9]);
        $req = TestDocument::find($r2->id)->submitForApproval($r2->id);
        ApprovalAction::where('request_id', $req->id)->update(['created_at' => now()->subHours(500)]);
        Artisan::call('approval:remind');
        Event::assertNotDispatched(ApprovalOverdue::class);
    }

    public function test_the_workflow_screen_service_saves_the_sla_hours(): void
    {
        [$wf] = app(WorkflowService::class)->save(null, [
            'name' => 'SLA', 'document_type' => self::DOC_TYPE, 'state' => 'draft',
            'steps' => [['name' => 'S', 'approver_type' => 'specific_user', 'user_id' => $this->approver->id, 'mode' => 'any', 'is_mandatory' => 1, 'sla_hours' => 48]],
        ]);

        $this->assertSame(48, (int) $wf->steps->first()->sla_hours);
    }
}
