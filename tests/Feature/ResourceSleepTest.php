<?php

use App\Actions\CoolifyTask\RunRemoteProcess;
use App\Jobs\ApplicationDeploymentJob;
use App\Jobs\CoolifyTask;
use App\Jobs\DatabaseBackupJob;
use App\Jobs\Middleware\ResourceSleepWork;
use App\Jobs\ReconcileResourceSleep;
use App\Livewire\Project\Shared\ResourceSleepSettings;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ResourceSleepPolicy;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledJobDelivery;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\User;
use App\Notifications\Database\BackupFailed;
use App\Services\ResourceSleep;
use App\Services\ServerTransfer\ServerTransferExporter;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
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
            ->and($policy->fresh()->state)->toBe('awake');
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

it('can disable a failed or sleeping policy without requiring a successful wake', function (string $state) {
    $application = sleepApplication();
    $lifecycle = app(ResourceSleep::class);
    $policy = $lifecycle->configure($application, ['enabled' => true]);
    $policy->update(['state' => $state, 'containers' => [$application->uuid => [['id' => str_repeat('a', 64)]]]]);
    $docker = sleepDocker();
    $docker->shouldNotReceive('start');
    $docker->shouldNotReceive('stop');
    $policy = $lifecycle->configure($application, ['enabled' => false]);
    expect($policy->enabled)->toBeFalse()->and($policy->state)->toBe('disabled')->and($policy->containers)->toBeNull();
})->with(['error', 'asleep']);

it('lets a deployment replace an unhealthy retained primary despite a failed wake hook', function () {
    $application = sleepApplication();
    $lifecycle = app(ResourceSleep::class);
    $policy = $lifecycle->configure($application, ['enabled' => true, 'webhooks' => ['before_wake' => 'https://8.8.8.8/hook']]);
    $policy->update(['state' => 'error', 'containers' => [$application->uuid => [['id' => str_repeat('a', 64)]]]]);
    Http::fake(['https://8.8.8.8/hook' => Http::response('', 503)]);
    $docker = sleepDocker([['id' => str_repeat('a', 64), 'state' => ['Running' => false]]]);
    $docker->shouldNotReceive('start');
    $lifecycle->deployment($application);
    expect($policy->fresh()->state)->toBe('awake')->and($policy->fresh()->containers)->toBeNull();
});

it('admits an application deployment without synchronously waking sleeping resources', function () {
    $application = sleepApplication();
    app(ResourceSleep::class)->configure($application, ['enabled' => true]);
    $lifecycle = Mockery::mock(ResourceSleep::class)->makePartial();
    $lifecycle->shouldNotReceive('deployment');
    app()->instance(ResourceSleep::class, $lifecycle);
    Queue::fake([ApplicationDeploymentJob::class]);
    queue_application_deployment($application, new_public_id());
    expect(ApplicationDeploymentQueue::where('application_id', $application->id)->count())->toBe(1);
});

it('cleans a deleted resource policy even when it was asleep', function () {
    $application = sleepApplication();
    $lifecycle = app(ResourceSleep::class);
    $policy = $lifecycle->configure($application, ['enabled' => true]);
    $policy->update(['state' => 'asleep']);
    $application->forceDelete();
    $lifecycle->reconcile($policy);
    expect($policy->fresh())->toBeNull();
});

it('refuses to export a sleeping resource without waking or disabling it first', function () {
    $application = sleepApplication();
    $policy = app(ResourceSleep::class)->configure($application, ['enabled' => true]);
    $policy->update(['state' => 'asleep']);
    app(ServerTransferExporter::class)->export($application->destination->server);
})->throws(RuntimeException::class, 'Wake or disable');

