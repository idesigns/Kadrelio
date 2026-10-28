<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->text('pesel')->nullable();
            $table->string('pesel_hash', 64)->nullable();
            $table->string('department')->nullable();
            $table->text('personal_data')->nullable();
            $table->string('form_token', 64)->unique();
            $table->timestamp('form_expires_at')->nullable();
            $table->timestamp('profile_completed_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'pesel_hash']);
            $table->index(['company_id', 'last_name', 'id']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Cofnięcie tej migracji wymaga osobnej zgody na usunięcie danych.');
    }
};
