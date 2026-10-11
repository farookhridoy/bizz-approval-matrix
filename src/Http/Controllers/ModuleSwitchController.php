<?php

namespace Bizzsol\ApprovalMatrix\Http\Controllers;

use App\Http\Controllers\Controller;
use Bizzsol\ApprovalMatrix\Services\ModuleSwitch;
use Illuminate\Http\Request;

class ModuleSwitchController extends Controller
{
    public function update(Request $request, string $module, ModuleSwitch $switch)
    {
        abort_unless(auth()->user()->can('approval-matrix-edit'), 403);
        abort_unless(in_array($module, config('approvalmatrix.modules', []), true), 404);

        $enabled = $request->boolean('enabled');
        $switch->set($module, $enabled, auth()->id());

        return response()->json([
            'success' => true,
            'enabled' => $enabled,
            'message' => ucfirst($module).' approval matrix '.($enabled ? 'enabled.' : 'disabled: its documents use their original approval flow.'),
        ]);
    }
}
