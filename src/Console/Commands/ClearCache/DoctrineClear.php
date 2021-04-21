<?php

namespace Itemvirtual\LaravelDoctrine\Console\Commands\ClearCache;

use Illuminate\Console\Command;
use Itemvirtual\LaravelDoctrine\Traits\DoctrineFunctions;


class DoctrineClear extends Command
{
    use DoctrineFunctions;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'doctrine:clear-cache {--flush : If defined, cache entries will be flushed instead of deleted/invalidated}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear metadata, query and result cache of the various cache drivers';

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
        $isFlush = $this->option('flush');
        $parameters = $isFlush ? ['--flush' => true] : [];

        try {
            $this->call('doctrine:clear-cache:metadata', $parameters);
            $this->call('doctrine:clear-cache:query', $parameters);
            $this->call('doctrine:clear-cache:result', $parameters);
        } catch (\Exception $exception) {
            $this->error($exception->getMessage());
        }

        $message = 'Successfully deleted all cache entries.';

        if ($isFlush) {
            $message = 'Successfully flushed all cache entries.';
        }

        $this->info(PHP_EOL . $message);

        return 0;
    }

}
