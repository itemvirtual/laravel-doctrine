<?php

namespace Itemvirtual\LaravelDoctrine\Console\Commands;

use Doctrine\ORM\Tools\SchemaValidator;
use Illuminate\Console\Command;
use Itemvirtual\LaravelDoctrine\Traits\DoctrineFunctions;
use Itemvirtual\LaravelDoctrine\Traits\ValidationFunctions;


class DoctrineValidate extends Command
{
    use DoctrineFunctions;
    use ValidationFunctions;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'doctrine:validate {--R|remove-entities : Delete current entities before generating new ones}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Validate mappings and synchronization with the database';

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
     * @throws \Doctrine\ORM\ORMException
     */
    public function handle()
    {
        /**
         * Validate entities and xml-mappings configuration
         */
        if (!$this->validateDirectories()) {
            return 0;
        }

        $removeEntities = $this->option('remove-entities');

        if ($removeEntities) {
            $this->call('doctrine:remove-entities');
        }

        // Generate entities
        $this->call('doctrine:generate-entities');

        $entityManager = $this->getAnnotationEntityManager();

        $schemaValidator = new SchemaValidator($entityManager);

        try {
            $errors = $schemaValidator->validateMapping();
            foreach ($errors as $className => $errorMessages) {
                $this->error('The entity-class ' . $className . ' mapping is invalid');
            }
        } catch (\Exception $exception) {
            $this->error($exception->getMessage());
            return 0;
        }

        $synced = $schemaValidator->schemaInSyncWithMetadata();
        if (!$synced) {
            $this->warn('The database schema is not in sync with the current mapping file.');
        } else {
            $this->info('The database schema is in sync with the mapping files.');
        }

        return 0;
    }

}
