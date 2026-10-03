<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the three Approval Matrix screens under the existing "ACL" sidebar menu, creates their
 * permissions and grants them to the Super Admin role.
 *
 * NOTE: touches the shared `menus` / `sub_menus` / `permissions` tables - take a mysqldump first.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'approval-matrix-index', 'approval-matrix-create', 'approval-matrix-edit', 'approval-matrix-delete',
        'approval-matrix-simulator', 'approval-inbox',
    ];

    private const SUB_MENUS = [
        ['name' => 'Approval Matrix', 'url' => 'approval-matrix/workflows', 'serial_num' => 6,
            'slug' => ['approval-matrix-index', 'approval-matrix-create', 'approval-matrix-edit', 'approval-matrix-delete']],
        ['name' => 'Approval Simulator', 'url' => 'approval-matrix/simulator', 'serial_num' => 7,
            'slug' => ['approval-matrix-simulator']],
        ['name' => 'My Approvals', 'url' => 'approval-matrix/inbox', 'serial_num' => 8,
            'slug' => ['approval-inbox']],
    ];

    public function up(): void
    {
        $acl = DB::table('menus')->where('name', 'ACL')->where('module', 'main')->whereNull('deleted_at')->first();
        if (! $acl) {
            throw new RuntimeException('ACL menu not found; cannot attach Approval Matrix screens.');
        }

        foreach (self::PERMISSIONS as $name) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $name, 'guard_name' => 'web'],
                ['module' => 'ApprovalMatrix', 'created_at' => now(), 'updated_at' => now()]
            );
        }

        $superAdmin = DB::table('roles')->where('name', 'Super Admin')->where('guard_name', 'web')->value('id');
        if ($superAdmin) {
            foreach (DB::table('permissions')->whereIn('name', self::PERMISSIONS)->pluck('id') as $pid) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $pid, 'role_id' => $superAdmin]);
            }
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

        // The parent menu is only shown when the user holds one of its slug permissions.
        $slug = json_decode($acl->slug, true) ?: [];
        DB::table('menus')->where('id', $acl->id)->update(['slug' => json_encode(array_values(array_unique(array_merge($slug, self::PERMISSIONS))))]);

        $this->clearPermissionCache();
    }

    public function down(): void
    {
        $acl = DB::table('menus')->where('name', 'ACL')->where('module', 'main')->first();
        if ($acl) {
            DB::table('sub_menus')->where('menu_id', $acl->id)->whereIn('url', array_column(self::SUB_MENUS, 'url'))->delete();
            $slug = array_values(array_diff(json_decode($acl->slug, true) ?: [], self::PERMISSIONS));
            DB::table('menus')->where('id', $acl->id)->update(['slug' => json_encode($slug)]);
        }

        $ids = DB::table('permissions')->whereIn('name', self::PERMISSIONS)->pluck('id');
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        $this->clearPermissionCache();
    }

    private function clearPermissionCache(): void
    {
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
