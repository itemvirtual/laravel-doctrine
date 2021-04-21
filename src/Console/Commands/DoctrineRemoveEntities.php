<?php

namespace Itemvirtual\LaravelDoctrine\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Itemvirtual\LaravelDoctrine\Traits\ValidationFunctions;


class DoctrineRemoveEntities extends Command
{
    use ValidationFunctions;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'doctrine:remove-entities';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove all entities';

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

        $files = File::files(config('laravel-doctrine.entities_path'));

        if (is_array($files) && count($files)) {
            shell_exec('rm ' . config('laravel-doctrine.entities_path') . '/*');
        }

        return 0;
    }

}
