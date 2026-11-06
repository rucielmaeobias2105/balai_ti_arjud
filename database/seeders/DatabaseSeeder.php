<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // Staff + salon operating configuration must exist first.
            AdminSeeder::class,
            ServiceSeeder::class,
            InventorySeeder::class,
            UserSeeder::class,
            AppointmentSeeder::class,
            TermsSeeder::class,
            ContentSeeder::class,
        ]);
    }
}
