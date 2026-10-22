<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Livewire\Project\Service\FileStorage;
use App\Livewire\Project\Service\Storage as StorageForm;
use App\Livewire\Project\Shared\Storages\All;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use App\Services\DeploymentConfiguration\ApplicationConfigurationSnapshot;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    config(['app.maintenance.store' => 'array', 'cache.default' => 'array']);
    Bus::fake();
    Process::fake(fn () => Process::result(output: 'OK'));
    InstanceSettings::forceCreate(['id' => 0]);
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'owner']);
    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    $destination = $server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'dockerimage',
        'docker_registry_image_name' => 'nginx',
        'docker_registry_image_tag' => 'alpine',
        'redirect' => 'both',
        'is_http_basic_auth_enabled' => false,
    ]);
    $token = $this->user->createToken('mount-test', ['*'], teamId: $this->team->id);
    $token->accessToken->forceFill(['team_id' => $this->team->id])->save();
    $this->withToken($token->plainTextToken);
    $this->endpoint = "/api/v1/applications/{$this->application->uuid}/storages";
});

dataset('application mount types', [
    'named volume' => [['type' => 'persistent', 'name' => 'data']],
    'directory' => [['type' => 'file', 'is_directory' => true, 'fs_path' => 'data']],
    'managed file' => [['type' => 'file', 'content' => 'configuration']],
    'host file' => [['type' => 'file', 'is_host_file' => true, 'fs_path' => '/srv/config.txt']],
]);

test('creates a mount with the selected access mode and lists it', function (array $payload, bool $readOnly) {
    $response = $this->postJson($this->endpoint, $payload + ['mount_path' => '/data', 'is_read_only' => $readOnly]);

    $response->assertCreated()->assertJsonPath('is_read_only', $readOnly);
    $collection = $payload['type'] === 'persistent' ? 'persistent_storages' : 'file_storages';
    $this->getJson($this->endpoint)->assertOk()->assertJsonPath($collection.'.0.is_read_only', $readOnly);
    $storage = $payload['type'] === 'persistent'
        ? $this->application->persistentStorages()->sole()
        : $this->application->fileStorages()->sole();
    expect($storage->is_read_only)->toBe($readOnly);
})->with('application mount types')->with([true, false]);

test('keeps omitted access modes writable', function (array $payload) {
    $response = $this->postJson($this->endpoint, $payload + ['mount_path' => '/data']);

    $response->assertCreated();
    $storage = $payload['type'] === 'persistent'
        ? $this->application->persistentStorages()->sole()
        : $this->application->fileStorages()->sole();
    expect($storage->is_read_only)->toBeFalse();
})->with('application mount types');

test('updates mount access in both directions without locking configuration', function (array $payload) {
    $response = $this->postJson($this->endpoint, $payload + ['mount_path' => '/data']);
    $response->assertCreated();
    $identity = ['type' => $payload['type'], 'uuid' => $response->json('uuid')];

    $this->patchJson($this->endpoint, $identity + ['is_read_only' => true])->assertOk()->assertJsonPath('is_read_only', true);
    $this->patchJson($this->endpoint, $identity + ['is_preview_suffix_enabled' => false])->assertOk()->assertJsonPath('is_read_only', true);
    $this->patchJson($this->endpoint, $identity + ['is_read_only' => false])->assertOk()->assertJsonPath('is_read_only', false);
})->with('application mount types');

test('rejects invalid access modes with 422 and creates no mount', function (mixed $value) {
    $this->postJson($this->endpoint, ['type' => 'persistent', 'name' => 'data', 'mount_path' => '/data', 'is_read_only' => $value])
        ->assertUnprocessable()->assertJsonValidationErrors('is_read_only');

    expect($this->application->persistentStorages()->count())->toBe(0);
})->with(['string' => ['readonly'], 'null' => [null], 'array' => [[]]]);

test('rejects access overrides for Compose mounts with 422', function (string $type) {
    $this->application->update(['build_pack' => 'dockercompose']);
    $storage = $type === 'persistent'
        ? $this->application->persistentStorages()->create(['name' => 'data', 'mount_path' => '/data'])
        : $this->application->fileStorages()->create(['fs_path' => '/srv/data', 'mount_path' => '/data', 'is_directory' => true]);

    $this->patchJson($this->endpoint, ['type' => $type, 'uuid' => $storage->uuid, 'is_read_only' => true])->assertUnprocessable();

    expect($storage->refresh()->is_read_only)->toBeFalse();
})->with(['persistent', 'file']);

