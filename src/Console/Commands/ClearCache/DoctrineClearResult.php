<?php

namespace Itemvirtual\LaravelDoctrine\Console\Commands\ClearCache;

use InvalidArgumentException;
use LogicException;
use Doctrine\Common\Cache\ApcCache;
use Doctrine\Common\Cache\XcacheCache;
use Illuminate\Console\Command;
use Itemvirtual\LaravelDoctrine\Traits\DoctrineFunctions;


class DoctrineClearResult extends Command
{
    use DoctrineFunctions;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'doctrine:clear-cache:result {--flush : If defined, cache entries will be flushed instead of deleted/invalidated}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear all result cache of the various cache drivers';

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

        $entityManager = $this->getAnnotationEntityManager();
        $cacheDriver = $entityManager->getConfiguration()->getResultCacheImpl();

        if (!$cacheDriver) {
            throw new InvalidArgumentException('No Result cache driver is configured on given EntityManager.');
        }

        if ($cacheDriver instanceof ApcCache) {
            throw new LogicException('Cannot clear APC Cache from Console, its shared in the Webserver memory and not accessible from the CLI.');
        }

        if ($cacheDriver instanceof XcacheCache) {
            throw new LogicException('Cannot clear XCache Cache from Console, its shared in the Webserver memory and not accessible from the CLI.');
        }

        $this->comment('Clearing all Result cache entries');

        $result = $cacheDriver->deleteAll();
        $message = $result ? 'Successfully deleted cache entries.' : 'No cache entries were deleted.';

        if ($isFlush) {
            $result = $cacheDriver->flushAll();
            $message = $result ? 'Successfully flushed cache entries.' : $message;
        }

        $this->info($message);

        return 0;
    }

}
