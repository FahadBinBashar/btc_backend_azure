<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Subscriber;

class SubscriberSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Subscriber::query()->delete();

        $subscribers = [
            [
                'msisdn' => '71234567',
                'first_name' => 'John',
                'last_name' => 'Doe',
                'is_whitelisted' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'msisdn' => '71234568',
                'first_name' => 'Jane',
                'last_name' => 'Smith',
                'is_whitelisted' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'msisdn' => '77000001',
                'first_name' => 'Not',
                'last_name' => 'Whitelisted',
                'is_whitelisted' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'msisdn' => '+26771234569',
                'first_name' => 'International',
                'last_name' => 'Format',
                'is_whitelisted' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        Subscriber::insert($subscribers);
    }
}