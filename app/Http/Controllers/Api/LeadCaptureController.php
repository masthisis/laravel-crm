<?php

namespace App\Http\Controllers\Api;

use App\Enums\IntegrationDriver;
use App\Models\LeadIntegration;
use App\Services\Capture\CaptureChannelRegistry;
use App\Services\Capture\CaptureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LeadCaptureController extends Controller
{
    public function __construct(
        protected CaptureChannelRegistry $registry,
        protected CaptureService $capture,
    ) {}

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

        $channel = $this->registry->for($integration->driver);

        $validated = $request->validate($channel->rules());

        $outcome = $this->capture->capture($integration, $channel->mapPayload($validated, $integration));

        return $this->respond($request, $outcome['status'], $outcome['payload']);
    }

    protected function resolveIntegration(Request $request): ?LeadIntegration
    {
        $token = $request->input('token')
            ?? $request->header('X-Integration-Token')
            ?? $this->bearerToken($request);

        if (blank($token)) {
            // Embedded forms identify by id: the form URL is public, so
            // the id carries no additional secret (tokens are hashed at
            // rest and only form_embed integrations resolve this way).
            $integrationId = $request->input('integration_id');

            if ($integrationId !== null) {
                return LeadIntegration::query()
                    ->whereKey($integrationId)
                    ->where('driver', IntegrationDriver::FormEmbed->value)
                    ->first();
            }

            return null;
        }

        return LeadIntegration::query()->where('token', hash('sha256', (string) $token))->first();
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
