<?php

namespace App\Http\Controllers\Api;

use App\Enums\IntegrationDriver;
use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\LeadIntegration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LeadCaptureController extends Controller
{
    /**
     * Embeddable lead form (rendered inside an iframe on external
     * sites, e.g. a blog article with a consultation form).
     */
    public function show(LeadIntegration $integration)
    {
        abort_unless($integration->is_active, 404);
        abort_unless(in_array($integration->driver, [IntegrationDriver::FormEmbed, IntegrationDriver::Webhook], true), 404);

        return view('embed.lead-form', [
            'integration' => $integration,
            'submitted' => request()->boolean('submitted'),
        ]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $integration = $this->resolveIntegration($request);

        if ($integration === null || ! $integration->is_active) {
            return $this->respond($request, 404, ['message' => 'Invalid or inactive integration token.']);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:32'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'meta' => ['nullable', 'array'],
        ]);

        $source = $integration->source;

        // Re-engagement: if an open lead with the same phone already
        // exists from ANOTHER source, flag the new source as the
        // re-engagement channel instead of creating a duplicate.
        $existing = Lead::query()
            ->where('phone', $validated['phone'])
            ->whereNotIn('status', [LeadStatus::Won->value, LeadStatus::Lost->value])
            ->latest()
            ->first();

        if ($existing !== null && (int) $existing->source_id !== (int) $source->id) {
            $existing->update([
                're_engaged_source_id' => $source->id,
                're_engaged_at' => now(),
                'status' => LeadStatus::ReEngaged,
                'email' => $existing->email ?? ($validated['email'] ?? null),
            ]);

            return $this->respond($request, 200, [
                'message' => 'Welcome back! Your request was recorded.',
                'data' => [
                    'id' => $existing->id,
                    'status' => $existing->status->value,
                    're_engaged' => true,
                ],
            ]);
        }

        if ($existing !== null) {
            // Same source, already an open lead — idempotent response.
            return $this->respond($request, 200, [
                'message' => 'You are already in our system. We will contact you soon!',
                'data' => [
                    'id' => $existing->id,
                    'status' => $existing->status->value,
                    'duplicate' => true,
                ],
            ]);
        }

        $lead = Lead::create([
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'],
            'source_id' => $source->id,
            'status' => LeadStatus::New,
            'notes' => $validated['notes'] ?? null,
            'meta' => array_merge($validated['meta'] ?? [], [
                'integration' => $integration->id,
                'driver' => $integration->driver->value,
            ]),
        ]);

        return $this->respond($request, 201, [
            'message' => 'Thank you! We will contact you shortly.',
            'data' => [
                'id' => $lead->id,
                'status' => $lead->status->value,
            ],
        ]);
    }

    protected function resolveIntegration(Request $request): ?LeadIntegration
    {
        $token = $request->input('token')
            ?? $request->header('X-Integration-Token')
            ?? $this->bearerToken($request);

        if (blank($token)) {
            return null;
        }

        return LeadIntegration::query()->where('token', $token)->first();
    }

    protected function bearerToken(Request $request): ?string
    {
        $header = $request->header('Authorization');

        if ($header !== null && str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }

        return null;
    }

    protected function respond(Request $request, int $status, array $payload): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json($payload, $status);
        }

        $integration = $this->resolveIntegration($request);

        return redirect()
            ->route('leads.embed-form', ['integration' => $integration->id ?? 0])
            ->with('submitted', $status < 300);
    }
}
