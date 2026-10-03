<?php

namespace Bizzsol\ApprovalMatrix\Tests\Feature;

use Bizzsol\ApprovalMatrix\Services\WorkflowService;
use Bizzsol\ApprovalMatrix\Tests\Support\ApprovalTestCase;
use Illuminate\Validation\ValidationException;

/** Per-document-type constraints from config (CS: every approver of a level must approve, no early finish). */
class DocumentTypeRulesTest extends ApprovalTestCase
{
    private function save(array $stepOver, string $type = 'procurement.cs')
    {
        $u = $this->makeUser('x');

        return app(WorkflowService::class)->save(null, [
            'name' => 'R', 'document_type' => $type, 'state' => 'draft',
            'steps' => [array_merge(['name' => 'S', 'approver_type' => 'specific_user', 'user_id' => $u->id, 'mode' => 'all', 'is_mandatory' => 1], $stepOver)],
        ]);
    }

    public function test_cs_accepts_mode_all(): void
    {
        [$wf] = $this->save([]);
        $this->assertSame('procurement.cs', $wf->document_type);
    }

    public function test_cs_rejects_any_and_n_of_m_modes(): void
    {
        foreach (['any', 'n_of_m'] as $mode) {
            try {
                $this->save(['mode' => $mode, 'min_approvals' => 1]);
                $this->fail("mode {$mode} should be refused for CS");
            } catch (ValidationException $e) {
                $this->assertStringContainsString('only supports the approval mode', $e->errors()['steps'][0]);
            }
        }
    }

    public function test_cs_rejects_can_finish(): void
    {
        $this->expectException(ValidationException::class);
        $this->save(['can_finish' => 1]);
    }

    public function test_other_document_types_are_unrestricted(): void
    {
        [$wf] = $this->save(['mode' => 'any', 'can_finish' => 1], 'procurement.requisition');
        $this->assertTrue($wf->steps->first()->can_finish);
    }
}
