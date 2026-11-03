<?php

namespace App\Livewire\Project\Shared;

use App\Jobs\ReconcileResourceSleep;
use App\Models\Application;
use App\Models\ResourceSleepPolicy;
use App\Models\Service;
use App\Services\ResourceSleep;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ResourceSleepSettings extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public Application|Service $resource;

    public bool $enabled = false;

    public bool $requestActivity = false;

    public int $idleMinutes = 30;

    public string $dependencies = '';

    public array $webhooks = [];

    public function mount(): void
    {
        $this->authorize('view', $this->resource);
        $policy = $this->policy();
        if ($policy) {
            $this->enabled = $policy->enabled;
            $this->requestActivity = $policy->request_activity;
            $this->idleMinutes = (int) ceil($policy->idle_timeout_seconds / 60);
            $this->dependencies = implode(', ', $policy->dependencies ?? []);
            if (auth()->user()->can('viewSensitive', $this->resource)) {
                $this->webhooks = $policy->webhooks ?? [];
            }
        }
    }

    public function save(): void
    {
        $this->authorize('update', $this->resource);
        $this->authorize('deploy', $this->resource);
        $this->validate(['idleMinutes' => 'integer|min:1|max:10080']);
        $lifecycle = app(ResourceSleep::class);
        if ($this->requestActivity) {
            $this->authorize('manageProxy', $lifecycle->server($this->resource));
        }
        $dependencies = array_values(array_filter(array_map('trim', explode(',', $this->dependencies))));
        foreach ($dependencies as $uuid) {
            $dependency = getResourceByUuid($uuid, $this->resource->environment->project->team_id);
            abort_unless($dependency, 422, 'Invalid dependency.');
            $this->authorize($dependency instanceof Application || $dependency instanceof Service ? 'deploy' : 'manage', $dependency);
        }
        $settings = ['enabled' => $this->enabled, 'request_activity' => $this->requestActivity,
            'idle_timeout_seconds' => $this->idleMinutes * 60, 'dependencies' => $dependencies];
        if (auth()->user()->can('viewSensitive', $this->resource)) {
            $settings['webhooks'] = $this->webhooks;
        }
        $lifecycle->configure($this->resource, $settings);
        $this->dispatch('success', 'Sleep settings saved.');
    }

    public function transition(string $action): void
    {
        $this->authorize('deploy', $this->resource);
        abort_unless(in_array($action, ['sleep', 'wake'], true), 422);
        $policy = $this->policy();
        abort_unless($policy?->enabled, 422, 'Enable automatic sleep first.');
        ReconcileResourceSleep::dispatch($policy->id, $action);
        $this->dispatch('success', ucfirst($action).' requested.');
    }

    public function policy(): ?ResourceSleepPolicy
    {
        return ResourceSleepPolicy::where('resource_uuid', $this->resource->uuid)->first();
    }

    public function render(): View
    {
        $this->authorize('view', $this->resource);

        return view('livewire.project.shared.resource-sleep-settings', ['policy' => $this->policy()]);
    }
}
