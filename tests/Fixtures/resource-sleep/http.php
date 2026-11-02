<?php

use App\Models\ResourceSleepPolicy;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

require __DIR__.'/bootstrap.php';
$request = Request::capture();
if ($request->getPathInfo() === '/_lifecycle-hook') {
    $body = $request->getContent();
    $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
    $policy = ResourceSleepPolicy::where('resource_uuid', $data['resource_uuid'])->firstOrFail();
    if (! hash_equals('sha256='.hash_hmac('sha256', $body, $policy->webhook_secret), $request->header('X-Coolify-Signature') ?? '')) {
        http_response_code(403);

        return;
    }
    file_put_contents($directory.'/hooks.jsonl', json_encode($data)."\n", FILE_APPEND | LOCK_EX);
    http_response_code(202);

    return;
}
$kernel = $app->make(Kernel::class);
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
