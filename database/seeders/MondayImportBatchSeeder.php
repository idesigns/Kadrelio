<?php

namespace Database\Seeders;

use App\Models\MondayImportBatch;
use Illuminate\Database\Seeder;

class MondayImportBatchSeeder extends Seeder
{
    public function run(): void
    {
        MondayImportBatch::factory()->create();
    }
}
