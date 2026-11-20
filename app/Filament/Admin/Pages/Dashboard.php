<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Widgets\AgentPerformanceWidget;
use App\Filament\Admin\Widgets\LeadsBySourceChart;
use App\Filament\Admin\Widgets\RevenueBySourceChart;
use App\Filament\Admin\Widgets\StatsOverviewWidget;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    public function getColumns(): int|string|array
    {
        return 2;
    }

    public function getWidgets(): array
    {
        return [
            StatsOverviewWidget::class,
            LeadsBySourceChart::class,
            RevenueBySourceChart::class,
            AgentPerformanceWidget::class,
        ];
    }
}
