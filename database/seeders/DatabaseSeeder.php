<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('local') && config('hr.demo_enabled')) {
            $this->call(DemoSeeder::class);
        }
    }
}
