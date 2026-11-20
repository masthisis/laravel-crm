<?php

namespace App\Filament\Agent\Pages;

use App\Filament\Agent\Widgets\AgentStatsWidget;
use App\Filament\Agent\Widgets\CommissionsBySourceChart;
use App\Filament\Agent\Widgets\MyPipelineChart;
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
            AgentStatsWidget::class,
            MyPipelineChart::class,
            CommissionsBySourceChart::class,
        ];
    }
}
