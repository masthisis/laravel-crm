<?php

namespace App\Filament\Admin\Pages;

use App\Models\CommissionEarning;
use App\Models\CommissionPayout;
use App\Models\Customer;
use App\Models\User;
use App\Services\Commission\CommissionSyncService;
use App\Support\Jalali;
use App\Support\Persian;
use Filament\Pages\Page;

/**
 * Commission manager page (spec 09 §2.5): per-agent and per-source
 * breakdowns, transactions and the payout modal (one per agent/month).
 */
class CommissionManager extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'پورسانت';

    protected static ?string $title = 'پورسانت فروش';

    protected static ?int $navigationSort = 10;

    protected static string $view = 'filament.admin.pages.commission-manager';

    public string $month = '';

    public array $perAgent = [];

    public array $perSource = [];

    public array $transactions = [];

    public array $payouts = [];

    public ?int $payout_agent = null;

    public string $payout_amount = '';

    public string $payout_paid_on = '';

    public string $payout_reference = '';

    public function mount(): void
    {
        $this->month = Jalali::monthKey(now());
        $this->loadRows();
    }

    public function loadRows(): void
    {
        $earnings = CommissionEarning::query()
            ->when($this->month !== '', fn ($q) => $q->where('month', $this->month))
            ->get();

        $paidByAgent = CommissionPayout::query()
            ->when($this->month !== '', fn ($q) => $q->where('month', $this->month))
            ->get()->groupBy('agent_id')
            ->map(static fn ($rows) => (int) $rows->sum('amount'));

        $this->perAgent = $earnings
            ->groupBy('agent_id')
            ->map(static function ($rows, $agentId) use ($paidByAgent) {
                $earned = (int) $rows->where('status', 'ok')->sum('amount');

                return [
                    'agent' => User::find($agentId)->name ?? '#'.$agentId,
                    'sales' => $rows->count(),
                    'earned' => $earned,
                    'paid' => $paidByAgent[$agentId] ?? 0,
                    'remaining' => $earned - ($paidByAgent[$agentId] ?? 0),
                    'review' => $rows->where('status', 'review')->count(),
                ];
            })
            ->sortByDesc('earned')
            ->values()
            ->all();

        $this->perSource = $earnings
            ->groupBy('source_name')
            ->map(static function ($rows, $source) {
                return [
                    'source' => $source === '' ? 'بدون منبع' : $source,
                    'count' => $rows->count(),
                    'commission' => (int) $rows->sum('amount'),
                ];
            })
            ->sortByDesc('commission')
            ->values()
            ->all();

        $this->transactions = $earnings
            ->sortByDesc('paid_at')
            ->take(100)
            ->map(static fn (CommissionEarning $e) => [
                'customer' => Customer::query()->whereKey($e->customer_id)->value('name') ?? 'ناشناس',
                'code' => $e->discount_code,
                'source' => $e->source_name ?? '—',
                'rate' => Persian::toPersian((string) $e->rate).'٪',
                'paid_amount' => Persian::toman($e->paid_amount),
                'amount' => Persian::toman($e->amount),
                'status' => $e->status === 'ok' ? 'تایید' : 'بررسی',
                'month' => $e->month,
            ])
            ->values()
            ->all();

        $this->payouts = CommissionPayout::query()
            ->when($this->month !== '', fn ($q) => $q->where('month', $this->month))
            ->orderByDesc('id')
            ->get()
            ->map(static fn (CommissionPayout $p) => [
                'agent' => User::find($p->agent_id)->name ?? '#'.$p->agent_id,
                'month' => $p->month,
                'amount' => Persian::toman($p->amount),
                'paid_on' => Jalali::format($p->paid_on),
                'reference' => $p->reference,
            ])
            ->all();
    }

    /** Payout modal submit: one lump-sum per agent per month (09 §2.5). */
    public function recordPayout(): void
    {
        $data = $this->validatePayout();
        if ($data === null) {
            return;
        }

        $exists = CommissionPayout::query()
            ->where('agent_id', $this->payout_agent)
            ->where('month', $this->month)
            ->exists();

        if ($exists) {
            $this->dispatch('toast', message: 'برای این کارشناس در این ماه پرداختی ثبت شده است.');

            return;
        }

        CommissionPayout::create([
            'agent_id' => $this->payout_agent,
            'month' => $this->month,
            'amount' => $data['amount'],
            'paid_on' => $data['paid_on'],
            'reference' => $this->payout_reference ?: null,
            'recorded_by' => auth()->id(),
        ]);

        $this->payout_agent = null;
        $this->payout_amount = '';
        $this->payout_paid_on = '';
        $this->payout_reference = '';
        $this->loadRows();
    }

    /** @return array{amount: int, paid_on: string}|null */
    private function validatePayout(): ?array
    {
        if ($this->payout_agent === null || ! User::query()->whereKey($this->payout_agent)->exists()) {
            $this->dispatch('toast', message: 'کارشناس انتخاب نشده است.');

            return null;
        }

        $amount = Persian::toInt($this->payout_amount);
        if ($amount <= 0) {
            $this->dispatch('toast', message: 'مبلغ باید بزرگ‌تر از صفر باشد.');

            return null;
        }

        if (trim($this->payout_paid_on) === '') {
            $this->dispatch('toast', message: 'تاریخ پرداخت نامعتبر است.');

            return null;
        }

        return ['amount' => $amount, 'paid_on' => $this->payout_paid_on];
    }

    /** Manual sync trigger (09 §2.3): on-page, behind the manager view. */
    public function syncNow(): void
    {
        $result = app(CommissionSyncService::class)->sync();
        $this->dispatch('toast', message: $result['skipped'] ? 'همگام‌سازی در حال اجراست' : 'تراکنش‌های جدید ثبت شد');
        $this->loadRows();
    }
}
