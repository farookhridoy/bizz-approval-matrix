<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master-department scope: Company > Unit > Master department.
 * `department_id` (a unit's own hr_department row) stays for exact, unit-specific scoping;
 * `master_department_id` lets one workflow cover "Production" in every unit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_workflows', function (Blueprint $table) {
            $table->unsignedBigInteger('master_department_id')->nullable()->after('department_id')->index();
        });
        Schema::table('approval_step_users', function (Blueprint $table) {
            $table->unsignedBigInteger('master_department_id')->nullable()->after('department_id');
        });
        Schema::table('approval_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('master_department_id')->nullable()->after('department_id');
        });
    }

    public function down(): void
    {
        Schema::table('approval_requests', fn (Blueprint $t) => $t->dropColumn('master_department_id'));
        Schema::table('approval_step_users', fn (Blueprint $t) => $t->dropColumn('master_department_id'));
        Schema::table('approval_workflows', function (Blueprint $t) {
            $t->dropIndex(['master_department_id']);
            $t->dropColumn('master_department_id');
        });
    }
};
