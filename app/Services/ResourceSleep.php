<?php

namespace App\Services;

use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ResourceSleepPolicy;
use App\Models\Server;
use App\Models\Service;
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
    public const SETTINGS = ['enabled', 'request_activity', 'idle_timeout_seconds', 'minimum_uptime_seconds', 'dependencies', 'webhooks'];

    public function configure(Model $resource, array $settings): ResourceSleepPolicy
    {
        if (! $resource instanceof Application && ! $resource instanceof Service) {
            throw ValidationException::withMessages(['resource' => 'Automatic sleep is available for applications and services.']);
        }
        if (array_diff(array_keys($settings), self::SETTINGS)) {
            throw ValidationException::withMessages(['settings' => 'Unknown sleep settings.']);
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
        if ($settings['request_activity'] ?? false) {
            throw ValidationException::withMessages(['request_activity' => 'Request wake requires the request activity feature.']);
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
        if ($policy->exists && $policy->state !== 'awake') {
            throw ValidationException::withMessages(['enabled' => 'Wake the resource before changing its sleep settings.']);
        }
        if (! $policy->exists) {
            $policy->forceFill([
                'team_id' => $teamId, 'server_id' => $server->id,
                'webhook_secret' => Str::random(64), 'proxy_token' => Str::random(64),
                'last_activity_at' => now(), 'woke_at' => now(),
            ]);
        }
        $policy->fill($settings)->save();

        return $policy->fresh();
    }

    public function deployment(Model $resource): void
    {
        $policy = ResourceSleepPolicy::where('enabled', true)
            ->where(fn ($query) => $query->where('resource_uuid', $resource->uuid)->orWhereJsonContains('dependencies', $resource->uuid))->first();
        if ($policy) {
            $this->wake($policy);
            $policy->update(['last_activity_at' => now(), 'woke_at' => now()]);
        }
    }

    public function reconcile(ResourceSleepPolicy $policy): void
    {
        $this->sleep($policy, idleOnly: true);
    }

    public function sleep(ResourceSleepPolicy $policy, bool $idleOnly = false): void
    {
        Cache::lock('resource-sleep:'.$policy->id, 300)->block(1, function () use ($policy, $idleOnly) {
            $policy->refresh();
            if (! $policy->enabled || $policy->state !== 'awake') {
                return;
            }
            if ($this->deploying($policy)) {
                $policy->update(['last_activity_at' => now()]);

                return;
            }
            if ($idleOnly && ($policy->last_activity_at->addSeconds($policy->idle_timeout_seconds)->isFuture()
                || $policy->woke_at->addSeconds($policy->minimum_uptime_seconds)->isFuture())) {
                return;
            }
            if ($this->activeRequests($policy)) {
                return;
            }
            $server = Server::findOrFail($policy->server_id);
            if (! $server->isFunctional() || $server->isTransferredAway()) {
                return;
            }
            $resources = $this->resources($policy);
            $containers = [];
            foreach ($resources as $resource) {
                $containers[$resource->uuid] = array_values(array_filter(app(ResourceSleepDocker::class)->inventory($resource), fn ($container) => data_get($container, 'state.Running') === true));
            }
            if (empty($containers[$policy->resource_uuid])) {
                // A manually stopped or never deployed resource must not start on a visit.
                return;
            }
            try {
                $this->hook($policy, 'before_sleep');
                $policy->update(['state' => 'sleeping', 'containers' => $containers, 'error' => null]);
                foreach (array_reverse($resources) as $resource) {
                    app(ResourceSleepDocker::class)->stop($server, array_column($containers[$resource->uuid], 'id'));
                }
                $policy->update(['state' => 'asleep']);
                $this->hook($policy, 'after_sleep');
            } catch (Throwable $exception) {
                // Keep the exact container snapshot for an explicit wake/recovery.
                $policy->update(['state' => 'error', 'error' => 'Sleep failed. Check resource health and lifecycle webhook delivery.']);
                throw new RuntimeException('Resource sleep failed.', code: 0);
            }
        });
    }

    public function wake(ResourceSleepPolicy $policy): void
    {
        Cache::lock('resource-sleep:'.$policy->id, 300)->block(1, function () use ($policy) {
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
                    app(ResourceSleepDocker::class)->start($server, $ids);
                }
                $this->hook($policy, 'after_wake');
                $policy->update(['state' => 'awake', 'containers' => null, 'error' => null, 'last_activity_at' => now(), 'woke_at' => now()]);
            } catch (Throwable $exception) {
                $policy->update(['state' => 'error', 'error' => 'Wake failed. Check resource health and lifecycle webhook delivery.']);
                throw new RuntimeException('Resource wake failed.', code: 0);
            }
        });
    }

    /** Dependencies are started first, and stopped in reverse order after the primary resource. */
    private function resources(ResourceSleepPolicy $policy): array
    {
        return array_map(function ($uuid) use ($policy) {
            $resource = getResourceByUuid($uuid, $policy->team_id);
            if (! $resource || $this->server($resource)->id !== $policy->server_id || $this->server($resource)->isTransferredAway()) {
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
        return collect(Cache::get('resource-sleep-requests:'.$policy->id, []))->contains(fn ($expires) => $expires > now()->timestamp);
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
            $response = Http::withOptions($options)->withoutRedirecting()->connectTimeout(3)->timeout(10)
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