test('upgrades legacy access suffixes and preserves permissions on rollback', function (string $type, string $mode, bool $expected) {
    $storage = $type === 'persistent'
        ? $this->application->persistentStorages()->create(['name' => 'data', 'mount_path' => '/data'])
        : $this->application->fileStorages()->create(['fs_path' => '/srv/data', 'mount_path' => '/data', 'is_directory' => true]);
    $table = $storage->getTable();
    $migration = require database_path('migrations/2026_10_22_200214_add_is_read_only_to_application_mounts.php');
    $migration->down();
    DB::table($table)->where('id', $storage->id)->update(['mount_path' => '/data:'.$mode]);

    $migration->up();

    expect($storage->refresh()->mount_path)->toBe('/data');
    expect($storage->is_read_only)->toBe($expected);
    $migration->down();
    expect(DB::table($table)->where('id', $storage->id)->value('mount_path'))->toBe($expected ? '/data:ro' : '/data');
    $migration->up();
})->with(['persistent', 'file'])->with(['read only' => ['ro', true], 'writable' => ['rw', false]]);

test('returns 404 when a storage UUID belongs to another application', function () {
    $other = Application::factory()->create(['environment_id' => $this->application->environment_id]);
    $storage = $other->persistentStorages()->create(['name' => 'other-data', 'mount_path' => '/data']);

    $this->patchJson($this->endpoint, ['type' => 'persistent', 'uuid' => $storage->uuid, 'is_read_only' => true])->assertNotFound();

    expect($storage->refresh()->is_read_only)->toBeFalse();
});

test('keeps application mounts editable while toggling read-only access in the UI', function () {
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
    $storage = $this->application->persistentStorages()->create(['name' => 'data', 'mount_path' => '/data']);
    $component = Livewire::test(All::class, ['resource' => $this->application]);

    $component->set("forms.{$storage->id}.isReadOnlyMount", true)->call('submit', $storage->id)->assertHasNoErrors();
    expect($storage->refresh()->is_read_only)->toBeTrue();
    expect($storage->shouldBeReadOnlyInUI())->toBeFalse();
    $component->set("forms.{$storage->id}.isReadOnlyMount", false)->call('submit', $storage->id)->assertHasNoErrors();
    expect($storage->refresh()->is_read_only)->toBeFalse();
});

test('changes directory and host file access without writing file contents', function (bool $hostFile) {
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
    $storage = $this->application->fileStorages()->create([
        'fs_path' => '/srv/data', 'mount_path' => '/data', 'is_host_file' => $hostFile, 'is_directory' => ! $hostFile, 'is_based_on_git' => false,
    ]);
    $component = Livewire::test(FileStorage::class, ['fileStorage' => $storage]);

    $component->set('isReadOnlyMount', true)->call('saveMountAccess')->assertHasNoErrors();

    expect($storage->refresh()->is_read_only)->toBeTrue();
    $component->set('isReadOnlyMount', false)->call('saveMountAccess')->assertHasNoErrors();
    expect($storage->refresh()->is_read_only)->toBeFalse();
})->with([true, false]);

test('returns 403 when a member tries to change mount access', function () {
    $storage = $this->application->persistentStorages()->create(['name' => 'data', 'mount_path' => '/data']);
    $this->team->members()->updateExistingPivot($this->user->id, ['role' => 'member']);

    $this->patchJson($this->endpoint, ['type' => 'persistent', 'uuid' => $storage->uuid, 'is_read_only' => true])->assertForbidden();

    expect($storage->refresh()->is_read_only)->toBeFalse();
});

test('returns 403 when a read token tries to change mount access', function () {
    $storage = $this->application->persistentStorages()->create(['name' => 'data', 'mount_path' => '/data']);
    $token = $this->user->createToken('read-only-token', ['read'], teamId: $this->team->id);

    $this->withToken($token->plainTextToken)->patchJson($this->endpoint, ['type' => 'persistent', 'uuid' => $storage->uuid, 'is_read_only' => true])->assertForbidden();

    expect($storage->refresh()->is_read_only)->toBeFalse();
});

