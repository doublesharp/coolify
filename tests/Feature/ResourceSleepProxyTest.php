<?php

use App\Jobs\ReconcileResourceSleep;
use App\Models\PersonalAccessToken;
use App\Models\ResourceSleepPolicy;
use App\Services\ResourceSleep;
use App\Services\ResourceSleepProxy;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Yaml\Yaml;

require_once __DIR__.'/../Support/ResourceSleep.php';

uses(LazilyRefreshDatabase::class);

it('authenticates a proxy callback for exactly one resource', function () {
    $application = sleepApplication();
    $policy = app(ResourceSleep::class)->configure($application, ['enabled' => true]);
    $policy->update(['request_activity' => true, 'state' => 'asleep']);
    $url = '/api/resource-sleep/'.$application->uuid.'/activity';
    Queue::fake([ReconcileResourceSleep::class]);
    $this->postJson($url, ['phase' => 'begin', 'request_id' => str_repeat('a', 32)])->assertNotFound();
    $this->withToken('invalid')->postJson($url, ['phase' => 'begin', 'request_id' => str_repeat('a', 32)])->assertNotFound();
    Queue::assertNothingPushed();
    $this->withToken($policy->proxy_token)->postJson($url, ['phase' => 'begin', 'request_id' => str_repeat('a', 32)])->assertOk()->assertJson(['ready' => false]);
    Queue::assertPushed(ReconcileResourceSleep::class, fn ($job) => $job->action === 'wake' && $job->policyId === $policy->id);
});

it('keeps an active request awake and expires abandoned requests', function () {
    $application = sleepApplication();
    $policy = app(ResourceSleep::class)->configure($application, ['enabled' => true]);
    $policy->update(['request_activity' => true]);
    $url = '/api/resource-sleep/'.$application->uuid.'/activity';
    $this->withToken($policy->proxy_token)->postJson($url, ['phase' => 'begin', 'request_id' => str_repeat('a', 32)])->assertOk()->assertJson(['ready' => true]);
    expect(app(ResourceSleep::class)->activeRequests($policy))->toBeTrue();
    $this->travel(40)->seconds();
    $this->postJson($url, ['phase' => 'heartbeat', 'request_id' => str_repeat('a', 32)])->assertOk();
    $this->travel(40)->seconds();
    expect(app(ResourceSleep::class)->activeRequests($policy))->toBeTrue();
    $this->postJson($url, ['phase' => 'end', 'request_id' => str_repeat('a', 32)])->assertOk();
    // Completion retains a conservative grace period; no per-request lease removal races.
    expect(app(ResourceSleep::class)->activeRequests($policy))->toBeTrue();
    $this->travel(61)->seconds();
    expect(app(ResourceSleep::class)->activeRequests($policy))->toBeFalse();
    $this->postJson($url, ['phase' => 'begin', 'request_id' => str_repeat('b', 32)])->assertOk();
    $this->travel(70)->seconds();
    expect(app(ResourceSleep::class)->activeRequests($policy))->toBeFalse();
});

it('retains authentication and path routing when building a persistent sleep route', function () {
    $application = sleepApplication();
    $policy = app(ResourceSleep::class)->configure($application, ['enabled' => true]);
    $raw = [
        'routers' => ['app@docker' => ['entryPoints' => ['https'], 'rule' => 'Host(`sleep.example.test`) && PathPrefix(`/api`)', 'priority' => 100, 'tls' => ['certResolver' => 'letsencrypt'], 'service' => 'app@docker', 'middlewares' => ['auth@docker'], 'status' => 'enabled']],
        'middlewares' => ['auth@docker' => ['basicAuth' => ['users' => ['user:hash']], 'status' => 'enabled']],
        'services' => ['app@docker' => ['loadBalancer' => ['servers' => [['url' => 'http://172.18.0.2:3000']]], 'status' => 'enabled']],
    ];
    $containers = [['name' => '/app-container', 'labels' => ['traefik.http.routers.app.rule' => 'Host(`sleep.example.test`)'], 'networks' => ['coolify' => ['IPAddress' => '172.18.0.2']]]];
    $config = app(ResourceSleepProxy::class)->configuration($policy, $containers, $raw);
    $router = array_values($config['http']['routers'])[0];
    expect($router['rule'])->toBe($raw['routers']['app@docker']['rule'])
        ->and($router['priority'])->toBe(101)
        ->and($router['tls'])->toBe(['certResolver' => 'letsencrypt'])
        ->and($router['middlewares'])->toHaveCount(2)
        ->and($router)->not->toHaveKey('status');
    $auth = $config['http']['middlewares'][$router['middlewares'][0]];
    expect($auth['basicAuth']['users'])->toBe(['user:hash']);
    expect($config['http']['services'][$router['service']]['loadBalancer']['servers'][0]['url'])->toBe('http://app-container:3000');
});

