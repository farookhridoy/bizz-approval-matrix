<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per document type ("workflow") on/off switch. A row is an explicit dashboard decision and beats the consuming app's own default
 * (its .env mode, e.g. erp-pms APPROVAL_MATRIX_CS_MODE); no row = "not set" and the app default applies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_document_settings', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 100)->unique();
            $table->boolean('enabled');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_document_settings');
    }
};
