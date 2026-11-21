<?php

namespace App\Console\Commands;

use App\Services\Intake\PollLegacyService;
use Illuminate\Console\Command;

class PollLegacyCommand extends Command
{
    protected $signature = 'crm:poll-legacy
        {--daemon : Keep polling every CRM_POLL_INTERVAL seconds}
        {--once : One pass (default)}';

    protected $description = 'Poll the legacy tables by id cursor / status window and feed the intake (spec 03 §4)';

    public function handle(PollLegacyService $service): int
    {
        if (! $service->legacyEnabled()) {
            $this->error('Legacy connection disabled. Set LEGACY_DB_ENABLED=true plus LEGACY_DB_HOST/DATABASE/USERNAME/PASSWORD.');

            return self::FAILURE;
        }

        $interval = max(5, (int) config('crm.events.poll_interval', 60));

        do {
            $counts = $service->pollAll();
            $total = array_sum($counts);

            if ($total > 0) {
                foreach ($counts as $cursor => $count) {
                    if ($count > 0) {
                        $this->line("  {$cursor}: {$count}");
                    }
                }
                $this->info("pass complete: {$total} rows fed to the intake");
            }

            if (! $this->option('daemon')) {
                return self::SUCCESS;
            }

            sleep($interval);
        } while (true);
    }
}
