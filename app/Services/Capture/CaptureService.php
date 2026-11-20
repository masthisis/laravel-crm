<?php

namespace App\Services\Capture;

use App\Enums\LeadStatus;
use App\Events\DuplicateCaptureIgnored;
use App\Events\LeadCaptured;
use App\Events\LeadReengaged;
use App\Models\Lead;
use App\Models\LeadIntegration;

/**
 * Channel-agnostic lead capture: idempotency for duplicates, automatic
 * re-engagement detection across sources, event dispatching. The
 * controller (or any queue job) supplies the integration and the
 * channel-mapped attributes.
 */
class CaptureService
{
    /**
     * @param  array<string, mixed>  $attributes  Channel-mapped lead attributes
     * @return array{status: int, payload: array<string, mixed>}
     */
    public function capture(LeadIntegration $integration, array $attributes): array
    {
        $source = $integration->source;

        // Re-engagement: if an open lead with the same phone already
        // exists from ANOTHER source, flag the new source as the
        // re-engagement channel instead of creating a duplicate.
        $existing = Lead::query()
            ->where('phone', $attributes['phone'])
            ->whereNotIn('status', [LeadStatus::Won->value, LeadStatus::Lost->value])
            ->latest()
            ->first();

        if ($existing !== null && (int) $existing->source_id !== (int) $source->id) {
            $existing->update([
                're_engaged_source_id' => $source->id,
                're_engaged_at' => now(),
                'status' => LeadStatus::ReEngaged,
                'email' => $existing->email ?? ($attributes['email'] ?? null),
            ]);

            LeadReengaged::dispatch($existing, $integration);

            return [
                'status' => 200,
                'payload' => [
                    'message' => 'Welcome back! Your request was recorded.',
                    'data' => [
                        'id' => $existing->id,
                        'status' => $existing->status->value,
                        're_engaged' => true,
                    ],
                ],
            ];
        }

        if ($existing !== null) {
            // Same source, already an open lead — idempotent response.
            DuplicateCaptureIgnored::dispatch($existing, $integration);

            return [
                'status' => 200,
                'payload' => [
                    'message' => 'You are already in our system. We will contact you soon!',
                    'data' => [
                        'id' => $existing->id,
                        'status' => $existing->status->value,
                        'duplicate' => true,
                    ],
                ],
            ];
        }

        $lead = Lead::create([
            ...$attributes,
            'source_id' => $source->id,
            'status' => LeadStatus::New,
        ]);

        LeadCaptured::dispatch($lead, $integration);

        return [
            'status' => 201,
            'payload' => [
                'message' => 'Thank you! We will contact you shortly.',
                'data' => [
                    'id' => $lead->id,
                    'status' => $lead->status->value,
                ],
            ],
        ];
    }
}
