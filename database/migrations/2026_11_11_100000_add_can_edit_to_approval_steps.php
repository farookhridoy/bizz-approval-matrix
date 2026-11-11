<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `can_edit`: the approver of this step may correct the document (e.g. quantities, destination) while deciding,
 * instead of only checking it and approving / rejecting. Honoured only by document types that declare
 * `allow_edit` in config and ship an approver edit screen. Default false: nothing changes for existing workflows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('approval_steps', 'can_edit')) {
            Schema::table('approval_steps', function (Blueprint $table) {
                $table->boolean('can_edit')->default(false)->after('can_finish');
            });
        }
    }

    public function down(): void
    {
        Schema::table('approval_steps', fn (Blueprint $t) => $t->dropColumn('can_edit'));
    }
};
