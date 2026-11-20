<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\CommissionStatus;
use App\Enums\LeadStatus;
use App\Models\Commission;
use App\Models\Lead;
use App\Models\Order;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

class StatsOverviewWidget extends BaseWidget
{
    protected function getStats(): array
    {
        $totalLeads = Lead::count();
        $wonLeads = Lead::query()->where('status', LeadStatus::Won)->count();
        $revenue = (float) Order::sum('amount');
        $payable = (float) Commission::query()->whereIn('status', [CommissionStatus::Pending, CommissionStatus::Approved])->sum('amount');
        $paid = (float) Commission::query()->where('status', CommissionStatus::Paid)->sum('amount');
        $currency = config('crm.currency', 'USD');

        $conversion = $totalLeads > 0 ? round($wonLeads / $totalLeads * 100, 1).'%' : '—';

        return [
            Stat::make('Total leads', $totalLeads)
                ->description($wonLeads.' won — '.$conversion.' conversion (all time)')
                ->color('primary')
                ->icon('heroicon-o-user-group'),
            Stat::make('Revenue', Number::currency($revenue, $currency))
                ->description('Total recorded sales (all time)')
                ->color('success')
                ->icon('heroicon-o-banknotes'),
            Stat::make('Commissions payable', Number::currency($payable, $currency))
                ->description('Pending + approved')
                ->color('warning')
                ->icon('heroicon-o-clock'),
            Stat::make('Commissions paid', Number::currency($paid, $currency))
                ->color('info')
                ->icon('heroicon-o-check-badge'),
        ];
    }
}
