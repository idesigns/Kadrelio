<?php

namespace Database\Factories;

use App\Models\MondayImportBatch;
use App\Models\MondayItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class MondayUpdateFactory extends Factory
{
    public function definition(): array
    {
        return ['monday_import_batch_id' => MondayImportBatch::factory(), 'monday_item_id' => MondayItem::factory(), 'source_id' => fake()->unique()->numerify('##########'), 'payload' => ['headers' => ['A' => 'User', 'B' => 'Created At', 'C' => 'Update Content'], 'values' => ['A' => 'HR', 'B' => '2024-01-01T10:00:00Z', 'C' => 'Komentarz do umowy']], 'posted_at' => '2024-01-01 10:00:00'];
    }
}
