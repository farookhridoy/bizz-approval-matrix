<?php

namespace Bizzsol\ApprovalMatrix\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Bizzsol\ApprovalMatrix\Models\ApprovalDelegation;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Out-of-office: name someone to approve on your behalf for a date range. Everyone manages only their own delegations. */
class DelegationController extends Controller
{
    public function index()
    {
        $me = auth()->id();
        $names = User::pluck('name', 'id');

        return view('approvalmatrix::delegations.index', [
            'title' => 'Approval Delegation',
            'mine' => ApprovalDelegation::where('delegator_id', $me)->orderByDesc('starts_on')->get(),
            'toMe' => ApprovalDelegation::activeOn()->where('delegate_id', $me)->get(),
            'names' => $names,
            'users' => User::where('id', '!=', $me)->orderBy('name')->get(['id', 'name']),
            'documentTypes' => config('approvalmatrix.document_types'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'delegate_id' => ['required', 'integer', 'exists:users,id', Rule::notIn([auth()->id()])],
            'document_type' => ['nullable', Rule::in(array_keys(config('approvalmatrix.document_types')))],
            'starts_on' => 'required|date|after_or_equal:today',
            'ends_on' => 'required|date|after_or_equal:starts_on',
            'reason' => 'nullable|string|max:255',
        ], ['delegate_id.not_in' => 'You cannot delegate to yourself.']);

        ApprovalDelegation::create($data + ['delegator_id' => auth()->id(), 'created_by' => auth()->id()]);

        return redirect()->route('approval-matrix.inbox.delegations.index')->with(['message' => 'Delegation saved.', 'alert-type' => 'success']);
    }

    public function destroy($id)
    {
        $delegation = ApprovalDelegation::where('delegator_id', auth()->id())->findOrFail($id);
        $delegation->delete();

        return redirect()->route('approval-matrix.inbox.delegations.index')->with(['message' => 'Delegation revoked.', 'alert-type' => 'success']);
    }
}
