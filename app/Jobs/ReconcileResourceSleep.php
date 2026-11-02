<?php

namespace App\Jobs;

use App\Models\ResourceSleepPolicy;
use App\Services\ResourceSleep;
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

    public int $timeout = 150;

    public function __construct(public ?int $policyId = null, public ?string $action = null)
    {
        $this->onQueue('resource-lifecycle');
    }

    public function handle(ResourceSleep $lifecycle): void
    {
        if ($this->policyId !== null) {
            $policy = ResourceSleepPolicy::find($this->policyId);
            if ($policy) {
                try {
                    match ($this->action) {
                        'wake' => $lifecycle->wake($policy),
                        'sleep' => $lifecycle->sleep($policy),
                        default => $lifecycle->reconcile($policy),
                    };
                } catch (LockTimeoutException) {
                    // Another lifecycle operation already owns this resource.
                } finally {
                    if ($this->action === 'wake') {
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
