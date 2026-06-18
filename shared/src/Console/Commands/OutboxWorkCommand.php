<?php

namespace SynergyERP\Shared\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use SynergyERP\Shared\Outbox\OutboxWorker;
use Throwable;

final class OutboxWorkCommand extends Command
{
    protected $signature = 'synergy:outbox-work
                            {--sleep=2 : Seconds to sleep when no outbox item is available}
                            {--once : Process a single outbox item and exit}';

    protected $description = 'Run the Outbox worker loop and dispatch OutboxItem records to the EventBus';

    public function handle(OutboxWorker $worker): int
    {
        $sleepSeconds = max(1, (int) $this->option('sleep'));
        $runOnce = (bool) $this->option('once');

        Log::info('Outbox worker command starting', [
            'sleep_seconds' => $sleepSeconds,
            'once' => $runOnce,
        ]);

        $this->info('Outbox worker started');

        try {
            if ($runOnce) {
                $processed = $worker->processNext();

                if ($processed) {
                    $this->info('Processed 1 outbox item');
                } else {
                    $this->info('No dispatchable outbox items found');
                }

                return self::SUCCESS;
            }

            $worker->run($sleepSeconds);

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::error('Outbox worker command crashed', [
                'error_type' => get_class($e),
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
            ]);

            $this->error(sprintf(
                'Outbox worker crashed: %s',
                $e->getMessage()
            ));

            return self::FAILURE;
        }
    }
}