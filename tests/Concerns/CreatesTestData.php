<?php

// للتذكير: هذا الملف يوفر أدوات مساعدة لإنشاء مستخدمين وأدوار للاختبارات.

namespace Tests\Concerns;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

trait CreatesTestData
{
    protected function makeUser(array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'Test User',
            'email' => 'user_' . Str::random(12) . '@example.test',
            'password' => Hash::make('Password123!'),
            'status' => 'active',
        ], $attributes));
    }

    protected function makeUserWithRole(string $slug, array $attributes = []): User
    {
        $user = $this->makeUser($attributes);
        $role = Role::firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $user->roles()->attach($role->id);

        return $user;
    }

    protected function makeAdmin(array $attributes = []): User
    {
        return $this->makeUserWithRole('admin', $attributes);
    }

    protected function eligibleRadiusState(): array
    {
        return [
            'current_radius_km' => 70,
            'radius_stage_started_at' => now(),
        ];
    }
}
