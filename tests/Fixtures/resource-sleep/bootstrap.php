<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// Isolated application bootstrap for the opt-in Docker integration test.
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
    'queue.default' => 'redis', 'database.redis.default.host' => '127.0.0.1',
    'database.redis.default.port' => (int) getenv('RESOURCE_SLEEP_TEST_REDIS_PORT'),
    'database.redis.default.password' => null, 'database.redis.default.database' => 0,
    'database.redis.options.prefix' => basename($directory).':', 'logging.default' => 'single', 'logging.channels.single.path' => $directory.'/laravel.log',
]);
DB::purge('testing');
require __DIR__.'/local-transport.php';
resourceSleepLocalTransport($directory, getenv('RESOURCE_SLEEP_TEST_PROXY'));
