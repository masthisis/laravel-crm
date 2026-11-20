# Capture API

All external systems push leads into the CRM through **capture integrations**. Create one in the admin panel (Sources → Integrations), pick the source it feeds, copy the token, and you have two ways in:

- **Webhook** — `POST /api/leads`
- **Embedded form** — an iframe served at `/embed/leads/{integration}`

## Webhook

```bash
curl -X POST https://your-crm.example.com/api/leads \
  -H "X-Integration-Token: <token>" \
  -H "Content-Type: application/json" \
  -d '{"name": "Jane Doe", "phone": "+15551234567", "email": "jane@example.com", "meta": {"page": "/pricing"}}'
```

The token is accepted in the `X-Integration-Token` header, as a `token` body field, or as a Bearer token. Rate limit: 30 req/min per client (`CRM_CAPTURE_THROTTLE`).

Responses:

| Situation | Status | Body |
| --- | --- | --- |
| New lead captured | `201` | `{message, data: {id, status: "new"}}` |
| Known lead returns via a *different* source | `200` | `data.re_engaged: true` — the existing lead is flagged and its source chain updated |
| Same source, same phone, still open | `200` | `data.duplicate: true` (idempotent, no new row) |
| Invalid / inactive token | `404` | — |

## Embedded form

```html
<iframe src="https://your-crm.example.com/embed/leads/{integration}"
        style="width:100%;border:0" title="Contact us"></iframe>
```

A name + phone form styled for embedding (e.g. inside a blog article). Submitting it posts to the same endpoint and renders a success state.

## Events

Every capture dispatches domain events — register listeners in `EventServiceProvider`-style closures or queued listeners:

| Event | Fired when |
| --- | --- |
| `App\Events\LeadCaptured` | A brand-new lead was created |
| `App\Events\LeadReengaged` | An open lead returned through a different source |
| `App\Events\DuplicateCaptureIgnored` | A same-source duplicate was ignored |

Each event carries `$lead` and `$integration`.

## Adding a capture channel (developers)

Channels are pluggable adapters — a new channel does **not** touch the controller or the service:

1. Implement `App\Contracts\LeadChannel`:

```php
namespace App\Services\Capture\Channels;

use App\Contracts\LeadChannel;
use App\Enums\IntegrationDriver;
use App\Models\LeadIntegration;

class SmsGatewayChannel implements LeadChannel
{
    public function driver(): IntegrationDriver
    {
        return IntegrationDriver::from('sms_gateway'); // add the case to the enum
    }

    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:32'],
            'text' => ['required', 'string', 'max:1000'],
        ];
    }

    public function mapPayload(array $validated, LeadIntegration $integration): array
    {
        return [
            'name' => 'SMS from '.$validated['phone'],
            'phone' => $validated['phone'],
            'notes' => $validated['text'],
            'meta' => ['integration' => $integration->id, 'driver' => 'sms_gateway'],
        ];
    }
}
```

2. Register it from any service provider:

```php
use App\Services\Capture\CaptureChannelRegistry;

$this->app->afterResolving(CaptureChannelRegistry::class,
    fn ($registry) => $registry->extend(SmsGatewayChannel::class));
```

3. Point an integration at the driver (add the enum case if needed) and start posting. Unregistered drivers fall back to the generic webhook shape.

Built-in channels: `WebhookChannel`, `FormEmbedChannel`, `ManualChannel` — see `app/Services/Capture/Channels/`.

## External database sync

For pulling (instead of pushing) from other databases, see `crm:sync-external-leads` in [RUNNING.md](RUNNING.md) and `config/crm.php` (`external_sync`).
