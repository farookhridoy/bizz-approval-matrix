<?php

namespace Bizzsol\ApprovalMatrix\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Bizzsol\ApprovalMatrix\Models\ApprovalWorkflow;
use Bizzsol\ApprovalMatrix\Services\OrgDirectory;
use Bizzsol\ApprovalMatrix\Services\WorkflowService;
use Yajra\DataTables\DataTables;

class WorkflowController extends Controller
{
    public function __construct(private WorkflowService $service, private OrgDirectory $org)
    {
    }

    public function headerColumns(): array
    {
        return [
            ['SL', 'SL', 'text-center'],
            ['name', 'Name', 'text-left'],
            ['module', 'Module', 'text-center'],
            ['document', 'Document', 'text-left'],
            ['scope', 'Scope', 'text-left'],
            ['conditions_text', 'Conditions', 'text-left'],
            ['steps_html', 'Steps', 'text-left'],
            ['version', 'Ver.', 'text-center'],
            ['state_html', 'State', 'text-center'],
            ['actions', 'Actions', 'text-center'],
        ];
    }

    public function index(Request $request)
    {
        if ($request->ajax()) {
            ['units' => $units, 'departments' => $depts, 'companies' => $companies, 'masters' => $masters] = $this->org->nameMaps();
            $docs = config('approvalmatrix.document_types');
            $query = ApprovalWorkflow::with('steps')
                ->when($request->filled('module'), fn ($q) => $q->where('module', $request->module))
                ->when($request->filled('state'), fn ($q) => $q->where('state', $request->state))
                ->orderBy('document_type')->orderByDesc('version');

            return DataTables::of($query)
                ->addIndexColumn()
                ->addColumn('document', fn ($w) => e($docs[$w->document_type]['label'] ?? $w->document_type))
                ->editColumn('module', fn ($w) => ucfirst($w->module))
                ->addColumn('scope', fn ($w) => e(implode(' › ', array_filter([
                    $companies[$w->company_id] ?? 'All companies',
                    $w->unit_id ? ($units[$w->unit_id] ?? "Unit #{$w->unit_id}") : 'All units',
                    $w->master_department_id
                        ? ($masters[$w->master_department_id] ?? "Dept #{$w->master_department_id}")
                        : ($w->department_id ? ($depts[$w->department_id] ?? "Dept #{$w->department_id}") : 'All departments'),
                ]))))
                ->addColumn('conditions_text', function ($w) {
                    $c = $w->conditions ?? [];
                    $parts = [];
                    if (isset($c['amount_min']) || isset($c['amount_max'])) {
                        $parts[] = 'Amount '.number_format($c['amount_min'] ?? 0).' – '.(isset($c['amount_max']) ? number_format($c['amount_max']) : '∞');
                    }
                    foreach (($c['attributes'] ?? []) as $k => $v) {
                        $parts[] = e($k).': '.e(implode('/', $v));
                    }

                    return ($parts ? implode('<br>', $parts) : '—').($w->priority ? '<br><small class="text-muted">priority '.$w->priority.'</small>' : '');
                })
                ->addColumn('steps_html', function ($w) {
                    return $w->steps->map(function ($s) {
                        $label = match ($s->approver_type) {
                            'reporting_head' => 'Reporting head'.((int) $s->approver_ref > 1 ? " +{$s->approver_ref}" : ''),
                            'custom_user' => 'Custom user',
                            'specific_user' => 'User #'.$s->user_id,
                            'role' => 'Role: '.$s->approver_ref,
                            'permission' => 'Perm: '.$s->approver_ref,
                            default => $s->approver_type,
                        };

                        return '<span class="badge badge-info mr-1" title="'.e($s->name).'">L'.$s->level.' '.e($label).'</span>';
                    })->implode('');
                })
                ->addColumn('state_html', fn ($w) => '<span class="badge badge-'.['active' => 'success', 'draft' => 'warning', 'archived' => 'secondary'][$w->state].'">'.ucfirst($w->state).'</span>')
                ->addColumn('actions', function ($w) {
                    $out = '';
                    if (auth()->user()->can('approval-matrix-edit')) {
                        $out .= '<a href="'.route('approval-matrix.workflows.edit', $w->id).'" class="btn btn-xs btn-info" title="Edit"><i class="la la-edit"></i></a> ';
                        if ($w->state !== 'active') {
                            $out .= '<a class="btn btn-xs btn-success" onclick="amPost(\''.route('approval-matrix.workflows.activate', $w->id).'\')" title="Activate"><i class="la la-check"></i></a> ';
                        }
                        if ($w->state === 'active') {
                            $out .= '<a class="btn btn-xs btn-warning" onclick="amPost(\''.route('approval-matrix.workflows.archive', $w->id).'\')" title="Archive"><i class="la la-archive"></i></a> ';
                        }
                    }
                    if (auth()->user()->can('approval-matrix-delete')) {
                        $out .= '<a class="btn btn-xs btn-danger" onclick="deleteFromCRUD($(this))" data-src="'.route('approval-matrix.workflows.destroy', $w->id).'" title="Delete"><i class="la la-trash"></i></a>';
                    }

                    return $out;
                })
                ->rawColumns(['conditions_text', 'steps_html', 'state_html', 'actions'])
                ->make(true);
        }

        return view('approvalmatrix::workflows.index', [
            'title' => 'Approval Matrix',
            'headerColumns' => $this->headerColumns(),
            'modules' => config('approvalmatrix.modules'),
        ]);
    }

