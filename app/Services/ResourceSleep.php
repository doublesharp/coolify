<?php

namespace App\Services;

use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ResourceSleepPolicy;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Rules\SafeWebhookUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Throwable;

class ResourceSleep
{
    public const OPERATION_SECONDS = 120;

    public const LOCK_SECONDS = 180;

    public const DEPLOYMENT_PREPARATION_SECONDS = 305;

    private float $deadline = 0;

    public const SETTINGS = ['enabled', 'request_activity', 'idle_timeout_seconds', 'minimum_uptime_seconds', 'dependencies', 'webhooks'];

    public function configure(Model $resource, array $settings): ResourceSleepPolicy
    {
        if (! $resource instanceof Application && ! $resource instanceof Service) {
            throw ValidationException::withMessages(['resource' => 'Automatic sleep is available for applications and services.']);
        }

        return Cache::lock('resource-sleep-config:'.$this->server($resource)->id, 300)->block(5, function () use ($resource, $settings) {
            $policy = ResourceSleepPolicy::where('resource_uuid', $resource->uuid)->first();
            if ($policy) {
                return Cache::lock('resource-sleep:'.$policy->id, 300)->block(5, fn () => $this->configureLocked($resource, $settings));
            }

            return $this->configureLocked($resource, $settings);
        });
    }

    private function configureLocked(Model $resource, array $settings): ResourceSleepPolicy
    {
        if (! $resource instanceof Application && ! $resource instanceof Service) {
            throw ValidationException::withMessages(['resource' => 'Automatic sleep is available for applications and services.']);
        }
        if (array_diff(array_keys($settings), self::SETTINGS)) {
            throw ValidationException::withMessages(['settings' => 'Unknown sleep settings.']);
        }
        $existing = ResourceSleepPolicy::where('resource_uuid', $resource->uuid)->first();
        if ($existing && array_key_exists('enabled', $settings) && in_array($settings['enabled'], [false, 0, '0'], true)) {
            $existing->update(['enabled' => false, 'state' => 'disabled', 'containers' => null]);
            if ($existing->request_activity && class_exists(ResourceSleepProxy::class)) {
                try {
                    app(ResourceSleepProxy::class)->remove($existing);
                    $existing->update(['error' => null]);
                } catch (Throwable) {
                    $existing->update(['error' => 'Automatic sleep disabled. Proxy cleanup will be retried.']);
                }
            }

            $existing->update(['request_activity' => false]);

            return $existing->fresh();
        }
        $settings = Validator::make($settings, [
            'enabled' => ['sometimes', 'boolean'],
            'request_activity' => ['sometimes', 'boolean'],
            'idle_timeout_seconds' => ['sometimes', 'integer', 'min:1', 'max:604800'],
            'minimum_uptime_seconds' => ['sometimes', 'integer', 'min:0', 'max:3600'],
            'dependencies' => ['sometimes', 'array', 'max:20'],
            'dependencies.*' => ['required', 'string', 'distinct'],
            'webhooks' => ['sometimes', 'array:before_sleep,after_sleep,before_wake,after_wake'],
            'webhooks.*' => ['nullable', 'url', new SafeWebhookUrl],
        ])->validate();
        if (($settings['request_activity'] ?? false) && ! class_exists(ResourceSleepProxy::class)) {
            throw ValidationException::withMessages(['request_activity' => 'Request activity is not installed.']);
        }
        $server = $this->server($resource);
        if ($server->isSwarm() || $server->isTransferredAway()) {
            throw ValidationException::withMessages(['resource' => 'Automatic sleep requires an active standalone Docker server.']);
        }
        if ($resource instanceof Application && $resource->additional_servers()->exists()) {
            throw ValidationException::withMessages(['resource' => 'Automatic sleep requires a single-server application.']);
        }
        $teamId = $resource->environment->project->team_id;
        if (ResourceSleepPolicy::where('enabled', true)->where('resource_uuid', '!=', $resource->uuid)->whereJsonContains('dependencies', $resource->uuid)->exists()) {
            throw ValidationException::withMessages(['resource' => 'This resource belongs to another sleep policy.']);
        }
        foreach ($settings['dependencies'] ?? [] as $uuid) {
            $dependency = getResourceByUuid($uuid, $teamId);
            if (! $dependency || $dependency instanceof ServiceDatabase || $uuid === $resource->uuid || $dependency->environment_id !== $resource->environment_id || $this->server($dependency)->id !== $server->id) {
                throw ValidationException::withMessages(['dependencies' => 'Dependencies must be dedicated resources in the same environment and server.']);
            }
            if (ResourceSleepPolicy::where('resource_uuid', '!=', $resource->uuid)->where('enabled', true)
                ->where(fn ($query) => $query->where('resource_uuid', $uuid)->orWhereJsonContains('dependencies', $uuid))->exists()) {
                throw ValidationException::withMessages(['dependencies' => 'A dependency cannot belong to another enabled sleep policy.']);
            }
        }
        $policy = ResourceSleepPolicy::firstOrNew(['resource_uuid' => $resource->uuid]);
        if ($policy->exists && ! in_array($policy->state, ['awake', 'disabled'], true)) {
            throw ValidationException::withMessages(['enabled' => 'Wake the resource before changing its sleep settings.']);
        }
        if (! $policy->exists) {
            $policy->forceFill([
                'team_id' => $teamId, 'server_id' => $server->id,
                'webhook_secret' => Str::random(64), 'proxy_token' => Str::random(64),
                'last_activity_at' => now(), 'woke_at' => now(),
            ]);
        }
        $wasRequestActivity = $policy->request_activity;
        $policy->fill($settings)->forceFill(['state' => 'awake', 'error' => null])->save();
        try {
            if ($policy->enabled && $policy->request_activity) {
                app(ResourceSleepProxy::class)->install($server);
                app(ResourceSleepProxy::class)->prepare($policy);
            } elseif ($wasRequestActivity) {
                app(ResourceSleepProxy::class)->remove($policy);
            }
        } catch (Throwable) {
            try {
                app(ResourceSleepProxy::class)->remove($policy);
            } catch (Throwable) {
                // Keep the policy so a later save can retry cleanup when the server recovers.
            }
            $policy->update(['enabled' => false, 'error' => 'Request activity setup failed. Check the proxy and try again.']);
            throw ValidationException::withMessages(['request_activity' => 'Request activity setup failed. Check the proxy and try again.']);
        }

        return $policy->fresh();
    }

