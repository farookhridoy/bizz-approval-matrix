<?php

namespace Bizzsol\ApprovalMatrix\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Bizzsol\ApprovalMatrix\Events\ApprovalFinished;
use Bizzsol\ApprovalMatrix\Events\ApprovalStepAssigned;
use Bizzsol\ApprovalMatrix\Exceptions\ApprovalException;
use Bizzsol\ApprovalMatrix\Exceptions\NoApproverException;
use Bizzsol\ApprovalMatrix\Models\ApprovalAction;
use Bizzsol\ApprovalMatrix\Models\ApprovalRequest;
use Bizzsol\ApprovalMatrix\Models\ApprovalWorkflow;

class ApprovalEngine
{
    public function __construct(
        private WorkflowResolver $workflows,
        private ApproverResolver $approvers,
    ) {
    }

    /** Context for a document: requester's org by default, overridable by $override. */
    public function context(Model $approvable, int $requesterId, array $override = []): array
    {
        $ctx = $this->approvers->requesterContext($requesterId);

        if (method_exists($approvable, 'approvalAmount')) {
            $ctx['amount'] = $approvable->approvalAmount();
        }
        if (method_exists($approvable, 'approvalAttributes')) {
            $ctx['attributes'] = $approvable->approvalAttributes();
        }

        $ctx = array_replace($ctx, array_filter($override, fn ($v) => $v !== null));

        // Callers (e.g. erp-pms) may only know the unit's own department; derive its master department.
        if (empty($ctx['master_department_id']) && ! empty($ctx['department_id'])) {
            $ctx['master_department_id'] = app(OrgDirectory::class)->masterOfDepartment((int) $ctx['department_id'], ! empty($ctx['unit_id']) ? (int) $ctx['unit_id'] : null);
        }

        return $ctx;
    }

    public function resolveWorkflow(string $documentType, array $ctx): ?ApprovalWorkflow
    {
        return $this->workflows->resolve($documentType, $ctx);
    }

    /**
     * Submit a document. Throws ApprovalException when no workflow matches (callers decide whether
     * to fall back to legacy behaviour) and NoApproverException when a mandatory step has nobody.
     */
    public function submit(Model $approvable, int $requesterId, string $documentType, array $override = []): ApprovalRequest
    {
        return DB::transaction(function () use ($approvable, $requesterId, $documentType, $override) {
            $ctx = $this->context($approvable, $requesterId, $override);

            $open = ApprovalRequest::where('approvable_type', $approvable->getMorphClass())
                ->where('approvable_id', $approvable->getKey())
                ->where('status', ApprovalRequest::PENDING)->lockForUpdate()->exists();
            if ($open) {
                throw new ApprovalException('This document already has an approval in progress.');
            }

            $workflow = $this->workflows->resolve($documentType, $ctx);
            if (! $workflow) {
                throw new ApprovalException("No active approval workflow matches [{$documentType}] for this company/unit/department.");
            }

            $steps = $workflow->steps()->with('customUsers')->get();
            if ($steps->isEmpty()) {
                throw new ApprovalException("Workflow [{$workflow->name}] has no steps.");
            }

            $request = ApprovalRequest::create([
                'approvable_type' => $approvable->getMorphClass(),
                'approvable_id' => $approvable->getKey(),
                'document_type' => $documentType,
                'workflow_id' => $workflow->id,
                'workflow_version' => $workflow->version,
                'revision' => ApprovalRequest::where('approvable_type', $approvable->getMorphClass())
                    ->where('approvable_id', $approvable->getKey())->count(),
                'steps_snapshot' => $steps->map(fn ($s) => [
                    'id' => $s->id,
                    'level' => (int) $s->level,
                    'name' => $s->name,
                    'stage_key' => $s->stage_key,
                    'step_kind' => $s->step_kind,
                    'approver_type' => $s->approver_type,
                    'approver_ref' => $s->approver_ref,
                    'user_id' => $s->user_id,
                    'mode' => $s->mode,
                    'min_approvals' => $s->min_approvals,
                    'is_mandatory' => (bool) $s->is_mandatory,
                    'skip_condition' => $s->skip_condition,
                    'on_reject' => $s->on_reject,
                    'custom_users' => $s->customUsers->map(fn ($u) => $u->only(['unit_id', 'department_id', 'master_department_id', 'user_id']))->all(),
                ])->all(),
                'status' => ApprovalRequest::PENDING,
                'requested_by' => $requesterId,
                'company_id' => $ctx['company_id'] ?? null,
                'unit_id' => $ctx['unit_id'] ?? null,
                'department_id' => $ctx['department_id'] ?? null,
                'master_department_id' => $ctx['master_department_id'] ?? null,
                'amount' => $ctx['amount'] ?? null,
            ]);

            $this->activateNext($request, null, $ctx);

            return $request->refresh();
        });
    }

    public function approve(ApprovalRequest $request, int $userId, ?string $comments = null): ApprovalRequest
    {
        return DB::transaction(function () use ($request, $userId, $comments) {
            $request = $this->lock($request);
            $action = $this->pendingActionFor($request, $userId);

            $action->update(['action' => ApprovalAction::APPROVED, 'acted_by' => $userId, 'comments' => $comments, 'acted_at' => now()]);

            if ($this->stepSatisfied($request, $action->level)) {
                $this->closeLevel($request, $action->level);
                $this->activateNext($request, $action->level, $this->ctxOf($request));
            }

            return $request->refresh();
        });
    }

