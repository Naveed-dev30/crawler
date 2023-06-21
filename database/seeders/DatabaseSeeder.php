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
    $user = new User();
    $password = Hash::make('qwerty123!@#');

    $user->email = 'admin@crawler.com';
    $user->password = $password;
    $user->name = 'Staging Crawler Admin';

    $user->save();
  }
}
