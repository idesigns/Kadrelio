<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class CompanyFactory extends Factory
{
    public function definition(): array
    {
        return ['owner_id' => User::factory(), 'name' => fake()->company(), 'hr_email' => fake()->safeEmail(), 'privacy_url' => 'https://example.com/rodo.pdf', 'color' => 'green', 'public_token' => Str::random(64)];
    }
}
