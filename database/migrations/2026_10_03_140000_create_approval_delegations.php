<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Out-of-office delegation: while a delegation is active the delegate can see, be notified about and act on
 * everything assigned to the delegator (optionally only for one document type). Actions keep the original
 * assignee and record the delegate in acted_by, so the history shows who really decided.
 * `approval_actions.reminded_at` supports overdue reminders (approval:remind).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_delegations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('delegator_id')->index();
            $table->unsignedBigInteger('delegate_id')->index();
            $table->string('document_type', 100)->nullable()->comment('null = every document type');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('reason')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['delegate_id', 'starts_on', 'ends_on'], 'ad_delegate_window_idx');
        });

        Schema::table('approval_actions', function (Blueprint $table) {
            $table->timestamp('reminded_at')->nullable()->after('acted_at');
        });
    }

    public function down(): void
    {
        Schema::table('approval_actions', fn (Blueprint $t) => $t->dropColumn('reminded_at'));
        Schema::dropIfExists('approval_delegations');
    }
};
