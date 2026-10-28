<?php

namespace Database\Seeders;

use App\Models\MondayRevision;
use Illuminate\Database\Seeder;

class MondayRevisionSeeder extends Seeder
{
    public function run(): void
    {
        MondayRevision::factory()->create();
    }
}
