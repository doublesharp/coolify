<?php

use App\Models\ResourceSleepPolicy;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// HTTP entry point used only by the opt-in Docker integration test.
$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$directory = getenv('RESOURCE_SLEEP_TEST_DIRECTORY');
if (! $directory || ! str_contains($directory, 'resource-sleep-integration-')) {
    throw new RuntimeException('This fixture requires an isolated integration test directory.');
}
config([
    'database.default' => 'testing', 'database.connections.testing.database' => $directory.'/database.sqlite',
    'cache.default' => 'file', 'cache.stores.file.path' => $directory.'/cache',
    'queue.default' => 'sync', 'logging.default' => 'single', 'logging.channels.single.path' => $directory.'/laravel.log',
]);
DB::purge('testing');
require __DIR__.'/local-transport.php';
resourceSleepLocalTransport($directory, getenv('RESOURCE_SLEEP_TEST_PROXY'));
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
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