it('removes a deleted resource route even when its policy was asleep', function () {
    $application = sleepApplication();
    $policy = app(ResourceSleep::class)->configure($application, ['enabled' => true]);
    $policy->update(['request_activity' => true, 'state' => 'asleep']);
    $proxy = Mockery::mock(ResourceSleepProxy::class);
    $proxy->shouldReceive('remove')->once()->withArgs(fn ($candidate) => $candidate->id === $policy->id);
    app()->instance(ResourceSleepProxy::class, $proxy);
    $application->forceDelete();
    app(ResourceSleep::class)->reconcile($policy);
    expect($policy->fresh())->toBeNull();
});

it('cleans a failed proxy setup and permits disabling it afterwards', function () {
    $application = sleepApplication();
    $lifecycle = app(ResourceSleep::class);
    $policy = $lifecycle->configure($application, ['enabled' => false]);
    $proxy = Mockery::mock(ResourceSleepProxy::class);
    $proxy->shouldReceive('install')->once();
    $proxy->shouldReceive('prepare')->once()->andThrow(new RuntimeException('Proxy setup failed.'));
    $proxy->shouldReceive('remove')->twice();
    app()->instance(ResourceSleepProxy::class, $proxy);
    try {
        $lifecycle->configure($application, ['enabled' => true, 'request_activity' => true]);
        test()->fail('Expected setup failure.');
    } catch (ValidationException) {
        expect($policy->fresh()->enabled)->toBeFalse();
    }
    $policy = $lifecycle->configure($application, ['enabled' => false, 'request_activity' => false]);
    expect($policy->request_activity)->toBeFalse();
});

it('requires a team-wide token before installing middleware on the shared server proxy', function () {
    $application = sleepApplication();
    $token = sleepToken($application);
    PersonalAccessToken::findOrFail(explode('|', $token)[0])->update(['scopes' => [[
        'scope' => 'resource', 'uuid' => $application->uuid, 'levels' => ['read', 'write', 'deploy'],
    ]]]);
    $proxy = Mockery::mock(ResourceSleepProxy::class);
    $proxy->shouldReceive('prepare')->andReturnNull();
    app()->instance(ResourceSleepProxy::class, $proxy);
    $this->withToken($token)->patchJson('/api/v1/resources/'.$application->uuid.'/sleep', ['enabled' => true, 'request_activity' => true])->assertForbidden();
    expect(ResourceSleepPolicy::count())->toBe(0);
});

it('permits a team-wide owner token to enable request activity', function () {
    $application = sleepApplication();
    $proxy = Mockery::mock(ResourceSleepProxy::class);
    $proxy->shouldReceive('install')->once();
    $proxy->shouldReceive('prepare')->once()->andReturnNull();
    app()->instance(ResourceSleepProxy::class, $proxy);
    $this->withToken(sleepToken($application))->patchJson('/api/v1/resources/'.$application->uuid.'/sleep', ['enabled' => true, 'request_activity' => true])
        ->assertOk()->assertJson(['enabled' => true, 'request_activity' => true]);
});

it('probes the callback without waking or recording visitor activity', function () {
    $application = sleepApplication();
    $policy = app(ResourceSleep::class)->configure($application, ['enabled' => true]);
    $policy->update(['request_activity' => true, 'state' => 'asleep']);
    Queue::fake([ReconcileResourceSleep::class]);
    $this->withToken($policy->proxy_token)->postJson('/api/resource-sleep/'.$application->uuid.'/activity', ['phase' => 'probe', 'request_id' => str_repeat('c', 32)])
        ->assertOk()->assertJson(['ready' => false]);
    expect($policy->fresh()->activity_version)->toBe(0)
        ->and(app(ResourceSleep::class)->activeRequests($policy))->toBeFalse();
    Queue::assertNothingPushed();
});

