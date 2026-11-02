<?php

namespace App\Jobs;

use App\Models\ResourceSleepPolicy;
use App\Services\ResourceSleep;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReconcileResourceSleep implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(public ?int $policyId = null, public ?string $action = null) {}

    public function handle(ResourceSleep $lifecycle): void
    {
        if ($this->policyId !== null) {
            $policy = ResourceSleepPolicy::find($this->policyId);
            if ($policy) {
                match ($this->action) {
                    'wake' => $lifecycle->wake($policy),
                    'sleep' => $lifecycle->sleep($policy),
                    default => $lifecycle->reconcile($policy),
                };
            }

            return;
        }
        ResourceSleepPolicy::where('enabled', true)->where('state', 'awake')->each(function ($policy) {
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
