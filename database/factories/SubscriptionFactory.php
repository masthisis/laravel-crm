<?php

namespace Database\Factories;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'status' => SubscriptionStatus::Active,
            'amount' => fake()->randomFloat(2, 20, 500),
            'interval' => fake()->randomElement(['monthly', 'yearly']),
            'started_at' => fake()->dateTimeBetween('-180 days'),
        ];
    }
}
