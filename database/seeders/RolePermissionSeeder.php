<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Role;
use App\Models\Permission;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['name' => 'Admin', 'slug' => 'admin'],
            ['name' => 'User', 'slug' => 'user'],
            ['name' => 'Technician', 'slug' => 'technician'],
            ['name' => 'Car Washer', 'slug' => 'car-washer'],
            ['name' => 'Fuel Provider', 'slug' => 'fuel-provider'],
        ];
        
        foreach ($roles as $role) {
            Role::updateOrCreate(['slug' => $role['slug']], $role);
        }
    }
}