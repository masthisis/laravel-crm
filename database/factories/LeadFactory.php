<?php

namespace Database\Factories;

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\LeadSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => sprintf('+1555%07d', random_int(0, 9999999)),
            'source_id' => LeadSource::factory(),
            'status' => LeadStatus::New,
            'estimated_value' => fake()->randomFloat(2, 100, 5000),
        ];
    }
}
