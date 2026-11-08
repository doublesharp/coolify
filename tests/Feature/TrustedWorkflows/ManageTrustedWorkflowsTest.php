<?php

use App\Actions\User\RevokeUserTeamTokens;
use App\Livewire\Security\TrustedWorkflows;
use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PersonalAccessToken;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\TrustedWorkflow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Audit events are written after the response; write them at once to assert them.
    $this->withoutDefer();
    Server::flushIdentityMap();
    config()->set('app.maintenance.store', 'array');
    config()->set('cache.default', 'array');

    InstanceSettings::query()->delete();
    $settings = new InstanceSettings(['is_api_enabled' => true]);
    $settings->id = 0;
    $settings->save();
    Once::flush();

    $this->team = Team::factory()->create();
    $this->admin = User::factory()->create();
    $this->team->members()->attach($this->admin->id, ['role' => 'admin']);

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = $server->standaloneDockers()->firstOrFail();
    $this->shop = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'shop']);
    $production = Environment::factory()->create(['project_id' => $this->shop->id, 'name' => 'prod']);
    $this->shopApi = Application::factory()->create([
        'name' => 'shop-api',
        'environment_id' => $production->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
});

afterEach(function () {
    Server::flushIdentityMap();
});

function manageTwActingAs(User $user, Team $team): void
{
    test()->actingAs($user);
    session(['currentTeam' => $team]);
}

function manageTwMember(Team $team, array $access): User
{
    $member = User::factory()->create();
    $team->members()->attach($member->id, ['role' => 'member', 'access' => json_encode($access)]);

    return $member;
}

function manageTwRule(Team $team, User $owner, string $name = 'Deploy'): TrustedWorkflow
{
    return TrustedWorkflow::query()->create([
        'team_id' => $team->id,
        'user_id' => $owner->id,
        'name' => $name,
        'issuer' => TrustedWorkflow::DEFAULT_ISSUER,
        'audience' => 'https://coolify.example.com',
        'conditions' => ['repository_id' => '123456'],
        'abilities' => ['read'],
        'scopes' => null,
        'token_lifetime_minutes' => 10,
    ]);
}

test('creates a trusted workflow from the GitHub fields', function () {
    manageTwActingAs($this->admin, $this->team);

    Livewire::test(TrustedWorkflows::class)
        ->set('form.description', 'Deploy shop API')
        ->set('form.audience', 'https://coolify.example.com')
        ->set('form.repositoryId', '123456')
        ->set('form.ref', 'refs/tags/v*')
        ->set('form.workflowPath', '/.github/workflows/deploy.yml')
        ->set('form.eventName', 'push')
        ->set('form.extraConditions', "runner_environment=github-hosted\n")
        ->set('form.permissions', ['deploy', 'read'])
        ->set('form.scopeMode', 'selected')
        ->set('form.selectedScopes', ["resource:{$this->shopApi->uuid}"])
        ->set('form.tokenLifetimeMinutes', 15)
        ->call('addTrustedWorkflow')
        ->assertHasNoErrors()
        ->assertDispatched('trusted-workflow-created');

    $rule = TrustedWorkflow::query()->sole();
    expect($rule->team_id)->toBe($this->team->id)
        ->and($rule->user_id)->toBe($this->admin->id)
        ->and($rule->issuer)->toBe('https://token.actions.githubusercontent.com')
        ->and($rule->conditions)->toBe([
            'repository_id' => '123456',
            'ref' => 'refs/tags/v*',
            'workflow_ref' => '*/.github/workflows/deploy.yml@*',
            'event_name' => 'push',
            'runner_environment' => 'github-hosted',
        ])
        ->and($rule->abilities)->toBe(['deploy', 'read'])
        ->and($rule->scopes)->toBe([['scope' => 'resource', 'uuid' => $this->shopApi->uuid]])
        ->and($rule->token_lifetime_minutes)->toBe(15)
        ->and(AuditEvent::query()->where('event', 'ui.trusted_workflow.created')->exists())->toBeTrue();
});

