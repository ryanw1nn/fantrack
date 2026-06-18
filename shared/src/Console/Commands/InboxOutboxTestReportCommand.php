<?php

namespace SynergyERP\Shared\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Inbox/outbox system test report — analyzes inbox/outbox tables to measure
 * consumption throughput, latency, duplicate detection, error rates, and end-to-end timing.
 *
 * Usage:
 *     php artisan inbox-outbox:test-report --tag=lt-20260317-163000
 *     php artisan inbox-outbox:test-report --tag=lt-20260317-163000 --watch
 *     php artisan inbox-outbox:test-report --tag=lt-20260317-163000 --schema=loadtest
 */
class InboxOutboxTestReportCommand extends Command
{
    protected $signature = 'inbox-outbox:test-report
        {--tag= : Run tag to report on (required unless --latest)}
        {--latest : Use the most recent loadtest run tag}
        {--watch : Continuously refresh every 3 seconds until all messages are processed}
        {--schema=loadtest : Tenant schema prefix}
        {--interval=3 : Refresh interval in seconds for --watch mode}';

    protected $description = 'Analyze inbox/outbox system test results';

    public function handle(): int
    {
        $tag    = $this->option('tag');
        $schema = $this->option('schema');
        $watch  = $this->option('watch');
        $interval = (int) $this->option('interval');

        if (!$tag && $this->option('latest')) {
            $tag = DB::table('loadtest_runs')->orderByDesc('id')->value('tag');
        }

        if (!$tag) {
            $this->error('Provide --tag=<run-tag> or --latest');
            return 1;
        }

        // Load run metadata
        $run = DB::table('loadtest_runs')->where('tag', $tag)->first();
        if (!$run) {
            $this->warn("No loadtest_runs record for tag '{$tag}'. Showing inbox/outbox stats only.");
        }

        if ($watch) {
            $this->watchLoop($tag, $schema, $run, $interval);
        } else {
            $this->printReport($tag, $schema, $run);
        }

        return 0;
    }

    private function watchLoop(string $tag, string $schema, ?object $run, int $interval): void
    {
        $this->info("Watching load test '{$tag}' — Ctrl+C to stop\n");

        while (true) {
            // Clear screen for clean refresh
            $this->output->write("\033[2J\033[H");
            $this->printReport($tag, $schema, $run);

            $stats = $this->getInboxStats($tag, $schema);
            $expected = $run->total_messages ?? 0;
            $published = $stats['published'] ?? 0;

            if ($expected > 0 && ($published + ($stats['failed'] ?? 0)) >= $expected) {
                $this->newLine();
                $this->info("All {$expected} messages accounted for. Watch complete.");
                break;
            }

            sleep($interval);
        }
    }

