<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\MondayImportBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

class MondayBoardFactory extends Factory
{
    public function definition(): array
    {
        return ['monday_import_batch_id' => MondayImportBatch::factory(), 'company_id' => Company::factory(), 'source_id' => fake()->unique()->numerify('##########'), 'name' => 'Umowy z Monday', 'kind' => 'contracts', 'columns' => ['A' => 'Name', 'B' => 'Status umowy', 'C' => 'PESEL Pracownika', 'D' => 'Item ID (auto generated)'], 'groups' => ['Aktualne umowy', 'Umowy rozwiązane'], 'workbook_path' => 'monday/test/source.xlsx', 'checksum' => str_repeat('b', 64)];
    }
}