it('keeps scheduled work awake and releases its protection after completion', function () {
    $application = sleepApplication();
    $lifecycle = app(ResourceSleep::class);
    $policy = $lifecycle->configure($application, ['enabled' => true]);
    $docker = sleepDocker([['id' => str_repeat('a', 64), 'state' => ['Running' => true]]]);
    $docker->shouldReceive('stop')->once();
    $middleware = new ResourceSleepWork($application);
    $middleware->handle((object) ['timeout' => 300, 'occurrenceUuid' => null], function () use ($lifecycle, $policy) {
        $lifecycle->sleep($policy);
        expect($policy->fresh()->state)->toBe('awake');
    });
    $lifecycle->sleep($policy);
    expect($policy->fresh()->state)->toBe('asleep');
});

it('skips scheduled work while its resource is asleep', function () {
    $application = sleepApplication();
    $policy = app(ResourceSleep::class)->configure($application, ['enabled' => true]);
    $policy->update(['state' => 'asleep']);
    $ran = false;
    (new ResourceSleepWork($application))->handle((object) ['timeout' => 300, 'occurrenceUuid' => null], function () use (&$ran) {
        $ran = true;
    });
    expect($ran)->toBeFalse();
});

it('runs service lifecycle preparation inside the remote worker before starting the service', function () {
    $application = sleepApplication();
    $service = Service::factory()->create(['environment_id' => $application->environment_id, 'server_id' => $application->destination->server_id, 'destination_id' => $application->destination_id, 'destination_type' => $application->destination_type]);
    $activity = activity()->withProperties(['resource_sleep_deployment_uuid' => $service->uuid])->log('[]');
    $order = [];
    $lifecycle = Mockery::mock(ResourceSleep::class);
    $lifecycle->shouldReceive('deployment')->once()->withArgs(fn ($candidate) => $candidate->id === $service->id)->andReturnUsing(function () use (&$order) {
        $order[] = 'prepare';
    });
    app()->instance(ResourceSleep::class, $lifecycle);
    $runner = Mockery::mock(RunRemoteProcess::class);
    $runner->shouldReceive('__invoke')->once()->andReturnUsing(function () use (&$order) {
        $order[] = 'start';

        return Process::result();
    });
    app()->bind(RunRemoteProcess::class, fn () => $runner);
    (new CoolifyTask($activity, false, null, null))->handle();
    expect($order)->toBe(['prepare', 'start']);
});

it('continues normal deployment admission while a lifecycle operation owns its lock', function () {
    $application = sleepApplication();
    $policy = app(ResourceSleep::class)->configure($application, ['enabled' => true]);
    $lock = Cache::lock('resource-sleep:'.$policy->id, ResourceSleep::LOCK_SECONDS);
    $lock->get();
    Queue::fake([ApplicationDeploymentJob::class]);
    try {
        queue_application_deployment($application, new_public_id());
        expect(ApplicationDeploymentQueue::where('application_id', $application->id)->count())->toBe(1);
    } finally {
        $lock->release();
    }
});

it('does not stop containers when a request arrives during its sleep decision', function () {
    $application = sleepApplication();
    $lifecycle = app(ResourceSleep::class);
    $policy = $lifecycle->configure($application, ['enabled' => true]);
    $docker = sleepDocker();
    $docker->shouldReceive('inventory')->andReturnUsing(function () use ($policy) {
        ResourceSleepPolicy::whereKey($policy->id)->increment('activity_version');

        return [['id' => str_repeat('a', 64), 'state' => ['Running' => true]]];
    });
    $docker->shouldNotReceive('stop');
    $lifecycle->sleep($policy);
    expect($policy->fresh()->state)->toBe('awake');
});

it('leaves the resource awake when another server is added after enabling sleep', function () {
    $application = sleepApplication();
    $policy = app(ResourceSleep::class)->configure($application, ['enabled' => true]);
    $server = $application->destination->server;
    $extra = Server::factory()->create(['team_id' => $server->team_id, 'private_key_id' => $server->private_key_id]);
    $application->additional_servers()->attach($extra->id, ['standalone_docker_id' => StandaloneDocker::where('server_id', $extra->id)->value('id')]);
    $docker = sleepDocker();
    $docker->shouldNotReceive('stop');
    app(ResourceSleep::class)->sleep($policy);
    expect($policy->fresh()->state)->toBe('awake')->and($policy->fresh()->error)->not->toBeNull();
});

