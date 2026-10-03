<?php

namespace Bizzsol\ApprovalMatrix\Services;

use App\Models\User;
use Bizzsol\ApprovalMatrix\Models\ApprovalWorkflow;

/** "Who would approve this?" — dry run, writes nothing. */
class ApprovalSimulator
{
    public function __construct(private WorkflowResolver $workflows, private ApproverResolver $approvers)
    {
    }

    /**
     * @param  array  $input  document_type, company_id, unit_id, department_id, amount, attributes (array), requester_id
     * @return array{workflow:?\Bizzsol\ApprovalMatrix\Models\ApprovalWorkflow,others:\Illuminate\Support\Collection,context:array,steps:array,warnings:array}
     */
    public function simulate(array $input): array
    {
        $requesterId = (int) ($input['requester_id'] ?? 0);
        $ctx = $requesterId ? $this->approvers->requesterContext($requesterId) : [];
        foreach (['company_id', 'unit_id', 'department_id', 'master_department_id', 'amount'] as $k) {
            if (isset($input[$k]) && $input[$k] !== '') {
                $ctx[$k] = $input[$k];
            }
        }
        $ctx['attributes'] = $input['attributes'] ?? [];

        $org = app(OrgDirectory::class);
        if (empty($ctx['company_id']) && ! empty($ctx['unit_id'])) {
            $ctx['company_id'] = $org->companyOfUnit((int) $ctx['unit_id']);
        }
        if (empty($ctx['master_department_id']) && ! empty($ctx['department_id'])) {
            $ctx['master_department_id'] = $org->masterOfDepartment((int) $ctx['department_id'], ! empty($ctx['unit_id']) ? (int) $ctx['unit_id'] : null);
        }

        $candidates = $this->workflows->candidates($input['document_type'], $ctx);
        $warnings = [];
        $steps = [];

        // A specific workflow was asked for (e.g. a draft being tested): simulate exactly that one.
        $forced = ! empty($input['workflow_id']) ? ApprovalWorkflow::find($input['workflow_id']) : null;
        if ($forced) {
            $workflow = $forced;
            foreach (['company_id', 'unit_id', 'master_department_id'] as $k) {
                if (empty($ctx[$k]) && ! empty($forced->{$k})) {
                    $ctx[$k] = $forced->{$k}; // test it at the scope it is written for
                }
            }
            if ($forced->state !== 'active') {
                $warnings[] = "This workflow is {$forced->state}: real requests ignore it until you set it to Active. Showing it anyway.";
            } elseif (! $candidates->contains('id', $forced->id)) {
                $warnings[] = 'This workflow does not match the context below (scope, amount or conditions), so a real request would not use it.';
            } elseif ($candidates->first()->id !== $forced->id) {
                $warnings[] = "A more specific or higher-priority workflow (\"{$candidates->first()->name}\") would win for this context.";
            }
            $candidates = collect([$forced]);
        } else {
            $workflow = $candidates->first();
        }

        if (! $workflow) {
            $warnings[] = 'No active workflow matches this document — the legacy flow (if any) would be used.';
        } else {
            if (! $forced && $candidates->count() > 1 && $candidates[1]->specificity() === $workflow->specificity() && $candidates[1]->priority === $workflow->priority) {
                $warnings[] = 'Two workflows tie on scope and priority; the newest version was picked. Fix the overlap.';
            }
            $snapshotSteps = $workflow->steps()->with('customUsers')->get();
            if (! $requesterId && $snapshotSteps->contains('approver_type', 'reporting_head')) {
                $warnings[] = 'Pick a requester to resolve "Reporting head" steps.';
            }

            foreach ($snapshotSteps as $s) {
                $snap = $s->toArray() + ['custom_users' => $s->customUsers->map(fn ($u) => $u->only(['unit_id', 'department_id', 'user_id']))->all()];
                $row = ['level' => $s->level, 'name' => $s->name, 'type' => $s->approver_type, 'ref' => $s->approver_ref, 'mode' => $s->mode,
                    'mandatory' => (bool) $s->is_mandatory, 'can_finish' => (bool) $s->can_finish, 'status' => 'applies', 'approvers' => [], 'approver_ids' => []];

                if ($s->skip_condition && ConditionMatcher::matches($s->skip_condition, $ctx)) {
                    $row['status'] = 'skipped';
                } else {
                    $ids = $s->approver_type === 'reporting_head' && ! $requesterId ? [] : $this->approvers->resolve($snap, $requesterId, $ctx);
                    $row['approver_ids'] = array_map('intval', $ids);
                    $row['approvers'] = User::whereIn('id', $ids)->pluck('name')->all();
                    if (! $ids) {
                        $row['status'] = $s->approver_type === 'reporting_head' && ! $requesterId ? 'needs-requester' : ($s->is_mandatory ? 'no-approver' : 'skipped');
                        if ($row['status'] === 'no-approver') {
                            $warnings[] = "Step {$s->level} \"{$s->name}\" has no approver for this unit/department — submissions would be blocked.";
                        }
                    }
                }
                $steps[] = $row;
            }
        }

        return ['workflow' => $workflow, 'others' => $candidates->slice(1)->values(), 'context' => $ctx, 'steps' => $steps, 'warnings' => $warnings];
    }
}
