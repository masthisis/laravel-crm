<?php

namespace App\Filament\Agent\Widgets;

use App\Models\Lead;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The agent's "my day" strip: what landed on my plate today and what
 * needs a call before the day ends.
 */
class MyDayWidget extends BaseWidget
{
    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $agentId = auth()->id();

        $receivedToday = Lead::query()->assignedToday($agentId)->count();
        $dueToday = Lead::query()
            ->where('assigned_agent_id', $agentId)
            ->open()
            ->whereDate('follow_up_at', today())
            ->count();
        $overdue = Lead::query()->where('assigned_agent_id', $agentId)->dueFollowUps()->whereDate('follow_up_at', '<', today())->count();
        $openPipeline = Lead::query()->where('assigned_agent_id', $agentId)->open()->count();

        return [
            Stat::make('New leads today', $receivedToday)
                ->description('Assigned to you since midnight')
                ->color('primary')
                ->icon('heroicon-o-inbox'),
            Stat::make('Follow-ups due today', $dueToday)
                ->description($overdue > 0 ? $overdue.' overdue beyond today' : 'Nothing overdue')
                ->color($dueToday > 0 ? 'warning' : 'gray')
                ->icon('heroicon-o-phone'),
            Stat::make('Overdue follow-ups', $overdue)
                ->description('Scheduled before today, still open')
                ->color($overdue > 0 ? 'danger' : 'success')
                ->icon('heroicon-o-exclamation-triangle'),
            Stat::make('Open pipeline', $openPipeline)
                ->description('All your open leads')
                ->color('info')
                ->icon('heroicon-o-user-group'),
        ];
    }
}
