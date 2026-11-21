<?php

namespace App\Filament\Agent\Widgets;

use App\Models\CrmCallLog;
use App\Models\CrmDepartment;
use App\Models\CrmInvoice;
use App\Models\CrmNote;
use App\Models\Customer;
use App\Models\LeadContact;
use App\Models\User;
use App\Support\Jalali;
use App\Support\Persian;
use Filament\Widgets\Widget;

/**
 * Dashboard panels (spec 04 §3.1): latest unpaid invoices of my
 * customers (ExtenSalesCond), my last 40 calls, my last 40 notes.
 */
class MyDashboardTables extends Widget
{
    protected int|string|array $columnSpan = 'full';

    protected static string $view = 'filament.agent.widgets.dashboard-table';

    public string $panel = 'invoices';

    public function getHeading(): string
    {
        return match ($this->panel) {
            'invoices' => 'آخرین پرداخت نشده‌های مشتریان من',
            'calls' => 'آخرین تماس‌های من',
            default => 'آخرین یادداشت‌های من',
        };
    }

    /** @return list<array<string, mixed>> */
    public function rows(): array
    {
        $exten = auth()->user()->operatorCode() ?? '';
        $contacts = LeadContact::query()->pluck('name', 'phone');
        $operators = User::query()->pluck('name', 'exten');

        if ($this->panel === 'invoices') {
            // ExtenSalesCond (04 §3.1), scoped recordsTotal (11 #13).
            return CrmInvoice::query()
                ->unpaidForDashboard()
                ->orderByDesc('id')
                ->limit(200)
                ->get()
                ->filter(static fn (CrmInvoice $invoice) => $invoice->belongsToExten($exten))
                ->take(200)
                ->values()
                ->map(static fn (CrmInvoice $invoice) => [
                    'tracking' => $invoice->tracking_code,
                    'customer' => ($invoice->customer->name) ?? 'ناشناس',
                    'products' => $invoice->lines->pluck('product_name')->filter()->implode(' / ') ?: '—',
                    'phone' => $invoice->phone,
                    'total' => Persian::toman($invoice->total),
                    'status' => match ((int) $invoice->status) {
                        CrmInvoice::STATUS_PAID => 'پرداخت شده',
                        CrmInvoice::STATUS_DELETED => 'حذف شده',
                        default => 'پرداخت نشده',
                    },
                    'discount' => $invoice->discount ?? '-',
                    'reagent' => $invoice->reagent_code ?? '-',
                    'date' => Jalali::format($invoice->invoice_date),
                ])
                ->all();
        }

        if ($this->panel === 'calls') {
            return CrmCallLog::query()
                ->where('exten', $exten)
                ->orderByDesc('called_at')
                ->limit(40)
                ->get()
                ->map(static fn (CrmCallLog $call) => [
                    'name' => $contacts[$call->phone ?? ''] ?? 'ناشناس',
                    'phone' => $call->phone,
                    'unknown_url' => ($contacts[$call->phone ?? ''] ?? null) === null ? '/agent/register/'.($call->phone ?? '') : null,
                    'time' => Jalali::format($call->called_at, 'Y/m/d H:i'),
                    'ago' => $call->called_at?->diffForHumans(),
                    'direction' => $call->type === 'incoming' ? '↓ ورودی' : '↑ خروجی',
                    'duration' => $call->durationLabel() ?: '—',
                    'accepted' => $operators[$call->accepted_call ?? ''] ?? ($call->accepted_call ?: 'ندارد'),
                ])
                ->all();
        }

        return CrmNote::query()
            ->where('operator_id', auth()->id())
            ->orderByDesc('created_at')
            ->limit(40)
            ->get()
            ->map(static fn (CrmNote $note) => [
                'customer' => Customer::query()->whereKey($note->customer_id)->value('name') ?? '—',
                'subject' => $note->subject,
                'created' => Jalali::format($note->created_at),
                'updated' => Jalali::format($note->updated_at),
                'department' => CrmDepartment::query()->whereKey($note->department_id)->value('name') ?? '—',
                'operator' => User::query()->whereKey($note->operator_id)->value('name') ?? '—',
            ])
            ->all();
    }

    /** @return list<string> */
    public function headers(): array
    {
        return match ($this->panel) {
            'invoices' => ['رسید', 'مشتری', 'محصولات', 'شماره', 'مبلغ', 'وضعیت', 'تخفیف', 'کد معرف', 'تاریخ'],
            'calls' => ['نام', 'شماره', 'زمان', 'جهت', 'مدت', 'پاسخگو'],
            default => ['مشتری', 'موضوع', 'ایجاد', 'آخرین تغییر', 'دپارتمان', 'کارشناس'],
        };
    }
}