    public function create()
    {
        $this->authorizeAbility('approval-matrix-create');

        return view('approvalmatrix::workflows.form', $this->formData(null) + ['title' => 'Create Approval Workflow']);
    }

    public function store(Request $request)
    {
        $this->authorizeAbility('approval-matrix-create');
        $data = $this->validated($request);
        [$workflow] = $this->service->save(null, $data, auth()->id());

        return redirect()->route('approval-matrix.workflows.index')
            ->with(['message' => "Workflow “{$workflow->name}” saved as {$workflow->state}.", 'alert-type' => 'success']);
    }

    public function edit($id)
    {
        $this->authorizeAbility('approval-matrix-edit');
        $workflow = ApprovalWorkflow::with(['steps.customUsers'])->findOrFail($id);

        return view('approvalmatrix::workflows.form', $this->formData($workflow) + ['title' => 'Edit Approval Workflow']);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeAbility('approval-matrix-edit');
        $workflow = ApprovalWorkflow::findOrFail($id);
        [$saved, $newVersion] = $this->service->save($workflow, $this->validated($request), auth()->id());

        $msg = $newVersion
            ? "This workflow already has approval requests, so version {$saved->version} was created and the old version was left untouched."
            : 'Workflow updated.';

        return redirect()->route('approval-matrix.workflows.index')->with(['message' => $msg, 'alert-type' => 'success']);
    }

    public function destroy($id)
    {
        $this->authorizeAbility('approval-matrix-delete');
        $deleted = $this->service->remove(ApprovalWorkflow::findOrFail($id));

        return response()->json([
            'success' => true,
            'message' => $deleted ? 'Workflow deleted.' : 'Workflow has approval history, so it was archived instead of deleted.',
        ]);
    }

    public function activate($id)
    {
        $this->authorizeAbility('approval-matrix-edit');
        $this->service->activate(ApprovalWorkflow::findOrFail($id));

        return response()->json(['success' => true, 'message' => 'Workflow activated.']);
    }

    public function archive($id)
    {
        $this->authorizeAbility('approval-matrix-edit');
        $this->service->archive(ApprovalWorkflow::findOrFail($id));

        return response()->json(['success' => true, 'message' => 'Workflow archived.']);
    }

    private function authorizeAbility(string $ability): void
    {
        abort_unless(auth()->user()->can($ability), 403);
    }

    private function validated(Request $request): array
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'document_type' => 'required|string',
            'company_id' => 'nullable|integer',
            'unit_id' => 'nullable|integer',
            'department_id' => 'nullable|integer',
            'master_department_id' => 'nullable|integer',
            'priority' => 'nullable|integer|min:0',
            'amount_min' => 'nullable|numeric|min:0',
            'amount_max' => 'nullable|numeric|min:0',
            'effective_from' => 'nullable|date',
            'effective_to' => 'nullable|date|after_or_equal:effective_from',
            'state' => 'required|in:draft,active,archived',
            'steps' => 'required|array|min:1',
        ]);

        return $request->all();
    }

    private function formData(?ApprovalWorkflow $workflow): array
    {
        return [
            'workflow' => $workflow,
            'documentTypes' => config('approvalmatrix.document_types'),
            'companies' => $this->org->companies(),
            'unitRows' => $this->org->unitRows($workflow?->company_id),
            'allUnitRows' => $this->org->unitRows(),
            'masters' => $this->org->masterDepartments($workflow?->unit_id, $workflow?->company_id),
            'allMasters' => $this->org->masterDepartments(),
            'users' => User::orderBy('name')->get(['id', 'name']),
            'roles' => \Spatie\Permission\Models\Role::orderBy('name')->pluck('name'),
            'permissions' => \Spatie\Permission\Models\Permission::orderBy('name')->pluck('name'),
            'approverTypes' => WorkflowService::APPROVER_TYPES,
            'stepsData' => $this->stepsForForm($workflow),
        ];
    }

    private function stepsForForm(?ApprovalWorkflow $workflow): array
    {
        $old = old('steps');
        if (is_array($old)) {
            return array_values($old);
        }
        if (! $workflow) {
            return [[]];
        }

        return $workflow->steps->map(fn ($s) => [
            'name' => $s->name, 'stage_key' => $s->stage_key, 'step_kind' => $s->step_kind,
            'approver_type' => $s->approver_type, 'approver_ref' => $s->approver_ref, 'user_id' => $s->user_id,
            'mode' => $s->mode, 'min_approvals' => $s->min_approvals, 'is_mandatory' => $s->is_mandatory ? 1 : 0, 'can_finish' => $s->can_finish ? 1 : 0,
            'skip_amount_max' => $s->skip_condition['amount_max'] ?? null, 'sla_hours' => $s->sla_hours, 'on_reject' => $s->on_reject,
            'custom' => $s->customUsers->map(fn ($u) => $u->only(['unit_id', 'department_id', 'master_department_id', 'user_id']))->all(),
        ])->all();
    }
}
