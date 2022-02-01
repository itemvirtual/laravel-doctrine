<?php

namespace Itemvirtual\LaravelDoctrine\Console\Commands;

use Doctrine\ORM\Tools\SchemaTool;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Itemvirtual\LaravelDoctrine\Traits\DoctrineFunctions;
use Itemvirtual\LaravelDoctrine\Traits\HelperFunctions;
use Itemvirtual\LaravelDoctrine\Traits\ValidationFunctions;


class DoctrineUpdate extends Command
{
    use DoctrineFunctions;
    use HelperFunctions;
    use ValidationFunctions;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'doctrine:update 
                            {--D|dump-sql : Dumps generated SQL statements to the console (does not execute them)} 
                            {--R|remove-entities : Delete current entities before generating new ones}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update the database (or dump SQL) based on the entities information';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */


    public function handle()
    {
        /**
         * Validate entities and xml-mappings configuration
         */
        if (!$this->validateDirectories()) {
            return 0;
        }

        $isDump = $this->option('dump-sql');
        $removeEntities = $this->option('remove-entities');

        if ($removeEntities) {
            $this->call('doctrine:remove-entities');
        }

        // Generate entities
        $this->call('doctrine:generate-entities');

        $entityManager = $this->getAnnotationEntityManager();
        $metadata = $this->getMetaData($entityManager);

        // Issue with type boolean default value 0 (missing in annotations)
        foreach ($metadata as $k_data => $data) {
            foreach ($data->fieldMappings as $k_mapping => $mapping) {
                $mappingTypes = ['boolean', 'smallint'];
                if (in_array($mapping['type'], $mappingTypes)) {
                    if (!array_key_exists('nullable', $mapping) || !$mapping['nullable']) {
                        if (!array_key_exists('options', $mapping)) {
                            $metadata[$k_data]->fieldMappings[$k_mapping]['options']['default'] = '0';
                        } else {
                            if (!array_key_exists('default', $mapping['options'])) {
                                $metadata[$k_data]->fieldMappings[$k_mapping]['options']['default'] = '0';
                            }
                        }
                    }
                }
            }
        }

        if (empty($metadata)) {
            $this->info('No mapping information to process.');
            return 0;
        }

        $schemaTool = new SchemaTool($entityManager);

        $queries = $schemaTool->getUpdateSchemaSql($metadata, false);

        if (empty($queries)) {
            $this->info('Nothing to update. your database is already in sync with the current entity metadata.');
            return 0;
        }

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

        $schemaTool->updateSchema($metadata, true);

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
