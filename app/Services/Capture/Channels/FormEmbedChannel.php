<?php

namespace App\Services\Capture\Channels;

use App\Enums\IntegrationDriver;

/**
 * The embeddable iframe form is a webhook channel with the same payload
 * shape — it only differs in response rendering (handled by the
 * controller for form submissions).
 */
class FormEmbedChannel extends WebhookChannel
{
    public function driver(): IntegrationDriver
    {
        return IntegrationDriver::FormEmbed;
    }
}
