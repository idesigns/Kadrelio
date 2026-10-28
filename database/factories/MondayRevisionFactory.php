<?php

namespace Database\Factories;

use App\Models\MondayItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class MondayRevisionFactory extends Factory
{
    public function definition(): array
    {
        return ['monday_item_id' => MondayItem::factory(), 'user_id' => User::factory(), 'changes' => ['reason' => 'Weryfikacja danych', 'fields' => ['B' => ['before' => 'Podpisana', 'after' => 'Rozwiązana']]]];
    }
}
