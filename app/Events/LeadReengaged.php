<?php

namespace App\Events;

use App\Models\Lead;
use App\Models\LeadIntegration;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a known open lead comes back through a DIFFERENT source —
 * the lead is flagged as re-engaged instead of duplicated.
 */
class LeadReengaged
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Lead $lead,
        public LeadIntegration $integration,
    ) {}
}
