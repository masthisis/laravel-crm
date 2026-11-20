<?php

namespace App\Filament\Agent\Widgets;

use App\Enums\LeadStatus;
use App\Models\Lead;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

class AgentStatsWidget extends BaseWidget
{
    protected function getStats(): array
    {
        $userId = auth()->id();

        $pipeline = Lead::query()
            ->where('assigned_agent_id', $userId)
            ->whereIn('status', [LeadStatus::New, LeadStatus::Contacted, LeadStatus::Qualified, LeadStatus::ReEngaged])
            ->count();

        $pipelineValue = (float) Lead::query()
            ->where('assigned_agent_id', $userId)
            ->whereIn('status', [LeadStatus::New, LeadStatus::Contacted, LeadStatus::Qualified, LeadStatus::ReEngaged])
            ->sum('estimated_value');

        $won = Lead::query()->where('assigned_agent_id', $userId)->where('status', LeadStatus::Won)->count();
        $pending = (float) auth()->user()->commissions()->where('status', 'pending')->sum('amount');

        $currency = config('crm.currency', 'USD');

        return [
            Stat::make('Open pipeline', $pipeline.' leads')
                ->description(Number::currency($pipelineValue, $currency).' estimated value')
                ->color('primary')
                ->icon('heroicon-o-user-group'),
            Stat::make('Deals won', $won)
                ->color('success')
                ->icon('heroicon-o-trophy'),
            Stat::make('Pending commissions', Number::currency($pending, $currency))
                ->description('Awaiting approval / payment')
                ->color('warning')
                ->icon('heroicon-o-clock'),
        ];
    }
}
