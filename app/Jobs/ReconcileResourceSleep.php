<?php

namespace App\Jobs;

use App\Models\ResourceSleepPolicy;
use App\Services\ResourceSleep;
use App\Services\ScheduledJobDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReconcileResourceSleep implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $maxExceptions = 1;

    public int $retryUntil;

    public int $timeout = 150;

    public function __construct(public ?int $policyId = null, public ?string $action = null)
    {
        $this->onQueue('resource-lifecycle');
        $this->retryUntil = now()->addHours(ScheduledJobDeliveryService::LATE_RUN_MAX_AGE_HOURS)->timestamp;
    }

    public function handle(ResourceSleep $lifecycle): void
    {
        if ($this->policyId !== null) {
            $policy = ResourceSleepPolicy::find($this->policyId);
            if ($policy) {
                try {
                    if ($this->action === 'wake' && $policy->state !== 'awake' && $lifecycle->activeWork($policy)) {
                        // Keep an accepted wake queued until the offline backup releases its lease.
                        Cache::put('resource-sleep-wake-queued:'.$policy->id, true, 240);
                        $this->release(15);

                        return;
                    }
                    match ($this->action) {
                        'wake' => $lifecycle->wake($policy),
                        'sleep' => $lifecycle->sleep($policy),
                        default => $lifecycle->reconcile($policy),
                    };
                } catch (LockTimeoutException) {
                    // Another lifecycle operation already owns this resource.
                } finally {
                    if ($this->action === 'wake' && ! $this->job?->isReleased()) {
                        Cache::forget('resource-sleep-wake-queued:'.$policy->id);
                    }
                }
            }

            return;
        }
        ResourceSleepPolicy::where(fn ($query) => $query->where('enabled', true)->orWhereNotNull('proxy_snapshot'))->each(function ($policy) {
            self::dispatch($policy->id);
        });
    }

    public function failed(?Throwable $exception): void
    {
        if ($this->policyId !== null) {
            ResourceSleepPolicy::whereKey($this->policyId)->whereIn('state', ['sleeping', 'waking'])->update(['state' => 'error', 'error' => 'Lifecycle operation interrupted. Wake the resource to recover.']);
        }
        // Do not log signed URLs, API credentials or remote command output.
        Log::warning('Resource lifecycle job failed.', ['policy_id' => $this->policyId, 'action' => $this->action]);
    }
}
