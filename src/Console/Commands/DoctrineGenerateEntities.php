<?php

namespace Itemvirtual\LaravelDoctrine\Console\Commands;

use Illuminate\Console\Command;
use Doctrine\ORM\Tools\EntityGenerator;
use Itemvirtual\LaravelDoctrine\Traits\DoctrineFunctions;
use Itemvirtual\LaravelDoctrine\Traits\ValidationFunctions;


class DoctrineGenerateEntities extends Command
{
    use DoctrineFunctions;
    use ValidationFunctions;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'doctrine:generate-entities';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate entity classes and method stubs from your mapping information (xml-mappings)';

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

        $entityManager = $this->getXMLEntityManager();
        $metadata = $this->getMetaData($entityManager);

        $entitiesPath = config('laravel-doctrine.entities_path');

        $entityGenerator = new EntityGenerator();

        $entityGenerator->setGenerateAnnotations(true);
        $entityGenerator->setGenerateStubMethods(true);
        $entityGenerator->setRegenerateEntityIfExists(true);
        $entityGenerator->setUpdateEntityIfExists(true);
        $entityGenerator->setNumSpaces(4);
        $entityGenerator->setAnnotationPrefix('ORM\\');

        // Generating Entities
        $entityGenerator->generate($metadata, $entitiesPath);
        $this->info('Entities generated in <comment>' . str_replace(base_path() . '/', '', config('laravel-doctrine.entities_path')) . '</comment>' . PHP_EOL);

        $this->addGitignoreFile($entitiesPath);
        return 0;
    }

}
