<?php

namespace Bizzsol\ApprovalMatrix\Services;

use Illuminate\Support\Collection;
use Bizzsol\ApprovalMatrix\Models\ApprovalWorkflow;

/**
 * Picks the workflow for a document: filter by document type / state / dates / scope / conditions,
 * then rank by specificity (department > unit > company > global), priority, version.
 */
class WorkflowResolver
{
    /**
     * @param  array{company_id?:?int,unit_id?:?int,department_id?:?int,master_department_id?:?int,amount?:float|int|string|null,attributes?:array}  $ctx
     */
    public function resolve(string $documentType, array $ctx, ?\DateTimeInterface $on = null): ?ApprovalWorkflow
    {
        return $this->candidates($documentType, $ctx, $on)->first();
    }

    /** Matching workflows, best first. */
    public function candidates(string $documentType, array $ctx, ?\DateTimeInterface $on = null): Collection
    {
        $on = ($on ? \Carbon\Carbon::instance($on) : now())->toDateString();

        return ApprovalWorkflow::query()
            ->where('document_type', $documentType)
            ->where('state', ApprovalWorkflow::STATE_ACTIVE)
            ->where('status', 1)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $on))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $on))
            ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $ctx['company_id'] ?? 0))
            ->where(fn ($q) => $q->whereNull('unit_id')->orWhere('unit_id', $ctx['unit_id'] ?? 0))
            ->where(fn ($q) => $q->whereNull('department_id')->orWhere('department_id', $ctx['department_id'] ?? 0))
            ->where(fn ($q) => $q->whereNull('master_department_id')->orWhere('master_department_id', $ctx['master_department_id'] ?? 0))
            ->get()
            ->filter(fn (ApprovalWorkflow $w) => ConditionMatcher::matches($w->conditions, $ctx))
            ->sort(fn ($a, $b) => [$b->specificity(), $b->priority, $b->version, $b->id] <=> [$a->specificity(), $a->priority, $a->version, $a->id])
            ->values();
    }

    /**
     * Active workflows that would tie with $workflow (same scope + priority, overlapping amount range).
     * Call before activating a workflow; a non-empty result means resolution would be ambiguous.
     */
    public function ambiguousWith(ApprovalWorkflow $workflow): Collection
    {
        return ApprovalWorkflow::query()
            ->where('id', '!=', $workflow->id ?? 0)
            ->where('document_type', $workflow->document_type)
            ->where('state', ApprovalWorkflow::STATE_ACTIVE)
            ->where('status', 1)
            ->where('company_id', $workflow->company_id)
            ->where('unit_id', $workflow->unit_id)
            ->where('department_id', $workflow->department_id)
            ->where('master_department_id', $workflow->master_department_id)
            ->where('priority', $workflow->priority)
            ->get()
            ->filter(fn (ApprovalWorkflow $other) => ConditionMatcher::overlaps($workflow->conditions, $other->conditions));
    }
}
