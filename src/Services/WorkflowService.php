<?php

namespace Bizzsol\ApprovalMatrix\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Bizzsol\ApprovalMatrix\Models\ApprovalRequest;
use Bizzsol\ApprovalMatrix\Models\ApprovalStep;
use Bizzsol\ApprovalMatrix\Models\ApprovalStepUser;
use Bizzsol\ApprovalMatrix\Models\ApprovalWorkflow;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Create / edit / activate workflows. Editing a workflow that already has approval requests never
 * touches it: a new version is created instead, so in-flight and historical requests stay intact.
 */
class WorkflowService
{
    public const APPROVER_TYPES = [
        ApprovalStep::TYPE_REPORTING_HEAD => 'Reporting head (employee)',
        ApprovalStep::TYPE_CUSTOM_USER => 'Custom user (per unit / department)',
        ApprovalStep::TYPE_SPECIFIC_USER => 'Specific user',
        ApprovalStep::TYPE_ROLE => 'Anyone with role',
        ApprovalStep::TYPE_PERMISSION => 'Anyone with permission',
    ];

    public function __construct(private WorkflowResolver $resolver, private OrgDirectory $org)
    {
    }

    /**
     * @param  array  $data  name, document_type, company_id, unit_id, department_id, priority, state,
     *                       amount_min, amount_max, attributes (text "key=v1,v2" per line), effective_from/to, steps[]
     * @return array{0:ApprovalWorkflow,1:bool}  workflow and whether a new version was created
     */
    public function save(?ApprovalWorkflow $workflow, array $data, ?int $userId = null): array
    {
        $documentTypes = config('approvalmatrix.document_types', []);
        $docType = $data['document_type'] ?? '';
        if (! isset($documentTypes[$docType])) {
            throw ValidationException::withMessages(['document_type' => 'Unknown document type.']);
        }
        [$companyId, $unitId, $masterId] = $this->validatedScope($data);
        $steps = $this->validatedSteps($data['steps'] ?? []);
        $state = $data['state'] ?? ApprovalWorkflow::STATE_DRAFT;
        if (! in_array($state, [ApprovalWorkflow::STATE_DRAFT, ApprovalWorkflow::STATE_ACTIVE, ApprovalWorkflow::STATE_ARCHIVED], true)) {
            throw ValidationException::withMessages(['state' => 'Invalid state.']);
        }

        $attrs = [
            'name' => $data['name'] ?? '',
            'module' => $documentTypes[$docType]['module'],
            'document_type' => $docType,
            'company_id' => $companyId,
            'unit_id' => $unitId,
            'department_id' => ($data['department_id'] ?? null) ?: null,
            'master_department_id' => $masterId,
            'priority' => (int) ($data['priority'] ?? 0),
            'conditions' => $this->buildConditions($data),
            'effective_from' => ($data['effective_from'] ?? null) ?: null,
            'effective_to' => ($data['effective_to'] ?? null) ?: null,
            'state' => $state,
            'status' => 1,
            'updated_by' => $userId,
        ];

        return DB::transaction(function () use ($workflow, $attrs, $steps, $userId, $state) {
            $newVersion = false;

            if ($workflow === null) {
                $workflow = ApprovalWorkflow::create($attrs + ['version' => 1, 'created_by' => $userId]);
            } elseif (ApprovalRequest::where('workflow_id', $workflow->id)->exists()) {
                $newVersion = true;
                $old = $workflow;
                $workflow = ApprovalWorkflow::create($attrs + [
                    'version' => ApprovalWorkflow::where('parent_id', $old->parent_id ?? $old->id)->orWhere('id', $old->parent_id ?? $old->id)->max('version') + 1,
                    'parent_id' => $old->parent_id ?? $old->id,
                    'created_by' => $userId,
                ]);
                if ($state === ApprovalWorkflow::STATE_ACTIVE) {
                    $old->update(['state' => ApprovalWorkflow::STATE_ARCHIVED]); // new version replaces it
                }
            } else {
                $workflow->update($attrs);
                $workflow->steps()->delete(); // no request references these steps
            }

            $this->writeSteps($workflow, $steps);

            if ($state === ApprovalWorkflow::STATE_ACTIVE) {
                $this->assertNotAmbiguous($workflow);
            }

            return [$workflow->refresh(), $newVersion];
        });
    }