it('admits parallel activity without acquiring a shared request lock', function () {
    $application = sleepApplication();
    $policy = app(ResourceSleep::class)->configure($application, ['enabled' => true]);
    $policy->update(['request_activity' => true]);
    $lock = Cache::lock('resource-sleep-requests-lock:'.$policy->id, 10);
    $lock->get();
    try {
        $this->withToken($policy->proxy_token)->postJson('/api/resource-sleep/'.$application->uuid.'/activity', ['phase' => 'begin', 'request_id' => str_repeat('d', 32)])
            ->assertOk()->assertJson(['ready' => true]);
        expect($policy->fresh()->activity_version)->toBe(1);
    } finally {
        $lock->release();
    }
});

it('lets a retained route bypass a disabled policy without queuing a wake', function () {
    $application = sleepApplication();
    $policy = app(ResourceSleep::class)->configure($application, ['enabled' => true]);
    $policy->update(['request_activity' => true, 'enabled' => false, 'state' => 'disabled']);
    Queue::fake([ReconcileResourceSleep::class]);
    $this->withToken($policy->proxy_token)->postJson('/api/resource-sleep/'.$application->uuid.'/activity', ['phase' => 'begin', 'request_id' => str_repeat('e', 32)])
        ->assertOk()->assertJson(['ready' => true]);
    Queue::assertNothingPushed();
});

it('preserves proxy integration and a sufficient upload timeout across native resets', function () {
    $application = sleepApplication();
    $server = $application->destination->server;
    $base = ['services' => ['traefik' => ['command' => ['--entrypoints.http.address=:80', '--entrypoints.http.transport.respondingtimeouts.readtimeout=60s', '--entrypoints.https.address=:443', '--entrypoints.https.transport.respondingtimeouts.readtimeout=0', '--api.insecure=true'], 'volumes' => ['/custom:/custom:ro']]]];
    $proxy = app(ResourceSleepProxy::class);
    $config = $proxy->managedConfiguration($server, Yaml::dump($base));
    $parsed = parseProxyComposeYaml($config);
    expect($parsed['services']['traefik']['command'])->toContain('--entrypoints.http.transport.respondingtimeouts.readtimeout=240s', '--entrypoints.https.transport.respondingtimeouts.readtimeout=0', '--api.insecure=true', '--experimental.localplugins.resourcesleep.modulename=github.com/coollabsio/resource-sleep')
        ->and($parsed['services']['traefik']['volumes'])->toContain('/custom:/custom:ro', $server->proxyPath().'/plugins-local:/plugins-local:ro')
        ->and($proxy->managedConfiguration($server, $config))->toBe($config);
});

it('gives the maintenance page precedence over a retained sleep route', function () {
    $application = sleepApplication();
    $application->update(['fqdn' => 'http://sleep.example.test']);
    $policy = app(ResourceSleep::class)->configure($application, ['enabled' => true]);
    $policy->update(['proxy_snapshot' => ['http' => ['routers' => ['test' => ['priority' => 1000]]]]]);
    $config = Yaml::parse($application->destination->server->maintenanceTraefikConfiguration(collect([$application])));
    expect(array_values($config['http']['routers'])[0]['priority'])->toBe(1001);
});

it('uses a loopback API without enabling the insecure dashboard listener', function () {
    $application = sleepApplication();
    $server = $application->destination->server;
    $base = ['services' => ['traefik' => ['command' => ['--entrypoints.http.address=:80', '--api.insecure=false'], 'ports' => ['80:80']]]];
    $proxy = app(ResourceSleepProxy::class);
    $config = $proxy->managedConfiguration($server, Yaml::dump($base));
    $service = parseProxyComposeYaml($config)['services']['traefik'];
    expect($service['command'])->toContain('--api=true', '--api.insecure=false', '--entrypoints.coolify-resource-sleep.address=127.0.0.1:18081')
        ->and($service['command'])->not->toContain('--api.insecure=true')
        ->and($service['ports'])->toBe(['80:80'])
        ->and($proxy->managedConfiguration($server, $config))->toBe($config);
});
