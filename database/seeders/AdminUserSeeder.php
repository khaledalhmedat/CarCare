<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Creates (or updates) a single admin account for local/manual testing of
 * the admin provider-approval APIs, and assigns it the 'admin' role.
 *
 * NOT called automatically by DatabaseSeeder — run it explicitly:
 *   php artisan db:seed --class=AdminUserSeeder
 *
 * Credentials can be overridden for this run via env vars:
 *   ADMIN_SEED_EMAIL, ADMIN_SEED_PASSWORD
 * Falls back to a local-only placeholder if not set. Change the password
 * immediately if this is ever run anywhere other than a local/dev database.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_SEED_EMAIL', 'admin@carcare.local');
        $password = env('ADMIN_SEED_PASSWORD', 'ChangeMe123!');

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => 'Admin',
                'password' => Hash::make($password),
                'status' => 'active',
                'email_verified_at' => now(),
            ]
        );

        $adminRole = Role::where('slug', 'admin')->first();

        if ($adminRole && !$user->hasRole('admin')) {
            $user->roles()->attach($adminRole->id);
        }
    }
}
