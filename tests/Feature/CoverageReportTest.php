<?php

namespace Bizzsol\ApprovalMatrix\Tests\Feature;

use Bizzsol\ApprovalMatrix\Services\CoverageReport;
use Bizzsol\ApprovalMatrix\Tests\Support\ApprovalTestCase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;

/** "Would this document type work for everybody?" - flags requesters with no workflow or a step nobody can approve. */
class CoverageReportTest extends ApprovalTestCase
{
    private $head;

    private $covered;

    private $orphan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->head = $this->makeUser('head');
        $headEmp = $this->makeEmployee($this->head);
        $this->covered = $this->makeUser('covered');
        $this->makeEmployee($this->covered, $headEmp);
        $this->orphan = $this->makeUser('orphan');
        $this->makeEmployee($this->orphan); // reports to itself: no reporting head
    }

    private function row(array $result, $user): array
    {
        return collect($result['rows'])->firstWhere('user_id', $user->id);
    }

    public function test_requesters_with_and_without_an_approver_are_told_apart(): void
    {
        $this->makeWorkflow([['approver_type' => 'reporting_head', 'name' => 'Dept head']]);

        $result = app(CoverageReport::class)->report(self::DOC_TYPE);

        $ok = $this->row($result, $this->covered);
        $this->assertSame('ok', $ok['status']);
        $this->assertStringContainsString('Test head', $ok['chain']);
        $bad = $this->row($result, $this->orphan);
        $this->assertSame('no-approver', $bad['status']);
        $this->assertStringContainsString('Dept head', $bad['problem']);
        $this->assertGreaterThanOrEqual(1, $result['summary']['ok']);
        $this->assertGreaterThanOrEqual(1, $result['summary']['no-approver']);
        $this->assertSame($result['summary']['total'], array_sum([$result['summary']['ok'], $result['summary']['no-workflow'], $result['summary']['no-approver']]));
    }

    public function test_without_any_workflow_everybody_is_flagged(): void
    {
        $result = app(CoverageReport::class)->report(self::DOC_TYPE);

        $this->assertSame('no-workflow', $this->row($result, $this->covered)['status']);
        $this->assertSame(0, $result['summary']['ok']);
    }

    public function test_the_amount_changes_which_workflow_is_checked(): void
    {
        $this->makeWorkflow([['approver_type' => 'reporting_head']], ['conditions' => ['amount_min' => 1000]]);

        $this->assertSame('no-workflow', $this->row(app(CoverageReport::class)->report(self::DOC_TYPE, 10), $this->covered)['status']);
        $this->assertSame('ok', $this->row(app(CoverageReport::class)->report(self::DOC_TYPE, 5000), $this->covered)['status']);
    }

    public function test_the_command_reports_and_fails_when_there_are_gaps(): void
    {
        $this->makeWorkflow([['approver_type' => 'reporting_head']]);

        $exit = Artisan::call('approval:coverage', ['document_type' => self::DOC_TYPE]);

        $this->assertSame(1, $exit, 'non-zero so it can gate a deploy');
        $out = Artisan::output();
        $this->assertStringContainsString('Test orphan', $out);
        $this->assertStringNotContainsString('Test covered', $out, 'only problems are listed unless --all');
        $this->assertStringContainsString('requesters:', $out);

        $this->assertStringContainsString('Test covered', $this->all());
    }

    private function all(): string
    {
        Artisan::call('approval:coverage', ['document_type' => self::DOC_TYPE, '--all' => true]);

        return Artisan::output();
    }

    public function test_the_command_rejects_unknown_document_types(): void
    {
        $this->assertSame(1, Artisan::call('approval:coverage', ['document_type' => 'nope.nothing']));
        $this->assertStringContainsString('Unknown document type', Artisan::output());
    }

    public function test_the_screen_lists_problems_and_needs_the_simulator_permission(): void
    {
        $this->makeWorkflow([['approver_type' => 'reporting_head']]);
        $viewer = $this->makeUser('viewer');
        $this->actingAs($viewer)->get(route('approval-matrix.coverage.index'))->assertForbidden();

        Permission::findOrCreate('approval-matrix-simulator', 'web');
        $viewer->givePermissionTo('approval-matrix-simulator');
        $this->app['auth']->forgetGuards();

        $this->actingAs($viewer)->get(route('approval-matrix.coverage.index'))->assertOk()->assertSee('Approval Coverage');
        $this->app['auth']->forgetGuards();
        $page = $this->actingAs($viewer)->get(route('approval-matrix.coverage.index', ['document_type' => self::DOC_TYPE]));
        $page->assertOk()->assertSee('Test orphan')->assertSee('No approver for: Step 1')->assertDontSee('Test covered');
    }
}
