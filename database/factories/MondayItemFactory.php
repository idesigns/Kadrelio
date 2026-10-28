<?php

namespace Database\Factories;

use App\Models\MondayBoard;
use Illuminate\Database\Eloquent\Factories\Factory;

class MondayItemFactory extends Factory
{
    public function definition(): array
    {
        return ['monday_board_id' => MondayBoard::factory(), 'source_id' => fake()->unique()->numerify('##########'), 'position' => fake()->numberBetween(1, 1000), 'group_name' => 'Aktualne umowy', 'name' => 'Anna Kowalska', 'status' => 'Podpisana', 'source_data' => ['headers' => ['A' => 'Name', 'B' => 'Status umowy', 'C' => 'PESEL Pracownika', 'D' => 'Item ID (auto generated)'], 'values' => ['A' => 'Anna Kowalska', 'B' => 'Podpisana', 'C' => '44051401458'], 'cells' => [], 'date1904' => false], 'fields' => ['A' => 'Anna Kowalska', 'B' => 'Podpisana', 'C' => '44051401458'], 'relation_state' => 'not_required'];
    }
}
