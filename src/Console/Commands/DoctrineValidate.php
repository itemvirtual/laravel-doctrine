<?php

namespace Itemvirtual\LaravelDoctrine\Console\Commands;

use Illuminate\Console\Command;
use Itemvirtual\LaravelDoctrine\Schema\ConnectionFactory;
use Itemvirtual\LaravelDoctrine\Schema\SchemaBuilder;
use Itemvirtual\LaravelDoctrine\Schema\XmlMappingReader;
use Itemvirtual\LaravelDoctrine\Traits\ValidationFunctions;
use RuntimeException;

class DoctrineValidate extends Command
{
    use ValidationFunctions;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'doctrine:validate';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Validate mappings and synchronization with the database';

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

        try {
            $entities = (new XmlMappingReader())->read(config('laravel-doctrine.xml_mappings_path'));
        } catch (RuntimeException $exception) {
            $this->error('The mapping files are invalid: ' . $exception->getMessage());
            return 0;
        }

        if (empty($entities)) {
            $this->info('No mapping information to process.');
            return 0;
        }

        $connection = ConnectionFactory::create();
        $schemaManager = $connection->createSchemaManager();

        try {
            $targetSchema = (new SchemaBuilder())->build($entities, ConnectionFactory::schemaConfig($connection));
        } catch (RuntimeException $exception) {
            $this->error('The mapping files are invalid: ' . $exception->getMessage());
            return 0;
        }

        $this->info('The mapping files are valid.');

        $currentSchema = $schemaManager->introspectSchema();
        $diff = $schemaManager->createComparator()->compareSchemas($currentSchema, $targetSchema);

        if (!$diff->isEmpty()) {
            $this->warn('The database schema is not in sync with the current mapping files.');
        } else {
            $this->info('The database schema is in sync with the mapping files.');
        }

        return 0;
    }

}