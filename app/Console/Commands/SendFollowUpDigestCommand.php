<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Roles;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;

/**
 * Notifies every sales agent (database notification) about their due
 * and overdue follow-ups. Scheduled daily in the morning — no email
 * infrastructure required.
 */
class SendFollowUpDigestCommand extends Command
{
    protected $signature = 'crm:send-followup-digest';

    protected $description = 'Notify agents about due and overdue follow-ups (database notifications)';

    public function handle(): int
    {
        $notified = 0;

        User::query()
            ->role(Roles::SALES_STAFF)
            ->withCount([
                'agentLeads as due_follow_ups_count' => fn ($query) => $query->where('follow_up_at', '<=', now())->open(),
            ])
            ->get()
            ->filter(fn (User $agent) => (int) $agent->getAttribute('due_follow_ups_count') > 0)
            ->each(function (User $agent) use (&$notified) {
                $count = (int) $agent->getAttribute('due_follow_ups_count');

                Notification::make()
                    ->title("You have {$count} follow-up".($count === 1 ? '' : 's').' due')
                    ->icon('heroicon-o-phone')
                    ->color($count > 5 ? 'danger' : 'warning')
                    ->body('Open your pipeline and use the "Follow-ups due" filter to work through them.')
                    ->sendToDatabase($agent);

                $notified++;
            });

        $this->info("Follow-up digest sent to {$notified} agent(s).");

        return self::SUCCESS;
    }
}
