<?php

namespace SynergyERP\Shared\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Cleans up inbox/outbox test data between runs.
 *
 * Usage:
 *     php artisan inbox-outbox:test-cleanup --tag=lt-20260317-163000
 *     php artisan inbox-outbox:test-cleanup --all
 *     php artisan inbox-outbox:test-cleanup --all --schema=loadtest
 */
class InboxOutboxTestCleanupCommand extends Command
{
    protected $signature = 'inbox-outbox:test-cleanup
        {--tag= : Delete data for a specific run tag only}
        {--all : Delete ALL load test data (inbox, outbox, loadtest_runs)}
        {--schema=loadtest : Tenant schema prefix}
        {--force : Skip confirmation prompt}';

    protected $description = 'Clean up inbox/outbox test data';

    public function handle(): int
    {
        $tag    = $this->option('tag');
        $all    = $this->option('all');
        $schema = $this->option('schema');
        $force  = $this->option('force');

        if (!$tag && !$all) {
            $this->error('Provide --tag=<run-tag> or --all');
            return 1;
        }

        if ($all) {
            $inboxCount  = DB::table('inbox_items')->where('idempotency_key', 'LIKE', 'lt-%')->count();
            $outboxCount = DB::table('outbox_items')->whereRaw("JSON_EXTRACT(payload, '$.loadtest_tag') IS NOT NULL")->count();
            $runCount    = DB::table('loadtest_runs')->count();

            $this->warn("This will delete:");
            $this->warn("  Inbox items (loadtest):  {$inboxCount}");
            $this->warn("  Outbox items (loadtest): {$outboxCount}");
            $this->warn("  Loadtest runs:           {$runCount}");

            if (!$force && !$this->confirm('Proceed?')) {
                $this->info('Cancelled.');
                return 0;
            }

            DB::table('inbox_items')->where('idempotency_key', 'LIKE', 'lt-%')->delete();
            DB::table('outbox_items')->whereRaw("JSON_EXTRACT(payload, '$.loadtest_tag') IS NOT NULL")->delete();
            DB::table('loadtest_runs')->truncate();

            $this->info("Cleaned up all load test data.");
        } else {
            $inboxDel  = DB::table('inbox_items')->where('idempotency_key', 'LIKE', "{$tag}%")->delete();
            $outboxDel = DB::table('outbox_items')->whereRaw("JSON_EXTRACT(payload, '$.loadtest_tag') = ?", [$tag])->delete();
            $runDel    = DB::table('loadtest_runs')->where('tag', $tag)->delete();

            $this->info("Cleaned up tag '{$tag}': {$inboxDel} inbox, {$outboxDel} outbox, {$runDel} run records deleted.");
        }

        return 0;
    }
}
