<?php

namespace App\Filament\Agent\Pages;

use App\Models\CrmOncall;
use App\Models\CrmOncallCategory;
use App\Models\Customer;
use App\Models\LeadContact;
use App\Services\Phone\WorkspaceService;
use App\Support\Jalali;
use App\Support\PhoneNormalizer;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

/**
 * «شماره ها» — my call queue (spec 04 §3.7): rows uploaded by admins,
 * outcomes recorded as a call-status note + the row status.
 */
class Oncalls extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-list-bullet';

    protected static ?string $navigationGroup = 'کارتابل فروش';

    protected static ?string $title = 'شماره ها';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.agent.pages.oncalls';

    public ?string $date_start = null;

    public ?string $date_end = null;

    public ?string $status = 'oncall'; // oncall | outcome id

    public array $rows = [];

    public ?int $outcome_row = null;

    public int $outcome_status = 1;

    public string $outcome_note = '';

    public function mount(): void
    {
        $this->date_start = now()->subDays(30)->toDateString();
        $this->date_end = now()->toDateString();
        $this->loadRows();
    }

    public function loadRows(): void
    {
        $operatorId = auth()->id();

        $query = CrmOncall::query()->where('operator_id', $operatorId);

        if ($this->status === 'oncall') {
            $query->whereNull('status');
        } elseif ($this->status !== null && $this->status !== '' && $this->status !== 'all') {
            $query->where('status', (int) $this->status);
        }

        if ($this->date_start !== null && $this->date_start !== '') {
            $query->where('queued_at', '>=', (Jalali::parse($this->date_start) ?? Carbon::parse($this->date_start))->startOfDay());
        }

        if ($this->date_end !== null && $this->date_end !== '') {
            $query->where('queued_at', '<=', (Jalali::parse($this->date_end) ?? Carbon::parse($this->date_end))->endOfDay());
        }

        $names = LeadContact::query()->pluck('name', 'phone');
        $outcomes = CrmOncallCategory::query()->orderBy('id')->get();
        $outcomeNames = $outcomes->pluck('name', 'id');
        $counts = [];

        foreach ($outcomes as $outcome) {
            $counts[$outcome->id] = CrmOncall::query()
                ->where('operator_id', $operatorId)
                ->where('status', $outcome->id)
                ->count();
        }

        $notCalled = CrmOncall::query()->where('operator_id', $operatorId)->whereNull('status')->count();

        $this->rows = $query->orderByDesc('queued_at')->limit(300)->get()->map(static function (CrmOncall $row) use ($names, $outcomeNames) {
            $phone = $row->phone;
            $contact = LeadContact::query()->where('phone', PhoneNormalizer::canonical($phone) ?? $phone)->first();
            $customer = $contact?->customer_id !== null ? Customer::find($contact->customer_id) : null;
            $queueCount = CrmOncall::query()->where('phone', $phone)->count();

            return [
                'id' => $row->id,
                'phone' => $phone,
                'name' => $names[$phone] ?? (LeadContact::query()->where('phone', PhoneNormalizer::canonical($phone) ?? $phone)->value('name') ?? 'ناشناس'),
                'has_customer' => $customer !== null,
                'customer_url' => $customer !== null ? '/agent/customers/'.$customer->id : null,
                'register_url' => '/agent/register/'.$phone,
                'category' => $row->category,
                'queued_at' => $row->queued_at,
                'status' => $row->status === null ? 'تماس گرفته نشده' : ($outcomeNames[$row->status] ?? $row->status),
                'has_history' => $queueCount > 1,
            ];
        })->values()->all();

        $this->outcomes = $outcomeNames->all();
        $this->outcomeCounts = $counts;
        $this->notCalledCount = $notCalled;
    }

    public array $outcomes = [];

    public array $outcomeCounts = [];

    public int $notCalledCount = 0;

    /** «ثبت وضعیت»: record the outcome (04 §3.7). */
    public function recordOutcome(): void
    {
        if ($this->outcome_row === null) {
            return;
        }

        $row = CrmOncall::query()->where('id', $this->outcome_row)->where('operator_id', auth()->id())->first();
        if ($row === null) {
            return; // ownership: only my queue rows
        }

        app(WorkspaceService::class)->recordOutcome(
            $row,
            auth()->user(),
            $this->outcome_status,
            $this->outcome_note,
        );

        $this->outcome_row = null;
        $this->outcome_note = '';
        $this->loadRows();
    }
}
