<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
  /**
   * Seed the application's database.
   */
  public function run(): void
  {
    $this->call(FilterSeeder::class);

    $password = Hash::make('qwerty123!@#');

    // Admin — full access incl. settings (Filters).
    // firstOrCreate: leaves an existing admin (password/name) untouched, creates only if missing.
    User::firstOrCreate(
      ['email' => 'admin@crawler.com'],
      [
        'name' => 'Staging Crawler Admin',
        'password' => $password,
        'role' => 'admin',
      ]
    );

    // Team — no settings (Filters) access.
    User::firstOrCreate(
      ['email' => 'team@crawler.com'],
      [
        'name' => 'Team User',
        'password' => $password,
        'role' => 'team',
      ]
    );
  }
}
