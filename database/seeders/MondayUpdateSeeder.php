<?php

namespace Database\Seeders;

use App\Models\MondayUpdate;
use Illuminate\Database\Seeder;

class MondayUpdateSeeder extends Seeder
{
    public function run(): void
    {
        MondayUpdate::factory()->create();
    }
}
