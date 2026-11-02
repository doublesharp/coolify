<?php

use App\Jobs\ReconcileResourceSleep;
use App\Livewire\Project\Shared\ResourceSleepSettings;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ResourceSleepPolicy;
use App\Models\User;
use App\Services\ResourceSleep;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

require_once __DIR__.'/../Support/ResourceSleep.php';

uses(LazilyRefreshDatabase::class);

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

it('renders and saves sleep settings through the authorized resource component', function () {
    $application = sleepApplication();
    sleepToken($application);
    $user = User::latest('id')->firstOrFail();
    $this->actingAs($user);
    Livewire::test(ResourceSleepSettings::class, ['resource' => $application])
        ->assertSee('Automatic sleep')->set('enabled', true)->set('idleMinutes', 5)->call('save')->assertHasNoErrors();
    expect(ResourceSleepPolicy::where('resource_uuid', $application->uuid)->firstOrFail()->idle_timeout_seconds)->toBe(300);
});

it('rejects component changes from a reader', function () {
    $application = sleepApplication();
    $team = $application->environment->project->team;
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'member']);
    session(['currentTeam' => $team]);
    $this->actingAs($user);
    Livewire::test(ResourceSleepSettings::class, ['resource' => $application])->set('enabled', true)->call('save')->assertForbidden();
    expect(ResourceSleepPolicy::count())->toBe(0);
});
