<?php

namespace App\Contracts;

use App\Enums\IntegrationDriver;
use App\Models\LeadIntegration;

/**
 * A capture channel knows how leads arrive for one integration driver.
 * Implement this interface, register it in CaptureChannelRegistry (see
 * AppServiceProvider), and point an integration at your driver — no
 * core files change.
 */
interface LeadChannel
{
    /** The integration driver this channel handles. */
    public function driver(): IntegrationDriver;

    /** Laravel validation rules for the inbound payload. */
    public function rules(): array;

    /**
     * Map the validated payload to lead attributes (name, phone, email,
     * notes, meta…). The integration and its source are added by the
     * CaptureService.
     *
     * @return array<string, mixed>
     */
    public function mapPayload(array $validated, LeadIntegration $integration): array;
}
