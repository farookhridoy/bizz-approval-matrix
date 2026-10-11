<?php

namespace Bizzsol\ApprovalMatrix\Http\Controllers;

use App\Http\Controllers\Controller;
use Bizzsol\ApprovalMatrix\Services\ModuleSwitch;
use Illuminate\Http\Request;

class DocumentSwitchController extends Controller
{
    /** state = on | off | default (back to "not set": the app's own .env mode applies) */
    public function update(Request $request, string $documentType, ModuleSwitch $switch)
    {
        abort_unless(auth()->user()->can('approval-matrix-edit'), 403);
        abort_unless(array_key_exists($documentType, config('approvalmatrix.document_types', [])), 404);

        $state = $request->validate(['state' => 'required|in:on,off,default'])['state'];
        $switch->setDocument($documentType, ['on' => true, 'off' => false, 'default' => null][$state], auth()->id());

        $label = config("approvalmatrix.document_types.{$documentType}.label", $documentType);

        return response()->json([
            'success' => true,
            'state' => $state,
            'message' => $label.': '.['on' => 'matrix switched on.', 'off' => 'matrix switched off, the original approval flow is used.', 'default' => 'back to the app default (.env mode).'][$state],
        ]);
    }
}
