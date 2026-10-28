<?php

namespace Database\Factories;

use App\Models\MondayImportBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

class MondayAssetFactory extends Factory
{
    public function definition(): array
    {
        return ['monday_import_batch_id' => MondayImportBatch::factory(), 'source_id' => fake()->unique()->numerify('##########'), 'name' => 'umowa.pdf', 'source_url' => 'https://example.monday.com/resources/12345/umowa.pdf'];
    }
}
