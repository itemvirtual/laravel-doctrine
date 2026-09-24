<?php

namespace Itemvirtual\LaravelDoctrine\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Itemvirtual\LaravelDoctrine\Schema\ConnectionFactory;
use Itemvirtual\LaravelDoctrine\Schema\SchemaBuilder;
use Itemvirtual\LaravelDoctrine\Schema\XmlMappingReader;
use Itemvirtual\LaravelDoctrine\Traits\HelperFunctions;
use Itemvirtual\LaravelDoctrine\Traits\ValidationFunctions;

class DoctrineUpdate extends Command
{
    use HelperFunctions;
    use ValidationFunctions;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'doctrine:update
                            {--D|dump-sql : Dumps generated SQL statements to the console (does not execute them)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update the database (or dump SQL) based on the xml-mappings information';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        if (!$this->validateMappingsPath()) {
            return 0;
        }

        $entities = (new XmlMappingReader())->read(config('laravel-doctrine.xml_mappings_path'));

        if (empty($entities)) {
            $this->info('No mapping information to process.');
            return 0;
        }

        $connection = ConnectionFactory::create();
        $schemaManager = $connection->createSchemaManager();

        $currentSchema = $schemaManager->introspectSchema();
        $targetSchema = (new SchemaBuilder())->build($entities, ConnectionFactory::schemaConfig($connection));

        $diff = $schemaManager->createComparator()->compareSchemas($currentSchema, $targetSchema);
        $queries = $connection->getDatabasePlatform()->getAlterSchemaSQL($diff);

        if (empty($queries)) {
            $this->info('Nothing to update. your database is already in sync with the current xml-mappings.');
            return 0;
        }

        $isDump = $this->option('dump-sql');

        if ($isDump) {
            $pluralization = (count($queries) > 1) ? 'queries will be' : 'query will be';
            $this->comment(count($queries) . ' ' . $pluralization . ' executed');
            foreach ($queries as $query) {
                $this->line('    ' . $query . ';' . PHP_EOL);
            }
            return 0;
        }

        if (env('APP_ENV') == 'production') {
            if (!$this->confirm('Your app is in <comment>PRODUCTION</comment>, Do you wish to continue?')) {
                return 0;
            }
        }

        $this->comment('Updating database schema...');

        foreach ($queries as $query) {
            $connection->executeStatement($query);
        }

        $pluralization = (count($queries) > 1) ? 'queries were' : 'query was';
        $this->info('<comment>' . count($queries) . '</comment> ' . $pluralization . ' executed');
        $this->info('Database schema updated successfully!');

        // Save logs
        if (config('laravel-doctrine.logging', null) && config('laravel-doctrine.save_logs', null)) {
            Log::channel('laravel-doctrine')->info(count($queries) . ' ' . $pluralization . ' executed');
            Log::channel('laravel-doctrine')->info(implode(PHP_EOL . '  ', $queries));
            $this->line('Log saved in <comment>' . $this->getRelativePath(storage_path('logs/laravel-doctrine-' . date('Y-m-d'))) . '.log</comment>');
        }

        return 0;
    }

}