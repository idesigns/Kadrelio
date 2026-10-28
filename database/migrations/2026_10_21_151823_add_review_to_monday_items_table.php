<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monday_items', function (Blueprint $table): void {
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Cofnięcie tej migracji wymaga osobnej zgody na usunięcie danych.');
    }
};