    /** Called only from workers: lifecycle failures must never discard an accepted deployment. */
    public function deployment(Model $resource): void
    {
        $policy = $this->policyFor($resource);
        if (! $policy) {
            return;
        }
        try {
            Cache::lock('resource-sleep:'.$policy->id, self::LOCK_SECONDS)->block(self::LOCK_SECONDS + 5, function () use ($policy, $resource) {
                $this->deadline = microtime(true) + self::OPERATION_SECONDS;
                $policy->refresh();
                if (! $policy->enabled) {
                    return;
                }
                $saved = $policy->containers ?? [];
                $policy->update(['state' => 'awake', 'containers' => null, 'error' => null, 'last_activity_at' => now(), 'woke_at' => now()]);
                if ($policy->request_activity && class_exists(ResourceSleepProxy::class)) {
                    try {
                        app(ResourceSleepProxy::class)->remove($policy);
                    } catch (Throwable) {
                        $policy->update(['error' => 'Deployment continues; proxy route cleanup failed.']);
                    }
                }
                $this->afterHook($policy, 'before_wake');
                foreach ([...($policy->dependencies ?? []), $policy->resource_uuid] as $uuid) {
                    if ($uuid === $resource->uuid) {
                        continue;
                    }
                    try {
                        $dependency = getResourceByUuid($uuid, $policy->team_id);
                        if (! $dependency || $this->server($dependency)->id !== $policy->server_id || $dependency->environment_id !== $resource->environment_id) {
                            throw new RuntimeException('Dependency is missing or moved.');
                        }
                        $owned = array_column(app(ResourceSleepDocker::class)->inventory($dependency), 'id');
                        $ids = array_values(array_intersect(array_column($saved[$uuid] ?? [], 'id'), $owned));
                        app(ResourceSleepDocker::class)->start($this->server($dependency), $ids, $this->remaining());
                    } catch (Throwable) {
                        $policy->update(['error' => 'Deployment continues; a dependency could not be resumed.']);
                    }
                }
                $this->afterHook($policy, 'after_wake');
            });
        } catch (Throwable) {
            // The deployment itself remains queued/running and can replace broken retained containers.
            $policy->update(['error' => 'Deployment continues; lifecycle preparation was unavailable.']);
        } finally {
            $this->deadline = 0;
        }
    }

    public function policyFor(?Model $resource): ?ResourceSleepPolicy
    {
        if ($resource instanceof ServiceDatabase || $resource instanceof ServiceApplication) {
            $resource = $resource->service;
        }
        if (! $resource) {
            return null;
        }

        return ResourceSleepPolicy::where('enabled', true)
            ->where(fn ($query) => $query->where('resource_uuid', $resource->uuid)->orWhereJsonContains('dependencies', $resource->uuid))->first();
    }

