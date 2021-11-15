<?php

namespace Itemvirtual\LaravelDoctrine\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;


class MigrationsGenerate extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'doctrine:migrations-generate 
                            {path=tests/database/migrations : Path where migrations will be stored}
                            {--R|remove : Remove previous generated migration files}
                            {--O|output : View migrations package console output}
                            {--S|single-file=true : Generate all migrations in a single file}
                            {--T|tables= : A list of Tables or Views you wish to Generate Migrations separated by comma: users,products,labels}
                            {--I|ignore= : A list of Tables or Views you wish to ignore, separated by comma: users,products,labels}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate laravel migration files from database';

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
        $path = trim($this->argument('path'));
        $output = $this->option('output');
        $remove = $this->option('remove');
        $tables = $this->option('tables');
        $ignore = $this->option('ignore');
        $singleFile = $this->option('single-file');
        $singleFile = filter_var($singleFile, FILTER_VALIDATE_BOOLEAN);

        if ($tables) {
            $params['--tables'] = $tables;
        }

        if ($ignore) {
            $params['--ignore'] = $ignore;
        }

        if ($singleFile) {
            $params['--squash'] = $singleFile;
        }

        $params['--path'] = $path;
        $params['--date'] = '2012-11-20 00:00:00';
        $params['--no-interaction'] = true;

        // Check that path is writable
        (new Filesystem)->ensureDirectoryExists(base_path($path));

        if (!(new Filesystem)->isWritable(base_path($path))) {
            $this->error('The destination path <comment>' . $path . '</comment> is not writable');
            return 0;
        }

        // Remove previous files
        if ($remove) {
            (new Filesystem)->deleteDirectory(base_path($path), true);
        }

        // Run the package command with custom params
        if ($output) {
            $this->call('migrate:generate', $params);
        } else {
            $this->callSilent('migrate:generate', $params);
        }

        $this->info('Migrations files generated in <comment>' . $path . '</comment>');

        return 0;
    }

}
