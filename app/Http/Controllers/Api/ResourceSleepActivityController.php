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
        $policy = ResourceSleepPolicy::where('resource_uuid', $uuid)->where('enabled', true)->where('request_activity', true)->first();
        abort_unless($policy && is_string($request->bearerToken()) && hash_equals($policy->proxy_token, $request->bearerToken()), 404);
        $data = $request->validate([
            'request_id' => ['required', 'string', 'regex:/\A[a-f0-9]{32}\z/D'],
            'phase' => ['required', 'in:begin,heartbeat,end'],
        ]);
        Cache::lock('resource-sleep-requests-lock:'.$policy->id, 5)->block(1, function () use ($policy, $data) {
            $key = 'resource-sleep-requests:'.$policy->id;
            $leases = array_filter(Cache::get($key, []), fn ($expires) => $expires > now()->timestamp);
            if ($data['phase'] === 'end') {
                unset($leases[$data['request_id']]);
            } else {
                abort_if(count($leases) >= 10000 && ! isset($leases[$data['request_id']]), 429);
                $leases[$data['request_id']] = now()->timestamp + 60;
            }
            Cache::put($key, $leases, 90);
            $policy->update(['last_activity_at' => now()]);
        });
        $resource = getResourceByUuid($uuid, $policy->team_id);
        abort_unless($resource && ! $resource->is_maintenance_enabled, 503);
        if ($data['phase'] === 'begin' && $policy->state === 'asleep' && Cache::add('resource-sleep-wake-queued:'.$policy->id, true, 240)) {
            ReconcileResourceSleep::dispatch($policy->id, 'wake');
        }
        abort_if($policy->fresh()->state === 'error', 503);

        return response()->json(['ready' => $policy->fresh()->state === 'awake']);
    }
}
