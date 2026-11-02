<?php

use App\Jobs\ReconcileResourceSleep;
use App\Models\PersonalAccessToken;
use App\Models\ResourceSleepPolicy;
use App\Services\ResourceSleep;
use App\Services\ResourceSleepProxy;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

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
    $proxy->shouldReceive('prepare')->once()->andReturnNull();
    app()->instance(ResourceSleepProxy::class, $proxy);
    $this->withToken(sleepToken($application))->patchJson('/api/v1/resources/'.$application->uuid.'/sleep', ['enabled' => true, 'request_activity' => true])
        ->assertOk()->assertJson(['enabled' => true, 'request_activity' => true]);
});