    public function activate(ApprovalWorkflow $workflow): void
    {
        DB::transaction(function () use ($workflow) {
            $workflow->update(['state' => ApprovalWorkflow::STATE_ACTIVE]);
            $this->assertNotAmbiguous($workflow);
        });
    }

    public function archive(ApprovalWorkflow $workflow): void
    {
        $workflow->update(['state' => ApprovalWorkflow::STATE_ARCHIVED]);
    }

    /** Hard-delete only when never used; otherwise archive. Returns true when deleted. */
    public function remove(ApprovalWorkflow $workflow): bool
    {
        if (ApprovalRequest::where('workflow_id', $workflow->id)->exists()) {
            $this->archive($workflow);

            return false;
        }
        $workflow->delete();

        return true;
    }

    private function assertNotAmbiguous(ApprovalWorkflow $workflow): void
    {
        $clash = $this->resolver->ambiguousWith($workflow);
        if ($clash->isNotEmpty()) {
            throw ValidationException::withMessages([
                'state' => 'Another active workflow with the same scope, priority and overlapping conditions exists: '
                    .$clash->pluck('name')->implode(', ').'. Change the priority/conditions or archive it first.',
            ]);
        }
    }

    /**
     * Company > Unit > Master department must be consistent. A unit implies its company; a master
     * department must actually be offered in the chosen unit (or in some unit of the chosen company).
     *
     * @return array{0:?int,1:?int,2:?int}
     */
    private function validatedScope(array $data): array
    {
        $company = ($data['company_id'] ?? null) ? (int) $data['company_id'] : null;
        $unit = ($data['unit_id'] ?? null) ? (int) $data['unit_id'] : null;
        $master = ($data['master_department_id'] ?? null) ? (int) $data['master_department_id'] : null;

        if ($unit) {
            $unitCompany = $this->org->companyOfUnit($unit);
            if (! $unitCompany) {
                throw ValidationException::withMessages(['unit_id' => 'Unknown unit.']);
            }
            if ($company && $company !== $unitCompany) {
                throw ValidationException::withMessages(['unit_id' => 'The selected unit does not belong to the selected company.']);
            }
            $company = $unitCompany;
        }
        if ($master) {
            $offered = $unit
                ? $this->org->unitHasMasterDepartment($unit, $master)
                : $this->org->masterDepartments(null, $company)->has($master);
            if (! $offered) {
                throw ValidationException::withMessages(['master_department_id' => 'This department is not available in the selected '.($unit ? 'unit' : 'company').'.']);
            }
        }

        return [$company, $unit, $master];
    }

    private function buildConditions(array $data): ?array
    {
        $c = [];
        foreach (['amount_min', 'amount_max'] as $k) {
            if (isset($data[$k]) && $data[$k] !== '') {
                $c[$k] = (float) $data[$k];
            }
        }
        if (isset($c['amount_min'], $c['amount_max']) && $c['amount_min'] > $c['amount_max']) {
            throw ValidationException::withMessages(['amount_min' => 'Minimum amount cannot exceed maximum amount.']);
        }
        foreach (preg_split('/\R/', (string) ($data['attributes'] ?? '')) as $line) {
            if (! str_contains($line, '=')) {
                continue;
            }
            [$key, $vals] = array_map('trim', explode('=', $line, 2));
            $vals = array_values(array_filter(array_map('trim', explode(',', $vals)), 'strlen'));
            if ($key !== '' && $vals) {
                $c['attributes'][$key] = $vals;
            }
        }

        return $c ?: null;
    }

