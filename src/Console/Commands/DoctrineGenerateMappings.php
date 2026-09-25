<?php

namespace Itemvirtual\LaravelDoctrine\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Itemvirtual\LaravelDoctrine\Schema\ConnectionFactory;
use Itemvirtual\LaravelDoctrine\Schema\DatabaseMappingWriter;
use Itemvirtual\LaravelDoctrine\Traits\HelperFunctions;
use Itemvirtual\LaravelDoctrine\Traits\ValidationFunctions;

class DoctrineGenerateMappings extends Command
{
    use HelperFunctions;
    use ValidationFunctions;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'doctrine:generate-mappings
                            {--path= : The path where your xml-mapping files will be generated}
                            {--table=* : The database tables to be generated}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate xml-mappings from your database';

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

        $path = $this->option('path');
        $tables = $this->option('table');

        $destinationPath = $path ?: config('laravel-doctrine.xml_mappings_path');

        if (!File::isDirectory($destinationPath)) {
            File::makeDirectory($destinationPath, 0755, true);
        }

        $files = File::glob(rtrim($destinationPath, '/') . '/*.dcm.xml');

        if (count($files)) {
            if (!$this->confirm('This action will overwrite your existing xml-mappings in <comment>' . $this->getRelativePath($destinationPath) . '</comment>, Do you wish to continue?')) {
                return 0;
            }
        }

        $connection = ConnectionFactory::create();
        $schemaTables = $connection->createSchemaManager()->introspectSchema()->getTables();

        $result = (new DatabaseMappingWriter())->write($schemaTables, $destinationPath, $tables);

        if (empty($result['written']) && empty($result['skipped']) && empty($result['manyToMany'])) {
            $this->info('No matching tables were found.');
            return 0;
        }

        if (!empty($result['manyToMany'])) {
            $summary = [];
            foreach ($result['manyToMany'] as $joinTable => $owner) {
                $summary[] = $joinTable . ' as <many-to-many> on ' . $owner;
            }
            $this->info('Mapped join tables: <comment>' . implode(', ', $summary) . '</comment>');
        }

        if (!empty($result['skipped'])) {
            $this->warn('Skipped <comment>' . implode(', ', $result['skipped']) . '</comment>: looks like a many-to-many join table (a composite primary key made entirely of foreign keys), but its relation could not be worked out automatically. Add it as a <many-to-many> relation on one of the related entities by hand.');
        }

        if (!empty($result['written'])) {
            $this->info('Generated xml-mappings for <comment>' . implode(', ', $result['written']) . '</comment> in <comment>' . $this->getRelativePath($destinationPath) . '</comment>');
        }

        return 0;
    }

}
