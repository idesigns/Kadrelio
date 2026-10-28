<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

class DocumentTemplateFactory extends Factory
{
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'name' => 'Szablon testowy', 'kind' => 'contract', 'agreement_type' => 'mandate', 'language' => 'PL', 'body' => 'Dane pracownika: {{full_name}}, PESEL {{pesel}}. Firma: {{company_name}}.'];
    }
}
