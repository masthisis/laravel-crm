<?php

namespace App\Http\Controllers\Api;

use App\Events\IncomingCall;
use App\Models\CrmCallLog;
use App\Models\Customer;
use App\Models\User;
use App\Services\Phone\PbxService;
use App\Support\Jalali;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PBX webhooks + desktop/mobile lookup APIs (spec 04 §5.1, §5.6).
 *
 * Fixes over the legacy endpoints (11 #2, #3): every call event requires
 * a shared secret (header X-Pbx-Token or ?token=), and the desktop /
 * mobile lookups use API tokens instead of query-string credentials.
 */
class PbxController extends Controller
{
    /** PBX call events: IncomingCallEvent / OutgoingCallEvent / IncomingCallAcceptedEvent / IncomingCallHangUpEvent. */
    public function service(Request $request, PbxService $pbx): JsonResponse
    {
        $token = (string) ($request->header('X-Pbx-Token') ?? $request->query('token', ''));
        $secret = (string) config('crm.pbx.secret');

        if ($secret === '' || ! hash_equals($secret, $token)) {
            return response()->json(['status' => false, 'error' => 'unauthorized'], 401);
        }

        $action = (string) $request->query('action', '');
        $exten = (string) $request->query('exten', '');
        $uniqueid = (string) $request->query('uniqueid', '');

        switch ($action) {
            case 'IncomingCallEvent':
            case 'OutgoingCallEvent':
                $number = (string) $request->query('number', '');
                $row = PbxService::logCall($action, $number, $exten, $uniqueid);

                // Push the live notification (04 §5.3 — event-driven, not 30 s polling).
                if ($row !== null && $row->type === 'incoming') {
                    broadcast(new IncomingCall($row))->toOthers();
                }

                return response()->json(['status' => true, 'id' => $row?->id]);

            case 'IncomingCallAcceptedEvent':
                CrmCallLog::query()
                    ->where('exten', $exten)->where('uniqueid', $uniqueid)
                    ->update(['accepted_call' => $exten]);

                return response()->json(['status' => true]);

            case 'IncomingCallHangUpEvent':
                CrmCallLog::query()
                    ->where('exten', $exten)->where('uniqueid', $uniqueid)
                    ->update(['hangup' => $exten]);

                return response()->json(['status' => true]);

            default:
                return response()->json(['status' => false, 'error' => 'unknown_action'], 422);
        }
    }

    /** Desktop notifier login — token-based (no credentials in the query). */
    public function loginapp(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null || $user->operatorCode() === null) {
            return response()->json(['status' => false]);
        }

        return response()->json([
            'status' => true,
            'name' => $user->name,
            'exten' => $user->operatorCode(),
            'username' => $user->email,
        ]);
    }

    /** Desktop notifier lookup — token-based (04 §5.6). */
    public function notification(Request $request): JsonResponse
    {
        $phone = PbxService::fixNumber((string) $request->query('phone', ''));
        if ($phone === null || $phone === '0') {
            return response()->json(['status' => false]);
        }

        /** @var ?Customer $customer */
        $customer = Customer::findByCanonicalPhone($phone);

        if ($customer === null) {
            return response()->json([
                'status' => true,
                'user_id' => null,
                'namefamily' => 'نامشخص',
                'phone' => $phone,
                'url' => url('/agent/customers?search='.$phone),
            ]);
        }

        $lastCall = CrmCallLog::query()
            ->where('phone', $phone)
            ->whereNotNull('accepted_call')
            ->where('accepted_call', '!=', '')
            ->orderByDesc('called_at')
            ->first();

        $lastOperator = $lastCall !== null
            ? User::query()->where('exten', $lastCall->accepted_call)->value('name')
            : null;

        return response()->json([
            'status' => true,
            'user_id' => $customer->id,
            'date' => Jalali::format($customer->created_at),
            'last_call' => $lastOperator ?? false,
            'namefamily' => trim((string) $customer->name) ?: 'نامشخص',
            'phone' => $phone,
            'url' => url('/agent/customers/'.$customer->id),
        ]);
    }
}
