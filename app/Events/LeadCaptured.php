<?php

namespace App\Events;

use App\Models\Lead;
use App\Models\LeadIntegration;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a brand-new lead enters the CRM through a capture channel.
 */
class LeadCaptured
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Lead $lead,
        public LeadIntegration $integration,
    ) {}
}