    public function reconcile(ResourceSleepPolicy $policy): void
    {
        // Keep failed remote cleanup retryable after a resource is deleted, including while asleep.
        if (! getResourceByUuid($policy->resource_uuid, $policy->team_id)) {
            $server = Server::find($policy->server_id);
            if ($policy->request_activity && $server && ! $server->isTransferredAway()) {
                app(ResourceSleepProxy::class)->remove($policy);
            }
            $policy->delete();

            return;
        }
        if (! $policy->enabled && $policy->proxy_snapshot && class_exists(ResourceSleepProxy::class)) {
            app(ResourceSleepProxy::class)->remove($policy);

            return;
        }
        $this->sleep($policy, idleOnly: true);
    }

    public function sleep(ResourceSleepPolicy $policy, bool $idleOnly = false): void
    {
        Cache::lock('resource-sleep:'.$policy->id, self::LOCK_SECONDS)->block(1, function () use ($policy, $idleOnly) {
            $this->deadline = microtime(true) + self::OPERATION_SECONDS;
            $policy->refresh();
            if (! $policy->enabled || $policy->state !== 'awake') {
                return;
            }
            if ($this->deploying($policy) || $this->activeWork($policy)) {
                $policy->update(['last_activity_at' => now()]);

                return;
            }
            $server = Server::findOrFail($policy->server_id);
            if (! $server->isFunctional() || $server->isTransferredAway()) {
                return;
            }
            if ($policy->request_activity && ! $policy->proxy_snapshot) {
                app(ResourceSleepProxy::class)->prepare($policy);
            }
            if ($idleOnly && ($policy->last_activity_at->addSeconds($policy->idle_timeout_seconds)->isFuture()
                || $policy->woke_at->addSeconds($policy->minimum_uptime_seconds)->isFuture())) {
                return;
            }
            if ($this->activeRequests($policy)) {
                return;
            }
            try {
                $resources = $this->resources($policy);
            } catch (Throwable) {
                $policy->update(['error' => 'Automatic sleep requires resources on one active server and in one environment.']);

                return;
            }
            $containers = [];
            foreach ($resources as $resource) {
                $containers[$resource->uuid] = array_values(array_filter(app(ResourceSleepDocker::class)->inventory($resource), fn ($container) => data_get($container, 'state.Running') === true));
            }
            if (empty($containers[$policy->resource_uuid])) {
                // A manually stopped or never deployed resource must not start on a visit.
                return;
            }
            $activityVersion = $policy->activity_version;
            $possiblyStopped = false;
            try {
                if ($policy->request_activity) {
                    app(ResourceSleepProxy::class)->prepare($policy, $containers);
                }
                // A callback increments the version before it can forward a request. Either its
                // write wins and this transition fails, or it observes sleeping and waits to wake.
                $policy->forceFill(['state' => 'sleeping', 'containers' => $containers, 'error' => null]);
                $canSleep = ! $this->activeRequests($policy) && ResourceSleepPolicy::whereKey($policy->id)
                    ->where('enabled', true)->where('state', 'awake')->where('activity_version', $activityVersion)
                    ->when($idleOnly, fn ($query) => $query->where('last_activity_at', '<=', now()->subSeconds($policy->idle_timeout_seconds)))
                    ->update(collect($policy->getAttributes())->only(['state', 'containers', 'error'])->all()) === 1;
                $policy->refresh();
                if (! $canSleep) {
                    return;
                }
                $this->hook($policy, 'before_sleep');
                foreach (array_reverse($resources) as $resource) {
                    $possiblyStopped = true;
                    app(ResourceSleepDocker::class)->stop($server, array_column($containers[$resource->uuid], 'id'), $this->remaining());
                }
                $policy->update(['state' => 'asleep']);
                $this->afterHook($policy, 'after_sleep');
            } catch (Throwable $exception) {
                // Keep the exact container snapshot for an explicit wake/recovery.
                $policy->update(['state' => $possiblyStopped ? 'error' : 'awake', 'containers' => $possiblyStopped ? $containers : null, 'last_activity_at' => now(), 'error' => 'Sleep failed. Check resource health and lifecycle webhook delivery.']);
                throw new RuntimeException('Resource sleep failed.', code: 0);
            }
        });
    }

