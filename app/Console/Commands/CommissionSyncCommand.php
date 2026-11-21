<?php

namespace App\Console\Commands;

use App\Services\Commission\CommissionSyncService;
use Illuminate\Console\Command;

class CommissionSyncCommand extends Command
{
    protected $signature = 'crm:commission-sync {--batch=500}';

    protected $description = 'Sync paid invoices into commission earnings (spec 09 §2.3, insert-only)';

    public function handle(): int
    {
        $service = new CommissionSyncService((int) $this->option('batch'));

        if (! $service->enabled()) {
            $this->error('Commission sync disabled: set CRM_COMMISSION_START_AT (e.g. "2026-12-01 00:00:00").');

            return self::FAILURE;
        }

        $result = $service->sync();

        if ($result['skipped']) {
            $this->line('Another sync is running — skipped.');

            return self::SUCCESS;
        }

        $this->info("Recorded {$result['processed']} earning(s).");

        return self::SUCCESS;
    }
}
