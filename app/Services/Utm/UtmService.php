<?php

namespace App\Services\Utm;

use App\Models\SourceAttribution;
use App\Models\UtmLink;
use App\Models\UtmRecord;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * UTM attribution service (spec 08). Capture itself stays with the
 * site/PWA; the CRM receives records and attributions and reads them.
 */
class UtmService
{
    /** Ignored referrers: payment-gateway and sign-in returns (08 §3). */
    public const IGNORED_REFERRERS = [
        'vafadar.io', // DirectPay
        'payping.ir', 'sep.ir', 'zarinpal.com', 'bazarpay.ir', 'myket.ir',
    ];

    /** @var list<string> search engines (hosts, matched by prefix). */
    public const SEARCH_ENGINES = ['google.', 'bing.com', 'yandex.', 'duckduckgo.com', 'yahoo.com', 'search.msn.com'];

    /** @var list<string> social sites. */
    public const SOCIAL_HOSTS = [
        't.me', 'telegram.me', 'instagram.com', 'facebook.com', 't.co', 'x.com', 'twitter.com',
        'aparat.com', 'eitaa.com', 'rubika.ir', 'bale.ai', 'whatsapp.com', 'youtube.com', 'linkedin.com',
    ];

    /**
     * Classification order (08 §3): campaign → internal → ignored
     * gateways → search → social → referral → direct.
     *
     * @return array{channel: string, source: string|null}
     */
    public static function classify(?string $referrerHost, ?string $landingUrl): array
    {
        $landing = parse_url((string) $landingUrl, PHP_URL_HOST);
        // Accept full URLs or bare hosts (with paths) in the referrer.
        $referrerInput = (string) $referrerHost;
        $host = parse_url($referrerInput, PHP_URL_HOST);
        if ($host === null || $host === false) {
            $host = str_contains($referrerInput, '/') ? parse_url('http://'.$referrerInput, PHP_URL_HOST) : $referrerInput;
        }
        $referrerHost = strtolower((string) $host);

        if (! empty($landingUrl) && str_contains((string) $landingUrl, 'utm_')) {
            return ['channel' => 'campaign', 'source' => null];
        }

        if ($referrerHost === '' || $referrerHost === '0') {
            return ['channel' => 'direct', 'source' => null];
        }

        if ($landing !== null && str_contains($referrerHost, strtolower((string) $landing))) {
            return ['channel' => 'internal', 'source' => null];
        }

        foreach (self::IGNORED_REFERRERS as $ignored) {
            if (str_contains($referrerHost, $ignored)) {
                return ['channel' => 'direct', 'source' => null]; // not an arrival
            }
        }

        foreach (self::SEARCH_ENGINES as $engine) {
            if (str_starts_with($referrerHost, $engine)) {
                return ['channel' => 'search', 'source' => $referrerHost];
            }
        }

        foreach (self::SOCIAL_HOSTS as $social) {
            if (str_contains($referrerHost, $social)) {
                return ['channel' => 'social', 'source' => $referrerHost];
            }
        }

        // Referral with the registrable domain as the source.
        return ['channel' => 'referral', 'source' => self::registrableDomain($referrerHost)];
    }

    /** The registrable domain of a host (last two labels). */
    public static function registrableDomain(string $host): string
    {
        $parts = explode('.', $host);
        if (count($parts) <= 2) {
            return $host;
        }

        return implode('.', array_slice($parts, -2));
    }

    /** Page dimensions: the first path segment is the section (08 §3). */
    public static function pageSection(string $path): array
    {
        $path = '/'.ltrim($path, '/');
        if ($path === '/') {
            return ['section' => 'home', 'category' => null, 'post' => null];
        }

        $segments = explode('/', trim($path, '/'));
        $section = $segments[0];

        $blogSections = ['cryptocurrency', 'bours', 'financial-market', 'news', 'saham'];
        if (in_array($section, $blogSections, true)) {
            return ['section' => $section, 'category' => ($segments[1] ?? null), 'post' => ($segments[2] ?? null)];
        }

        if (count($segments) >= 2 && $section !== 'other') {
            return ['section' => $section, 'category' => $segments[1], 'post' => null];
        }

        return ['section' => $section, 'category' => null, 'post' => null];
    }

    /**
     * Identify a visitor: signup/login after OTP or password flows
     * (08 §2). Only unassigned rows are updated; the first identity wins.
     */
    public function identify(string $visitorTokenHash, ?int $userId, string $type): void
    {
        UtmRecord::query()
            ->where('visitor_token_hash', $visitorTokenHash)
            ->whereNull('identification_type')
            ->update([
                'user_id' => $userId,
                'identification_type' => $type,
                'identified_at' => now(),
            ]);
    }

