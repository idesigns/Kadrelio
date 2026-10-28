<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->string('format')->default('text');
            $table->string('source_path')->nullable();
            $table->string('source_name')->nullable();
            $table->string('source_checksum', 64)->nullable();
            $table->text('placeholders')->nullable();
            $table->text('field_mapping')->nullable();
            $table->string('status')->default('active');
            $table->uuid('series_key')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('revision')->default(0);
            $table->string('market')->nullable();
            $table->text('package_kinds')->nullable();
            $table->string('preview_path')->nullable();
            $table->string('preview_fingerprint', 64)->nullable();
            $table->string('preview_checksum', 64)->nullable();
            $table->timestamp('previewed_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->unsignedBigInteger('activated_by')->nullable();
            $table->index(['company_id', 'status', 'kind']);
            $table->unique(['company_id', 'series_key', 'version']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Wersje i oryginały szablonów są zachowywane. Zastosuj migrację naprawczą bez usuwania danych.');
    }
};
