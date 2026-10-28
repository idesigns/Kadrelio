<?php

namespace Database\Seeders;

use App\Models\MondayBoard;
use Illuminate\Database\Seeder;

class MondayBoardSeeder extends Seeder
{
    public function run(): void
    {
        MondayBoard::factory()->create();
    }
}
