<?php

namespace Database\Seeders;

use App\Models\Admin;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::create([
            'email' => 'admin@ctms.edu',
            'phone_number' => '+1234567890',
            'password' => Hash::make('Admin@123'),
            'first_name' => 'System',
            'last_name' => 'Administrator',
            'role' => 'ADMIN',
            'is_active' => true,
        ]);

        Admin::create([
            'user_id' => $user->id,
            'designation' => 'Chief Transport Officer',
            'department' => 'Transport Operations',
            'access_level' => 'SUPER_ADMIN',
        ]);
    }
}
