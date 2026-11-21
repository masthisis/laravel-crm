<?php

namespace App\Filament\Admin\Pages;

use App\Services\Leads\ConversionReportService;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

/**
 * گزارش نرخ تبدیل (spec 05 §10): history vs owner-at-purchase views,
 * Jalali weekly/monthly or per-agent grouping.
 */
class ConversionReport extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationGroup = 'مدیریت لیدها';

    protected static ?string $title = 'گزارش نرخ تبدیل';

    protected static ?int $navigationSort = 30;

    protected static string $view = 'filament.admin.pages.conversion-report';

    public ?string $referral_from = null;

    public ?string $referral_to = null;

    public ?string $purchase_from = null;

    public ?string $purchase_to = null;

    public string $purchase_kind = 'order';

    public string $report_view = 'history';

    public string $grouping = 'agent';

    public array $rows = [];

    public bool $calculated = false;

    public function mount(): void
    {
        $this->referral_from = now()->subDays(30)->toDateString();
        $this->referral_to = now()->toDateString();
        $this->purchase_from = now()->subDays(30)->toDateString();
        $this->purchase_to = now()->toDateString();
    }

    public function calculate(): void
    {
        $service = app(ConversionReportService::class);

        try {
            $this->rows = $service->report(
                ['from' => Carbon::parse($this->referral_from)->startOfDay(), 'to' => Carbon::parse($this->referral_to)->endOfDay()],
                ['from' => Carbon::parse($this->purchase_from)->startOfDay(), 'to' => Carbon::parse($this->purchase_to)->endOfDay()],
                ['kind' => $this->purchase_kind],
                $this->report_view,
                $this->grouping,
            );
            $this->calculated = true;
        } catch (\Throwable) {
            $this->rows = [];
            $this->calculated = true;
        }
    }
}
