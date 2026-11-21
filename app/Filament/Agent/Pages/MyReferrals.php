<?php

namespace App\Filament\Agent\Pages;

use App\Models\CrmReferral;
use App\Models\CrmSmsMessage;
use App\Models\Customer;
use App\Models\LeadContact;
use App\Services\Leads\EngagementService;
use App\Services\Leads\ReferralService;
use App\Services\Phone\PbxService;
use App\Services\Phone\WorkspaceService;
use App\Support\Jalali;
use App\Support\Persian;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

/**
 * «ارجاع روز» — the operator's main work queue (spec 04 §3.5): the
 * latest open referral per phone for my extension, with lead-type
 * badges, engagement tags and the commission badge. Date and phone
 * filters are actually applied (bug 11 #9 fixed).
 */
class MyReferrals extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static ?string $navigationGroup = 'کارتابل فروش';

    protected static ?string $title = 'ارجاع روز';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.agent.pages.my-referrals';

    public ?string $date_start = null;

    public ?string $date_end = null;

    public ?string $phone = null;

    public array $rows = [];

    /** «عدم خرید» dialog state (course + reason, per 04 §3.5). */
    public ?int $reject_id = null;

    public string $reject_course = '';

    public string $reject_reason = '';

    /** SMS dialog state. */
    public ?int $sms_id = null;

    public string $sms_text = '';

    public int $sms_parts = 1;

    public function mount(): void
    {
        $this->date_start = now()->subDays(30)->toDateString();
        $this->date_end = now()->toDateString();
        $this->loadRows();
    }

    public function loadRows(): void
    {
        $exten = auth()->user()->operatorCode();
        if ($exten === null) {
            $this->rows = [];

            return;
        }

        // Latest open referral per phone for my extension (ROW_NUMBER semantics).
        $referrals = CrmReferral::query()
            ->where('reagent_code', $exten)
            ->where('status', CrmReferral::STATUS_OPEN)
            ->whereIn('id', function ($query) use ($exten) {
                $query->from('crm_referrals')
                    ->selectRaw('MAX(id)')
                    ->where('reagent_code', $exten)
                    ->where('status', CrmReferral::STATUS_OPEN)
                    ->groupBy('phone');
            })
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        // Date + search filters — applied for real (bug 11 #9 fixed).
        if ($this->date_start !== null && $this->date_start !== '') {
            $start = (Jalali::parse($this->date_start) ?? Carbon::parse($this->date_start))->startOfDay();
            $referrals = $referrals->filter(static fn (CrmReferral $r) => $r->referred_at !== null && $r->referred_at->gte($start));
        }

        if ($this->date_end !== null && $this->date_end !== '') {
            $end = (Jalali::parse($this->date_end) ?? Carbon::parse($this->date_end))->endOfDay();
            $referrals = $referrals->filter(static fn (CrmReferral $r) => $r->referred_at !== null && $r->referred_at->lte($end));
        }

        if ($this->phone !== null && $this->phone !== '') {
            $needle = (string) Persian::digits($this->phone);
            $referrals = $referrals->filter(static fn ($r) => str_contains($r->phone, $needle));
        }

        $engagement = app(EngagementService::class);

        $this->rows = $referrals->map(function (CrmReferral $referral) use ($engagement) {
            $contact = LeadContact::query()->where('phone', $referral->phone)->first();
            $customer = $contact?->customer_id !== null ? Customer::find($contact->customer_id) : null;
            $source = $referral->source;
            $context = $referral->context;
            $tags = $contact !== null ? $engagement->profile($contact) : ['first' => null, 'latest' => null];

            return [
                'id' => $referral->id,
                'phone' => $referral->phone,
                'name' => $referral->name ?? $contact?->name,
                'has_customer' => $customer !== null,
                'customer_url' => $customer !== null ? '/agent/customers/'.$customer->id : null,
                'register_url' => '/agent/register/'.$referral->phone,
                'lead_type_label' => $source !== null ? $source->name : self::leadTypeLabel($referral->lead_type),
                'badge_color' => $source !== null ? $source->color : '#64748B',
                'badge_text' => $source !== null ? $source->badgeTextColor() : '#fff',
                'commission' => $source !== null ? $source->commission_percent : null,
                'description' => $referral->description,
                'referred_at' => $referral->referred_at,
                'first_tag' => $context?->first_label !== null
                    ? ['label' => $context->first_label, 'at' => $context->first_at, 'inferred' => $context->status === 'inferred']
                    : ($tags['first'] !== null ? ['label' => $tags['first']['label'], 'at' => $tags['first']['at'], 'inferred' => false] : null),
                'latest_tag' => $context?->latest_label !== null
                    ? ['label' => $context->latest_label, 'at' => $context->latest_at]
                    : ($tags['latest'] !== null ? ['label' => $tags['latest']['label'], 'at' => $tags['latest']['at']] : null),
                'trigger' => $context?->trigger_label,
            ];
        })->values()->all();
    }

    /** Hard-coded lead-type badges (04 §3.5). */
    public static function leadTypeLabel(string $leadType): string
    {
        return match ($leadType) {
            'app_moshavare' => 'درخواست مشاوره اپلیکیشن',
            'app_not_pay' => 'پرداخت نشده اپلیکیشن',
            'app_not_have' => 'اپلیکیشن ندارند',
            'cryptopumpt_link' => 'کریپتو پامپ',
            'lead_webinar' => 'وبینار',
            'lead_from_admin' => 'ثبت شده توسط ادمین',
            'active_latest_lead' => '۲ ماه گذشته روی سایت اکتیو بودن',
            'app_users_lead' => 'لید تمدید کاربران',
            'unpaid_invoice' => 'فاکتور پرداخت‌نشده',
            'dex_gheyrehozoori' => 'دکس غیرحضوری',
            default => $leadType,
        };
    }

    /** Open the «عدم خرید» dialog. */
    public function openReject(int $referralId): void
    {
        $this->reject_id = $referralId;
        $this->reject_course = '';
        $this->reject_reason = '';
    }

    /** «عدم خرید»: close as lost (04 §3.5) with ownership enforced. */
    public function confirmReject(): void
    {
        if ($this->reject_id === null || $this->reject_course === '' || $this->reject_reason === '') {
            return;
        }

        try {
            app(ReferralService::class)->reject(
                $this->reject_id,
                $this->reject_course,
                $this->reject_reason,
                auth()->user(),
            );
        } catch (\RuntimeException $e) {
            $this->dispatch('toast', message: $e->getMessage());
        }

        $this->reject_id = null;
        $this->loadRows();
    }

    /** Open the SMS dialog with the template pre-filled (04 §3.5). */
    public function openSms(int $referralId, int $template = 1, string $code = '', string $link = ''): void
    {
        $referral = CrmReferral::query()->find($referralId);
        if ($referral === null) {
            return;
        }

        $templates = WorkspaceService::smsTemplates(auth()->user()->name);
        $this->sms_text = str_replace(
            ['{code}', '{link}'],
            [$code, $link],
            $templates[$template]['body'] ?? '',
        );
        $this->sms_id = $referralId;
        $this->sms_parts = CrmSmsMessage::partsFor($this->sms_text);
    }

    public function updatedSmsText(): void
    {
        $this->sms_parts = CrmSmsMessage::partsFor($this->sms_text);
    }

    /** «ارسال پیام»: the server handler the legacy UI never had (11 #11). */
    public function sendSms(): void
    {
        if ($this->sms_id === null || trim($this->sms_text) === '') {
            return;
        }

        app(WorkspaceService::class)->sendSms(
            (string) (CrmReferral::query()->whereKey($this->sms_id)->value('phone') ?? ''),
            $this->sms_text,
            auth()->user(),
            $this->sms_id,
        );

        $this->sms_id = null;
        $this->sms_text = '';
        $this->loadRows();
    }

    /** Click-to-call through the PBX bridge (04 §5.2). */
    public function dial(string $phone): void
    {
        $result = app(PbxService::class)->callFromCrm(
            auth()->user(),
            $phone,
            LeadContact::query()->where('phone', $phone)->value('name'),
        );

        if (! ($result['ok'] ?? false)) {
            $this->dispatch('toast', message: 'تماس برقرار نشد: '.($result['error'] ?? 'خطای پل تلفن'));
        }
    }
}
