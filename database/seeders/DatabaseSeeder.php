<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database with a demo user and a starter library so a fresh
     * install (or a `docker compose ... db:seed`) opens onto a clean, populated demo.
     */
    public function run(): void
    {
        $demo = User::firstOrCreate(
            ['email' => 'demo@example.com'],
            ['name' => 'Demo User', 'password' => Hash::make('password')],
        );

        $this->call(DemoDocumentsSeeder::class, parameters: ['user' => $demo]);
    }
}
