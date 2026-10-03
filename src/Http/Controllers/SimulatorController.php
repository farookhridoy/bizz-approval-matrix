<?php

namespace Bizzsol\ApprovalMatrix\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Bizzsol\ApprovalMatrix\Services\ApprovalSimulator;
use Bizzsol\ApprovalMatrix\Services\OrgDirectory;

class SimulatorController extends Controller
{
    public function index(Request $request, ApprovalSimulator $simulator, OrgDirectory $org)
    {
        $result = null;
        if ($request->filled('document_type')) {
            $request->validate([
                'document_type' => 'required|in:'.implode(',', array_keys(config('approvalmatrix.document_types'))),
                'amount' => 'nullable|numeric|min:0',
            ]);
            $attrs = [];
            foreach (preg_split('/\R/', (string) $request->input('attributes')) as $line) {
                if (str_contains($line, '=')) {
                    [$k, $v] = array_map('trim', explode('=', $line, 2));
                    $attrs[$k] = $v;
                }
            }
            $result = $simulator->simulate($request->only(['document_type', 'company_id', 'unit_id', 'master_department_id', 'amount', 'requester_id']) + ['attributes' => $attrs]);
        }

        return view('approvalmatrix::simulator.index', [
            'title' => 'Approval Simulator',
            'result' => $result,
            'documentTypes' => config('approvalmatrix.document_types'),
            'companies' => $org->companies(),
            'unitRows' => $org->unitRows($request->integer('company_id') ?: null),
            'masters' => $org->masterDepartments($request->integer('unit_id') ?: null, $request->integer('company_id') ?: null),
            'orgNames' => $org->nameMaps(),
            'users' => User::orderBy('name')->get(['id', 'name']),
        ]);
    }
}
