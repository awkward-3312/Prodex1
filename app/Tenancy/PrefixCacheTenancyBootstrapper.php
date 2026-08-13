<?php

declare(strict_types=1);

namespace App\Tenancy;

use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Drop-in replacement for Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper.
 *
 * Uses key-prefixing instead of cache tags, so it works with ALL cache drivers
 * (file, database, array, redis, memcached, etc.).
 */
class PrefixCacheTenancyBootstrapper implements TenancyBootstrapper
{
    protected ?CacheManager $originalCache = null;
    protected Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function bootstrap(Tenant $tenant): void
    {
        $this->resetFacadeCache();

        $this->originalCache = $this->originalCache ?? $this->app['cache'];

        $tagBase = config('tenancy.cache.tag_base', 'tenant');
        $prefix  = $tagBase . '_' . $tenant->getTenantKey();

        $this->app->extend('cache', function () use ($prefix) {
            $manager = new PrefixCacheManager($this->app);
            $manager->setTenantPrefix($prefix);

            return $manager;
        });
    }

    public function revert(): void
    {
        $this->resetFacadeCache();

        $this->app->extend('cache', function () {
            return $this->originalCache;
        });

        $this->originalCache = null;
    }

    protected function resetFacadeCache(): void
    {
        Cache::clearResolvedInstances();
    }
}
