<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->string('tax_id', 20)->nullable();
            $table->text('address')->nullable();
            $table->string('hr_email');
            $table->string('privacy_url', 1000);
            $table->string('color')->default('green');
            $table->string('public_token', 64)->unique();
            $table->boolean('is_demo')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Cofnięcie tej migracji wymaga osobnej zgody na usunięcie danych.');
    }
};
