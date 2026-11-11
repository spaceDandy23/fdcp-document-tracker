<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Application users are synchronized from FDCP SSO. Use explicit local-only seeders for development data.
    }
}
