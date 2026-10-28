<?php

namespace Database\Seeders;

use App\Models\MondayItem;
use Illuminate\Database\Seeder;

class MondayItemSeeder extends Seeder
{
    public function run(): void
    {
        MondayItem::factory()->create();
    }
}
