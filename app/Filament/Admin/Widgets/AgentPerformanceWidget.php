<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\LeadStatus;
use App\Models\User;
use App\Support\Roles;
use Filament\Widgets\Widget;

class AgentPerformanceWidget extends Widget
{
    protected static string $view = 'filament.admin.widgets.agent-performance';

    protected int|string|array $columnSpan = 'full';

    public function getViewData(): array
    {
        $agents = User::query()
            ->role([Roles::SALES_STAFF, Roles::ADMIN, Roles::SUPER_ADMIN])
            ->withCount([
                'agentLeads',
                'agentLeads as won_leads_count' => fn ($query) => $query->where('status', LeadStatus::Won),
            ])
            ->withSum('commissions as commissions_earned', 'amount')
            ->orderByDesc('agent_leads_count')
            ->get()
            ->map(function (User $agent) {
                $total = (int) $agent->agent_leads_count;
                $won = (int) $agent->won_leads_count;

                return [
                    'name' => $agent->name,
                    'assigned' => $total,
                    'won' => $won,
                    'conversion' => $total > 0 ? round($won / $total * 100, 1) : null,
                    'commissions' => number_format((float) $agent->commissions_earned, 2),
                ];
            });

        return ['agents' => $agents];
    }
}
