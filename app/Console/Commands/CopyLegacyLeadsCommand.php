<?php

namespace App\Console\Commands;

use App\Services\Leads\LegacyCopyService;
use Illuminate\Console\Command;

class CopyLegacyLeadsCommand extends Command
{
    protected $signature = 'crm:copy-legacy-leads
        {--adapter= : One adapter (starttraining, ask, …); omit with --all for every adapter}
        {--all : Copy every adapter}
        {--chunk=1000 : Rows per step}
        {--max-steps=200 : Safety limit of steps per adapter}';

    protected $description = 'Copy old-system lead tables into LeadService by id cursor (spec 05 §7)';

    public function handle(LegacyCopyService $service): int
    {
        if (! $service->legacyEnabled()) {
            $this->error('Legacy connection disabled. Set LEGACY_DB_ENABLED=true plus LEGACY_DB_HOST/DATABASE/USERNAME/PASSWORD.');

            return self::FAILURE;
        }

        $adapters = $this->option('all')
            ? array_keys(LegacyCopyService::adapters())
            : [(string) $this->option('adapter')];

        if (! $this->option('all') && ! isset(LegacyCopyService::adapters()[$adapters[0]])) {
            $this->error('Unknown adapter "'.$adapters[0].'". Available: '.implode(', ', array_keys(LegacyCopyService::adapters())));

            return self::FAILURE;
        }

        $chunk = max(1, (int) $this->option('chunk'));
        $maxSteps = max(1, (int) $this->option('max-steps'));

        foreach ($adapters as $adapter) {
            $this->info("Copying {$adapter} …");
            $steps = 0;
            $total = 0;

            do {
                $result = $service->step($adapter, $chunk);
                $total += $result['copied'];
                $steps++;

                if ($result['copied'] > 0) {
                    $this->line("  step {$steps}: {$result['copied']} copied (cursor at {$result['last_id']})");
                }
            } while (! $result['done'] && $steps < $maxSteps);

            $this->info("  done: {$total} new entries for {$adapter}");
        }

        return self::SUCCESS;
    }
}