test('requires an exact repository or project ID', function (array $fields, string $errorField) {
    manageTwActingAs($this->admin, $this->team);

    Livewire::test(TrustedWorkflows::class)
        ->set('form.description', 'Deploy')
        ->set($fields)
        ->call('addTrustedWorkflow')
        ->assertHasErrors($errorField);

    expect(TrustedWorkflow::query()->count())->toBe(0);
})->with([
    'GitHub without repository ID' => [['form.repositoryId' => ''], 'form.repositoryId'],
    'GitHub with only an owner ID' => [['form.repositoryId' => '', 'form.repositoryOwnerId' => '999', 'form.workflowPath' => '.github/workflows/deploy.yml'], 'form.repositoryId'],
    'GitLab without project ID' => [['form.issuer' => 'https://gitlab.com', 'form.repositoryId' => '', 'form.ref' => 'main'], 'form.extraConditions'],
    'GitLab with only a namespace ID' => [['form.issuer' => 'https://gitlab.com', 'form.repositoryId' => '', 'form.extraConditions' => 'namespace_id=5'], 'form.extraConditions'],
    'ID with a glob' => [['form.issuer' => 'https://gitlab.com', 'form.repositoryId' => '', 'form.extraConditions' => 'project_id=4*'], 'form.extraConditions'],
    'reserved claim' => [['form.repositoryId' => '1', 'form.extraConditions' => 'aud=https://example.com'], 'form.extraConditions'],
    'malformed line' => [['form.repositoryId' => '1', 'form.extraConditions' => 'environment'], 'form.extraConditions'],
]);

test('only team admins and owners can trust an issuer other than GitHub and GitLab.com', function () {
    $member = manageTwMember($this->team, [['scope' => 'project', 'uuid' => $this->shop->uuid, 'levels' => ['read']]]);
    $create = fn (string $issuer, string $conditions) => Livewire::test(TrustedWorkflows::class)
        ->set('form.description', 'Deploy')
        ->set('form.issuer', $issuer)
        ->set('form.repositoryId', '')
        ->set('form.extraConditions', $conditions)
        ->set('form.permissions', ['read'])
        ->set('form.scopeMode', 'selected')
        ->set('form.selectedScopes', ["project:{$this->shop->uuid}"])
        ->call('addTrustedWorkflow');

    manageTwActingAs($member, $this->team);
    $create('https://ci.example.com', 'project_id=1')->assertHasErrors('form.issuer');
    expect(TrustedWorkflow::query()->count())->toBe(0);
    $create('https://gitlab.com', 'project_id=1')->assertHasNoErrors();
    expect(TrustedWorkflow::query()->count())->toBe(1);

    manageTwActingAs($this->admin, $this->team);
    $create('https://ci.example.com', 'project_id=2')->assertHasNoErrors();
    expect(TrustedWorkflow::query()->where('issuer', 'https://ci.example.com')->exists())->toBeTrue();
});

test('rejects an issuer that is not HTTPS', function () {
    manageTwActingAs($this->admin, $this->team);

    Livewire::test(TrustedWorkflows::class)
        ->set('form.description', 'Deploy')
        ->set('form.issuer', 'http://token.actions.githubusercontent.com')
        ->set('form.repositoryId', '1')
        ->call('addTrustedWorkflow')
        ->assertHasErrors('form.issuer');
});

test('a member cannot give a trusted workflow more access than the member has', function () {
    $member = manageTwMember($this->team, [['scope' => 'project', 'uuid' => $this->shop->uuid, 'levels' => ['read']]]);
    manageTwActingAs($member, $this->team);

    Livewire::test(TrustedWorkflows::class)
        ->set('form.description', 'Deploy')
        ->set('form.repositoryId', '1')
        ->set('form.permissions', ['deploy', 'read'])
        ->set('form.scopeMode', 'selected')
        ->set('form.selectedScopes', ["project:{$this->shop->uuid}"])
        ->call('addTrustedWorkflow')
        ->assertDispatched('error');

    expect(TrustedWorkflow::query()->count())->toBe(0);
});

