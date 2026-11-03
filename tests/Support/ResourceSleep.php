<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Services\ResourceSleepDocker;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;

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

function sleepDocker(array $containers = []): MockInterface
{
    $docker = Mockery::mock(ResourceSleepDocker::class);
    $docker->shouldReceive('inventory')->andReturn($containers)->byDefault();
    app()->instance(ResourceSleepDocker::class, $docker);

    return $docker;
}

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
