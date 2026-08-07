<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Identity\Domain\Models\Address;
use Modules\Identity\Domain\Models\City;
use Modules\Identity\Domain\Models\Province;

class AddressFactory extends Factory
{
    protected $model = Address::class;

    public function definition(): array
    {
        return [
            // Assigned here rather than left to the model's creating hook, so the
            // factory still produces a valid address when a caller disables model
            // events (seeders run that way).
            'public_code' => Address::generateUniquePublicCode(),
            'user_id' => 1, // or create a user factory and call ->for(User::factory())
            'province_id' => Province::factory(),
            'city_id' => City::factory(),
            'title' => $this->faker->randomElement(['Home', 'Work']),
            'address' => $this->faker->streetAddress(),
            'postal_code' => $this->faker->postcode(),
            'latitude' => $this->faker->latitude(),
            'longitude' => $this->faker->longitude(),
            'map_address' => $this->faker->address(),
            'is_default_shipping' => false,
            // any other NOT NULL columns in addresses table
        ];
    }
}
