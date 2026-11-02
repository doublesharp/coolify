<?php

use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\InstanceSettings;
use App\Services\ResourceSleep;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Once;
use Symfony\Component\Process\Process as NativeProcess;
use Symfony\Component\Yaml\Yaml;

require_once __DIR__.'/../Support/ResourceSleep.php';
require_once __DIR__.'/../Fixtures/resource-sleep/local-transport.php';

it('sleeps and wakes real containers through Traefik while preserving requests and data', function () {
    $scratch = getenv('RESOURCE_SLEEP_TEST_OUTPUT');
    if (! $scratch || ! is_dir($scratch)) {
        test()->fail('Set RESOURCE_SLEEP_TEST_OUTPUT to an existing disposable output directory.');
    }
    $prefix = 'resource-sleep-integration-'.bin2hex(random_bytes(4));
    $directory = $scratch.'/'.$prefix;
    mkdir($directory, 0700, true);
    $run = function (array $command) {
        $process = new NativeProcess($command, timeout: 120);
        $process->mustRun();

        return trim($process->getOutput());
    };
    $freePort = function (): int {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        return $port;
    };
    $proxyPort = $freePort();
    $managementPort = $freePort();
    $proxyName = $prefix.'-proxy';
    $backendName = $prefix.'-app';
    $dependencyName = $prefix.'-database';
    $retryUntil = function (callable $check): void {
        $deadline = microtime(true) + 30;
        do {
            try {
                if ($check()) {
                    return;
                }
            } catch (ConnectionException) {
            }
            usleep(200000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Integration condition did not become ready.');
    };
    $web = null;
    $slow = null;
    try {
        touch($directory.'/database.sqlite');
        config(['database.connections.testing.database' => $directory.'/database.sqlite', 'cache.default' => 'file', 'cache.stores.file.path' => $directory.'/cache']);
        DB::purge('testing');
        $this->artisan('migrate:fresh', ['--database' => 'testing', '--force' => true])->assertSuccessful();
        $application = sleepApplication();
        $server = $application->destination->server;
        $server->user = 'coolify-test-user';
        $server->proxy->type = ProxyTypes::TRAEFIK->value;
        $server->save();
        $dependency = Application::factory()->create(['environment_id' => $application->environment_id, 'destination_id' => $application->destination_id, 'destination_type' => $application->destination_type]);
        $application->destination->update(['network' => $prefix]);
        $token = sleepToken($application);
        $host = 'http://host.docker.internal:'.$managementPort;
        $settings = InstanceSettings::find(0);
        $settings->update(['fqdn' => null, 'webhook_allowed_internal_hosts' => ['host.docker.internal', '127.0.0.1'], 'webhook_allow_localhost' => true]);
        config(['app.url' => $host]);
        Once::flush();
        $run(['docker', 'network', 'create', '--label', 'coolify.sleep-test='.$prefix, $prefix]);
        $run(['docker', 'volume', 'create', '--label', 'coolify.sleep-test='.$prefix, $prefix]);
        $backendId = $run(['docker', 'run', '-d', '--name', $backendName, '--network', $prefix,
            '--label', 'coolify.applicationUuid='.$application->uuid, '--label', 'coolify.managed=true', '--label', 'coolify.type=application', '--label', 'coolify.sleep-test='.$prefix,
            '--label', 'traefik.enable=true', '--label', 'traefik.http.routers.sleep-test.rule=Host(`sleep.example.test`)',
            '--label', 'traefik.http.routers.sleep-test.entrypoints=http', '--label', 'traefik.http.routers.sleep-test.middlewares=test-auth',
            '--label', 'traefik.http.middlewares.test-auth.basicauth.users=visitor:'.password_hash('secret', PASSWORD_BCRYPT),
            '--label', 'traefik.http.services.sleep-test.loadbalancer.server.port=8080',
            '-v', realpath(__DIR__.'/../Fixtures/resource-sleep/backend.js').':/server.js:ro', '-v', $prefix.':/data', 'node:22-alpine', 'node', '/server.js']);
        $dependencyId = $run(['docker', 'run', '-d', '--name', $dependencyName, '--network', $prefix,
            '--label', 'coolify.applicationUuid='.$dependency->uuid, '--label', 'coolify.managed=true', '--label', 'coolify.type=application', '--label', 'coolify.sleep-test='.$prefix, '--label', 'traefik.enable=false', 'redis:7.4-alpine']);
        $configuration = ['name' => $prefix, 'services' => ['traefik' => [
            'image' => 'traefik:v3.6', 'container_name' => 'coolify-proxy', 'labels' => ['coolify.sleep-test' => $prefix],
            'ports' => ['127.0.0.1:'.$proxyPort.':80'], 'extra_hosts' => PHP_OS_FAMILY === 'Linux' ? ['host.docker.internal:host-gateway'] : [], 'command' => ['--entrypoints.http.address=:80', '--providers.docker=true', '--providers.docker.constraints=Label(`coolify.sleep-test`, `'.$prefix.'`)', '--providers.docker.exposedbydefault=false', '--providers.file.directory=/traefik/dynamic', '--providers.file.watch=true', '--api.insecure=true'],
            'volumes' => ['/var/run/docker.sock:/var/run/docker.sock:ro', $directory.'/proxy:/traefik'],
            'networks' => [$prefix],
        ]], 'networks' => [$prefix => ['external' => true]]];
        mkdir($directory.'/proxy/dynamic', 0700, true);
        file_put_contents($directory.'/proxy/docker-compose.yml', str_replace('coolify-proxy', $proxyName, Yaml::dump($configuration, 20, 2)));
        $server->proxy->last_saved_proxy_configuration = Yaml::dump($configuration, 20, 2);
        $server->save();
        $run(['docker', 'compose', '-p', $prefix, '-f', $directory.'/proxy/docker-compose.yml', 'up', '-d']);
        resourceSleepLocalTransport($directory, $proxyName);
        $web = new NativeProcess([PHP_BINARY, '-S', '0.0.0.0:'.$managementPort, __DIR__.'/../Fixtures/resource-sleep/http.php'], base_path(), [
            'RESOURCE_SLEEP_TEST_DIRECTORY' => $directory, 'RESOURCE_SLEEP_TEST_PROXY' => $proxyName, 'PHP_CLI_SERVER_WORKERS' => '4', 'TMPDIR' => $directory,
        ], timeout: null);
        $web->start();
        $management = 'http://127.0.0.1:'.$managementPort;
        $retryUntil(fn () => Http::get($management.'/api/health')->successful());
        $lifecycle = app(ResourceSleep::class);
        $policy = $lifecycle->configure($application, ['enabled' => true, 'request_activity' => true, 'idle_timeout_seconds' => 2, 'minimum_uptime_seconds' => 0, 'dependencies' => [$dependency->uuid], 'webhooks' => ['before_sleep' => $management.'/_lifecycle-hook', 'after_wake' => $management.'/_lifecycle-hook']]);
        $site = 'http://127.0.0.1:'.$proxyPort;
        Http::withHeaders(['Host' => 'sleep.example.test'])->get($site.'/echo')->throwUnlessStatus(401);
        $policy->update(['last_activity_at' => now()->subMinute(), 'woke_at' => now()->subMinute()]);
        $lifecycle->reconcile($policy);
        expect($policy->fresh()->state)->toBe('asleep');
        expect($run(['docker', 'inspect', '--format', '{{.State.Running}}', $backendId, $dependencyId]))->toBe("false\nfalse");
        $response = Http::withBasicAuth('visitor', 'secret')->withHeaders(['Host' => 'sleep.example.test', 'Cookie' => 'example=value'])->withBody('original-body', 'text/plain')->post($site.'/echo')->throw();
        expect($response->json())->toMatchArray(['method' => 'POST', 'body' => 'original-body', 'url' => '/echo', 'cookie' => 'example=value', 'authorization' => 'Basic '.base64_encode('visitor:secret'), 'count' => 1]);
        expect($policy->fresh()->state)->toBe('awake');
        $slow = new NativeProcess(['curl', '--fail', '--silent', '--user', 'visitor:secret', '-H', 'Host: sleep.example.test', $site.'/slow'], timeout: 35);
        $slow->start();
        $retryUntil(fn () => $lifecycle->activeRequests($policy));
        $policy->update(['last_activity_at' => now()->subMinute(), 'woke_at' => now()->subMinute()]);
        $lifecycle->reconcile($policy);
        expect($policy->fresh()->state)->toBe('awake');
        $slow->wait();
        expect($slow->isSuccessful())->toBeTrue();
        $retryUntil(fn () => ! $lifecycle->activeRequests($policy));
        usleep(2500000);
        $lifecycle->reconcile($policy);
        expect($policy->fresh()->state)->toBe('asleep');
        $response = Http::withBasicAuth('visitor', 'secret')->withHeaders(['Host' => 'sleep.example.test'])->get($site.'/echo')->throw();
        expect($response->json('count'))->toBe(3);
        expect($run(['docker', 'inspect', '--format', '{{.Id}}', $backendName]))->toBe($backendId);
        $hooks = array_map(fn ($line) => json_decode($line, true), file($directory.'/hooks.jsonl', FILE_IGNORE_NEW_LINES));
        expect(array_column($hooks, 'event'))->toBe(['before_sleep', 'after_wake', 'before_sleep', 'after_wake']);
        $mode = fileperms($directory.'/proxy/dynamic/resource-sleep-'.$application->uuid.'.yaml') & 0777;
        expect($mode)->toBe(0600);
    } catch (Throwable $exception) {
        $logs = new NativeProcess(['docker', 'logs', $proxyName]);
        $logs->run();
        file_put_contents($directory.'/proxy.log', $logs->getOutput().$logs->getErrorOutput());
        file_put_contents($directory.'/http.log', $web?->getErrorOutput() ?? 'HTTP server not started.');
        throw new RuntimeException($exception->getMessage().' Debug logs: '.$directory, previous: $exception);
    } finally {
        $slow?->stop();
        $web?->stop();
        foreach ([$proxyName, $backendName, $dependencyName] as $name) {
            (new NativeProcess(['docker', 'rm', '-f', $name]))->run();
        }
        (new NativeProcess(['docker', 'network', 'rm', $prefix]))->run();
        (new NativeProcess(['docker', 'volume', 'rm', $prefix]))->run();
        DB::disconnect('testing');
    }
})->skip(! getenv('RESOURCE_SLEEP_DOCKER_TESTS'), 'Opt-in Docker integration test.');
