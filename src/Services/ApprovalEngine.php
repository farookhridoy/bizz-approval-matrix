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
use Bizzsol\ApprovalMatrix\Models\ApprovalDelegation;
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
     *
     * @param  bool  $resume  re-submitting after a rejection: when the previous request ran on the same workflow
     *                        version, the levels below the one that rejected are carried over as already approved
     *                        and the new request starts at the rejecting level (e.g. a revised CS goes straight
     *                        back to the approver who rejected it). Otherwise the chain starts from the top.
     */
    public function submit(Model $approvable, int $requesterId, string $documentType, array $override = [], bool $resume = false): ApprovalRequest
    {
        return DB::transaction(function () use ($approvable, $requesterId, $documentType, $override, $resume) {
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
                    'can_finish' => (bool) $s->can_finish,
                    'sla_hours' => $s->sla_hours,
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

            $afterLevel = $resume ? $this->carryOver($request, $approvable, $workflow) : null;
            $this->activateNext($request, $afterLevel, $ctx);

            return $request->refresh();
        });
    }

    /**
     * Resume support: copy the approvals of the levels below the rejecting level from the previous request.
     *
     * @return int|null the last carried level (the new request continues after it), null for a fresh start
     */
    private function carryOver(ApprovalRequest $request, Model $approvable, ApprovalWorkflow $workflow): ?int
    {
        $previous = ApprovalRequest::where('approvable_type', $approvable->getMorphClass())
            ->where('approvable_id', $approvable->getKey())
            ->where('id', '!=', $request->id)
            ->whereIn('status', [ApprovalRequest::REJECTED, ApprovalRequest::RETURNED])
            ->latest('id')->first();

        if (! $previous || (int) $previous->workflow_id !== (int) $workflow->id
            || (int) $previous->workflow_version !== (int) $workflow->version || ! $previous->current_level) {
            return null;
        }

        $carried = null;
        foreach (collect($request->steps_snapshot)->pluck('level')->map(fn ($l) => (int) $l)->sort() as $level) {
            if ($level >= (int) $previous->current_level) {
                break;
            }
            $approvals = $previous->actions()->where('level', $level)->where('action', ApprovalAction::APPROVED)->get();
            foreach ($approvals as $a) {
                ApprovalAction::create([
                    'request_id' => $request->id, 'level' => $level, 'step_id' => $a->step_id, 'assigned_to' => $a->assigned_to,
                    'acted_by' => $a->acted_by, 'action' => ApprovalAction::CARRIED, 'acted_at' => $a->acted_at,
                    'comments' => 'carried over from revision '.$previous->revision,
                ]);
            }
            $carried = $level;
        }

        return $carried;
    }

    /**
     * @param  bool  $finish  complete the whole request at this step (only for steps flagged `can_finish`,
     *                        and only once the step itself is satisfied); remaining steps are recorded as skipped.
     */
    public function approve(ApprovalRequest $request, int $userId, ?string $comments = null, bool $finish = false): ApprovalRequest
    {
        return DB::transaction(function () use ($request, $userId, $comments, $finish) {
            $request = $this->lock($request);
            $action = $this->pendingActionFor($request, $userId);

            if ($finish && empty($request->stepAt($action->level)['can_finish'])) {
                throw new ApprovalException('This step cannot complete the request on its own; forward it to the next step.');
            }

            $action->update(['action' => ApprovalAction::APPROVED, 'acted_by' => $userId, 'comments' => $comments, 'acted_at' => now()]);

            if ($this->stepSatisfied($request, $action->level)) {
                $this->closeLevel($request, $action->level);
                if ($finish) {
                    $this->skipRemaining($request, $action->level);
                    $this->finish($request, ApprovalRequest::APPROVED);
                } else {
                    $this->activateNext($request, $action->level, $this->ctxOf($request));
                }
            }

            return $request->refresh();
        });
    }

    /** The open (pending) request for a document, if any. */
    public function openRequestFor(Model $approvable): ?ApprovalRequest
    {
        return ApprovalRequest::where('approvable_type', $approvable->getMorphClass())
            ->where('approvable_id', $approvable->getKey())->where('status', ApprovalRequest::PENDING)->latest('id')->first();
    }

    /** Is $userId one of the people the open request is currently waiting on? */
    public function isAssigned(ApprovalRequest $request, int $userId): bool
    {
        return $request->status === ApprovalRequest::PENDING
            && $request->actions()->where('level', $request->current_level)->where('action', ApprovalAction::PENDING)
                ->whereIn('assigned_to', $this->assigneeIdsFor($userId, $request->document_type))->exists();
    }

    /**
     * The pending assignment $userId would act on right now: their own, else one they hold through a delegation.
     * Null when they have none (no exception). Lets callers keep legacy per-approver rows in step with the matrix.
     */
    public function assignmentFor(ApprovalRequest $request, int $userId): ?ApprovalAction
    {
        if ($request->status !== ApprovalRequest::PENDING) {
            return null;
        }
        $level = $request->actions()->where('level', $request->current_level)->where('action', ApprovalAction::PENDING);

        return (clone $level)->where('assigned_to', $userId)->first()
            ?? (clone $level)->whereIn('assigned_to', array_diff($this->assigneeIdsFor($userId, $request->document_type), [$userId]))->first();
    }

    /** Approvers the request is currently waiting on. @return int[] */
    public function currentApprovers(ApprovalRequest $request): array
    {
        return $request->actions()->where('level', $request->current_level)->where('action', ApprovalAction::PENDING)->pluck('assigned_to')->map(fn ($i) => (int) $i)->all();
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

    /**
     * Administrative withdrawal: the document itself was cancelled/voided, so its open approval is closed (status
     * recalled, pending assignments recalled). Unlike recall() it is not limited to the requester - callers authorise it.
     */
    public function cancel(ApprovalRequest $request, int $userId, ?string $comments = null): ApprovalRequest
    {
        return DB::transaction(function () use ($request, $userId, $comments) {
            $request = $this->lock($request);
            $request->actions()->where('action', ApprovalAction::PENDING)->update([
                'action' => ApprovalAction::RECALLED, 'acted_by' => $userId, 'comments' => $comments ?: 'document cancelled', 'acted_at' => now(),
            ]);
            $this->finish($request, ApprovalRequest::RECALLED);

            return $request->refresh();
        });
    }

    /** Requests currently waiting on $userId - or on someone who delegated to them. */
    public function inbox(int $userId): Builder
    {
        $delegations = $this->activeDelegationsFor($userId);

        return ApprovalRequest::query()
            ->where('status', ApprovalRequest::PENDING)
            ->where(function ($query) use ($userId, $delegations) {
                $query->whereHas('actions', fn ($q) => $q->where('assigned_to', $userId)->where('action', ApprovalAction::PENDING));
                foreach ($delegations as $d) {
                    $query->orWhere(function ($q) use ($d) {
                        if ($d->document_type) {
                            $q->where('document_type', $d->document_type);
                        }
                        $q->whereHas('actions', fn ($a) => $a->where('assigned_to', $d->delegator_id)->where('action', ApprovalAction::PENDING));
                    });
                }
            });
    }

    /** Active delegations in which $userId is the delegate. @return \Illuminate\Support\Collection<int,ApprovalDelegation> */
    public function activeDelegationsFor(int $userId)
    {
        return ApprovalDelegation::activeOn()->where('delegate_id', $userId)->where('delegator_id', '!=', $userId)->get();
    }

    /**
     * Everyone $userId currently decides for: themselves plus the people who delegated to them
     * (optionally only for one document type). Use it for "can this user see / act on X" checks.
     *
     * @return int[]
     */
    public function assigneeIdsFor(int $userId, ?string $documentType = null): array
    {
        $delegators = $this->activeDelegationsFor($userId)
            ->filter(fn ($d) => ! $d->document_type || ! $documentType || $d->document_type === $documentType)
            ->pluck('delegator_id')->map(fn ($i) => (int) $i)->all();

        return array_values(array_unique(array_merge([$userId], $delegators)));
    }

    /**
     * People to notify when $approverIds are asked to decide: the approvers and whoever covers for them right now.
     *
     * @param  int[]  $approverIds
     * @return int[]
     */
    public function withDelegates(array $approverIds, ?string $documentType = null): array
    {
        if (! $approverIds) {
            return [];
        }
        $delegates = ApprovalDelegation::activeOn()->whereIn('delegator_id', $approverIds)
            ->when($documentType, fn ($q) => $q->where(fn ($w) => $w->whereNull('document_type')->orWhere('document_type', $documentType)))
            ->pluck('delegate_id')->map(fn ($i) => (int) $i)->all();

        return array_values(array_unique(array_merge(array_map('intval', $approverIds), $delegates)));
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
        // the user's own assignment first, then one they hold through a delegation
        $own = $request->actions()->where('level', $request->current_level)->where('assigned_to', $userId)->where('action', ApprovalAction::PENDING)->first();
        if ($own) {
            return $own;
        }

        $delegated = $request->actions()->where('level', $request->current_level)->where('action', ApprovalAction::PENDING)
            ->whereIn('assigned_to', array_diff($this->assigneeIdsFor($userId, $request->document_type), [$userId]))->first();
        if ($delegated && $userId === (int) $request->requested_by) {
            throw new ApprovalException('You cannot approve your own request, even on someone\'s behalf.');
        }
        if (! $delegated) {
            throw new ApprovalException('You are not an approver for the current step.');
        }

        return $delegated;
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

    private function skipRemaining(ApprovalRequest $request, int $afterLevel): void
    {
        for ($l = $request->nextLevelAfter($afterLevel); $l !== null; $l = $request->nextLevelAfter($l)) {
            $this->recordSkip($request, $request->stepAt($l), 'request completed at an earlier step');
        }
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