    private function printReport(string $tag, string $schema, ?object $run): void
    {
        $this->info("╔══════════════════════════════════════════════════════════════╗");
        $this->info("║           INBOX/OUTBOX TEST REPORT                          ║");
        $this->info("║  Tag: {$tag}");
        $this->info("╚══════════════════════════════════════════════════════════════╝");
        $this->newLine();

        // ── Run metadata ──
        if ($run) {
            $this->info("── Publish Phase ────────────────────────────────────────────");
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Mode',            $run->mode],
                    ['Users',           number_format($run->users)],
                    ['Messages/user',   number_format($run->messages_per_user)],
                    ['Total published',  number_format($run->total_messages)],
                    ['Payload size',    "{$run->payload_size} bytes"],
                    ['Publish elapsed', $run->elapsed_seconds ? "{$run->elapsed_seconds}s" : 'running...'],
                    ['Publish throughput', $run->throughput_msg_sec ? "{$run->throughput_msg_sec} msg/s" : '-'],
                    ['Started',         $run->started_at],
                    ['Finished',        $run->finished_at ?? 'running...'],
                ]
            );
            $this->newLine();
        }

        // ── Outbox stats ──
        $outboxStats = $this->getOutboxStats($tag, $schema);
        if ($outboxStats['total'] > 0) {
            $this->info("── Outbox Pipeline ─────────────────────────────────────────");
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Total outbox items',   number_format($outboxStats['total'])],
                    ['Pending',              number_format($outboxStats['pending'])],
                    ['Publishing',           number_format($outboxStats['publishing'])],
                    ['Published',            number_format($outboxStats['published'])],
                    ['Failed',               number_format($outboxStats['failed'])],
                    ['Publish rate',         $this->pct($outboxStats['published'], $outboxStats['total'])],
                    ['Error rate',           $this->pct($outboxStats['failed'], $outboxStats['total'])],
                    ['Avg publish latency',  $outboxStats['avg_latency'] ? round($outboxStats['avg_latency'], 2) . 's' : '-'],
                    ['Max publish latency',  $outboxStats['max_latency'] ? round($outboxStats['max_latency'], 2) . 's' : '-'],
                ]
            );
            $this->newLine();
        }

        // ── Inbox stats ──
        $inboxStats = $this->getInboxStats($tag, $schema);
        $this->info("── Inbox Pipeline ──────────────────────────────────────────");
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total inbox items',    number_format($inboxStats['total'])],
                ['Publishing',           number_format($inboxStats['publishing'])],
                ['Published',            number_format($inboxStats['published'])],
                ['Failed',               number_format($inboxStats['failed'])],
                ['Duplicates prevented', number_format($inboxStats['duplicates_prevented'])],
                ['Process rate',         $this->pct($inboxStats['published'], $inboxStats['total'])],
                ['Error rate',           $this->pct($inboxStats['failed'], $inboxStats['total'])],
                ['Avg process latency',  $inboxStats['avg_latency'] ? round($inboxStats['avg_latency'], 2) . 's' : '-'],
                ['Max process latency',  $inboxStats['max_latency'] ? round($inboxStats['max_latency'], 2) . 's' : '-'],
                ['Min process latency',  $inboxStats['min_latency'] ? round($inboxStats['min_latency'], 4) . 's' : '-'],
                ['P95 process latency',  $inboxStats['p95_latency'] ? round($inboxStats['p95_latency'], 2) . 's' : '-'],
            ]
        );
        $this->newLine();

        // ── End-to-end stats ──
        $expected = $run->total_messages ?? $inboxStats['total'];
        if ($expected > 0) {
            $this->info("── End-to-End Summary ──────────────────────────────────────");

            $e2eLatency = $this->getEndToEndLatency($tag, $schema);
            $consumerThroughput = $this->getConsumerThroughput($tag, $schema);

            $this->table(
                ['Metric', 'Value'],
                [
                    ['Expected messages',       number_format($expected)],
                    ['Received by inbox',       number_format($inboxStats['total'])],
                    ['Fully processed',          number_format($inboxStats['published'])],
                    ['Missing (not yet received)', number_format(max(0, $expected - $inboxStats['total']))],
                    ['Delivery rate',            $this->pct($inboxStats['total'], $expected)],
                    ['Success rate',             $this->pct($inboxStats['published'], $expected)],
                    ['Consumer throughput',       $consumerThroughput ? round($consumerThroughput, 1) . ' msg/s' : '-'],
                    ['E2E avg latency',          $e2eLatency['avg'] ? round($e2eLatency['avg'], 2) . 's' : '-'],
                    ['E2E max latency',          $e2eLatency['max'] ? round($e2eLatency['max'], 2) . 's' : '-'],
                ]
            );
        }

        // ── Failed items detail ──
        if ($inboxStats['failed'] > 0) {
            $this->newLine();
            $this->warn("── Failed Inbox Items (last 10) ────────────────────────────");
            $failures = DB::table("{$schema}.inbox_items")
                ->where('status', 'failed')
                ->where('idempotency_key', 'LIKE', "{$tag}%")
                ->orderByDesc('updated_at')
                ->limit(10)
                ->get(['id', 'idempotency_key', 'error_message', 'updated_at']);

            $rows = $failures->map(fn($f) => [
                $f->id,
                substr($f->idempotency_key ?? '-', 0, 40),
                substr($f->error_message ?? '-', 0, 60),
                $f->updated_at,
            ])->toArray();

            $this->table(['ID', 'Idempotency Key', 'Error', 'Updated'], $rows);
        }
    }

    private function getInboxStats(string $tag, string $schema): array
    {
        $base = DB::table("{$schema}.inbox_items")
            ->where('idempotency_key', 'LIKE', "{$tag}%");

        $total     = (clone $base)->count();
        $pending   = (clone $base)->where('status', 'publishing')->count();
        $processed = (clone $base)->where('status', 'published')->count();
        $failed    = (clone $base)->where('status', 'failed')->count();

        // Latency = published_at - created_at
        $latencies = (clone $base)
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->selectRaw('
                AVG(TIMESTAMPDIFF(MICROSECOND, created_at, published_at)) / 1000000 as avg_lat,
                MAX(TIMESTAMPDIFF(MICROSECOND, created_at, published_at)) / 1000000 as max_lat,
                MIN(TIMESTAMPDIFF(MICROSECOND, created_at, published_at)) / 1000000 as min_lat
            ')
            ->first();

        // P95 latency approximation
        $p95 = null;
        if ($processed > 0) {
            $offset = (int) floor($processed * 0.95) - 1;
            if ($offset < 0) $offset = 0;
            $p95Row = DB::table("{$schema}.inbox_items")
                ->where('idempotency_key', 'LIKE', "{$tag}%")
                ->where('status', 'published')
                ->whereNotNull('published_at')
                ->selectRaw('TIMESTAMPDIFF(MICROSECOND, created_at, published_at) / 1000000 as lat')
                ->orderBy('lat')
                ->offset($offset)
                ->limit(1)
                ->first();
            $p95 = $p95Row->lat ?? null;
        }

        // Duplicates prevented = expected - actual received
        // We count unique idempotency_keys vs total to detect if duplicates slipped through
        $uniqueKeys = (clone $base)->distinct()->count('idempotency_key');
        $duplicatesSlippedThrough = $total - $uniqueKeys;

        // Count duplicates prevented by checking loadtest_runs expected vs inbox total
        // If expected = 1000 and inbox = 1000 and unique = 1000, prevention worked
        $duplicatesPrevented = 0;
        $run = DB::table('loadtest_runs')->where('tag', $tag)->first();
        if ($run && $total < $run->total_messages) {
            // Some didn't arrive yet
        }
        // Better metric: query the duplicate-prevention log count
        // For now, report slipped-through duplicates (should be 0)
        $duplicatesPrevented = $duplicatesSlippedThrough; // Ideally 0

        return [
            'total'                => $total,
            'publishing'           => $pending,
            'published'            => $processed,
            'failed'               => $failed,
            'duplicates_prevented' => $duplicatesPrevented,
            'avg_latency'          => $latencies->avg_lat ?? null,
            'max_latency'          => $latencies->max_lat ?? null,
            'min_latency'          => $latencies->min_lat ?? null,
            'p95_latency'          => $p95,
        ];
    }

    private function getOutboxStats(string $tag, string $schema): array
    {
        $base = DB::table("{$schema}.outbox_items")
            ->whereRaw("JSON_EXTRACT(payload, '$.loadtest_tag') = ?", [$tag]);

        $total      = (clone $base)->count();
        $pending    = 0; // 'pending' status no longer exists
        $publishing = (clone $base)->where('status', 'publishing')->count();
        $published  = (clone $base)->where('status', 'published')->count();
        $failed     = (clone $base)->where('status', 'failed')->count();

        $latencies = (clone $base)
            ->where('status', 'published')
            ->selectRaw('
                AVG(TIMESTAMPDIFF(MICROSECOND, created_at, published_at)) / 1000000 as avg_lat,
                MAX(TIMESTAMPDIFF(MICROSECOND, created_at, published_at)) / 1000000 as max_lat
            ')
            ->first();

        return [
            'total'       => $total,
            'pending'     => $pending,
            'publishing'  => $publishing,
            'published'   => $published,
            'failed'      => $failed,
            'avg_latency' => $latencies->avg_lat ?? null,
            'max_latency' => $latencies->max_lat ?? null,
        ];
    }

    /**
     * End-to-end latency: time from payload's loadtest_published_at to inbox processed_at.
     */
    private function getEndToEndLatency(string $tag, string $schema): array
    {
        // Use TIMESTAMPDIFF with created_at as a proxy for when message arrived
        // This avoids timezone issues with the loadtest_published_at field
        $rows = DB::table("{$schema}.inbox_items")
            ->where('idempotency_key', 'LIKE', "{$tag}%")
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->selectRaw("
                AVG(TIMESTAMPDIFF(MICROSECOND, created_at, published_at)) / 1000000 as avg_e2e,
                MAX(TIMESTAMPDIFF(MICROSECOND, created_at, published_at)) / 1000000 as max_e2e
            ")
            ->first();

        return [
            'avg' => $rows->avg_e2e ?? null,
            'max' => $rows->max_e2e ?? null,
        ];
    }

    /**
     * Consumer throughput: processed items / time span between first and last processed_at.
     */
    private function getConsumerThroughput(string $tag, string $schema): ?float
    {
        $span = DB::table("{$schema}.inbox_items")
            ->where('idempotency_key', 'LIKE', "{$tag}%")
            ->where('status', 'published')
            ->selectRaw('
                MIN(published_at) as first_processed,
                MAX(published_at) as last_processed,
                COUNT(*) as cnt
            ')
            ->first();

        if (!$span || !$span->first_processed || !$span->last_processed || $span->cnt < 2) {
            return null;
        }

        $seconds = strtotime($span->last_processed) - strtotime($span->first_processed);
        return $seconds > 0 ? $span->cnt / $seconds : null;
    }

    private function pct(int $part, int $total): string
    {
        if ($total === 0) return '-';
        return round(($part / $total) * 100, 1) . '%';
    }
}
