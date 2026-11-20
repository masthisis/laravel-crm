<?php

namespace App\Services;

use App\Enums\AssignmentStrategy;
use App\Models\AssignmentBatch;
use App\Models\Lead;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Assigns leads to sales agents, either all to one agent or
 * distributed evenly across a team (balanced round-robin).
 */
class LeadAssignmentService
{
    /**
     * Assign every lead to a single agent.
     */
    public function assignAll(iterable $leads, User $agent, ?User $actor = null, ?string $filename = null): AssignmentBatch
    {
        $leads = collect($leads);

        foreach ($leads as $lead) {
            $this->assign($lead, $agent);
        }

        $batch = AssignmentBatch::create([
            'filename' => $filename,
            'total_leads' => $leads->count(),
            'strategy' => AssignmentStrategy::Single,
            'assigned_agent_id' => $agent->id,
            'user_id' => $actor->id ?? auth()->id(),
            'distribution' => [$agent->id => $leads->count()],
        ]);

        $this->notifyAgent($agent, $leads->count());

        return $batch;
    }

    /**
     * Spread leads evenly across agents. Agents with fewer open leads
     * receive leads first so the workload stays balanced.
     *
     * @param  array<int, int>  $agentIds
     */
    public function distribute(iterable $leads, array $agentIds, ?User $actor = null, ?string $filename = null): AssignmentBatch
    {
        $leads = collect($leads);
        $agentIds = array_values(array_map(intval(...), $agentIds));

        if (empty($agentIds)) {
            throw new \InvalidArgumentException('At least one agent is required.');
        }

        // Start from the current open workload of each agent.
        $counts = [];
        foreach ($agentIds as $id) {
            $counts[$id] = Lead::query()
                ->where('assigned_agent_id', $id)
                ->whereIn('status', ['new', 'contacted', 'qualified', 're_engaged'])
                ->count();
        }

        $distribution = array_fill_keys($agentIds, 0);

        foreach ($leads as $lead) {
            $chosen = null;
            foreach ($agentIds as $id) {
                if ($chosen === null || $counts[$id] < $counts[$chosen]) {
                    $chosen = $id;
                }
            }

            $this->assign($lead, User::find($chosen));
            $counts[$chosen]++;
            $distribution[$chosen]++;
        }

        $batch = AssignmentBatch::create([
            'filename' => $filename,
            'total_leads' => $leads->count(),
            'strategy' => AssignmentStrategy::Distribute,
            'assigned_agent_id' => null,
            'user_id' => $actor->id ?? auth()->id(),
            'distribution' => $distribution,
        ]);

        foreach ($distribution as $agentId => $count) {
            if ($count > 0 && ($notified = User::find($agentId)) !== null) {
                $this->notifyAgent($notified, $count);
            }
        }

        return $batch;
    }

    /** Send the "leads landed on you" database notification (batched). */
    protected function notifyAgent(User $agent, int $count): void
    {
        Notification::make()
            ->title($count === 1 ? 'You have a new lead' : "You have {$count} new leads")
            ->icon('heroicon-o-user-plus')
            ->color('success')
            ->body('Open your pipeline to start working them — new leads are marked as New.')
            ->sendToDatabase($agent);
    }

    protected function assign(Lead $lead, User $agent): Lead
    {
        $lead->update([
            'assigned_agent_id' => $agent->id,
            'assigned_at' => now(),
        ]);

        return $lead;
    }

    /** @return Collection<int, User> */
    public function salesAgents(): Collection
    {
        return User::role('sales_staff')->orderBy('name')->get();
    }
}
