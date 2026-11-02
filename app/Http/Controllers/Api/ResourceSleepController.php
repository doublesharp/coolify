<?php

namespace App\Http\Controllers\Api;

use App\Enums\AccessLevel;
use App\Http\Controllers\Controller;
use App\Jobs\ReconcileResourceSleep;
use App\Models\Application;
use App\Models\ResourceSleepPolicy;
use App\Models\Service;
use App\Services\Access\AccessResolver;
use App\Services\ResourceSleep;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResourceSleepController extends Controller
{
    public function show(Request $request, string $uuid): JsonResponse
    {
        $resource = $this->resource($uuid);
        $this->authorize('view', $resource);
        $policy = ResourceSleepPolicy::where('resource_uuid', $uuid)->first();
        if ($policy && $request->attributes->get('can_read_sensitive') && $request->user()->can('viewSensitive', $resource)) {
            $policy->makeVisible(['webhooks', 'webhook_secret']);
        }

        return response()->json($policy ?? ['enabled' => false]);
    }

    public function update(Request $request, string $uuid, ResourceSleep $lifecycle): JsonResponse
    {
        $resource = $this->resource($uuid);
        $this->authorize('update', $resource);
        $this->authorize('deploy', $resource);
        // Dependencies can contain databases or other applications with separate access restrictions.
        foreach ((array) $request->input('dependencies', []) as $dependencyUuid) {
            $dependency = getResourceByUuid((string) $dependencyUuid, getTeamIdFromToken());
            abort_unless($dependency, 422, 'Invalid dependency.');
            $this->authorize('deploy', $dependency);
        }
        $currentPolicy = ResourceSleepPolicy::where('resource_uuid', $uuid)->first();
        if ($request->boolean('request_activity', $currentPolicy?->request_activity ?? false)) {
            abort_unless(app(AccessResolver::class)->allowsTeamWide($request->user(), AccessLevel::Write, (int) getTeamIdFromToken()), 403, 'Managing request activity requires team-wide write access.');
            $this->authorize('manageProxy', $lifecycle->server($resource));
        }
        $policy = $lifecycle->configure($resource, $request->all());

        return response()->json($policy);
    }

    public function sleep(string $uuid): JsonResponse
    {
        return $this->transition($uuid, 'sleep');
    }

    public function wake(string $uuid): JsonResponse
    {
        return $this->transition($uuid, 'wake');
    }

    private function transition(string $uuid, string $action): JsonResponse
    {
        $resource = $this->resource($uuid);
        $this->authorize('deploy', $resource);
        $policy = ResourceSleepPolicy::where('resource_uuid', $uuid)->where('enabled', true)->firstOrFail();
        ReconcileResourceSleep::dispatch($policy->id, $action);

        return response()->json(['message' => ucfirst($action).' requested.', 'resource_uuid' => $uuid], 202);
    }

    private function resource(string $uuid): Model
    {
        $resource = getResourceByUuid($uuid, getTeamIdFromToken());
        abort_unless($resource instanceof Application || $resource instanceof Service, 404);

        return $resource;
    }
}
