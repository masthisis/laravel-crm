<?php

namespace App\Services\Capture\Channels;

use App\Enums\IntegrationDriver;

/**
 * Manually created leads (admin panel) — validation happens in the
 * Filament form, so this channel accepts anything.
 */
class ManualChannel extends WebhookChannel
{
    public function driver(): IntegrationDriver
    {
        return IntegrationDriver::Manual;
    }

    public function rules(): array
    {
        return [];
    }
}
