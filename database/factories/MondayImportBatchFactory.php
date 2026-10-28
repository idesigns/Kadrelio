<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class MondayImportBatchFactory extends Factory
{
    public function definition(): array
    {
        return ['source_key' => 'test-'.fake()->unique()->numerify('########'), 'owner_id' => User::factory(), 'fingerprint' => str_repeat('a', 64), 'status' => 'completed', 'manifest' => [], 'team' => []];
    }
}
