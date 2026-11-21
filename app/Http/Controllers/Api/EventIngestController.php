<?php

namespace App\Http\Controllers\Api;

use App\Services\Intake\EventIntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Event receiver (spec 03 §1–3): POST /api/events with an HMAC
 * signature header `X-Signature: sha256=<hex of HMAC(secret, body)>`.
 */
class EventIngestController extends Controller
{
    public function store(Request $request, EventIntakeService $intake): JsonResponse
    {
        $secret = (string) config('crm.events.secret');
        $body = $request->getContent();

        if ($secret === '') {
            return response()->json(['error' => ['code' => 'NOT_CONFIGURED', 'message' => 'CRM_EVENT_SECRET is not set']], 503);
        }

        $signature = (string) $request->header('X-Signature', '');
        $expected = 'sha256='.hash_hmac('sha256', $body, $secret);

        if (! hash_equals($expected, $signature)) {
            return response()->json(['error' => ['code' => 'BAD_SIGNATURE', 'message' => 'Invalid signature']], 401);
        }

        $event = json_decode($body, true);
        if (! is_array($event)) {
            return response()->json(['error' => ['code' => 'BAD_JSON', 'message' => 'Body must be a JSON event envelope']], 422);
        }

        $result = $intake->accept($event);

        return response()->json([
            'status' => $result['status'],
            'event_id' => $event['event_id'] ?? null,
            'result' => $result['result'],
        ], $result['status']);
    }

    /** Tiny helper for senders: sign a payload the same way. */
    public static function signature(string $body, string $secret): string
    {
        return 'sha256='.hash_hmac('sha256', $body, $secret);
    }
}
