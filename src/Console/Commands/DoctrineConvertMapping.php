<?php

namespace Itemvirtual\LaravelDoctrine\Console\Commands;

use Doctrine\ORM\Tools\Export\ClassMetadataExporter;
use Doctrine\ORM\Tools\Export\Driver\AbstractExporter;
use InvalidArgumentException;
use Doctrine\ORM\Mapping\Driver\DatabaseDriver;
use Doctrine\ORM\Tools\Console\MetadataFilter;
use Doctrine\ORM\Tools\DisconnectedClassMetadataFactory;
use Doctrine\ORM\Tools\EntityGenerator;
use Doctrine\ORM\Tools\Export\Driver\AnnotationExporter;
use Illuminate\Console\Command;
use Itemvirtual\LaravelDoctrine\Traits\DoctrineFunctions;
use Itemvirtual\LaravelDoctrine\Traits\ValidationFunctions;


class DoctrineConvertMapping extends Command
{
    use DoctrineFunctions;
    use ValidationFunctions;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'doctrine:convert-mapping 
                            {to-type : The mapping type to be converted}
                            {dest-path : The path to generate your entities classes}
                            {filter : A string pattern used to match entities that should be processed}
                            {--force : Force to overwrite existing mapping files}
                            {--from-database : Whether or not to convert mapping information from existing database}
                            {--extend= : Defines a base class to be extended by generated entity classes}
                            {--num-spaces=4 : Defines the number of indentation spaces (default 4)}
                            {--namespace= : Defines a namespace for the generated entity classes, if converted from database}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Convert mapping information between supported formats';

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
     * @throws \Doctrine\DBAL\Exception
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\Tools\Export\ExportException
     */
    public function handle()
    {
        /**
         * Validate entities and xml-mappings configuration
         */
        if (!$this->validateDirectories()) {
            return 0;
        }

        $toType = $this->argument('to-type');
        $destPath = $this->argument('dest-path');
        $filter = $this->argument('filter');

        $isForce = $this->option('force');
        $isFromDatabase = $this->option('from-database');
        $extend = $this->option('extend');
        $numSpaces = $this->option('num-spaces');
        $namespace = $this->option('namespace');

        $entityManager = $this->getAnnotationEntityManager();

        if ($isFromDatabase) {
            $databaseDriver = new DatabaseDriver(
                $entityManager->getConnection()->getSchemaManager()
            );

            $entityManager->getConfiguration()->setMetadataDriverImpl(
                $databaseDriver
            );

            if ($namespace !== null) {
                $databaseDriver->setNamespace($namespace);
            }
        }

        $classMetadataFactory = new DisconnectedClassMetadataFactory();
        $classMetadataFactory->setEntityManager($entityManager);
        $metadata = $classMetadataFactory->getAllMetadata();
        $metadata = MetadataFilter::filter($metadata, $filter);

        // Process destination directory
        if (!is_dir($destPath)) {
            mkdir($destPath, 0775, true);
        }

        $realDestPath = realpath($destPath);

        if (!file_exists($realDestPath)) {
            throw new InvalidArgumentException(
                sprintf("Mapping destination directory '%s' does not exist.", $destPath)
            );
        }

        if (!is_writable($realDestPath)) {
            throw new InvalidArgumentException(
                sprintf("Mapping destination directory '%s' does not have write permissions.", $realDestPath)
            );
        }

        $toType = strtolower($toType);

        $exporter = $this->getExporter($toType, $realDestPath);
        $exporter->setOverwriteExistingFiles($isForce);

        if ($exporter instanceof AnnotationExporter) {
            $entityGenerator = new EntityGenerator();
            $exporter->setEntityGenerator($entityGenerator);

            $entityGenerator->setNumSpaces((int)$numSpaces);

            if ($extend !== null) {
                $entityGenerator->setClassToExtend($extend);
            }
        }

        if (empty($metadata)) {
            $this->info('No Metadata Classes to process.');
            return 0;
        }

        foreach ($metadata as $class) {
            $this->line('Processing entity <comment>' . $class->name . '</comment>');
        }

        $exporter->setMetadata($metadata);
        $exporter->export();

        $this->info('Exporting ' . $toType . ' mapping information to <comment>' . str_replace(base_path() . '/', '', $realDestPath) . '</comment>');

        return 0;
    }

    /**
     * @param string $toType
     * @param string $destPath
     *
     * @return AbstractExporter
     */
    protected function getExporter($toType, $destPath)
    {
        $ClassMetadataExporter = new ClassMetadataExporter();
        return $ClassMetadataExporter->getExporter($toType, $destPath);
    }

}
