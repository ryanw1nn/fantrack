<?php

namespace SynergyERP\Shared\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SynergyERP\Shared\Database\Migrations\CreateOutboxItemsTableMigration;

class CreateOutboxItemsTablesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'outbox:create-tables {--schema= : Specific schema to create tables in}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create outbox_items tables in all tenant schemas or a specific schema';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $specificSchema = $this->option('schema');
        
        if ($specificSchema) {
            $this->createTablesForSchema($specificSchema);
        } else {
            $this->createTablesForAllSchemas();
        }
        
        return 0;
    }
    
    /**
     * Create outbox_items table in a specific schema
     *
     * @param string $schema
     * @return void
     */
    protected function createTablesForSchema(string $schema)
    {
        $this->info("Creating outbox_items table in schema: {$schema}");
        
        try {
            $migration = new CreateOutboxItemsTableMigration();
            $migration->up($schema);
            
            $this->info("Successfully created outbox_items table in schema: {$schema}");
        } catch (\Exception $e) {
            $this->error("Failed to create outbox_items table in schema {$schema}: {$e->getMessage()}");
            Log::error("Failed to create outbox_items table in schema {$schema}: {$e->getMessage()}", [
                'exception' => $e
            ]);
        }
    }
    
    /**
     * Create outbox_items tables in all tenant schemas
     *
     * @return void
     */
    protected function createTablesForAllSchemas()
    {
        $this->info("Creating outbox_items tables in all tenant schemas");
        
        // Get all databases except system ones
        $databases = DB::select("SHOW DATABASES WHERE `Database` NOT IN ('information_schema', 'mysql', 'performance_schema', 'sys')");
        
        $migration = new CreateOutboxItemsTableMigration();
        $successCount = 0;
        $failCount = 0;
        
        foreach ($databases as $database) {
            $schema = $database->Database;
            
            $this->info("Processing schema: {$schema}");
            
            try {
                $migration->up($schema);
                $this->info("Successfully created outbox_items table in schema: {$schema}");
                $successCount++;
            } catch (\Exception $e) {
                $this->error("Failed to create outbox_items table in schema {$schema}: {$e->getMessage()}");
                Log::error("Failed to create outbox_items table in schema {$schema}: {$e->getMessage()}", [
                    'exception' => $e
                ]);
                $failCount++;
            }
        }
        
        $this->info("Completed creating outbox_items tables. Success: {$successCount}, Failed: {$failCount}");
    }
}
