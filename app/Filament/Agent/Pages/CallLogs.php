<?php

namespace App\Filament\Agent\Pages;

use App\Models\CrmCallLog;
use App\Models\LeadContact;
use App\Models\User;
use App\Support\Jalali;
use App\Support\Persian;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

/**
 * «گزارش» — call logs (spec 04 §3.10): four tabs over the operator's
 * calls. The missed-calls condition is parenthesised correctly (11 #8).
 */
class CallLogs extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-phone-arrow-down-left';

    protected static ?string $navigationGroup = 'کارتابل فروش';

    protected static ?string $title = 'گزارش تماس';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.agent.pages.call-logs';

    public string $tab = 'incoming';

    public ?string $date_start = null;

    public ?string $date_end = null;

    public ?string $phone = null;

    public array $rows = [];

    public function mount(): void
    {
        $this->date_start = now()->subDays(7)->toDateString();
        $this->date_end = now()->toDateString();
        $this->loadRows();
    }

    public function updatedTab(): void
    {
        $this->loadRows();
    }

    public function loadRows(): void
    {
        $exten = auth()->user()->operatorCode();
        if ($exten === null) {
            $this->rows = [];

            return;
        }

        $query = CrmCallLog::query()->where('exten', $exten);

        $query = match ($this->tab) {
            'incoming' => $query->where('type', 'incoming')->where('accepted_call', $exten),
            'outgoing' => $query->where('type', 'outgoing'),
            // Intended legacy semantics, with correct precedence (11 #8):
            // exten = me AND hangup = me AND (accepted_call IS NULL OR '')
            'missed' => $query->where('type', 'incoming')->where('hangup', $exten)
                ->where(static function ($q) {
                    $q->whereNull('accepted_call')->orWhere('accepted_call', '');
                }),
            default => $query, // all
        };

        if ($this->date_start !== null && $this->date_start !== '') {
            $start = (Jalali::parse($this->date_start) ?? Carbon::parse($this->date_start))->startOfDay();
            $query->where('called_at', '>=', $start);
        }

        if ($this->date_end !== null && $this->date_end !== '') {
            $end = (Jalali::parse($this->date_end) ?? Carbon::parse($this->date_end))->endOfDay();
            $query->where('called_at', '<=', $end);
        }

        if ($this->phone !== null && $this->phone !== '') {
            $needle = (string) Persian::digits($this->phone);
            $query->where('phone', 'like', "%{$needle}%");
        }

        $contacts = LeadContact::query()->pluck('name', 'phone');
        $operators = User::query()->pluck('name', 'exten');

        $this->rows = $query->orderByDesc('called_at')->limit(300)->get()->map(static function (CrmCallLog $call) use ($contacts, $operators) {
            return [
                'id' => $call->id,
                'phone' => $call->phone,
                'name' => $contacts[$call->phone ?? ''] ?? 'ناشناس',
                'direction' => $call->type,
                'answered' => $call->isAnswered(),
                'duration' => $call->durationLabel(),
                'accepted' => $operators[$call->accepted_call ?? ''] ?? ($call->accepted_call ?: null),
                'called_at' => $call->called_at,
                'uniqueid' => $call->uniqueid,
            ];
        })->values()->all();
    }
}
