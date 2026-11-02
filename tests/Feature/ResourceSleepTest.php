<?php

use App\Jobs\ReconcileResourceSleep;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\ResourceSleepPolicy;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Services\ResourceSleep;
use App\Services\ResourceSleepDocker;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;

uses(LazilyRefreshDatabase::class);

function sleepApplication(): Application
{
    Storage::fake('ssh-keys');
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));
    $team = Team::factory()->create();
    $key = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $key->id]);
    $server->settings->update(['is_reachable' => true, 'is_usable' => true]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    return Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'fqdn' => 'http://sleep.example.test',
        'status' => 'running:healthy',
    ]);
}

it('stores an opt-in policy with hidden encrypted webhook credentials', function () {
    $application = sleepApplication();
    $policy = app(ResourceSleep::class)->configure($application, ['enabled' => true]);

    expect($policy)->toMatchArray([
        'resource_uuid' => $application->uuid,
        'server_id' => $application->destination->server_id,
        'team_id' => $application->environment->project->team_id,
        'enabled' => true,
        'request_activity' => false,
        'idle_timeout_seconds' => 1800,
    ]);
    expect($policy->toArray())->not->toHaveKeys(['proxy_token', 'webhook_secret', 'webhooks', 'containers']);
    expect($policy->getRawOriginal('webhook_secret'))->not->toBe($policy->webhook_secret);
});

it('rejects sleep intervals that could continually restart an application', function () {
    $application = sleepApplication();
    app(ResourceSleep::class)->configure($application, ['idle_timeout_seconds' => 0]);
})->throws(ValidationException::class, 'at least 1');

it('rejects a dependency from another environment without saving a policy', function () {
    $application = sleepApplication();
    $other = sleepApplication();
    try {
        app(ResourceSleep::class)->configure($application, ['dependencies' => [$other->uuid]]);
    } catch (ValidationException $exception) {
        expect(ResourceSleepPolicy::count())->toBe(0);
        throw $exception;
    }
})->throws(ValidationException::class, 'same environment');

function sleepDocker(array $containers = []): MockInterface
{
    $docker = Mockery::mock(ResourceSleepDocker::class);
    $docker->shouldReceive('inventory')->andReturn($containers)->byDefault();
    app()->instance(ResourceSleepDocker::class, $docker);

    return $docker;
}

it('stops only running containers and resumes the saved deployment without pulling images', function () {
    $application = sleepApplication();
    $lifecycle = app(ResourceSleep::class);
    $policy = $lifecycle->configure($application, ['enabled' => true]);
    $id = str_repeat('a', 64);
    $docker = sleepDocker([
        ['id' => $id, 'state' => ['Running' => true]],
        ['id' => str_repeat('b', 64), 'state' => ['Running' => false]],
    ]);
    $docker->shouldReceive('stop')->once()->withArgs(fn ($server, $ids) => $ids === [$id]);
    $docker->shouldReceive('start')->once()->withArgs(fn ($server, $ids) => $ids === [$id]);

    $lifecycle->sleep($policy);
    expect($policy->fresh()->state)->toBe('asleep');
    $lifecycle->wake($policy);
    expect($policy->fresh()->state)->toBe('awake')
        ->and($policy->fresh()->containers)->toBeNull();
});

it('does not sleep during an application deployment', function () {
    $application = sleepApplication();
    $lifecycle = app(ResourceSleep::class);
    $policy = $lifecycle->configure($application, ['enabled' => true, 'minimum_uptime_seconds' => 0]);
    $policy->update(['last_activity_at' => now()->subHour(), 'woke_at' => now()->subHour()]);
    ApplicationDeploymentQueue::create(['application_id' => $application->id, 'deployment_uuid' => 'sleep-test', 'server_id' => $policy->server_id, 'status' => 'in_progress']);
    $docker = sleepDocker();
    $docker->shouldNotReceive('stop');
    $lifecycle->reconcile($policy);
    expect($policy->fresh()->state)->toBe('awake');
});

it('retains recovery information when a stop fails', function () {
    $application = sleepApplication();
    $lifecycle = app(ResourceSleep::class);
    $policy = $lifecycle->configure($application, ['enabled' => true]);
    $docker = sleepDocker([['id' => str_repeat('a', 64), 'state' => ['Running' => true]]]);
    $docker->shouldReceive('stop')->once()->andThrow(new RuntimeException('transport failure'));
    try {
        $lifecycle->sleep($policy);
    } catch (RuntimeException) {
        expect($policy->fresh()->state)->toBe('error')
            ->and($policy->fresh()->containers)->not->toBeEmpty();

        return;
    }
    test()->fail('Expected stop failure.');
});

it('signs outgoing hooks and refuses a non-success response before stopping', function () {
    $application = sleepApplication();
    $lifecycle = app(ResourceSleep::class);
    $policy = $lifecycle->configure($application, ['enabled' => true, 'webhooks' => ['before_sleep' => 'https://8.8.8.8/hook']]);
    $docker = sleepDocker([['id' => str_repeat('a', 64), 'state' => ['Running' => true]]]);
    $docker->shouldNotReceive('stop');
    Http::fake(['https://8.8.8.8/hook' => Http::response('', 302)]);
    try {
        $lifecycle->sleep($policy);
        test()->fail('Expected webhook failure.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Resource sleep failed.')
            ->and($policy->fresh()->state)->toBe('error');
    }
    Http::assertSent(fn ($request) => $request->hasHeader('X-Coolify-Signature', 'sha256='.hash_hmac('sha256', $request->body(), $policy->webhook_secret))
        && $request['event'] === 'before_sleep' && $request['resource_uuid'] === $application->uuid);
});

function sleepToken(Application $application, array $abilities = ['read', 'write', 'deploy']): string
{
    $team = $application->environment->project->team;
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    session(['currentTeam' => $team]);
    InstanceSettings::find(0)->update(['is_api_enabled' => true]);
    $token = $user->createToken('sleep-api-test', $abilities);
    $token->accessToken->forceFill(['team_id' => $team->id])->save();

    return $token->plainTextToken;
}

it('requires authentication and deploy ability for incoming lifecycle webhooks', function () {
    $application = sleepApplication();
    app(ResourceSleep::class)->configure($application, ['enabled' => true]);
    $url = '/api/v1/resources/'.$application->uuid.'/sleep';
    $this->postJson($url)->assertUnauthorized();
    $this->withToken(sleepToken($application, ['read']))->postJson($url)->assertForbidden();
});

it('queues an authorized wake and hides lifecycle secrets from ordinary readers', function () {
    $application = sleepApplication();
    $policy = app(ResourceSleep::class)->configure($application, ['enabled' => true]);
    Queue::fake([ReconcileResourceSleep::class]);
    $this->withToken(sleepToken($application));
    $this->postJson('/api/v1/resources/'.$application->uuid.'/wake')->assertAccepted();
    Queue::assertPushed(ReconcileResourceSleep::class, fn ($job) => $job->policyId === $policy->id && $job->action === 'wake');
    $response = $this->getJson('/api/v1/resources/'.$application->uuid.'/sleep')->assertOk();
    expect($response->json())->not->toHaveKeys(['webhook_secret', 'proxy_token', 'webhooks', 'containers']);
});

it('does not disclose another team resource through lifecycle routes', function () {
    $application = sleepApplication();
    $other = sleepApplication();
    $this->withToken(sleepToken($other))->getJson('/api/v1/resources/'.$application->uuid.'/sleep')->assertNotFound();
});
