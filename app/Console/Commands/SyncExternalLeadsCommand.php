<?php

namespace App\Console\Commands;

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Services\CommissionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Pulls leads from an external database (an old CRM, a marketing
 * platform, any system we can reach) into this CRM.
 *
 * Configure the connection in .env (EXTERNAL_DB_*) and the table /
 * column mapping in config/crm.php (external_sync). The command is
 * idempotent: rows already imported (tracked by their external id)
 * are skipped.
 */
class SyncExternalLeadsCommand extends Command
{
    protected $signature = 'crm:sync-external-leads
                            {--source= : Name of the lead source to record for imported rows (default: External DB)}';

    protected $description = 'Import leads from the external lead database (cross-database tracking)';

    public function handle(): int
    {
        if (! config('crm.external_sync.enabled')) {
            $this->info('External sync is disabled (EXTERNAL_SYNC_ENABLED=false). Nothing to do.');

            return self::SUCCESS;
        }

        $connection = config('crm.external_sync.connection');
        $table = config('crm.external_sync.table');
        $columns = config('crm.external_sync.columns');
        $refColumn = config('crm.external_sync.reference_column', 'id');

        $sourceName = $this->option('source') ?: 'External DB';
        $source = CommissionService::resolveSource($sourceName, 'database_sync');

        try {
            $rows = DB::connection($connection)
                ->table($table)
                ->limit(500)
                ->get();
        } catch (\Throwable $e) {
            $this->error("Could not reach external database [{$connection}]: {$e->getMessage()}");

            return self::FAILURE;
        }

        $imported = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $ref = $row->{$refColumn} ?? null;
            $name = $row->{$columns['name']} ?? null;
            $phone = $row->{$columns['phone']} ?? null;

            if (blank($name) || blank($ref)) {
                $skipped++;

                continue;
            }

            $alreadyImported = Lead::query()
                ->where('meta->external_ref', (string) $ref)
                ->where('source_id', $source->id)
                ->exists();

            if ($alreadyImported) {
                $skipped++;

                continue;
            }

            Lead::create([
                'name' => $name,
                'email' => $row->{$columns['email']} ?? null,
                'phone' => $phone,
                'source_id' => $source->id,
                'status' => LeadStatus::New,
                'meta' => ['external_ref' => (string) $ref, 'driver' => 'database_sync'],
            ]);

            $imported++;
        }

        $this->info("External sync complete: {$imported} imported, {$skipped} skipped (already imported or incomplete).");

        return self::SUCCESS;
    }
}
