<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds "Approval Coverage" and "Delegation" under the existing "ACL" sidebar menu, next to the other
 * Approval Matrix screens. They reuse existing permissions (approval-matrix-simulator / approval-inbox),
 * so no new permissions are created.
 *
 * NOTE: touches the shared `menus` / `sub_menus` tables - take a mysqldump first.
 */
return new class extends Migration
{
    private const SUB_MENUS = [
        ['name' => 'Approval Coverage', 'url' => 'approval-matrix/coverage', 'serial_num' => 9,
            'slug' => ['approval-matrix-simulator']],
        ['name' => 'Delegation', 'url' => 'approval-matrix/inbox/delegations', 'serial_num' => 10,
            'slug' => ['approval-inbox']],
    ];

    public function up(): void
    {
        $acl = DB::table('menus')->where('name', 'ACL')->where('module', 'main')->whereNull('deleted_at')->first();
        if (! $acl) {
            throw new RuntimeException('ACL menu not found; cannot attach Coverage / Delegation screens.');
        }

        foreach (self::SUB_MENUS as $m) {
            DB::table('sub_menus')->updateOrInsert(
                ['menu_id' => $acl->id, 'url' => $m['url']],
                [
                    'module' => 'main', 'name' => $m['name'], 'serial_num' => $m['serial_num'], 'status' => 'Active',
                    'slug' => json_encode($m['slug']), 'menu_for' => 'Sub menu for admin', 'open_new_tab' => 'No Open New Tab',
                    'created_by' => 1, 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => null,
                ]
            );
        }
    }

    public function down(): void
    {
        $acl = DB::table('menus')->where('name', 'ACL')->where('module', 'main')->first();
        if ($acl) {
            DB::table('sub_menus')->where('menu_id', $acl->id)->whereIn('url', array_column(self::SUB_MENUS, 'url'))->delete();
        }
    }
};
