<?php

namespace App\Events;

use App\Models\Lead;
use App\Models\LeadIntegration;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a submission is ignored because the same phone already has
 * an open lead from the same source (idempotent capture).
 */
class DuplicateCaptureIgnored
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Lead $lead,
        public LeadIntegration $integration,
    ) {}
}
