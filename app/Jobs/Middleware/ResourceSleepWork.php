<?php

namespace App\Jobs\Middleware;

use App\Jobs\DatabaseBackupJob;
use App\Jobs\ReconcileResourceSleep;
use App\Jobs\VolumeBackupJob;
use App\Services\ResourceSleep;
use App\Services\ScheduledJobDeliveryService;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

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
        $databaseBackup = $job instanceof DatabaseBackupJob;
        $volumeBackup = $job instanceof VolumeBackupJob;
        $lease = (string) Str::uuid();
        $key = 'resource-sleep-work:'.$policy->id;
        $ttl = max(1, (int) ($job->timeout ?? 3600)) + 300;
        try {
            $admitted = Cache::lock('resource-sleep:'.$policy->id, ResourceSleep::LOCK_SECONDS)->block(5, function () use ($policy, $key, $lease, $ttl, $databaseBackup, $volumeBackup) {
                $policy->refresh();
                if ($policy->enabled) {
                    if ($databaseBackup && $policy->state === 'error') {
                        throw new RuntimeException('Backup could not start because its resource failed to wake. Wake the resource and retry the backup.');
                    }
                    // A stopped volume can be archived without waking its resource. Transitions
                    // must finish first; a running backup then holds a lease against sleep/wake.
                    if ($policy->state !== 'awake' && ! ($volumeBackup && in_array($policy->state, ['asleep', 'error'], true))) {
                        return false;
                    }
                    // The status monitor may lag a successful wake. Wait rather than reaching
                    // DatabaseBackupJob's normal stopped-database skip path.
                    if ($databaseBackup && ! str($this->resource->refresh()?->status)->startsWith('running')) {
                        return false;
                    }
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
            if ($databaseBackup || $volumeBackup) {
                $policy->refresh();
                if ($databaseBackup && $policy->enabled && $policy->state === 'asleep'
                    && Cache::add('resource-sleep-wake-queued:'.$policy->id, true, 240)) {
                    ReconcileResourceSleep::dispatch($policy->id, 'wake');
                }
                // The job payload's absolute retry deadline bounds these releases; maxExceptions
                // still fails real backup errors immediately through the normal notifications.
                $job->release(15);

                return null;
            }
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
