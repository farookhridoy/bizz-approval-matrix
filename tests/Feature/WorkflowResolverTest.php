<?php

namespace Bizzsol\ApprovalMatrix\Tests\Feature;

use Bizzsol\ApprovalMatrix\Services\WorkflowResolver;
use Bizzsol\ApprovalMatrix\Tests\Support\ApprovalTestCase;

class WorkflowResolverTest extends ApprovalTestCase
{
    private function resolver(): WorkflowResolver
    {
        return app(WorkflowResolver::class);
    }

    private function step(): array
    {
        return [['approver_type' => 'reporting_head']];
    }

    public function test_most_specific_scope_wins(): void
    {
        $o = $this->org();
        $global = $this->makeWorkflow($this->step(), ['name' => 'global']);
        $company = $this->makeWorkflow($this->step(), ['name' => 'company', 'company_id' => $o['company']]);
        $unit = $this->makeWorkflow($this->step(), ['name' => 'unit', 'company_id' => $o['company'], 'unit_id' => $o['unit']]);
        $dept = $this->makeWorkflow($this->step(), ['name' => 'dept', 'company_id' => $o['company'], 'unit_id' => $o['unit'], 'department_id' => $o['dept']]);

        $ctx = ['company_id' => $o['company'], 'unit_id' => $o['unit'], 'department_id' => $o['dept']];
        $this->assertSame($dept->id, $this->resolver()->resolve(self::DOC_TYPE, $ctx)->id);

        $ctx['department_id'] = $o['dept2'];
        $this->assertSame($unit->id, $this->resolver()->resolve(self::DOC_TYPE, $ctx)->id);

        $ctx['unit_id'] = $o['unit2'];
        $this->assertSame($company->id, $this->resolver()->resolve(self::DOC_TYPE, $ctx)->id);

        $ctx['company_id'] = 999999;
        $this->assertSame($global->id, $this->resolver()->resolve(self::DOC_TYPE, $ctx)->id);
    }

    public function test_priority_breaks_ties_between_equal_scope(): void
    {
        $this->makeWorkflow($this->step(), ['name' => 'low', 'priority' => 1]);
        $high = $this->makeWorkflow($this->step(), ['name' => 'high', 'priority' => 5]);

        $this->assertSame($high->id, $this->resolver()->resolve(self::DOC_TYPE, [])->id);
    }

    public function test_amount_conditions_select_the_right_workflow(): void
    {
        $small = $this->makeWorkflow($this->step(), ['name' => 'small', 'conditions' => ['amount_max' => 99999]]);
        $big = $this->makeWorkflow($this->step(), ['name' => 'big', 'conditions' => ['amount_min' => 100000]]);

        $this->assertSame($small->id, $this->resolver()->resolve(self::DOC_TYPE, ['amount' => 500])->id);
        $this->assertSame($big->id, $this->resolver()->resolve(self::DOC_TYPE, ['amount' => 100000])->id);
        $this->assertNull($this->resolver()->resolve(self::DOC_TYPE, []), 'amount-based workflows must not match a document without an amount');
    }

    public function test_attribute_conditions(): void
    {
        $foreign = $this->makeWorkflow($this->step(), ['conditions' => ['attributes' => ['purchase_type' => ['foreign']]]]);

        $this->assertSame($foreign->id, $this->resolver()->resolve(self::DOC_TYPE, ['attributes' => ['purchase_type' => 'foreign']])->id);
        $this->assertNull($this->resolver()->resolve(self::DOC_TYPE, ['attributes' => ['purchase_type' => 'local']]));
    }

    public function test_inactive_draft_archived_and_out_of_date_workflows_are_ignored(): void
    {
        $this->makeWorkflow($this->step(), ['state' => 'draft']);
        $this->makeWorkflow($this->step(), ['state' => 'archived']);
        $this->makeWorkflow($this->step(), ['status' => 0]);
        $this->makeWorkflow($this->step(), ['effective_to' => now()->subDay()->toDateString()]);
        $this->makeWorkflow($this->step(), ['effective_from' => now()->addDay()->toDateString()]);

        $this->assertNull($this->resolver()->resolve(self::DOC_TYPE, []));

        $live = $this->makeWorkflow($this->step(), ['effective_from' => now()->subDay()->toDateString(), 'effective_to' => now()->addDay()->toDateString()]);
        $this->assertSame($live->id, $this->resolver()->resolve(self::DOC_TYPE, [])->id);
    }

    public function test_other_document_types_do_not_leak(): void
    {
        $this->makeWorkflow($this->step(), ['document_type' => 'procurement.cs']);

        $this->assertNull($this->resolver()->resolve(self::DOC_TYPE, []));
    }

    public function test_ambiguity_detection(): void
    {
        $a = $this->makeWorkflow($this->step(), ['conditions' => ['amount_max' => 1000]]);
        $overlap = $this->makeWorkflow($this->step(), ['conditions' => ['amount_min' => 500]]);
        $disjoint = $this->makeWorkflow($this->step(), ['conditions' => ['amount_min' => 1001]]);

        $ambiguous = $this->resolver()->ambiguousWith($a)->pluck('id');
        $this->assertTrue($ambiguous->contains($overlap->id));
        $this->assertFalse($ambiguous->contains($disjoint->id));
    }
}
