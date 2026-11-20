<?php

namespace Database\Factories;

use App\Enums\SourceType;
use App\Models\LeadSource;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LeadSource>
 */
class LeadSourceFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => ucfirst($name),
            'slug' => Str::slug($name),
            'type' => SourceType::Webhook,
            'color' => fake()->hexColor(),
            'is_active' => true,
        ];
    }
}
