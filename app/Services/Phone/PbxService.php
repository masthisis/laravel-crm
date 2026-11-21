<?php

namespace App\Services\Phone;

use App\Models\CrmCallLog;
use App\Models\User;
use App\Support\Persian;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PBX integration (spec 04 §5): call webhooks in, click-to-call and CDR
 * verification out through the bridge. Every value sent to the bridge is
 * encrypted; the key comes from the environment, never the repo.
 */
class PbxService
{
    public function bridgeEnabled(): bool
    {
        return config('crm.pbx.bridge') !== null && config('crm.pbx.bridge') !== '';
    }

    /**
     * FixNumber (01 §1.1 / 11 #17): for call-display only. Mobiles go
     * through the canonical normalizer; landlines keep the area code.
     */
    public static function fixNumber(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $canonical = PhoneNormalizer::canonical($raw);
        if ($canonical !== null) {
            return $canonical;
        }

        // Landline: 10 digits whose first 2 digits are an Iranian area
        // code (without the 0), or an 8-digit Tehran local number.
        $digits = preg_replace('/\D/', '', Persian::digits(trim($raw)) ?? '') ?? '';

        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) === 10 && in_array(substr($digits, 0, 2), self::areaCodes(), true)) {
            return $digits;
        }

        if (strlen($digits) === 8) {
            return $digits;
        }

        return $digits !== '' ? $digits : null;
    }

    /** @return list<string> Iranian area codes without the leading 0 (rayan_codeintercity). */
    public static function areaCodes(): array
    {
        return [
            '21', '26', '31', '41', '44', '51', '54', '56', '58', '61',
            '66', '71', '76', '77', '83', '84', '86', '87', '11', '13',
            '14', '15', '16', '17', '18', '19', '20', '23', '25', '28',
            '29', '32', '33', '34', '35', '37', '38', '39', '42', '45',
        ];
    }

    /** Log an incoming/outgoing call (04 §5.1) — deduped per extension. */
    public static function logCall(string $action, string $number, string $exten, string $uniqueid): ?CrmCallLog
    {
        $direction = str_contains($action, 'Outgoing') ? 'outgoing' : 'incoming';
        $phone = self::fixNumber($number);

        if ($phone === null || mb_strlen($phone) <= (str_contains($action, 'Outgoing') ? 5 : 3)) {
            return null;
        }

        $existing = CrmCallLog::query()
            ->where('uniqueid', $uniqueid)
            ->where('exten', $exten)
            ->where('type', $direction)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return CrmCallLog::create([
            'phone' => $phone,
            'phone_raw' => $number,
            'exten' => $exten,
            'type' => $direction,
            'uniqueid' => $uniqueid,
            'accepted_call' => $direction === 'outgoing' ? $exten : null,
            'called_at' => now(),
        ]);
    }

    /** Click-to-call (04 §5.2): POST CallFromCrm through the bridge. */
    public function callFromCrm(User $operator, string $number, ?string $name = null): array
    {
        if (! $this->bridgeEnabled()) {
            return ['ok' => false, 'error' => 'PBX bridge is not configured (CRM_PBX_BRIDGE)'];
        }

        $payload = [
            'action' => 'CallFromCrm',
            'ext' => $this->encrypt((string) $operator->operatorCode()),
            'number' => $this->encrypt($number),
            'name' => $this->encrypt($name ?? ''),
        ];

        try {
            $response = Http::asForm()->timeout(10)->post(config('crm.pbx.bridge'), $payload);

            return ['ok' => $response->successful(), 'body' => $response->body()];
        } catch (\Throwable $e) {
            Log::warning('Click-to-call failed', ['error' => $e->getMessage()]);

            return ['ok' => false, 'error' => 'bridge_unreachable'];
        }
    }

    /** CDR verification cron (04 §5.5) — returns rows verified. */
    public function verifyCdr(int $minutes = 40): int
    {
        if (! $this->bridgeEnabled()) {
            return 0;
        }

        $rows = CrmCallLog::query()
            ->where('cdr_verify', false)
            ->where('called_at', '>=', now()->subMinutes($minutes))
            ->whereNotNull('phone')
            ->limit(200)
            ->get();

        $verified = 0;
        foreach ($rows as $row) {
            try {
                $response = Http::asForm()->timeout(10)->post(config('crm.pbx.bridge'), [
                    'action' => 'CdrVerify',
                    'uid' => $this->encrypt($row->uniqueid),
                    'exten' => $this->encrypt((string) $row->exten),
                    'phone' => $this->encrypt((string) $row->phone_raw),
                ]);

                $data = $response->json();
                if (is_array($data) && isset($data['calldate'])) {
                    $row->fill([
                        'call_time' => (int) ($data['duration'] ?? 0),
                        'status_asterisk' => $data['disposition'] ?? null,
                        'cdr_verify' => true,
                    ])->save();
                    $verified++;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $verified;
    }

    /** AES-256-CBC with a random IV — the legacy Password::encrypt shape. */
    private function encrypt(string $value): string
    {
        $key = base64_decode((string) config('crm.pbx.key'), true) ?: hash('sha256', (string) config('crm.pbx.key'), true);
        $key = substr($key, 0, 32);
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($value, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        return base64_encode($iv.$cipher);
    }
}