    public function reject(ApprovalRequest $request, int $userId, ?string $comments = null): ApprovalRequest
    {
        return DB::transaction(function () use ($request, $userId, $comments) {
            $request = $this->lock($request);
            $action = $this->pendingActionFor($request, $userId);

            $action->update(['action' => ApprovalAction::REJECTED, 'acted_by' => $userId, 'comments' => $comments, 'acted_at' => now()]);
            $this->closeLevel($request, $action->level);

            $step = $request->stepAt($action->level);
            $this->finish($request, ($step['on_reject'] ?? 'terminate') === 'return_to_requester'
                ? ApprovalRequest::RETURNED
                : ApprovalRequest::REJECTED);

            return $request->refresh();
        });
    }

    /** Requester withdraws a pending request. */
    public function recall(ApprovalRequest $request, int $userId, ?string $comments = null): ApprovalRequest
    {
        return DB::transaction(function () use ($request, $userId, $comments) {
            $request = $this->lock($request);
            if ($request->requested_by !== $userId) {
                throw new ApprovalException('Only the requester can recall this request.');
            }

            $request->actions()->where('action', ApprovalAction::PENDING)->update([
                'action' => ApprovalAction::RECALLED, 'acted_by' => $userId, 'comments' => $comments, 'acted_at' => now(),
            ]);
            $this->finish($request, ApprovalRequest::RECALLED);

            return $request->refresh();
        });
    }

    /** Requests currently waiting on $userId. */
    public function inbox(int $userId): Builder
    {
        return ApprovalRequest::query()
            ->where('status', ApprovalRequest::PENDING)
            ->whereHas('actions', fn ($q) => $q->where('assigned_to', $userId)->where('action', ApprovalAction::PENDING));
    }

    // ---------------------------------------------------------------- internals

    private function lock(ApprovalRequest $request): ApprovalRequest
    {
        $fresh = ApprovalRequest::whereKey($request->getKey())->lockForUpdate()->firstOrFail();
        if ($fresh->status !== ApprovalRequest::PENDING) {
            throw new ApprovalException("Request is already {$fresh->status}.");
        }

        return $fresh;
    }

    private function pendingActionFor(ApprovalRequest $request, int $userId): ApprovalAction
    {
        $action = $request->actions()
            ->where('level', $request->current_level)->where('assigned_to', $userId)
            ->where('action', ApprovalAction::PENDING)->first();

        if (! $action) {
            throw new ApprovalException('You are not an approver for the current step.');
        }

        return $action;
    }

    private function ctxOf(ApprovalRequest $request): array
    {
        return [
            'company_id' => $request->company_id, 'unit_id' => $request->unit_id,
            'department_id' => $request->department_id,
            'master_department_id' => $request->master_department_id, 'amount' => $request->amount,
        ];
    }

    private function stepSatisfied(ApprovalRequest $request, int $level): bool
    {
        $step = $request->stepAt($level);
        $rows = $request->actions()->where('level', $level);
        $approved = (clone $rows)->where('action', ApprovalAction::APPROVED)->count();
        $pending = (clone $rows)->where('action', ApprovalAction::PENDING)->count();

        return match ($step['mode'] ?? 'any') {
            'all' => $pending === 0,
            'n_of_m' => $approved >= max(1, (int) ($step['min_approvals'] ?? 1)),
            default => $approved >= 1,
        };
    }

    /** Remaining pending rows on a level are no longer needed. */
    private function closeLevel(ApprovalRequest $request, int $level): void
    {
        $request->actions()->where('level', $level)->where('action', ApprovalAction::PENDING)
            ->update(['action' => ApprovalAction::SUPERSEDED, 'acted_at' => now()]);
    }

    /** Move to the next step that applies; finish as approved when none are left. */
    private function activateNext(ApprovalRequest $request, ?int $afterLevel, array $ctx): void
    {
        $level = $afterLevel === null
            ? collect($request->steps_snapshot)->pluck('level')->map(fn ($l) => (int) $l)->min()
            : $request->nextLevelAfter($afterLevel);

        while ($level !== null) {
            $step = $request->stepAt($level);

            if ($step['skip_condition'] && ConditionMatcher::matches($step['skip_condition'], $ctx)) {
                $this->recordSkip($request, $step, 'skip condition met');
                $level = $request->nextLevelAfter($level);

                continue;
            }

            $users = $this->approvers->resolve($step, $request->requested_by, $ctx);

            if (! $users) {
                if ($step['is_mandatory']) {
                    throw new NoApproverException(
                        'No approver could be found for step "'.($step['name'] ?: 'Level '.$level)."\" ({$step['approver_type']}). "
                        .'Configure a reporting head / custom user for this unit & department.'
                    );
                }
                $this->recordSkip($request, $step, 'no approver (optional step)');
                $level = $request->nextLevelAfter($level);

                continue;
            }

            foreach ($users as $uid) {
                ApprovalAction::create([
                    'request_id' => $request->id, 'level' => $level, 'step_id' => $step['id'],
                    'assigned_to' => $uid, 'action' => ApprovalAction::PENDING,
                ]);
            }
            $request->update(['current_level' => $level]);
            event(new ApprovalStepAssigned($request, $level, $users));

            return;
        }

        $this->finish($request, ApprovalRequest::APPROVED);
    }

    private function recordSkip(ApprovalRequest $request, array $step, string $why): void
    {
        ApprovalAction::create([
            'request_id' => $request->id, 'level' => $step['level'], 'step_id' => $step['id'],
            'action' => ApprovalAction::SKIPPED, 'comments' => $why, 'acted_at' => now(),
        ]);
    }

    private function finish(ApprovalRequest $request, string $status): void
    {
        $request->update(['status' => $status, 'completed_at' => now()]);
        event(new ApprovalFinished($request->refresh()));
    }
}
