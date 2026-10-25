<?php

namespace App\Console\Commands;

use App\Services\PictureCache;
use Illuminate\Console\Command;

/**
 * Daily (routes/console.php): scenes nobody's been sent in a while leave the shared picture cache.
 */
class EvictPictureCacheCommand extends Command
{
    protected $signature = 'pictures:evict {--days= : Unused for this many days (default 30)}';

    protected $description = 'Drop scenes from the shared picture cache that nobody has been sent in a while';

    public function handle(PictureCache $cache): int
    {
        $days = (int) ($this->option('days') ?: PictureCache::EVICT_AFTER_DAYS);
        $this->info("Dropped {$cache->evict($days)} cached pictures unused for {$days} days.");

        return self::SUCCESS;
    }
}
