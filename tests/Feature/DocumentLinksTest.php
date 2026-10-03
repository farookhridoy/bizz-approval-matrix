<?php

namespace Bizzsol\ApprovalMatrix\Tests\Feature;

use Bizzsol\ApprovalMatrix\Support\DocumentLinks;
use Bizzsol\ApprovalMatrix\Tests\Support\ApprovalTestCase;
use Bizzsol\ApprovalMatrix\Tests\Support\TestDocument;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Permission;

/** The inbox opens the real document when the host app registered how (DocumentLinks). */
class DocumentLinksTest extends ApprovalTestCase
{
    protected function tearDown(): void
    {
        DocumentLinks::flush();
        parent::tearDown();
    }

    private function pending()
    {
        $approver = $this->makeUser('approver');
        $requester = $this->makeUser('requester');
        $this->makeWorkflow([['approver_type' => 'specific_user', 'user_id' => $approver->id]]);
        $request = TestDocument::find($requester->id)->submitForApproval($requester->id);
        Permission::findOrCreate('approval-inbox', 'web');
        $approver->givePermissionTo('approval-inbox');

        return [$request, $approver];
    }

    public function test_unregistered_types_have_no_link(): void
    {
        [$request] = $this->pending();

        $this->assertNull(DocumentLinks::describe($request));
    }

    public function test_a_registered_resolver_feeds_the_inbox_list_and_review_page(): void
    {
        DocumentLinks::register(self::DOC_TYPE, fn (Model $d) => ['url' => 'https://erp.test/doc/'.$d->getKey(), 'label' => 'REQ-'.$d->getKey()]);
        [$request, $approver] = $this->pending();

        $d = DocumentLinks::describe($request);
        $this->assertSame('REQ-'.$request->approvable_id, $d['label']);

        $rows = $this->actingAs($approver)->get(route('approval-matrix.inbox.index', ['draw' => 1]), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->json('data');
        $this->assertStringContainsString('href="https://erp.test/doc/'.$request->approvable_id.'"', $rows[0]['document']);

        $this->app['auth']->forgetGuards();
        $this->actingAs($approver)->get(route('approval-matrix.inbox.show', $request->id))->assertOk()->assertSee('Open document')->assertSee('https://erp.test/doc/'.$request->approvable_id, false);
    }

    public function test_a_resolver_returning_nothing_is_treated_as_no_link(): void
    {
        DocumentLinks::register(self::DOC_TYPE, fn () => null);
        [$request] = $this->pending();

        $this->assertNull(DocumentLinks::describe($request));
    }
}
