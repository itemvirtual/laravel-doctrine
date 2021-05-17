<?php

namespace Itemvirtual\LaravelDoctrine\Console\Commands;

use Illuminate\Console\Command;
use Doctrine\ORM\Tools\EntityGenerator;
use Illuminate\Support\Str;
use Itemvirtual\LaravelDoctrine\Traits\DoctrineFunctions;
use Itemvirtual\LaravelDoctrine\Traits\HelperFunctions;
use Itemvirtual\LaravelDoctrine\Traits\ValidationFunctions;


class DoctrineGenerateEntities extends Command
{
    use DoctrineFunctions;
    use HelperFunctions;
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
        $this->info('Entities generated in <comment>' . $this->getRelativePath(config('laravel-doctrine.entities_path')) . '</comment>' . PHP_EOL);

        $this->addEntitiesNamespaces();
        $this->addGitignoreFile($entitiesPath);
        return 0;
    }

    /**
     * Add namespaces to generated entities Files
     */
    private function addEntitiesNamespaces()
    {
        $filesExcluded = ['.', '..', '.gitignore'];
        $entitiesFiles = scandir(config('laravel-doctrine.entities_path'));
        $entitiesNamespace = $this->getEntitiesNamespace();

        foreach ($entitiesFiles as $entitiesFile) {
            if (in_array($entitiesFile, $filesExcluded)) {
                continue;
            }
            $this->printNamespaceInFile($entitiesFile, $entitiesNamespace);
        }
    }

    /**
     * Print the namespace in the given file
     * @param $file
     * @param $namespace
     */
    private function printNamespaceInFile($file, $namespace)
    {
        $file = config('laravel-doctrine.entities_path') . '/' . $file;
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        $lines[2] = 'namespace ' . $namespace . ';';
        file_put_contents($file, implode("\n", $lines));
    }

    private function getEntitiesNamespace()
    {
        if (config('laravel-doctrine.entities_namespace')) {
            $arNamespace = preg_split('~[\\\\/]~', config('laravel-doctrine.entities_namespace'));
        } else {
            $arNamespace = $arNamespace = preg_split('~[\\\\/]~', $this->getRelativePath(config('laravel-doctrine.entities_path')));
        }

        foreach ($arNamespace as $k => $namespaceParam) {
            $arNamespace[$k] = ucfirst(Str::camel($namespaceParam));
        }

        return implode('\\', $arNamespace);
    }

}
