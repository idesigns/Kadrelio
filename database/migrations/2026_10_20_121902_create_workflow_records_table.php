<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained('workflow_records')->restrictOnDelete();
            $table->string('kind');
            $table->string('status')->default('draft');
            $table->string('language', 2)->default('PL');
            $table->string('department')->nullable();
            $table->date('concluded_at')->nullable();
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->text('data')->nullable();
            $table->text('document_html')->nullable();
            $table->string('document_hash', 64)->nullable();
            $table->string('pdf_path')->nullable();
            $table->string('statement_pdf_path')->nullable();
            $table->string('signing_token', 64)->unique();
            $table->timestamp('signing_expires_at')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('mail_sent_at')->nullable();
            $table->string('mail_status')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'kind', 'status', 'id']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Cofnięcie tej migracji wymaga osobnej zgody na usunięcie danych.');
    }
};
