<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Identity\Domain\Models\Address;
use Modules\Identity\Domain\Models\Province;
use Modules\Identity\Domain\Models\City;

class AddressFactory extends Factory
{
    protected $model = Address::class;

    public function definition(): array
    {
        return [
            'user_id'    => 1, // or create a user factory and call ->for(User::factory())
            'province_id'=> Province::factory(),
            'city_id'    => City::factory(),
            'title'      => $this->faker->randomElement(['Home', 'Work']),
            'address'    => $this->faker->streetAddress(),
            'postal_code'=> $this->faker->postcode(),
            'is_default_shipping' => false,
            // any other NOT NULL columns in addresses table
        ];
    }
}
