<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class EmployeeFactory extends Factory
{
    public function definition(): array
    {
        $pesel = fake()->unique()->numerify('###########');

        return ['company_id' => Company::factory(), 'first_name' => fake()->firstName(), 'last_name' => fake()->lastName(), 'email' => fake()->unique()->safeEmail(), 'pesel' => $pesel, 'pesel_hash' => Employee::identityHash($pesel), 'form_token' => Str::random(64), 'form_expires_at' => now()->addDays(30), 'department' => 'p1'];
    }

    public function completed(): static
    {
        return $this->state(fn (): array => ['profile_completed_at' => now(), 'personal_data' => ['bank_account' => '00111122223333444455556666', 'city' => 'Warszawa', 'street' => 'Przykładowa', 'house_number' => '1', 'postal_code' => '00-001']]);
    }
}
