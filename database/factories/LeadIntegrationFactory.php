<?php

namespace Database\Factories;

use App\Enums\IntegrationDriver;
use App\Models\LeadIntegration;
use App\Models\LeadSource;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LeadIntegration>
 */
class LeadIntegrationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'lead_source_id' => LeadSource::factory(),
            'driver' => IntegrationDriver::Webhook,
            'token' => Str::random(48),
            'is_active' => true,
        ];
    }
}