it('preserves the completed sleep when its after-sleep receiver fails', function () {
    $application = sleepApplication();
    $policy = app(ResourceSleep::class)->configure($application, ['enabled' => true, 'webhooks' => ['after_sleep' => 'https://8.8.8.8/hook']]);
    Http::fake(['https://8.8.8.8/hook' => Http::response('', 503)]);
    $docker = sleepDocker([['id' => str_repeat('a', 64), 'state' => ['Running' => true]]]);
    $docker->shouldReceive('stop')->once();
    app(ResourceSleep::class)->sleep($policy);
    expect($policy->fresh()->state)->toBe('asleep')->and($policy->fresh()->error)->toContain('after_sleep');
});

it('retains a manual database backup until its sleeping dependency is ready', function () {
    $application = sleepApplication();
    $database = StandalonePostgresql::create(['name' => 'sleep-backup-database', 'postgres_password' => 'test-only-password', 'environment_id' => $application->environment_id, 'destination_id' => $application->destination_id, 'destination_type' => $application->destination_type, 'status' => 'running:healthy']);
    $backup = ScheduledDatabaseBackup::create(['database_id' => $database->id, 'database_type' => $database::class, 'team_id' => $application->environment->project->team_id, 'frequency' => 'daily', 'save_s3' => false]);
    $policy = app(ResourceSleep::class)->configure($application, ['enabled' => true, 'dependencies' => [$database->uuid]]);
    $policy->update(['state' => 'asleep']);
    Queue::fake([ReconcileResourceSleep::class]);
    $job = (new DatabaseBackupJob($backup))->withFakeQueueInteractions();
    $ran = false;
    $next = function () use (&$ran, $policy) {
        $ran = true;
        expect(app(ResourceSleep::class)->activeWork($policy))->toBeTrue();
    };
    $job->middleware()[0]->handle($job, $next);
    $job->assertReleased(15);
    expect($ran)->toBeFalse();
    Queue::assertPushed(ReconcileResourceSleep::class, fn ($wake) => $wake->policyId === $policy->id && $wake->action === 'wake');
    expect($job->retryUntil)->toBeGreaterThan(now()->addMinutes(5)->timestamp);
    $policy->update(['state' => 'awake']);
    $database->update(['status' => 'exited']);
    $job->middleware()[0]->handle($job, $next);
    expect($ran)->toBeFalse();
    $database->update(['status' => 'running:healthy']);
    $job->middleware()[0]->handle($job, $next);
    expect($job->backup->database->status)->toBe('running:healthy');
    expect($ran)->toBeTrue()->and(app(ResourceSleep::class)->activeWork($policy))->toBeFalse();
});

it('reports a failed wake for a queued backup and preserves its scheduled failure record', function () {
    $application = sleepApplication();
    $database = StandalonePostgresql::create(['name' => 'sleep-backup-database', 'postgres_password' => 'test-only-password', 'environment_id' => $application->environment_id, 'destination_id' => $application->destination_id, 'destination_type' => $application->destination_type, 'status' => 'running:healthy']);
    $team = $application->environment->project->team;
    $team->emailNotificationSettings->update(['smtp_enabled' => true, 'backup_failure_email_notifications' => true]);
    $backup = ScheduledDatabaseBackup::create(['database_id' => $database->id, 'database_type' => $database::class, 'team_id' => $team->id, 'frequency' => 'daily', 'save_s3' => false]);
    $occurrence = ScheduledJobDelivery::create(['schedule_key' => 'database-backup-'.$backup->id, 'job_type' => 'database-backup', 'resource_id' => $backup->id, 'scheduled_for' => now(), 'status' => 'enqueued', 'payload' => []]);
    $policy = app(ResourceSleep::class)->configure($application, ['enabled' => true, 'dependencies' => [$database->uuid]]);
    $policy->update(['state' => 'error']);
    $job = new DatabaseBackupJob($backup, $occurrence->uuid);
    Notification::fake();
    try {
        $job->middleware()[0]->handle($job, fn () => test()->fail('Backup ran before wake recovered.'));
        test()->fail('Expected a visible failure.');
    } catch (RuntimeException $exception) {
        $job->failed($exception);
    }
    expect($occurrence->fresh()->status)->toBe('failed')
        ->and($backup->executions()->sole()->status)->toBe('failed');
    Notification::assertSentTo($team, BackupFailed::class);
});

