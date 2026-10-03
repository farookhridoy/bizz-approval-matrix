<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Global approval matrix: Module > Company > Unit > Department.
 *
 * Purely additive on the shared `approval_workflows` / `approval_steps` tables
 * (created by 2026_04_29_062953_approval_workflows, 0 rows at time of writing) so
 * nothing already reading them breaks. `approval_logs` is left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        // company becomes optional: null = applies to every company.
        DB::statement('ALTER TABLE approval_workflows MODIFY company_id BIGINT UNSIGNED NULL');

        Schema::table('approval_workflows', function (Blueprint $table) {
            $table->string('module', 40)->default('core')->after('name')->index();
            $table->string('document_type', 100)->nullable()->after('module')->index();
            $table->unsignedBigInteger('unit_id')->nullable()->after('company_id');
            $table->unsignedInteger('priority')->default(0)->after('department_id');
            $table->json('conditions')->nullable()->after('priority');
            $table->unsignedInteger('version')->default(1)->after('conditions');
            $table->unsignedBigInteger('parent_id')->nullable()->after('version');
            $table->date('effective_from')->nullable()->after('parent_id');
            $table->date('effective_to')->nullable()->after('effective_from');
            $table->string('state', 20)->default('active')->after('effective_to'); // draft|active|archived

            $table->index(['document_type', 'state'], 'aw_doctype_state_idx');
        });

        Schema::table('approval_steps', function (Blueprint $table) {
            $table->string('stage_key', 60)->nullable()->after('level');
            $table->string('name')->nullable()->after('stage_key');
            $table->string('step_kind', 20)->default('approval')->after('name'); // approval|task|decision
            $table->string('approver_ref')->nullable()->after('approver_type'); // role name, permission, hop count...
            $table->string('mode', 10)->default('any')->after('approver_ref'); // any|all|n_of_m
            $table->unsignedInteger('min_approvals')->nullable()->after('mode');
            $table->json('skip_condition')->nullable()->after('is_mandatory');
            $table->unsignedInteger('sla_hours')->nullable()->after('skip_condition');
            $table->string('on_reject', 20)->default('terminate')->after('sla_hours'); // terminate|return_to_requester
        });

        // Custom-user approvers, chosen per unit / department (null = any).
        Schema::create('approval_step_users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('step_id')->index();
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('user_id')->index();
            $table->timestamps();

            $table->foreign('step_id')->references('id')->on('approval_steps')->onDelete('cascade');
        });

        // One row per document submission (revision). Holds a snapshot so later edits
        // to the workflow never change an in-flight approval.
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->string('approvable_type');
            $table->unsignedBigInteger('approvable_id');
            $table->string('document_type', 100)->index();
            $table->unsignedBigInteger('workflow_id');
            $table->unsignedInteger('workflow_version')->default(1);
            $table->unsignedInteger('revision')->default(0);
            $table->json('steps_snapshot');
            $table->unsignedInteger('current_level')->nullable();
            $table->string('status', 20)->default('pending'); // pending|approved|rejected|returned|recalled
            $table->unsignedBigInteger('requested_by');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->decimal('amount', 18, 2)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['approvable_type', 'approvable_id'], 'ar_approvable_idx');
            $table->index(['status', 'current_level'], 'ar_status_level_idx');
        });

        // Per-step assignments and decisions.
        Schema::create('approval_actions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('request_id')->index();
            $table->unsignedInteger('level');
            $table->unsignedBigInteger('step_id')->nullable();
            $table->unsignedBigInteger('assigned_to')->nullable()->index();
            $table->unsignedBigInteger('acted_by')->nullable();
            $table->string('action', 20)->default('pending'); // pending|approved|rejected|returned|skipped|superseded|recalled
            $table->text('comments')->nullable();
            $table->timestamp('acted_at')->nullable();
            $table->timestamps();

            $table->index(['assigned_to', 'action'], 'aa_inbox_idx');
            $table->foreign('request_id')->references('id')->on('approval_requests')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_actions');
        Schema::dropIfExists('approval_requests');
        Schema::dropIfExists('approval_step_users');

        Schema::table('approval_steps', function (Blueprint $table) {
            $table->dropColumn(['stage_key', 'name', 'step_kind', 'approver_ref', 'mode', 'min_approvals', 'skip_condition', 'sla_hours', 'on_reject']);
        });

        Schema::table('approval_workflows', function (Blueprint $table) {
            $table->dropIndex('aw_doctype_state_idx');
            $table->dropColumn(['module', 'document_type', 'unit_id', 'priority', 'conditions', 'version', 'parent_id', 'effective_from', 'effective_to', 'state']);
        });
        // company_id intentionally left nullable on rollback (safe superset).
    }
};
