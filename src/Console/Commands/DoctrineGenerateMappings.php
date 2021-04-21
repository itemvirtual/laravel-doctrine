<?php

namespace Itemvirtual\LaravelDoctrine\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Itemvirtual\LaravelDoctrine\Traits\DoctrineFunctions;
use Itemvirtual\LaravelDoctrine\Traits\ValidationFunctions;


class DoctrineGenerateMappings extends Command
{
    use DoctrineFunctions;
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

        $path = $this->option('path');
        $tables = $this->option('table');

        $destinationPath = $path ? $path : config('laravel-doctrine.xml_mappings_path');

        foreach ($tables as $k_table => $table) {
            $tables[$k_table] = ucfirst(Str::camel($table));
        }

        $files = [];
        if (File::isDirectory($destinationPath)) {
            $files = File::files($destinationPath);
        }

        if (count($files)) {
            $mappingsPath = str_replace(base_path() . '/', '', $destinationPath);
            if (!$this->confirm('This action will overwrite your existing xml-mappings in <comment>' . $mappingsPath . '</comment>, Do you wish to continue?')) {
                return 0;
            }
        }

        // orm:convert-mapping --from-database xml ./src/xml-mappings/ --force
        $parameters = [
            'to-type' => 'xml',
            'dest-path' => $destinationPath,
            'filter' => $tables,
            '--force' => true,
            '--from-database' => true,
        ];

        try {
            $this->call('doctrine:convert-mapping', $parameters);
        } catch (\Exception $exception) {
            $this->error($exception->getMessage());
        }

        return 0;
    }

}
