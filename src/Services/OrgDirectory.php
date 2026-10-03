<?php

namespace Bizzsol\ApprovalMatrix\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Org lookups for the cascade Company > Unit > Master department.
 *   companies.id  <-  hr_unit.company_id
 *   hr_unit.id    <-  hr_department.hr_unit_id ; hr_department.master_department_id -> master_departments.id
 *   (a unit's own hr_department row is what employees reference; department_unit mirrors the same data)
 */
class OrgDirectory
{
    /** @return Collection<int,string> id => name */
    public function companies(): Collection
    {
        return DB::table('companies')->whereNull('deleted_at')->orderBy('name')->pluck('name', 'id');
    }

    /** Units, optionally limited to one company. @return Collection<int,string> id => name */
    public function units(?int $companyId = null): Collection
    {
        return DB::table('hr_unit')->whereNull('deleted_at')->where('hr_unit_status', 1)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->orderBy('hr_unit_name')->pluck('hr_unit_name', 'id');
    }

    /** Units with their company, for cascade dropdowns. @return Collection<int,object{id:int,name:string,company_id:int}> */
    public function unitRows(?int $companyId = null): Collection
    {
        return DB::table('hr_unit')->whereNull('deleted_at')->where('hr_unit_status', 1)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->orderBy('hr_unit_name')->get(['id', 'hr_unit_name as name', 'company_id']);
    }

    /**
     * Master departments offered in a unit; with no unit, in any unit of the company; with neither, all active ones.
     * Same source as erp-pms's store-requisition Unit > Master Department filter: hr_department rows carry
     * their unit and master department. (The PMS filter additionally limits to the viewer's own departments;
     * an admin configuring the matrix sees them all.)
     *
     * @return Collection<int,string> id => name
     */
    public function masterDepartments(?int $unitId = null, ?int $companyId = null): Collection
    {
        return DB::table('master_departments as m')
            ->whereNull('m.deleted_at')->where('m.status', 1)
            ->when($unitId || $companyId, function ($q) use ($unitId, $companyId) {
                $q->whereIn('m.id', function ($sub) use ($unitId, $companyId) {
                    $sub->select('d.master_department_id')->from('hr_department as d')
                        ->whereNull('d.deleted_at')->whereNotNull('d.master_department_id')
                        ->when($unitId, fn ($s) => $s->where('d.hr_unit_id', $unitId))
                        ->when(! $unitId && $companyId, fn ($s) => $s->whereIn('d.hr_unit_id', DB::table('hr_unit')->where('company_id', $companyId)->select('id')));
                });
            })
            ->orderBy('m.name')->pluck('m.name', 'm.id');
    }

    public function companyOfUnit(int $unitId): ?int
    {
        $c = DB::table('hr_unit')->where('id', $unitId)->value('company_id');

        return $c ? (int) $c : null;
    }

    /** Is this master department offered in this unit? */
    public function unitHasMasterDepartment(int $unitId, int $masterDepartmentId): bool
    {
        return DB::table('hr_department')->whereNull('deleted_at')
            ->where('hr_unit_id', $unitId)->where('master_department_id', $masterDepartmentId)->exists();
    }

    /** Master department of a unit's own department row (employees.main_department_id). */
    public function masterOfDepartment(?int $hrDepartmentId, ?int $unitId = null): ?int
    {
        if (! $hrDepartmentId) {
            return null;
        }
        $id = DB::table('hr_department')->whereNull('deleted_at')->where('id', $hrDepartmentId)
            ->when($unitId, fn ($q) => $q->where('hr_unit_id', $unitId))->value('master_department_id');

        return $id ? (int) $id : null;
    }

    /** Display names for list pages. @return array{companies:Collection,units:Collection,masters:Collection,departments:Collection} */
    public function nameMaps(): array
    {
        return [
            'companies' => DB::table('companies')->pluck('name', 'id'),
            'units' => DB::table('hr_unit')->pluck('hr_unit_name', 'id'),
            'masters' => DB::table('master_departments')->pluck('name', 'id'),
            'departments' => DB::table('hr_department')->pluck('hr_department_name', 'id'),
        ];
    }
}