    private function validatedSteps(array $rows): array
    {
        $rows = array_values(array_filter($rows, fn ($r) => ! empty($r['approver_type'])));
        if (! $rows) {
            throw ValidationException::withMessages(['steps' => 'Add at least one step.']);
        }

        foreach ($rows as $i => $r) {
            $n = $i + 1;
            $fail = fn ($msg) => throw ValidationException::withMessages(['steps' => "Step {$n}: {$msg}"]);
            $type = $r['approver_type'];

            if (! isset(self::APPROVER_TYPES[$type])) {
                $fail('unknown approver type.');
            }
            if ($type === ApprovalStep::TYPE_SPECIFIC_USER && empty($r['user_id'])) {
                $fail('choose a user.');
            }
            if ($type === ApprovalStep::TYPE_ROLE && ! Role::where('name', $r['approver_ref'] ?? '')->exists()) {
                $fail('choose an existing role.');
            }
            if ($type === ApprovalStep::TYPE_PERMISSION && ! Permission::where('name', $r['approver_ref'] ?? '')->exists()) {
                $fail('choose an existing permission.');
            }
            if ($type === ApprovalStep::TYPE_REPORTING_HEAD) {
                $hops = (int) ($r['approver_ref'] ?? 1);
                if ($hops < 1 || $hops > 5) {
                    $fail('reporting head level must be between 1 and 5.');
                }
            }
            if ($type === ApprovalStep::TYPE_CUSTOM_USER) {
                $users = array_filter($r['custom'] ?? [], fn ($c) => ! empty($c['user_id']));
                if (! $users) {
                    $fail('add at least one custom user.');
                }
                $seen = [];
                foreach ($users as $c) {
                    $cu = ($c['unit_id'] ?? null) ? (int) $c['unit_id'] : null;
                    $cm = ($c['master_department_id'] ?? null) ? (int) $c['master_department_id'] : null;
                    if ($cm && $cu && ! $this->org->unitHasMasterDepartment($cu, $cm)) {
                        $fail('a custom approver row pairs a department that is not available in its unit.');
                    }
                    $key = ($c['unit_id'] ?? '').'|'.($c['master_department_id'] ?? '').'|'.($c['department_id'] ?? '').'|'.$c['user_id'];
                    if (isset($seen[$key])) {
                        $fail('the same user is listed twice for one unit/department.');
                    }
                    $seen[$key] = true;
                }
            }
            if (($r['mode'] ?? 'any') === 'n_of_m' && (int) ($r['min_approvals'] ?? 0) < 1) {
                $fail('set the number of approvals required.');
            }
        }

        return $rows;
    }

    private function writeSteps(ApprovalWorkflow $workflow, array $rows): void
    {
        foreach ($rows as $i => $r) {
            $type = $r['approver_type'];
            $skip = isset($r['skip_amount_max']) && $r['skip_amount_max'] !== '' ? ['amount_max' => (float) $r['skip_amount_max']] : null;

            $step = ApprovalStep::create([
                'workflow_id' => $workflow->id,
                'level' => $i + 1,
                'name' => ($r['name'] ?? '') ?: 'Step '.($i + 1),
                'stage_key' => ($r['stage_key'] ?? '') ?: null,
                'step_kind' => $r['step_kind'] ?? 'approval',
                'approver_type' => $type,
                'approver_ref' => in_array($type, [ApprovalStep::TYPE_REPORTING_HEAD, ApprovalStep::TYPE_ROLE, ApprovalStep::TYPE_PERMISSION], true)
                    ? ($r['approver_ref'] ?? null) : null,
                'user_id' => $type === ApprovalStep::TYPE_SPECIFIC_USER ? $r['user_id'] : null,
                'mode' => $r['mode'] ?? 'any',
                'min_approvals' => ($r['mode'] ?? 'any') === 'n_of_m' ? (int) $r['min_approvals'] : null,
                'is_mandatory' => ! empty($r['is_mandatory']) ? 1 : 0,
                'can_finish' => ! empty($r['can_finish']) ? 1 : 0,
                'skip_condition' => $skip,
                'sla_hours' => ! empty($r['sla_hours']) ? (int) $r['sla_hours'] : null,
                'on_reject' => $r['on_reject'] ?? 'terminate',
            ]);

            if ($type === ApprovalStep::TYPE_CUSTOM_USER) {
                foreach ($r['custom'] as $c) {
                    if (empty($c['user_id'])) {
                        continue;
                    }
                    ApprovalStepUser::create([
                        'step_id' => $step->id,
                        'unit_id' => ($c['unit_id'] ?? '') ?: null,
                        'department_id' => ($c['department_id'] ?? '') ?: null,
                        'master_department_id' => ($c['master_department_id'] ?? '') ?: null,
                        'user_id' => $c['user_id'],
                    ]);
                }
            }
        }
    }
}
