<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /** Display name of the seeded demo account. */
    public const DEMO_NAME = 'Fabian Okky';

    /** Login of the seeded demo account (RFC 2606 reserved domain, safe to publish). */
    public const DEMO_EMAIL = 'fabian@example.com';

    /** Password of the seeded demo account. Local/demo use only — never seed production. */
    public const DEMO_PASSWORD = 'password';

    /**
     * Seed the application's database with a demo user and a starter library so a fresh
     * install (or a `docker compose ... db:seed`) opens onto a clean, populated demo.
     */
    public function run(): void
    {
        $demo = User::firstOrCreate(
            ['email' => self::DEMO_EMAIL],
            ['name' => self::DEMO_NAME, 'password' => Hash::make(self::DEMO_PASSWORD)],
        );

        $this->call(DemoDocumentsSeeder::class, parameters: ['user' => $demo]);
    }
}
