<?php

namespace App\Services\Capture\Channels;

use App\Contracts\LeadChannel;
use App\Enums\IntegrationDriver;
use App\Models\LeadIntegration;

/**
 * Channel for plain JSON webhooks (POST /api/leads with an integration
 * token). Name + phone are the minimum viable lead.
 */
class WebhookChannel implements LeadChannel
{
    public function driver(): IntegrationDriver
    {
        return IntegrationDriver::Webhook;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:32'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'meta' => ['nullable', 'array'],
        ];
    }

    public function mapPayload(array $validated, LeadIntegration $integration): array
    {
        return [
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'],
            'notes' => $validated['notes'] ?? null,
            'meta' => array_merge($validated['meta'] ?? [], [
                'integration' => $integration->id,
                'driver' => $integration->driver->value,
            ]),
        ];
    }
}
