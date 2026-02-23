<?php

namespace Database\Seeders;

use App\Models\UserRole;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $admin = User::query()->updateOrCreate([
            'email' => (string) env('ADMIN_EMAIL', 'admin@example.com'),
        ], [
            'name' => (string) env('ADMIN_NAME', 'Super Admin'),
            'password' => Hash::make((string) env('ADMIN_PASSWORD', 'admin123')),
            'is_admin' => true,
            'is_super_admin' => true,
            'is_protected' => true,
        ]);

        UserRole::query()->firstOrCreate([
            'user_id' => $admin->id,
            'role' => 'super_admin',
        ]);

        UserRole::query()->firstOrCreate([
            'user_id' => $admin->id,
            'role' => 'admin',
        ]);

        $this->call(SubscriberSeeder::class);
    }
}
