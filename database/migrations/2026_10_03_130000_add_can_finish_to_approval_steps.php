<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `can_finish`: the approver of this step may complete the whole request here ("Acknowledge"),
 * leaving the remaining steps unused, or forward it to the next step ("Send to Management").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_steps', function (Blueprint $table) {
            $table->boolean('can_finish')->default(false)->after('is_mandatory');
        });
    }

    public function down(): void
    {
        Schema::table('approval_steps', fn (Blueprint $t) => $t->dropColumn('can_finish'));
    }
};