it('keeps backup deferrals retryable beyond one attempt without extending their deadline', function () {
    $application = sleepApplication();
    $database = StandalonePostgresql::create(['name' => 'sleep-backup-database', 'postgres_password' => 'test-only-password', 'environment_id' => $application->environment_id, 'destination_id' => $application->destination_id, 'destination_type' => $application->destination_type, 'status' => 'running:healthy']);
    $backup = ScheduledDatabaseBackup::create(['database_id' => $database->id, 'database_type' => $database::class, 'team_id' => $application->environment->project->team_id, 'frequency' => 'daily', 'save_s3' => false]);
    $job = new DatabaseBackupJob($backup);
    $deadline = $job->retryUntil;
    $this->travel(30)->seconds();
    $occurrence = ScheduledJobDelivery::create(['schedule_key' => 'database-backup-'.$backup->id, 'job_type' => 'database-backup', 'resource_id' => $backup->id, 'scheduled_for' => now()->subHours(23), 'status' => 'enqueued', 'payload' => []]);
    expect((new DatabaseBackupJob($backup, $occurrence->uuid))->retryUntil)->toBe(now()->addHour()->timestamp);
    $copy = unserialize(serialize($job));
    expect(app('queue')->connection('sync')->getJobExpiration($copy))->toBe($deadline)
        ->and($copy->maxExceptions)->toBe(1);
});

it('stays awake if its budget expires before the first stop command', function () {
    $application = sleepApplication();
    $lifecycle = app(ResourceSleep::class);
    $policy = $lifecycle->configure($application, ['enabled' => true, 'webhooks' => ['before_sleep' => 'https://8.8.8.8/hook']]);
    Http::fake(function () use ($lifecycle) {
        (new ReflectionProperty(ResourceSleep::class, 'deadline'))->setValue($lifecycle, microtime(true) - 1);

        return Http::response('', 200);
    });
    $docker = sleepDocker([['id' => str_repeat('a', 64), 'state' => ['Running' => true]]]);
    $docker->shouldNotReceive('stop');
    expect(fn () => $lifecycle->sleep($policy))->toThrow(RuntimeException::class);
    expect($policy->fresh()->state)->toBe('awake')->and($policy->fresh()->containers)->toBeNull();
});

it('retains an accepted wake until the offline backup releases its lease', function () {
    $application = sleepApplication();
    $lifecycle = app(ResourceSleep::class);
    $policy = $lifecycle->configure($application, ['enabled' => true]);
    $id = str_repeat('a', 64);
    $policy->update(['state' => 'asleep', 'containers' => [$application->uuid => [['id' => $id]]]]);
    Cache::put('resource-sleep-work:'.$policy->id, ['backup' => now()->addMinute()->timestamp], 60);
    $job = (new ReconcileResourceSleep($policy->id, 'wake'))->withFakeQueueInteractions();
    $job->handle($lifecycle);
    $job->assertReleased(15);
    expect($policy->fresh()->state)->toBe('asleep')->and(Cache::has('resource-sleep-wake-queued:'.$policy->id))->toBeTrue();
    Cache::forget('resource-sleep-work:'.$policy->id);
    $docker = sleepDocker([['id' => $id, 'state' => ['Running' => false]]]);
    $docker->shouldReceive('start')->once();
    (new ReconcileResourceSleep($policy->id, 'wake'))->handle($lifecycle);
    expect($policy->fresh()->state)->toBe('awake')->and(Cache::has('resource-sleep-wake-queued:'.$policy->id))->toBeFalse();
});
