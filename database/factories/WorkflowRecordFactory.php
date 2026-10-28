<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class WorkflowRecordFactory extends Factory
{
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'employee_id' => fn (array $attributes): int => Employee::factory()->create(['company_id' => $attributes['company_id']])->id, 'kind' => 'contract', 'status' => 'awaiting_data', 'language' => 'PL', 'department' => 'p1', 'concluded_at' => today(), 'starts_at' => today()->addDay(), 'ends_at' => today()->addYear(), 'signing_token' => Str::random(64), 'data' => ['reporter_name' => 'Osoba HR', 'reporter_email' => 'hr@example.test']];
    }

    public function ready(): static
    {
        return $this->state(fn (): array => ['status' => 'ready', 'generated_at' => now(), 'document_html' => '<!DOCTYPE html><html><meta charset="utf-8"><body>Dokument testowy</body></html>', 'document_hash' => hash('sha256', '<!DOCTYPE html><html><meta charset="utf-8"><body>Dokument testowy</body></html>')]);
    }

    public function sent(): static
    {
        return $this->ready()->state(fn (): array => ['status' => 'sent', 'signing_expires_at' => now()->addDays(30)]);
    }

    public function signed(): static
    {
        return $this->ready()->state(fn (): array => ['status' => 'signed', 'signed_at' => now()]);
    }
}
