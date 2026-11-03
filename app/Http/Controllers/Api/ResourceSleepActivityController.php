<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ReconcileResourceSleep;
use App\Models\ResourceSleepPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** The proxy credential can report activity and wake one enabled resource, nothing else. */
class ResourceSleepActivityController extends Controller
{
    public function __invoke(Request $request, string $uuid): JsonResponse
    {
        $policy = ResourceSleepPolicy::where('resource_uuid', $uuid)->first();
        abort_unless($policy && is_string($request->bearerToken()) && hash_equals($policy->proxy_token, $request->bearerToken()), 404);
        $data = $request->validate([
            'request_id' => ['required', 'string', 'regex:/\A[a-f0-9]{32}\z/D'],
            'phase' => ['required', 'in:begin,heartbeat,end,probe'],
        ]);
        // Retained routes can be removed asynchronously after emergency disable.
        if (! $policy->enabled || ! $policy->request_activity) {
            return response()->json(['ready' => true]);
        }
        if ($data['phase'] === 'probe') {
            return response()->json(['ready' => $policy->state === 'awake']);
        }
        // Atomic versioning serializes activity with the final sleep transition. A short
        // aggregate lease avoids per-request locks and conservatively covers completed calls.
        ResourceSleepPolicy::whereKey($policy->id)->increment('activity_version', 1, ['last_activity_at' => now()]);
        Cache::put('resource-sleep-active:'.$policy->id, now()->timestamp + 60, 60);
        $policy->refresh();
        $resource = getResourceByUuid($uuid, $policy->team_id);
        abort_unless($resource && ! $resource->is_maintenance_enabled, 503);
        if ($data['phase'] === 'begin' && $policy->state === 'asleep' && Cache::add('resource-sleep-wake-queued:'.$policy->id, true, 240)) {
            ReconcileResourceSleep::dispatch($policy->id, 'wake');
        }
        abort_if($policy->fresh()->state === 'error', 503);

        return response()->json(['ready' => $policy->fresh()->state === 'awake']);
    }
}