test('returns 404 when another team owns the application', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => 'owner']);
    $token = $user->createToken('foreign-token', ['*'], teamId: $team->id);
    $storage = $this->application->persistentStorages()->create(['name' => 'data', 'mount_path' => '/data']);

    $this->withToken($token->plainTextToken)->patchJson($this->endpoint, ['type' => 'persistent', 'uuid' => $storage->uuid, 'is_read_only' => true])->assertNotFound();

    expect($storage->refresh()->is_read_only)->toBeFalse();
});

test('forbids mount access changes in the UI for a member of the resource team', function () {
    $storage = $this->application->persistentStorages()->create(['name' => 'data', 'mount_path' => '/data']);
    $this->team->members()->updateExistingPivot($this->user->id, ['role' => 'member']);
    $otherTeam = Team::factory()->create();
    $otherTeam->members()->attach($this->user, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $otherTeam]);

    Livewire::test(All::class, ['resource' => $this->application])
        ->set("forms.{$storage->id}.isReadOnlyMount", true)
        ->call('submit', $storage->id)->assertForbidden();

    expect($storage->refresh()->is_read_only)->toBeFalse();
});

test('forbids directory mount access changes in the UI for members', function () {
    $storage = $this->application->fileStorages()->create([
        'fs_path' => '/srv/data', 'mount_path' => '/data', 'is_directory' => true, 'is_based_on_git' => false,
    ]);
    $this->team->members()->updateExistingPivot($this->user->id, ['role' => 'member']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    Livewire::test(FileStorage::class, ['fileStorage' => $storage])
        ->set('isReadOnlyMount', true)->call('saveMountAccess')->assertForbidden();

    expect($storage->refresh()->is_read_only)->toBeFalse();
});

/** @return array<string, mixed> */
function renderApplicationMountCompose(Application $application, int $pullRequestId = 0): array
{
    $queue = ApplicationDeploymentQueue::create([
        'deployment_uuid' => new_public_id(),
        'application_id' => $application->id,
        'server_id' => $application->destination->server_id,
        'destination_id' => $application->destination_id,
        'status' => 'in_progress',
    ]);
    $job = new ApplicationDeploymentJob($queue->id);
    $reflection = new ReflectionClass($job);
    foreach (['production_image_name' => 'nginx:alpine', 'container_name' => 'readonly-mount-test', 'pull_request_id' => $pullRequestId] as $property => $value) {
        $reflection->getProperty($property)->setValue($job, $value);
    }
    $reflection->getMethod('generate_compose_file')->invoke($job);

    return Yaml::parse($reflection->getProperty('generated_docker_compose')->getValue($job));
}

test('renders persistent and file mount permissions in generated Compose', function (bool $readOnly, int $preview, bool $suffix) {
    $this->application->persistentStorages()->create([
        'name' => 'shared-data', 'mount_path' => '/data', 'is_read_only' => $readOnly, 'is_preview_suffix_enabled' => $suffix,
    ]);
    $this->application->persistentStorages()->create([
        'name' => 'host-data', 'host_path' => '/srv/releases', 'mount_path' => '/releases', 'is_read_only' => $readOnly, 'is_preview_suffix_enabled' => false,
    ]);
    $this->application->fileStorages()->create([
        'fs_path' => '/srv/config', 'mount_path' => '/config', 'is_directory' => true, 'is_read_only' => $readOnly,
    ]);

    $compose = renderApplicationMountCompose($this->application->fresh(), $preview);

    $option = $readOnly ? ':ro' : '';
    $name = $preview && $suffix ? 'shared-data-pr-42' : 'shared-data';
    expect($compose['services']['readonly-mount-test']['volumes'])->toBe([
        $name.':/data'.$option,
        '/srv/releases:/releases'.$option,
        '/srv/config:/config'.$option,
    ]);
})->with([true, false])->with([
    'production' => [0, true],
    'isolated preview' => [42, true],
    'shared preview' => [42, false],
]);

test('keeps mixed access modes independent in one application', function () {
    $this->application->persistentStorages()->create(['name' => 'reader', 'mount_path' => '/reader', 'is_read_only' => true]);
    $this->application->persistentStorages()->create(['name' => 'writer', 'mount_path' => '/writer']);

    $compose = renderApplicationMountCompose($this->application->fresh());

    expect($compose['services']['readonly-mount-test']['volumes'])->toBe(['reader:/reader:ro', 'writer:/writer']);
});

test('preserves mount access when cloning an application', function () {
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
    $this->application->persistentStorages()->create(['name' => 'data', 'mount_path' => '/data', 'is_read_only' => true]);
    $this->application->fileStorages()->create([
        'fs_path' => '/srv/config', 'mount_path' => '/config', 'is_directory' => true, 'is_read_only' => true,
    ]);

    $clone = clone_application($this->application, $this->application->destination, ['environment_id' => $this->application->environment_id]);

    expect($clone->persistentStorages()->sole()->is_read_only)->toBeTrue();
    expect($clone->fileStorages()->sole()->is_read_only)->toBeTrue();
});

test('enforces reader and publisher permissions in Docker after recreation', function (string $kind) {
    $directory = rtrim(getenv('COOLIFY_MOUNT_DOCKER_TEST_DIR'), '/').'/'.$kind;
    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }
    file_put_contents($directory.'/published', 'initial');
    $prefix = json_decode(getenv('COOLIFY_MOUNT_DOCKER_COMMAND') ?: '["docker"]', true, flags: JSON_THROW_ON_ERROR);
    $docker = function (array $arguments, bool $mustSucceed = true) use ($prefix): Symfony\Component\Process\Process {
        $process = new Symfony\Component\Process\Process([...$prefix, ...$arguments]);
        $process->setTimeout(90);
        $process->run();
        if ($mustSucceed) {
            expect($process->isSuccessful(), $process->getErrorOutput())->toBeTrue();
        }

        return $process;
    };
    if ($kind !== 'directory') {
        $mount = $this->application->persistentStorages()->create([
            'name' => 'coolify-mount-runtime-test', 'host_path' => $kind === 'named' ? null : $directory, 'mount_path' => '/data', 'is_read_only' => true,
        ]);
    } else {
        $mount = $this->application->fileStorages()->create([
            'fs_path' => $directory, 'mount_path' => '/data', 'is_directory' => true, 'is_read_only' => true,
        ]);
    }
    $readerCompose = renderApplicationMountCompose($this->application->fresh());
    $readerMount = $readerCompose['services']['readonly-mount-test']['volumes'][0];
    $mount->update(['is_read_only' => false]);
    $writerMount = renderApplicationMountCompose($this->application->fresh())['services']['readonly-mount-test']['volumes'][0];
    $compose = new Symfony\Component\Process\Process(['docker', 'compose', '-f', '-', 'config', '--format', 'json']);
    $compose->setInput(Yaml::dump([
        'services' => [
            'reader' => ['image' => 'alpine:latest', 'volumes' => [$readerMount]],
            'writer' => ['image' => 'alpine:latest', 'volumes' => [$writerMount]],
        ],
        ...(! empty($readerCompose['volumes']) ? ['volumes' => $readerCompose['volumes']] : []),
    ], 8));
    $compose->run();
    expect($compose->isSuccessful(), $compose->getErrorOutput())->toBeTrue();
    $normalized = json_decode($compose->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect($normalized['services']['reader']['volumes'][0]['read_only'])->toBeTrue();
    expect($normalized['services']['writer']['volumes'][0]['read_only'] ?? false)->toBeFalse();
    $names = ['coolify-mount-reader-'.$kind, 'coolify-mount-writer-'.$kind];

    try {
        foreach ([1, 2] as $generation) {
            foreach ([$readerMount, $writerMount] as $i => $volume) {
                $docker(['run', '-d', '--name', $names[$i], '--network', 'none', '-v', $volume, 'alpine:latest', 'sleep', '120']);
                $rw = trim($docker(['inspect', '--format', '{{(index .Mounts 0).RW}}', $names[$i]])->getOutput());
                expect($rw)->toBe($i === 0 ? 'false' : 'true');
            }
            $docker(['exec', $names[1], 'sh', '-c', 'printf published > /data/published']);
            expect(trim($docker(['exec', $names[0], 'cat', '/data/published'])->getOutput()))->toBe('published');
            $denied = $docker(['exec', $names[0], 'sh', '-c', 'printf forbidden > /data/forbidden'], false);
            expect($denied->isSuccessful())->toBeFalse();
            expect($denied->getErrorOutput())->toContain('Read-only file system');
            expect(file_exists($directory.'/forbidden'))->toBeFalse();
            foreach ($names as $name) {
                $docker(['rm', '-f', $name]);
            }
        }
    } finally {
        foreach ($names as $name) {
            $docker(['rm', '-f', $name], false);
        }
        if ($kind === 'named') {
            $docker(['volume', 'rm', 'coolify-mount-runtime-test'], false);
        }
        unlink($directory.'/published');
        rmdir($directory);
    }
})->with(['persistent', 'directory', 'named'])->skip(fn () => ! getenv('COOLIFY_MOUNT_DOCKER_TEST_DIR'), 'Set COOLIFY_MOUNT_DOCKER_TEST_DIR to an isolated temporary directory to run real Docker mount checks.');

test('keeps legacy access suffixes valid when enabling read-only mounts', function (string $type, string $path, string $expected) {
    $storage = $type === 'persistent'
        ? $this->application->persistentStorages()->create(['name' => 'data', 'host_path' => '/srv/data', 'mount_path' => $path, 'is_read_only' => true])
        : $this->application->fileStorages()->create(['fs_path' => '/srv/data', 'mount_path' => $path, 'is_directory' => true, 'is_read_only' => true]);

    $compose = renderApplicationMountCompose($this->application->fresh());

    expect($compose['services']['readonly-mount-test']['volumes'])->toBe(['/srv/data:'.$expected]);
})->with(['persistent', 'file'])->with([
    'legacy read only' => ['/data:ro', '/data:ro'],
    'legacy writable' => ['/data:rw', '/data:ro'],
    'SELinux label' => ['/data:z', '/data:ro,z'],
]);

test('shows mount access changes as pending deployment configuration', function (string $type) {
    $storage = $type === 'persistent'
        ? $this->application->persistentStorages()->create(['name' => 'data', 'mount_path' => '/data'])
        : $this->application->fileStorages()->create(['fs_path' => '/srv/data', 'mount_path' => '/data', 'is_directory' => true]);
    $snapshot = new ApplicationConfigurationSnapshot($this->application);
    $before = $snapshot->toArray();
    $deployment = ApplicationDeploymentQueue::create([
        'deployment_uuid' => new_public_id(), 'application_id' => $this->application->id, 'status' => 'finished', 'pull_request_id' => 0,
    ]);
    $this->application->markDeploymentConfigurationApplied($deployment);
    expect($this->application->refresh()->hasPendingDeploymentConfigurationChanges())->toBeFalse();
    $before = $snapshot->toArray();
    $storage->update(['is_read_only' => true]);

    $after = $snapshot->toArray();

    expect($before['sections']['storage']['items'])->not->toBe($after['sections']['storage']['items']);
    expect($this->application->refresh()->hasPendingDeploymentConfigurationChanges())->toBeTrue();
    $storage->update(['is_read_only' => false]);
    expect($snapshot->toArray())->toBe($before);
})->with(['persistent', 'file']);

test('rejects mount access suffixes in new API destination paths', function (array $payload) {
    $this->postJson($this->endpoint, $payload + ['mount_path' => '/data:ro', 'is_read_only' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('mount_path');
})->with('application mount types');

test('does not change Compose volume access through the UI', function () {
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
    $this->application->update(['build_pack' => 'dockercompose']);
    $storage = $this->application->persistentStorages()->create(['name' => 'data', 'mount_path' => '/data']);

    Livewire::test(All::class, ['resource' => $this->application])
        ->set("forms.{$storage->id}.isReadOnlyMount", true)->call('submit', $storage->id);

    expect($storage->refresh()->is_read_only)->toBeFalse();
});

test('saves mount access without saving unrelated pending row edits', function () {
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
    $storage = $this->application->persistentStorages()->create(['name' => 'data', 'mount_path' => '/data']);

    Livewire::test(All::class, ['resource' => $this->application])
        ->set("forms.{$storage->id}.name", 'unsaved-name')
        ->call('saveMountAccess', $storage->id, true)->assertHasNoErrors()->assertDispatched('configurationChanged');

    expect($storage->refresh()->name)->toBe('data');
    expect($storage->is_read_only)->toBeTrue();
});

test('rejects invalid access values in the dedicated UI action', function () {
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
    $storage = $this->application->persistentStorages()->create(['name' => 'data', 'mount_path' => '/data']);

    Livewire::test(All::class, ['resource' => $this->application])
        ->call('saveMountAccess', $storage->id, 'invalid')->assertHasErrors('is_read_only');

    expect($storage->refresh()->is_read_only)->toBeFalse();
});

test('forbids the dedicated UI action for members', function () {
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
    $storage = $this->application->persistentStorages()->create(['name' => 'data', 'mount_path' => '/data']);
    $this->team->members()->updateExistingPivot($this->user->id, ['role' => 'member']);

    Livewire::test(All::class, ['resource' => $this->application])
        ->call('saveMountAccess', $storage->id, true)->assertForbidden();

    expect($storage->refresh()->is_read_only)->toBeFalse();
});

test('does not allow the dedicated UI action to change another application mount', function () {
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
    $other = Application::factory()->create(['environment_id' => $this->application->environment_id]);
    $storage = $other->persistentStorages()->create(['name' => 'other', 'mount_path' => '/data']);

    expect(fn () => Livewire::test(All::class, ['resource' => $this->application])
        ->call('saveMountAccess', $storage->id, true))->toThrow(ModelNotFoundException::class);

    expect($storage->refresh()->is_read_only)->toBeFalse();
});

test('keeps legacy volume destination options available for database and service forms', function (string $type) {
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
    $resource = $type === 'database'
        ? StandalonePostgresql::create([
            'name' => 'database', 'image' => 'postgres:17-alpine', 'postgres_user' => 'postgres', 'postgres_password' => 'test-password',
            'environment_id' => $this->application->environment_id, 'destination_id' => $this->application->destination_id,
            'destination_type' => $this->application->destination_type,
        ])
        : ServiceApplication::create([
            'name' => 'service-app', 'image' => 'nginx:alpine',
            'service_id' => Service::factory()->create([
                'environment_id' => $this->application->environment_id,
                'server_id' => $this->application->destination->server_id,
            ])->id,
        ]);

    Livewire::test(StorageForm::class, ['resource' => $resource])
        ->set('name', 'legacy-data')->set('mount_path', '/data:ro')->call('submitPersistentVolume')->assertHasNoErrors();

    expect($resource->persistentStorages()->where('mount_path', '/data:ro')->count())->toBe(1);
})->with(['database', 'service']);

test('leaves Compose and non-application paths untouched during migration and rollback', function (string $resourceType) {
    $this->application->update(['build_pack' => 'dockercompose']);
    $migration = require database_path('migrations/2026_10_22_200214_add_is_read_only_to_application_mounts.php');
    $migration->down();
    foreach (['local_persistent_volumes', 'local_file_volumes'] as $table) {
        DB::table($table)->insert([
            'uuid' => new_public_id(), 'mount_path' => '/legacy:ro', 'resource_type' => $resourceType,
            'resource_id' => $this->application->id,
            ...($table === 'local_persistent_volumes' ? ['name' => 'legacy-volume'] : ['fs_path' => '/srv/legacy']),
        ]);
    }

    $migration->up();

    foreach (['local_persistent_volumes', 'local_file_volumes'] as $table) {
        expect(DB::table($table)->where('mount_path', '/legacy:ro')->count())->toBe(1);
        DB::table($table)->where('mount_path', '/legacy:ro')->update(['is_read_only' => true]);
    }
    $migration->down();
    foreach (['local_persistent_volumes', 'local_file_volumes'] as $table) {
        expect(DB::table($table)->where('mount_path', '/legacy:ro')->count())->toBe(1);
    }
    $migration->up();
})->with([Application::class, ServiceApplication::class, StandalonePostgresql::class]);

test('rejects changing a Compose directory access mode through the UI', function () {
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
    $this->application->update(['build_pack' => 'dockercompose']);
    $storage = $this->application->fileStorages()->create([
        'fs_path' => '/srv/data', 'mount_path' => '/data', 'is_directory' => true, 'is_based_on_git' => false,
    ]);

    Livewire::test(FileStorage::class, ['fileStorage' => $storage])
        ->set('isReadOnlyMount', true)->call('saveMountAccess')->assertUnprocessable();

    expect($storage->refresh()->is_read_only)->toBeFalse();
});
