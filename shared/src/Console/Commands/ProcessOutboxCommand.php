<?php

namespace SynergyERP\Shared\Console\Commands;

use Illuminate\Console\Command;
use SynergyERP\Shared\Services\OutboxService;

class ProcessOutboxCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'outbox:process {--daemon : Run continuously}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process pending outbox items';

    /**
     * Execute the console command.
     *
     * @param OutboxService $outboxService
     * @return int
     */
    public function handle(OutboxService $outboxService): int
    {
        $daemon = $this->option('daemon');
        
        if ($daemon) {
            $this->info('Starting outbox processor in daemon mode...');
            
            while (true) {
                $this->processItems($outboxService);
                sleep(5); // Wait 5 seconds between batches
            }
        } else {
            $this->info('Processing outbox items...');
            $this->processItems($outboxService);
            $this->info('Done processing outbox items.');
        }
        
        return 0;
    }
    
    /**
     * Process a batch of outbox items
     */
    protected function processItems(OutboxService $outboxService): void
    {
        try {
            $outboxService->processOutboxStack();
        } catch (\Exception $e) {
            $this->error('Error processing outbox items: ' . $e->getMessage());
        }
    }
}