test('members see their own trusted workflows and admins see the whole team', function () {
    $member = manageTwMember($this->team, [['scope' => 'project', 'uuid' => $this->shop->uuid, 'levels' => ['read']]]);
    manageTwRule($this->team, $this->admin, 'Admin rule');
    manageTwRule($this->team, $member, 'Member rule');
    $otherTeam = Team::factory()->create();
    $otherTeam->members()->attach($this->admin->id, ['role' => 'owner']);
    manageTwRule($otherTeam, $this->admin, 'Other team rule');

    manageTwActingAs($member, $this->team);
    Livewire::test(TrustedWorkflows::class)
        ->assertSet('workflows', fn ($workflows) => $workflows->pluck('name')->all() === ['Member rule']);

    manageTwActingAs($this->admin, $this->team);
    Livewire::test(TrustedWorkflows::class)
        ->assertSet('workflows', fn ($workflows) => $workflows->pluck('name')->sort()->values()->all() === ['Admin rule', 'Member rule']);
});

test('a member cannot pause or delete the trusted workflow of another member', function () {
    $member = manageTwMember($this->team, [['scope' => 'project', 'uuid' => $this->shop->uuid, 'levels' => ['read']]]);
    $rule = manageTwRule($this->team, $this->admin);

    manageTwActingAs($member, $this->team);
    Livewire::test(TrustedWorkflows::class)
        ->call('setPaused', $rule->uuid, true)
        ->call('delete', $rule->uuid);

    expect($rule->fresh())->not->toBeNull()
        ->and($rule->fresh()->isPaused())->toBeFalse();
});

test('a trusted workflow of another team is not found', function () {
    $otherTeam = Team::factory()->create();
    $otherAdmin = User::factory()->create();
    $otherTeam->members()->attach($otherAdmin->id, ['role' => 'owner']);
    $rule = manageTwRule($otherTeam, $otherAdmin);

    manageTwActingAs($this->admin, $this->team);
    Livewire::test(TrustedWorkflows::class)->call('delete', $rule->uuid)->assertStatus(404);

    expect($rule->fresh())->not->toBeNull();
});

test('pausing revokes the issued tokens and deleting removes the rule and its tokens', function () {
    $rule = manageTwRule($this->team, $this->admin);
    $issue = function () use ($rule) {
        $token = $this->admin->createToken('issued', ['read'], now()->addMinutes(10), $this->team->id);
        $token->accessToken->forceFill(['trusted_workflow_id' => $rule->id])->save();
    };
    $issue();

    manageTwActingAs($this->admin, $this->team);
    $component = Livewire::test(TrustedWorkflows::class)->call('setPaused', $rule->uuid, true);
    expect($rule->fresh()->isPaused())->toBeTrue()
        ->and(PersonalAccessToken::query()->count())->toBe(0);

    $component->call('setPaused', $rule->uuid, false);
    expect($rule->fresh()->isPaused())->toBeFalse();

    $issue();
    $component->call('delete', $rule->uuid);
    expect(TrustedWorkflow::query()->count())->toBe(0)
        ->and(PersonalAccessToken::query()->count())->toBe(0)
        ->and(AuditEvent::query()->whereIn('event', ['ui.trusted_workflow.paused', 'ui.trusted_workflow.resumed', 'ui.trusted_workflow.deleted'])->count())->toBe(3);
});

test('removing a member from the team deletes their trusted workflows', function () {
    $member = manageTwMember($this->team, [['scope' => 'project', 'uuid' => $this->shop->uuid, 'levels' => ['read']]]);
    manageTwRule($this->team, $member);

    RevokeUserTeamTokens::forUserTeam($member, $this->team->id);

    expect(TrustedWorkflow::query()->count())->toBe(0);
});

test('the API tokens page renders the trusted workflows section', function () {
    manageTwRule($this->team, $this->admin, 'Shown rule');
    manageTwActingAs($this->admin, $this->team);

    $this->get(route('security.api-tokens'))
        ->assertOk()
        ->assertSee('Trusted workflows')
        ->assertSee('Shown rule');
});
