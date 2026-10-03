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
            ['workflow', 'Workflow', 'text-left'],
            ['applies_to', 'Applies to', 'text-left'],
            ['applies_when', 'Applies when', 'text-left'],
            ['approval_chain', 'Approval chain', 'text-left'],
            ['version', 'Ver.', 'text-center'],
            ['state', 'State', 'text-center'],
            ['actions', 'Actions', 'text-center'],
        ];
    }

    public function index(Request $request)
    {
        if ($request->ajax()) {
            return $this->table($request);
        }

        $counts = ApprovalWorkflow::selectRaw('state, count(*) c')->groupBy('state')->pluck('c', 'state');

        return view('approvalmatrix::workflows.index', [
            'title' => 'Approval Workflows',
            'headerColumns' => $this->headerColumns(),
            'modules' => config('approvalmatrix.modules'),
            'counts' => ['all' => $counts->sum(), 'active' => $counts['active'] ?? 0, 'draft' => $counts['draft'] ?? 0, 'archived' => $counts['archived'] ?? 0],
        ]);
    }

    private function table(Request $request)
    {
        ['units' => $units, 'departments' => $depts, 'companies' => $companies, 'masters' => $masters] = $this->org->nameMaps();
        $users = User::pluck('name', 'id');
        $docs = config('approvalmatrix.document_types');
        $query = ApprovalWorkflow::with('steps.customUsers')
            ->when($request->filled('module'), fn ($q) => $q->where('module', $request->module))
            ->when($request->filled('state'), fn ($q) => $q->where('state', $request->state))
            ->orderBy('document_type')->orderByDesc('version');

        $chip = fn (string $text, string $class = '', string $title = '') => '<span class="am-chip '.$class.'"'.($title ? ' title="'.e($title).'"' : '').'>'.e($text).'</span>';
        $short = function (array $names) use ($chip) {
            $names = array_values(array_unique($names));

            return implode(' ', array_map($chip, array_slice($names, 0, 2))).(count($names) > 2 ? ' '.$chip('+'.(count($names) - 2), 'muted', implode(', ', array_slice($names, 2))) : '');
        };

        return DataTables::of($query)
            ->addIndexColumn()
            ->addColumn('workflow', fn ($w) => '<div class="am-name">'.e($w->name).'<small>'.e($docs[$w->document_type]['label'] ?? $w->document_type).' · '.e(ucfirst($w->module)).'</small></div>')
            ->filterColumn('workflow', fn ($q, $kw) => $q->where(fn ($w) => $w->where('name', 'like', "%{$kw}%")->orWhere('document_type', 'like', "%{$kw}%")))
            ->orderColumn('workflow', 'name $1')
            ->addColumn('applies_to', function ($w) use ($units, $depts, $companies, $masters) {
                $part = fn ($label, $all) => '<span class="am-chip '.($label ? '' : 'muted').'">'.e($label ?: $all).'</span>';
                $dept = $w->master_department_id ? ($masters[$w->master_department_id] ?? "Dept #{$w->master_department_id}") : ($w->department_id ? ($depts[$w->department_id] ?? "Dept #{$w->department_id}") : null);

                return '<div class="am-scope">'.$part($companies[$w->company_id] ?? null, 'All companies').'<span class="sep">›</span>'
                    .$part($w->unit_id ? ($units[$w->unit_id] ?? "Unit #{$w->unit_id}") : null, 'All units').'<span class="sep">›</span>'.$part($dept, 'All departments').'</div>';
            })
            ->orderColumn('applies_to', 'company_id $1')
            ->addColumn('applies_when', function ($w) use ($chip) {
                $c = $w->conditions ?? [];
                $out = [];
                if (isset($c['amount_min']) || isset($c['amount_max'])) {
                    $out[] = $chip('Amount '.number_format($c['amount_min'] ?? 0).' – '.(isset($c['amount_max']) ? number_format($c['amount_max']) : '∞'), 'info');
                }
                foreach (($c['attributes'] ?? []) as $k => $v) {
                    $out[] = $chip($k.': '.implode(' / ', $v), 'info');
                }
                if ($w->effective_from || $w->effective_to) {
                    $out[] = $chip(($w->effective_from?->format('d M Y') ?? '…').' → '.($w->effective_to?->format('d M Y') ?? '…'), 'muted');
                }
                if ($w->priority) {
                    $out[] = $chip('priority '.$w->priority, 'muted', 'Higher wins when scope is equal');
                }

                return $out ? '<div class="am-chain">'.implode(' ', $out).'</div>' : '<span class="am-muted am-small">Always</span>';
            })
            ->orderColumn('applies_when', 'priority $1')
            ->addColumn('approval_chain', function ($w) use ($users, $chip, $short) {
                $items = $w->steps->map(function ($s) use ($users, $chip, $short) {
                    $who = match ($s->approver_type) {
                        'reporting_head' => $chip('Reporting head'.((int) $s->approver_ref > 1 ? ' +'.((int) $s->approver_ref - 1) : '')),
                        'custom_user' => $short($s->customUsers->map(fn ($u) => $users[$u->user_id] ?? "User #{$u->user_id}")->all()) ?: $chip('no approver', 'bad'),
                        'specific_user' => $chip($users[$s->user_id] ?? "User #{$s->user_id}"),
                        'role' => $chip('Role: '.$s->approver_ref),
                        'permission' => $chip('Perm: '.$s->approver_ref),
                        default => $chip($s->approver_type),
                    };
                    $tags = ($s->mode === 'all' ? ' <span class="am-small am-muted" title="Every approver of this step must approve">all</span>' : '')
                        .($s->mode === 'n_of_m' ? ' <span class="am-small am-muted" title="'.(int) $s->min_approvals.' approvals needed">'.(int) $s->min_approvals.'×</span>' : '')
                        .($s->can_finish ? ' <span class="am-small" title="The approver may finish the request here" style="color:#099dae">✓</span>' : '');

                    return '<span title="'.e($s->name).'">'.$who.$tags.'</span>';
                })->all();

                return $items ? '<div class="am-chain">'.implode(' <span class="arrow">→</span> ', $items).'</div>' : '<span class="am-muted">no steps</span>';
            })
            ->orderColumn('approval_chain', 'id $1')
            ->editColumn('version', fn ($w) => 'v'.$w->version)
            ->addColumn('state', fn ($w) => '<span class="am-state '.$w->state.'">'.ucfirst($w->state).'</span>')
            ->orderColumn('state', 'state $1')
            ->addColumn('actions', function ($w) {
                $user = auth()->user();
                $post = fn ($route, $title, $text, $button, $danger = false, $method = 'POST') => "amAct({url:'".route($route, $w->id)."',method:'{$method}',title:".json_encode($title).',text:'.json_encode($text).',button:'.json_encode($button).',danger:'.($danger ? 'true' : 'false').'})';
                $edit = $user->can('approval-matrix-edit') ? '<a href="'.route('approval-matrix.workflows.edit', $w->id).'" class="btn btn-sm btn-primary"><i class="la la-pencil"></i> Edit</a>' : '';
                $items = '';
                if ($user->can('approval-matrix-simulator')) {
                    $items .= '<a href="'.route('approval-matrix.simulator.index', ['document_type' => $w->document_type]).'"><i class="la la-project-diagram"></i> Simulate</a>';
                }
                if ($user->can('approval-matrix-create')) {
                    $items .= '<a onclick="'.e($post('approval-matrix.workflows.duplicate', 'Duplicate this workflow?', 'A draft copy is created; nothing changes for running approvals.', 'Duplicate')).'"><i class="la la-copy"></i> Duplicate as draft</a>';
                }
                if ($user->can('approval-matrix-edit')) {
                    $items .= $w->state !== 'active'
                        ? '<a onclick="'.e($post('approval-matrix.workflows.activate', 'Activate “'.$w->name.'”?', 'New requests will use it immediately. It cannot overlap another active workflow of the same scope and priority.', 'Activate')).'"><i class="la la-check-circle"></i> Activate</a>'
                        : '<a onclick="'.e($post('approval-matrix.workflows.archive', 'Archive “'.$w->name.'”?', 'New requests stop using it; running approvals are not affected.', 'Archive', true)).'"><i class="la la-archive"></i> Archive</a>';
                }
                if ($user->can('approval-matrix-delete')) {
                    $items .= '<hr><a class="danger" onclick="'.e($post('approval-matrix.workflows.destroy', 'Delete “'.$w->name.'”?', 'A workflow that already has approval history is archived instead of deleted.', 'Delete', true, 'DELETE')).'"><i class="la la-trash"></i> Delete</a>';
                }
                $menu = $items ? '<div class="btn-group"><button type="button" class="btn btn-sm btn-default am-kebab dropdown-toggle" data-toggle="dropdown" aria-label="More actions"><i class="la la-ellipsis-v"></i></button><div class="dropdown-menu dropdown-menu-right am-menu">'.$items.'</div></div>' : '';

                return '<div class="am-actions">'.$edit.$menu.'</div>';
            })
            ->rawColumns(['workflow', 'applies_to', 'applies_when', 'approval_chain', 'state', 'actions'])
            ->make(true);
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

    public function duplicate($id)
    {
        $this->authorizeAbility('approval-matrix-create');
        $copy = $this->service->duplicate(ApprovalWorkflow::with('steps.customUsers')->findOrFail($id), auth()->id());

        return response()->json(['success' => true, 'message' => "Copied as draft “{$copy->name}”.", 'edit' => route('approval-matrix.workflows.edit', $copy->id)]);
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
