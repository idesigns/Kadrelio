<?php

namespace Database\Seeders;

use App\Models\MondayAsset;
use Illuminate\Database\Seeder;

class MondayAssetSeeder extends Seeder
{
    public function run(): void
    {
        MondayAsset::factory()->create();
    }
}
