<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-module on/off switch for the approval matrix. A module with no row follows config approvalmatrix.default_module_enabled
 * (true unless APPROVAL_MATRIX_DEFAULT_ENABLED=false). A disabled module resolves no workflow, so its documents use their
 * original (legacy) approval flow; the workflows themselves are kept untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_module_settings', function (Blueprint $table) {
            $table->id();
            $table->string('module', 40)->unique();
            $table->boolean('enabled')->default(true);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_module_settings');
    }
};
