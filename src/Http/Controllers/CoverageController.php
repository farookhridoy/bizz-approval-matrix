<?php

namespace Bizzsol\ApprovalMatrix\Http\Controllers;

use App\Http\Controllers\Controller;
use Bizzsol\ApprovalMatrix\Services\CoverageReport;
use Illuminate\Http\Request;

class CoverageController extends Controller
{
    public function index(Request $request, CoverageReport $report)
    {
        $types = config('approvalmatrix.document_types');
        $result = null;
        if ($request->filled('document_type')) {
            $request->validate(['document_type' => 'in:'.implode(',', array_keys($types)), 'amount' => 'nullable|numeric|min:0']);
            $result = $report->report($request->document_type, $request->filled('amount') ? (float) $request->amount : null);
        }

        return view('approvalmatrix::coverage.index', [
            'title' => 'Approval Coverage',
            'documentTypes' => $types,
            'result' => $result,
            'onlyProblems' => $request->boolean('only_problems', true),
        ]);
    }
}
