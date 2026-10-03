<?php

namespace Bizzsol\ApprovalMatrix\Tests\Feature;

use Bizzsol\ApprovalMatrix\Exceptions\ApprovalException;
use Bizzsol\ApprovalMatrix\Models\ApprovalAction;
use Bizzsol\ApprovalMatrix\Models\ApprovalDelegation;
use Bizzsol\ApprovalMatrix\Models\ApprovalRequest;
use Bizzsol\ApprovalMatrix\Services\ApprovalEngine;
use Bizzsol\ApprovalMatrix\Tests\Support\ApprovalTestCase;
use Bizzsol\ApprovalMatrix\Tests\Support\TestDocument;
use Spatie\Permission\Models\Permission;

/** Out-of-office delegation: the delegate sees, is notified about and can act on the delegator's approvals. */
class DelegationTest extends ApprovalTestCase
{
    private $boss;

    private $delegate;

    private $requester;

    private ApprovalRequest $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->boss = $this->makeUser('boss');
        $this->delegate = $this->makeUser('delegate');
        $this->requester = $this->makeUser('requester');
        $this->makeWorkflow([['approver_type' => 'specific_user', 'user_id' => $this->boss->id, 'name' => 'Boss']]);
        $this->request = TestDocument::find($this->requester->id)->submitForApproval($this->requester->id);
    }

    private function engine(): ApprovalEngine
    {
        return app(ApprovalEngine::class);
    }

    private function delegate(array $over = []): ApprovalDelegation
    {
        return ApprovalDelegation::create(array_merge([
            'delegator_id' => $this->boss->id, 'delegate_id' => $this->delegate->id,
            'starts_on' => now()->subDay()->toDateString(), 'ends_on' => now()->addDays(3)->toDateString(),
        ], $over));
    }

    public function test_without_a_delegation_the_delegate_has_no_access(): void
    {
        $this->assertFalse($this->engine()->isAssigned($this->request, $this->delegate->id));
        $this->assertFalse($this->engine()->inbox($this->delegate->id)->exists());
        $this->expectException(ApprovalException::class);
        $this->engine()->approve($this->request, $this->delegate->id);
    }

    public function test_an_active_delegation_lets_the_delegate_see_and_approve_and_history_shows_who_decided(): void
    {
        $this->delegate();

        $this->assertTrue($this->engine()->isAssigned($this->request, $this->delegate->id));
        $this->assertTrue($this->engine()->inbox($this->delegate->id)->whereKey($this->request->id)->exists());

        $request = $this->engine()->approve($this->request, $this->delegate->id, 'covering');

        $this->assertSame(ApprovalRequest::APPROVED, $request->status);
        $action = ApprovalAction::where('request_id', $request->id)->where('action', ApprovalAction::APPROVED)->first();
        $this->assertSame($this->boss->id, (int) $action->assigned_to, 'the assignment stays with the delegator');
        $this->assertSame($this->delegate->id, (int) $action->acted_by, 'but the delegate is on record as the decider');
    }

    public function test_the_boss_keeps_access_and_the_delegate_can_reject_too(): void
    {
        $this->delegate();
        $this->assertTrue($this->engine()->isAssigned($this->request, $this->boss->id));

        $request = $this->engine()->reject($this->request, $this->delegate->id, 'not needed');

        $this->assertSame(ApprovalRequest::REJECTED, $request->status);
    }

    public function test_expired_and_future_delegations_do_nothing(): void
    {
        $this->delegate(['starts_on' => now()->subDays(10)->toDateString(), 'ends_on' => now()->subDay()->toDateString()]);
        $this->delegate(['starts_on' => now()->addDays(2)->toDateString(), 'ends_on' => now()->addDays(5)->toDateString()]);

        $this->assertFalse($this->engine()->isAssigned($this->request, $this->delegate->id));
        $this->assertFalse($this->engine()->inbox($this->delegate->id)->exists());
    }

    public function test_a_delegation_limited_to_one_document_type_only_covers_that_type(): void
    {
        $this->delegate(['document_type' => 'procurement.cs']);
        $this->assertFalse($this->engine()->isAssigned($this->request, $this->delegate->id), 'this is a requisition');
        $this->assertFalse($this->engine()->inbox($this->delegate->id)->exists());

        ApprovalDelegation::query()->update(['document_type' => self::DOC_TYPE]);
        $this->assertTrue($this->engine()->isAssigned($this->request, $this->delegate->id));
        $this->assertTrue($this->engine()->inbox($this->delegate->id)->exists());
    }

    public function test_a_revoked_delegation_stops_working(): void
    {
        $d = $this->delegate();
        $this->assertTrue($this->engine()->isAssigned($this->request, $this->delegate->id));

        $d->delete();

        $this->assertFalse($this->engine()->isAssigned($this->request, $this->delegate->id));
    }

    public function test_the_requester_cannot_approve_their_own_request_through_a_delegation(): void
    {
        $this->delegate(['delegate_id' => $this->requester->id]);

        $this->assertTrue($this->engine()->isAssigned($this->request, $this->requester->id), 'they can see it');
        $this->expectException(ApprovalException::class);
        $this->expectExceptionMessage('your own request');
        $this->engine()->approve($this->request, $this->requester->id);
    }

    public function test_helpers_expand_assignees_and_notification_recipients(): void
    {
        $this->delegate();
        $other = $this->makeUser('other');

        $this->assertEqualsCanonicalizing([$this->delegate->id, $this->boss->id], $this->engine()->assigneeIdsFor($this->delegate->id));
        $this->assertSame([$other->id], $this->engine()->assigneeIdsFor($other->id));
        $this->assertEqualsCanonicalizing([$this->boss->id, $this->delegate->id], $this->engine()->withDelegates([$this->boss->id]));
        $this->assertSame([], $this->engine()->withDelegates([]));
        $this->assertEqualsCanonicalizing([$this->boss->id, $this->delegate->id], $this->engine()->withDelegates([$this->boss->id], 'procurement.cs'), 'an every-document delegation also covers CS');

        ApprovalDelegation::query()->update(['document_type' => self::DOC_TYPE]);
        $this->assertSame([$this->boss->id], $this->engine()->withDelegates([$this->boss->id], 'procurement.cs'), 'a requisition-only delegate is not notified about CS');
        $this->assertEqualsCanonicalizing([$this->boss->id, $this->delegate->id], $this->engine()->withDelegates([$this->boss->id], self::DOC_TYPE));
    }

    // ------------------------------------------------------------------ screen

    private function member()
    {
        Permission::findOrCreate('approval-inbox', 'web');
        $u = $this->makeUser('member');
        $u->givePermissionTo('approval-inbox');

        return $u;
    }

    public function test_the_screen_creates_lists_and_revokes_my_delegations(): void
    {
        $me = $this->member();
        $payload = ['delegate_id' => $this->delegate->id, 'document_type' => 'procurement.cs', 'starts_on' => now()->toDateString(), 'ends_on' => now()->addDays(2)->toDateString(), 'reason' => 'Eid leave'];

        $this->actingAs($me)->post(route('approval-matrix.inbox.delegations.store'), $payload)->assertRedirect(route('approval-matrix.inbox.delegations.index'));
        $d = ApprovalDelegation::where('delegator_id', $me->id)->firstOrFail();
        $this->assertSame('procurement.cs', $d->document_type);

        $this->app['auth']->forgetGuards();
        $this->actingAs($me)->get(route('approval-matrix.inbox.delegations.index'))->assertOk()->assertSee('Eid leave')->assertSee('Active');

        $this->actingAs($me)->delete(route('approval-matrix.inbox.delegations.destroy', $d->id))->assertRedirect();
        $this->assertNull(ApprovalDelegation::find($d->id));
    }

    public function test_the_screen_validates_and_protects_other_peoples_delegations(): void
    {
        $me = $this->member();
        $good = ['delegate_id' => $this->delegate->id, 'starts_on' => now()->toDateString(), 'ends_on' => now()->addDay()->toDateString()];

        $this->actingAs($me)->post(route('approval-matrix.inbox.delegations.store'), ['delegate_id' => $me->id] + $good)->assertSessionHasErrors('delegate_id');
        $this->actingAs($me)->post(route('approval-matrix.inbox.delegations.store'), ['ends_on' => now()->subDay()->toDateString()] + $good)->assertSessionHasErrors('ends_on');
        $this->actingAs($me)->post(route('approval-matrix.inbox.delegations.store'), ['starts_on' => now()->subDay()->toDateString()] + $good)->assertSessionHasErrors('starts_on');
        $this->actingAs($me)->post(route('approval-matrix.inbox.delegations.store'), ['document_type' => 'nope.nothing'] + $good)->assertSessionHasErrors('document_type');
        $this->assertSame(0, ApprovalDelegation::where('delegator_id', $me->id)->count());

        $bosses = $this->delegate(); // belongs to $this->boss
        $this->actingAs($me)->delete(route('approval-matrix.inbox.delegations.destroy', $bosses->id))->assertNotFound();
        $this->assertNotNull(ApprovalDelegation::find($bosses->id));
    }

    public function test_the_screen_needs_the_inbox_permission(): void
    {
        $this->actingAs($this->makeUser('nobody'))->get(route('approval-matrix.inbox.delegations.index'))->assertForbidden();
    }
}
