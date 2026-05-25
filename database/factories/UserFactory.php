<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Modules\Identity\Domain\Models\User;

class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->unique()->numerify('09#########'),
            'email_verified_at' => now(),
            'password' => Hash::make('password123'),
            'remember_token' => \Illuminate\Support\Str::random(10),
        ];
    }
}
