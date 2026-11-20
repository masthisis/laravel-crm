<?php

namespace App\Console\Commands;

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\CommissionService;
use App\Support\Roles;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Pulls leads from one or more external databases into this CRM.
 *
 * Connections are configured in config/crm.php (external_sync.connections)
 * — each names a DB connection, a table, a reference column and a
 * column mapping, so any foreign table can be ingested without touching
 * its schema. Reads are ordered by the reference column and chunked
 * (memory-safe); the last processed ref is stored on each run, so the
 * next run is incremental. Runs are audited in sync_runs.
 */
class SyncExternalLeadsCommand extends Command
{
    protected $signature = 'crm:sync-external-leads
                            {connection? : Connection name from crm.external_sync.connections (default: the legacy single connection)}
                            {--all : Sync every configured connection}
                            {--dry-run : Report what would happen, write nothing}';

    protected $description = 'Import leads from external lead database(s) — chunked, incremental, audited';

    protected const CHUNK = 200;

    public function handle(): int
    {
        if (! config('crm.external_sync.enabled')) {
            $this->info('External sync is disabled (EXTERNAL_SYNC_ENABLED=false). Nothing to do.');

            return self::SUCCESS;
        }

        $names = $this->connectionNames();

        if ($names === []) {
            $this->error('No external sync connections configured.');

            return self::FAILURE;
        }

        $failed = false;

        foreach ($names as $name) {
            $failed = ! $this->syncConnection($name) || $failed;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @return array<int, string> */
    protected function connectionNames(): array
    {
        if ($this->option('all')) {
            return array_keys((array) config('crm.external_sync.connections', []));
        }

        $name = (string) ($this->argument('connection') ?: 'external');

        return [$name];
    }

    /**
     * @return array<string, mixed>|null Connection config, or null when not configured.
     */
    protected function configFor(string $name): ?array
    {
        $config = config("crm.external_sync.connections.{$name}");

        // Backwards-compatible fallback to the legacy flat config.
        if ($config === null && $name === 'external') {
            $config = [
                'connection' => config('crm.external_sync.connection', 'external'),
                'table' => config('crm.external_sync.table', 'external_leads'),
                'reference_column' => config('crm.external_sync.reference_column', 'id'),
                'columns' => (array) config('crm.external_sync.columns', []),
                'source_name' => (string) config('crm.external_sync.source_name', 'External DB'),
            ];
        }

        if (! is_array($config)) {
            $this->error("Connection [{$name}] is not configured.");

            return null;
        }

        return $config;
    }

    protected function syncConnection(string $name): bool
    {
        $config = $this->configFor($name);

        if ($config === null) {
            return false;
        }

        $run = SyncRun::create([
            'connection_name' => $name,
            'source_name' => $config['source_name'] ?? 'External DB',
            'trigger' => $this->option('all') ? 'schedule' : 'manual',
            'dry_run' => (bool) $this->option('dry-run'),
            'started_at' => now(),
        ]);

        $source = CommissionService::resolveSource((string) $config['source_name'], 'database_sync');
        $refColumn = (string) $config['reference_column'];
        $columns = (array) $config['columns'];

        $lastRef = SyncRun::query()
            ->where('connection_name', $name)
            ->where('dry_run', false)
            ->whereNotNull('last_ref')
            ->latest('id')
            ->value('last_ref');

        $counts = ['seen' => 0, 'imported' => 0, 'skipped' => 0, 'failed' => 0];
        $errors = [];
        $cursor = $lastRef;

        try {
            DB::connection((string) $config['connection'])
                ->table((string) $config['table'])
                ->when($lastRef !== null, fn ($query) => $query->where($refColumn, '>', $lastRef))
                ->orderBy($refColumn)
                ->chunkById(self::CHUNK, function ($rows) use (&$cursor, &$counts, &$errors, $source, $refColumn, $columns, $run): void {
                    foreach ($rows as $row) {
                        $counts['seen']++;

                        $ref = $row->{$refColumn} ?? null;
                        $leadName = $row->{$columns['name'] ?? 'name'} ?? null;

                        if ($ref === null || blank($leadName)) {
                            $counts['failed']++;
                            $errors[] = ['ref' => (string) $ref, 'reason' => 'Missing reference or name.'];

                            continue;
                        }

                        // Incremental cursor: highest ref wins (chunkById is ordered).
                        $cursor = (string) $ref;

                        $alreadyImported = Lead::query()
                            ->where('source_id', $source->id)
                            ->where('meta->external_ref', (string) $ref)
                            ->exists();

                        if ($alreadyImported) {
                            $counts['skipped']++;

                            continue;
                        }

                        if ($run->dry_run) {
                            $counts['imported']++;

                            continue;
                        }

                        Lead::create([
                            'name' => $leadName,
                            'email' => $row->{$columns['email'] ?? 'email'} ?? null,
                            'phone' => $row->{$columns['phone'] ?? 'phone'} ?? null,
                            'source_id' => $source->id,
                            'status' => LeadStatus::New,
                            'meta' => ['external_ref' => (string) $ref, 'driver' => 'database_sync', 'connection' => $run->connection_name],
                        ]);

                        $counts['imported']++;
                    }
                }, $refColumn);
        } catch (\Throwable $e) {
            $run->update([
                'seen' => $counts['seen'],
                'imported' => $counts['imported'],
                'skipped' => $counts['skipped'],
                'failed' => $counts['failed'],
                'last_ref' => $cursor,
                'errors' => array_merge($errors, [['ref' => null, 'reason' => 'Connection failed: '.$e->getMessage()]]),
                'finished_at' => now(),
            ]);

            $this->error("[{$name}] sync failed: {$e->getMessage()}");

            $this->notifyAdminsOfFailure($name, $e->getMessage());

            return false;
        }

        $run->update([...$counts, 'last_ref' => $cursor, 'errors' => $errors ?: null, 'finished_at' => now()]);

        $this->info(sprintf(
            '[%s] %sseen %d, imported %d, skipped %d, failed %d (cursor: %s)',
            $name,
            $run->dry_run ? 'DRY RUN — ' : '',
            $counts['seen'],
            $counts['imported'],
            $counts['skipped'],
            $counts['failed'],
            (string) $cursor,
        ));

        return true;
    }

    protected function notifyAdminsOfFailure(string $connection, string $message): void
    {
        $admins = User::query()->role([Roles::ADMIN, Roles::SUPER_ADMIN])->where('is_active', true)->get();

        Notification::make()
            ->title("External sync failed [{$connection}]")
            ->icon('heroicon-o-exclamation-triangle')
            ->color('danger')
            ->body($message)
            ->sendToDatabase($admins);
    }
}