    public function wake(ResourceSleepPolicy $policy): void
    {
        Cache::lock('resource-sleep:'.$policy->id, self::LOCK_SECONDS)->block(1, function () use ($policy) {
            $this->deadline = microtime(true) + self::OPERATION_SECONDS;
            $policy->refresh();
            if (! $policy->enabled) {
                throw new RuntimeException('Automatic sleep is disabled.');
            }
            if ($policy->state === 'awake') {
                $policy->update(['last_activity_at' => now()]);

                return;
            }
            try {
                $this->hook($policy, 'before_wake');
                $policy->update(['state' => 'waking', 'error' => null]);
                $server = Server::findOrFail($policy->server_id);
                foreach ($this->resources($policy) as $resource) {
                    $saved = $policy->containers[$resource->uuid] ?? [];
                    // A deployment or operator can remove a retained container. Never recreate it here.
                    $owned = array_column(app(ResourceSleepDocker::class)->inventory($resource), 'id');
                    $ids = array_column($saved, 'id');
                    if (array_diff($ids, $owned)) {
                        throw new RuntimeException('The retained deployment has changed. Deploy the resource to recover.');
                    }
                    app(ResourceSleepDocker::class)->start($server, $ids, $this->remaining());
                }
                if ($policy->request_activity && $policy->proxy_snapshot) {
                    app(ResourceSleepProxy::class)->waitForBackends($policy, $this->remaining());
                }
                $policy->update(['state' => 'awake', 'containers' => null, 'error' => null, 'last_activity_at' => now(), 'woke_at' => now()]);
                $this->afterHook($policy, 'after_wake');
            } catch (Throwable $exception) {
                $policy->update(['state' => 'error', 'error' => 'Wake failed. Check resource health and lifecycle webhook delivery.']);
                throw new RuntimeException('Resource wake failed.', code: 0);
            }
        });
    }

    /** Dependencies are started first, and stopped in reverse order after the primary resource. */
    private function resources(ResourceSleepPolicy $policy): array
    {
        $primary = getResourceByUuid($policy->resource_uuid, $policy->team_id);

        return array_map(function ($uuid) use ($policy, $primary) {
            $resource = getResourceByUuid($uuid, $policy->team_id);
            if (! $primary || ! $resource || ($resource instanceof Application && $resource->additional_servers()->exists()) || $resource->environment_id !== $primary->environment_id || $this->server($resource)->id !== $policy->server_id || $this->server($resource)->isTransferredAway()) {
                throw new RuntimeException('A sleep resource is missing or has moved to another server.');
            }

            return $resource;
        }, [...($policy->dependencies ?? []), $policy->resource_uuid]);
    }

    private function deploying(ResourceSleepPolicy $policy): bool
    {
        $ids = Application::whereIn('uuid', [...($policy->dependencies ?? []), $policy->resource_uuid])->pluck('id');

        return ApplicationDeploymentQueue::whereIn('application_id', $ids)->whereIn('status', ['queued', 'in_progress'])->exists()
            || Activity::whereIn('properties->type_uuid', [...($policy->dependencies ?? []), $policy->resource_uuid])
                ->whereIn('properties->status', ['queued', 'in_progress'])->exists();
    }

    public function activeRequests(ResourceSleepPolicy $policy): bool
    {
        return (int) Cache::get('resource-sleep-active:'.$policy->id, 0) > now()->timestamp;
    }

    public function activeWork(ResourceSleepPolicy $policy): bool
    {
        return collect(Cache::get('resource-sleep-work:'.$policy->id, []))->contains(fn ($expires) => $expires > now()->timestamp);
    }

    private function remaining(): int
    {
        $remaining = (int) floor($this->deadline - microtime(true));
        if ($remaining < 1) {
            throw new RuntimeException('Lifecycle operation exceeded its time budget.');
        }

        return $remaining;
    }

    private function afterHook(ResourceSleepPolicy $policy, string $event): void
    {
        try {
            $this->hook($policy, $event);
        } catch (Throwable) {
            $policy->update(['error' => $event.' webhook delivery failed. Resource state was preserved.']);
        }
    }

    /** Only bounded, signed, non-redirecting requests to validated destinations. */
    public function hook(ResourceSleepPolicy $policy, string $event): void
    {
        $url = $policy->webhooks[$event] ?? null;
        if (! $url) {
            return;
        }
        $body = json_encode(['event' => $event, 'resource_uuid' => $policy->resource_uuid, 'delivery_id' => (string) Str::uuid(), 'timestamp' => now()->toIso8601String()], JSON_THROW_ON_ERROR);
        try {
            $options = SafeWebhookUrl::httpClientOptions($url);
            $response = Http::withOptions($options)->withoutRedirecting()->connectTimeout(3)->timeout($this->deadline > 0 ? min(10, $this->remaining()) : 10)
                ->withHeaders(['X-Coolify-Event' => $event, 'X-Coolify-Signature' => 'sha256='.hash_hmac('sha256', $body, $policy->webhook_secret)])
                ->withBody($body, 'application/json')->post($url);
            if (! $response->successful()) {
                throw new RuntimeException('Lifecycle webhook did not accept the event.');
            }
        } catch (Throwable) {
            throw new RuntimeException('Lifecycle webhook delivery failed.');
        }
    }

    public function server(Model $resource): Server
    {
        return $resource instanceof Service ? $resource->server : $resource->destination->server;
    }
}
