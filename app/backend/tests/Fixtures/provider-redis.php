<?php

use Illuminate\Support\Facades\Cache;

return static function (int $port, string $prefix): void {
    if ($port < 1024 || $port === 6379 || preg_match('/\Aprovider-review-[a-f0-9]{32}:\z/', $prefix) !== 1) {
        throw new RuntimeException('Provider integration tests require an explicit disposable Redis port and prefix.');
    }
    config([
        'database.redis.client' => 'predis',
        'database.redis.options.prefix' => $prefix,
        'database.redis.provider_review' => ['host' => '127.0.0.1', 'port' => $port, 'database' => 0, 'password' => null, 'timeout' => 2, 'read_timeout' => 2],
        'cache.stores.provider_review' => ['driver' => 'redis', 'connection' => 'provider_review', 'lock_connection' => 'provider_review', 'prefix' => $prefix],
        'subtitles.tiers.concurrency_cache_store' => 'provider_review',
        'subtitles.tiers.plans.base.batch_concurrency' => 1,
        'subtitles.providers.global_concurrency' => 1,
    ]);
    Cache::forgetDriver('provider_review');
};
