<?php

namespace App\Events;

use App\Models\CrmCallLog;
use App\Models\Customer;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Live incoming-call notification (spec 04 §5.3): pushed the moment the
 * PBX webhook lands, replacing the legacy 30 s SSE poll that missed most
 * calls (bug 11 #18).
 */
class IncomingCall implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public readonly CrmCallLog $call) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [
            new Channel('calls.exten.'.$this->call->exten),
        ];
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        $customer = Customer::findByCanonicalPhone($this->call->phone ?? '');
        $url = $customer !== null
            ? url('/agent/customers/'.$customer->id)
            : url('/agent/register/'.$this->call->phone);

        return [
            'id' => $this->call->id,
            'phone' => $this->call->phone,
            'exten' => $this->call->exten,
            'namefamily' => $customer !== null ? trim(($customer->name ?? '').' ') ?: 'نامشخص' : 'ناشناس',
            'user_id' => $customer?->id,
            'url' => $url,
        ];
    }
}
