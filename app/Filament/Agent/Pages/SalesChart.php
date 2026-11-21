<?php

namespace App\Filament\Agent\Pages;

use App\Models\CrmInvoice;
use App\Models\CrmInvoiceLine;
use App\Models\User;
use App\Support\Jalali;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

/**
 * «نمودار فروش» (spec 04 §3.8): sales per operator (skip operator id 1,
 * product 22 excluded, status 2 only, ExtenSalesCond) plus my sales with
 * conflict detection and a today's-owners legacy chart.
 */
class SalesChart extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationGroup = 'کارتابل فروش';

    protected static ?string $title = 'نمودار فروش';

    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.agent.pages.sales-chart';

    public ?string $date_start = null;

    public ?string $date_end = null;

    public array $perOperator = [];

    public array $mySales = [];

    public function mount(): void
    {
        $this->date_start = now()->toDateString();
        $this->date_end = now()->toDateString();
        $this->loadRows();
    }

    public function loadRows(): void
    {
        $start = $this->date_start !== null && $this->date_start !== ''
            ? (Jalali::parse($this->date_start) ?? Carbon::parse($this->date_start))->startOfDay()
            : now()->startOfDay();
        $end = $this->date_end !== null && $this->date_end !== ''
            ? (Jalali::parse($this->date_end) ?? Carbon::parse($this->date_end))->endOfDay()
            : now()->endOfDay();

        $operators = User::query()
            ->where('is_active', true)
            ->whereNotNull('exten')->where('exten', '!=', '')
            ->where('id', '!=', 1) // operator id 1 excluded from the chart (04 §3.8)
            ->get();

        $invoices = CrmInvoice::query()
            ->where('status', CrmInvoice::STATUS_PAID)
            ->whereBetween('invoice_date', [$start, $end])
            ->with('lines')
            ->limit(5000)
            ->get();

        // Per-operator chart: COUNT(DISTINCT trackingcode) with ExtenSalesCond.
        $this->perOperator = [];
        foreach ($operators as $operator) {
            $exten = $operator->operatorCode();
            if ($exten === null) {
                continue;
            }

            $count = $invoices
                ->filter(static fn (CrmInvoice $invoice) => $invoice->belongsToExten($exten))
                ->filter(static fn (CrmInvoice $invoice) => $invoice->lines->every(
                    static fn (CrmInvoiceLine $line) => (int) $line->product_id !== CrmInvoice::EXCLUDED_PRODUCT_ID,
                ))
                ->pluck('tracking_code')
                ->unique()
                ->count();

            if ($count > 0) {
                $this->perOperator[] = ['name' => $operator->name, 'count' => $count];
            }
        }

        usort($this->perOperator, static fn ($a, $b) => $b['count'] <=> $a['count']);

        // My sales with conflict detection.
        $myExten = auth()->user()->operatorCode();
        $operatorNames = User::query()->pluck('name', 'exten');

        if ($myExten !== null) {
            $this->mySales = $invoices
                ->filter(static fn (CrmInvoice $invoice) => $invoice->belongsToExten($myExten))
                ->map(static function (CrmInvoice $invoice) use ($myExten, $operatorNames) {
                    $conflict = $invoice->conflictFor($myExten);

                    return [
                        'id' => $invoice->id,
                        'phone' => $invoice->phone,
                        'customer' => $invoice->customer !== null ? $invoice->customer->name : 'ناشناس',
                        'invoice_date' => $invoice->invoice_date,
                        'discount' => $invoice->discount,
                        'reagent_code' => $invoice->reagent_code,
                        'products' => $invoice->lines->pluck('product_name')->filter()->implode('، '),
                        'total' => $invoice->total,
                        'conflict' => $conflict !== null,
                        'conflict_tooltip' => $conflict !== null
                            ? 'کد معرف '.($invoice->reagent_code ?? '').' → '.$operatorNames[$invoice->reagent_code ?? ''].' | کد تخفیف '.($invoice->discount ?? '').' → '.$operatorNames[$conflict['d_owner']]
                            : '',
                    ];
                })
                ->values()
                ->all();
        }
    }
}
