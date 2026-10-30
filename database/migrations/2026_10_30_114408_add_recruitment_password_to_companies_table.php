<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('recruitment_password')->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Usunięcie hasła formularza rekrutacji wymaga osobnej zgody na usunięcie danych.');
    }
};
