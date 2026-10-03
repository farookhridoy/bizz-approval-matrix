<?php

namespace Bizzsol\ApprovalMatrix\Http\Controllers;

use App\Http\Controllers\Controller;
use Bizzsol\ApprovalMatrix\Services\OrgDirectory;
use Illuminate\Http\Request;

/** JSON feeds for the Company > Unit > Master department cascade. */
class OrgController extends Controller
{
    public function __construct(private OrgDirectory $org)
    {
    }

    public function units(Request $request)
    {
        return response()->json($this->org->unitRows($request->integer('company_id') ?: null)->values());
    }

    public function masterDepartments(Request $request)
    {
        return $this->options($this->org->masterDepartments($request->integer('unit_id') ?: null, $request->integer('company_id') ?: null));
    }

    public function users(Request $request)
    {
        return response()->json($this->org->users(
            $request->integer('company_id') ?: null,
            $request->integer('unit_id') ?: null,
            $request->integer('master_department_id') ?: null,
        )->values());
    }

    private function options($collection)
    {
        return response()->json($collection->map(fn ($name, $id) => ['id' => $id, 'name' => $name])->values());
    }
}
