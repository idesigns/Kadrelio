<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monday_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('source_key', 100)->unique();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('fingerprint', 64);
            $table->string('status')->default('importing');
            $table->json('manifest');
            $table->longText('team')->nullable();
            $table->json('report')->nullable();
            $table->timestamps();
        });
        Schema::create('monday_boards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('monday_import_batch_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('source_id', 40);
            $table->string('name');
            $table->string('kind');
            $table->json('columns');
            $table->json('groups');
            $table->string('workbook_path');
            $table->string('checksum', 64);
            $table->timestamps();
            $table->unique(['monday_import_batch_id', 'source_id']);
            $table->index(['company_id', 'kind']);
        });
        Schema::create('monday_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('monday_board_id')->constrained()->restrictOnDelete();
            $table->string('source_id', 40);
            $table->unsignedInteger('position');
            $table->string('group_name')->nullable();
            $table->string('name', 1000);
            $table->string('status')->nullable();
            $table->string('identity_hash', 64)->nullable();
            $table->date('document_date')->nullable();
            $table->date('original_date')->nullable();
            $table->longText('source_data');
            $table->longText('fields');
            $table->json('issues')->nullable();
            $table->string('relation_state')->default('not_required');
            $table->foreignId('parent_id')->nullable()->constrained('monday_items')->restrictOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('workflow_record_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('revision')->default(0);
            $table->timestamps();
            $table->unique(['monday_board_id', 'source_id']);
            $table->index(['monday_board_id', 'position']);
            $table->index(['monday_board_id', 'identity_hash', 'document_date'], 'monday_items_identity_date_index');
            $table->index(['monday_board_id', 'relation_state']);
        });
        Schema::create('monday_updates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('monday_import_batch_id')->constrained()->restrictOnDelete();
            $table->foreignId('monday_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('source_id', 80);
            $table->longText('payload');
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->unique(['monday_import_batch_id', 'source_id']);
        });
        Schema::create('monday_assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('monday_import_batch_id')->constrained()->restrictOnDelete();
            $table->string('source_id', 40);
            $table->text('name');
            $table->text('source_url');
            $table->string('path')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->timestamps();
            $table->unique(['monday_import_batch_id', 'source_id']);
        });
        Schema::create('monday_asset_item', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('monday_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('monday_asset_id')->constrained()->restrictOnDelete();
            $table->string('column_key', 10);
            $table->unique(['monday_item_id', 'monday_asset_id', 'column_key'], 'monday_asset_item_unique');
        });
        Schema::create('monday_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('monday_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->longText('changes');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Cofnięcie tej migracji wymaga osobnej zgody na usunięcie danych.');
    }
};
