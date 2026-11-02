<?php

namespace App\Jobs\Middleware;

use App\Services\ResourceSleep;
use App\Services\ScheduledJobDeliveryService;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Serializes work admission with the sleep decision, then protects work for its bounded lifetime. */
class ResourceSleepWork
{
    public function __construct(public ?Model $resource) {}

    public function handle(object $job, Closure $next): mixed
    {
        $policy = app(ResourceSleep::class)->policyFor($this->resource);
        if (! $policy) {
            return $next($job);
        }
        $lease = (string) Str::uuid();
        $key = 'resource-sleep-work:'.$policy->id;
        $ttl = max(1, (int) ($job->timeout ?? 3600)) + 300;
        try {
            $admitted = Cache::lock('resource-sleep:'.$policy->id, ResourceSleep::LOCK_SECONDS)->block(5, function () use ($policy, $key, $lease, $ttl) {
                $policy->refresh();
                if ($policy->enabled && $policy->state !== 'awake') {
                    return false;
                }
                $leases = array_filter(Cache::get($key, []), fn ($expires) => $expires > now()->timestamp);
                $leases[$lease] = now()->timestamp + $ttl;
                Cache::put($key, $leases, max($leases) - now()->timestamp);

                return true;
            });
        } catch (LockTimeoutException) {
            $admitted = false;
        }
        if (! $admitted) {
            if ($job->occurrenceUuid ?? null) {
                app(ScheduledJobDeliveryService::class)->skip($job->occurrenceUuid, 'resource is sleeping or changing lifecycle state');
            }
            Log::channel('scheduled')->info('Scheduled work skipped while its resource is asleep.', ['policy_id' => $policy->id]);

            return null;
        }
        try {
            return $next($job);
        } finally {
            try {
                Cache::lock('resource-sleep:'.$policy->id, ResourceSleep::LOCK_SECONDS)->block(5, function () use ($key, $lease) {
                    $leases = Cache::get($key, []);
                    unset($leases[$lease]);
                    $leases = array_filter($leases, fn ($expires) => $expires > now()->timestamp);
                    Cache::put($key, $leases, $leases === [] ? 1 : max($leases) - now()->timestamp);
                });
            } catch (LockTimeoutException) {
                // A lost completion leaves only a bounded lease; it must not fail successful work.
            }
        }
    }
}
