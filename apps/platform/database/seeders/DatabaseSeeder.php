<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Empty scaffold default removed: it seeded App\Models\User, which does not exist —
     * player is the identity table (Story 1.3). Real seeders (test players, systemConfig
     * defaults) land with the stories that introduce those tables.
     */
    public function run(): void
    {
        $this->call(BlackRedGameSeeder::class);
    }
}
