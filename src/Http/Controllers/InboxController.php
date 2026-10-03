<?php

namespace Bizzsol\ApprovalMatrix\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Bizzsol\ApprovalMatrix\Exceptions\ApprovalException;
use Bizzsol\ApprovalMatrix\Models\ApprovalAction;
use Bizzsol\ApprovalMatrix\Models\ApprovalRequest;
use Bizzsol\ApprovalMatrix\Services\ApprovalEngine;
use Yajra\DataTables\DataTables;

class InboxController extends Controller
{
    public function __construct(private ApprovalEngine $engine)
    {
    }

    public function index(Request $request)
    {
        if ($request->ajax()) {
            $docs = config('approvalmatrix.document_types');
            $names = User::pluck('name', 'id');
            $query = $this->engine->inbox(auth()->id())->latest('id');

            return DataTables::of($query)
                ->addIndexColumn()
                ->addColumn('document', fn ($r) => e(($docs[$r->document_type]['label'] ?? $r->document_type).' · '.class_basename($r->approvable_type).' #'.$r->approvable_id))
                ->addColumn('requester', fn ($r) => e($names[$r->requested_by] ?? '—'))
                ->editColumn('amount', fn ($r) => $r->amount !== null ? number_format($r->amount, 2) : '—')
                ->addColumn('step', fn ($r) => e($r->stepAt((int) $r->current_level)['name'] ?? ('Level '.$r->current_level)))
                ->addColumn('submitted', fn ($r) => $r->created_at->format('Y-m-d H:i'))
                ->addColumn('actions', fn ($r) => '<a href="'.route('approval-matrix.inbox.show', $r->id).'" class="btn btn-xs btn-primary"><i class="la la-eye"></i> Review</a>')
                ->rawColumns(['actions'])
                ->make(true);
        }

        return view('approvalmatrix::inbox.index', [
            'title' => 'My Approvals',
            'headerColumns' => [
                ['SL', 'SL', 'text-center'],
                ['document', 'Document', 'text-left'],
                ['requester', 'Requested by', 'text-left'],
                ['amount', 'Amount', 'text-right'],
                ['step', 'Waiting at', 'text-left'],
                ['submitted', 'Submitted', 'text-center'],
                ['actions', 'Actions', 'text-center'],
            ],
        ]);
    }

    public function show($id)
    {
        $approval = ApprovalRequest::with('actions')->findOrFail($id);
        $uid = auth()->id();
        $mine = $approval->status === ApprovalRequest::PENDING
            && $approval->actions->contains(fn ($a) => $a->assigned_to === $uid && $a->action === ApprovalAction::PENDING && $a->level == $approval->current_level);

        // Only requester, assigned approvers (past or present) and admins may view.
        $involved = $approval->requested_by === $uid || $approval->actions->contains('assigned_to', $uid);
        abort_unless($involved || auth()->user()->can('approval-matrix-index'), 403);

        return view('approvalmatrix::inbox.show', [
            'title' => 'Approval Request #'.$approval->id,
            'approval' => $approval,
            'names' => User::pluck('name', 'id'),
            'docLabel' => config('approvalmatrix.document_types')[$approval->document_type]['label'] ?? $approval->document_type,
            'canAct' => $mine,
            'canRecall' => $approval->status === ApprovalRequest::PENDING && $approval->requested_by === $uid,
        ]);
    }

    public function approve(Request $request, $id)
    {
        return $this->act($request, $id, fn ($req, $uid, $c) => $this->engine->approve($req, $uid, $c), 'Approved.');
    }

    public function reject(Request $request, $id)
    {
        $request->validate(['comments' => 'required|string|max:2000']);

        return $this->act($request, $id, fn ($req, $uid, $c) => $this->engine->reject($req, $uid, $c), 'Rejected.');
    }

    public function recall(Request $request, $id)
    {
        return $this->act($request, $id, fn ($req, $uid, $c) => $this->engine->recall($req, $uid, $c), 'Request recalled.');
    }

    private function act(Request $request, $id, \Closure $do, string $okMessage)
    {
        $request->validate(['comments' => 'nullable|string|max:2000']);
        try {
            $do(ApprovalRequest::findOrFail($id), auth()->id(), $request->input('comments'));
        } catch (ApprovalException $e) {
            return redirect()->back()->with(['message' => $e->getMessage(), 'alert-type' => 'error']);
        }

        return redirect()->route('approval-matrix.inbox.index')->with(['message' => $okMessage, 'alert-type' => 'success']);
    }
}