    /**
     * identifyContact: a phone form identifies the visitor's anonymous
     * UTM visits (08 §2). contact_origin = form, contact_ref = row id.
     */
    public function identifyContact(string $phone, string $type, int|string $ref): void
    {
        $canonical = PhoneNormalizer::canonical($phone);
        if ($canonical === null) {
            return;
        }

        UtmRecord::query()
            ->whereNull('identification_type')
            ->update([
                'phone' => $canonical,
                'identification_type' => $type,
                'identified_at' => now(),
                'contact_origin' => 'form',
                'contact_ref' => (string) $ref,
            ]);
    }

    /**
     * attributeLead (08 §3): one source_attribution row per consult or
     * footer lead, unique per (origin_table, origin_id), INSERT IGNORE
     * semantics — a failed write is not fatal.
     */
    public function attributeLead(string $originTable, int $originId, string $form, string $phone, array $visit = [], array $firstTouch = [], array $lastNonDirect = []): ?SourceAttribution
    {
        $canonical = PhoneNormalizer::canonical($phone);
        if ($canonical === null) {
            return null;
        }

        $exists = SourceAttribution::query()
            ->where('origin_table', $originTable)
            ->where('origin_id', $originId)
            ->exists();

        if ($exists) {
            return null;
        }

        return SourceAttribution::create([
            'origin_table' => $originTable,
            'origin_id' => $originId,
            'form' => $form,
            'phone' => $canonical,
            'event_at' => $visit['at'] ?? now(),
            'event_host' => $visit['host'] ?? null,
            'event_path' => $visit['path'] ?? null,
            'vt_channel' => $visit['channel'] ?? 'unknown',
            'vt_source' => $visit['source'] ?? null,
            'vt_referrer_host' => $visit['referrer_host'] ?? null,
            'vt_host' => $visit['host'] ?? null,
            'vt_section' => $visit['section'] ?? null,
            'vt_category' => $visit['category'] ?? null,
            'vt_post' => $visit['post'] ?? null,
            'vt_landing_path' => $visit['path'] ?? null,
            'vt_at' => $visit['at'] ?? null,
            'ft_channel' => $firstTouch['channel'] ?? null,
            'ft_source' => $firstTouch['source'] ?? null,
            'ft_at' => $firstTouch['at'] ?? null,
            'lnd_channel' => $lastNonDirect['channel'] ?? null,
            'lnd_source' => $lastNonDirect['source'] ?? null,
            'lnd_at' => $lastNonDirect['at'] ?? null,
        ]);
    }

    /** Automatic link registration, deduped by the normalized UTM values. */
    public function registerAutomaticLink(array $utm, string $destinationUrl, string $displayName): UtmLink
    {
        $key = 'auto:'.md5(implode('|', [
            parse_url($destinationUrl, PHP_URL_HOST) ?: '',
            parse_url($destinationUrl, PHP_URL_PATH) ?: '',
            $utm['utm_source'] ?? '', $utm['utm_medium'] ?? '', $utm['utm_campaign'] ?? '',
            $utm['utm_term'] ?? '', $utm['utm_content'] ?? '', $utm['utm_id'] ?? '',
        ]));

        return UtmLink::query()->firstOrCreate(
            ['automatic_key' => $key],
            [
                'public_token' => Str::random(32),
                'automatic_key' => $key,
                'display_name' => $displayName,
                'destination_url' => $destinationUrl,
                'destination_identity' => (parse_url($destinationUrl, PHP_URL_HOST) ?: '').(parse_url($destinationUrl, PHP_URL_PATH) ?: ''),
                'generated_url' => $destinationUrl.(str_contains($destinationUrl, '?') ? '&' : '?').'utm_link_id='.Str::random(32),
                'utm_source' => $utm['utm_source'] ?? null,
                'utm_medium' => $utm['utm_medium'] ?? null,
                'utm_campaign' => $utm['utm_campaign'] ?? null,
                'utm_term' => $utm['utm_term'] ?? null,
                'utm_content' => $utm['utm_content'] ?? null,
                'utm_id' => $utm['utm_id'] ?? null,
                'origin' => 'automatic',
            ],
        );
    }

    /** Link the CRM account to a phone after account.created events. */
    public function linkAccount(string $phone, int $customerId): void
    {
        $canonical = PhoneNormalizer::canonical($phone);
        if ($canonical === null) {
            return;
        }

        UtmRecord::query()
            ->where('phone', $canonical)
            ->whereNull('user_id')
            ->update(['user_id' => $customerId]);
    }

    /** Tehran-time engagement timestamp for a UTM record (05 §8). */
    public static function engagementAt(UtmRecord $record): Carbon
    {
        return Carbon::parse($record->created_at)->timezone(config('crm.lead_service.timezone', 'Asia/Tehran'));
    }
}
