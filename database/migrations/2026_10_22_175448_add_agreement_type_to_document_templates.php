<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_templates', function (Blueprint $table): void {
            $table->string('agreement_type')->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Usunięcie typu umowy z szablonów wymaga osobnej zgody na usunięcie danych.');
    }
};
