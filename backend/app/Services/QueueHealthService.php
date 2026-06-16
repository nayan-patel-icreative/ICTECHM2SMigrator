<?php

namespace App\Services;

use App\Jobs\QueueProbeJob;
use Illuminate\Support\Facades\Cache;

class QueueHealthService
{
    public function probe(int $timeoutMs = 2500): bool
    {
        $probeKey = 'queue_probe:'.bin2hex(random_bytes(16));

        Cache::forget($probeKey);

        // Probe on the same queue used for migrations to avoid false negatives when workers
        // are listening to a subset of queues.
        QueueProbeJob::dispatch($probeKey)->onQueue('default');

        $deadline = microtime(true) + ($timeoutMs / 1000);
        while (microtime(true) < $deadline) {
            if (Cache::has($probeKey)) {
                Cache::forget($probeKey);
                return true;
            }

            usleep(200000);
        }

        return false;
    }

    public function isWorkerOnlineCached(int $cacheSeconds = 10): bool
    {
        $cacheKey = 'queue_health:worker_online';

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return (bool) $cached;
        }

        $online = $this->probe(4000);

        Cache::put($cacheKey, $online ? 1 : 0, now()->addSeconds($cacheSeconds));

        return $online;
    }
}
