<?php

use App\Auth\Oidc\OidcDiscoveryService;
use App\Auth\Oidc\WorkloadTokenValidator;
use App\Jobs\ApiTokenExpirationWarningJob;
use App\Livewire\Security\ApiTokens;
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
use App\Services\Access\AccessResolver;
use App\Services\TrustedWorkflows\TrustedWorkflowTokenExchange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Once;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

const TW_ISSUER = 'https://token.actions.githubusercontent.com';
const TW_JWKS = 'https://token.actions.githubusercontent.com/.well-known/jwks';
const TW_AUDIENCE = 'https://coolify.example.com';

beforeEach(function () {
    Server::flushIdentityMap();
    Queue::fake();
    config()->set('app.maintenance.store', 'array');
    config()->set('cache.default', 'array');
    Cache::flush();

    InstanceSettings::query()->delete();
    $settings = new InstanceSettings(['is_api_enabled' => true]);
    $settings->id = 0;
    $settings->save();
    Once::flush();

    $this->team = Team::factory()->create();
    $this->admin = User::factory()->create();
    $this->team->members()->attach($this->admin->id, ['role' => 'admin']);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = $this->server->standaloneDockers()->firstOrFail();
    $this->shop = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'shop']);
    $production = Environment::factory()->create(['project_id' => $this->shop->id, 'name' => 'prod']);
    $makeApp = fn (string $name) => Application::factory()->create([
        'name' => $name,
        'environment_id' => $production->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $this->shopApi = $makeApp('shop-api');
    $this->shopWorker = $makeApp('shop-worker');

    // Workload issuers are fetched with DNS checks; resolve test hosts to a public address without real DNS.
    twResolveTo('140.82.112.21');

    $this->key = twKeyPair('key-1');
    twFakeIssuer([$this->key['jwk']]);
});

function twResolveTo(string ...$ips): void
{
    app()->instance(OidcDiscoveryService::class, new OidcDiscoveryService(fn (string $host) => $ips));
}

afterEach(function () {
    Server::flushIdentityMap();
});

function twBase64Url(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

/**
 * @return array{private_pem: string, jwk: array<string, string>}
 */
function twKeyPair(string $kid): array
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $privatePem);
    $details = openssl_pkey_get_details($key);

    return [
        'private_pem' => $privatePem,
        'jwk' => [
            'kty' => 'RSA',
            'kid' => $kid,
            'alg' => 'RS256',
            'use' => 'sig',
            'n' => twBase64Url($details['rsa']['n']),
            'e' => twBase64Url($details['rsa']['e']),
        ],
    ];
}

/**
 * @param  list<array<string, string>>  ...$keySets  JWKS responses in order; the last one repeats
 */
function twFakeIssuer(array ...$keySets): void
{
    $sequence = Http::sequence();
    foreach ($keySets as $keys) {
        $sequence->push(['keys' => $keys]);
    }
    $sequence->whenEmpty(Http::response(['keys' => end($keySets)]));

    // A new factory, so a test can replace the responses that beforeEach registered.
    Http::swap(new Factory);

    // GitHub publishes no login endpoints: only the issuer, the JWKS URI and the algorithms.
    Http::fake([
        TW_ISSUER.'/.well-known/openid-configuration' => Http::response([
            'issuer' => TW_ISSUER,
            'jwks_uri' => TW_JWKS,
            'id_token_signing_alg_values_supported' => ['RS256'],
        ]),
        TW_JWKS => $sequence,
        '*' => Http::response('unexpected request', 500),
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function twClaims(array $overrides = []): array
{
    $now = time();

    return array_merge([
        'iss' => TW_ISSUER,
        'aud' => TW_AUDIENCE,
        'sub' => 'repo:acme/shop:ref:refs/heads/main',
        'jti' => (string) Str::uuid(),
        'iat' => $now,
        'nbf' => $now,
        'exp' => $now + 300,
        'repository' => 'acme/shop',
        'repository_id' => '123456',
        'repository_owner' => 'acme',
        'repository_owner_id' => '999',
        'ref' => 'refs/heads/main',
        'workflow_ref' => 'acme/shop/.github/workflows/deploy.yml@refs/heads/main',
        'job_workflow_ref' => 'acme/shop/.github/workflows/deploy.yml@refs/heads/main',
        'event_name' => 'push',
        'run_id' => '42',
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $claims
 */
function twSign(array $claims, string $privatePem, string $kid = 'key-1', string $algorithm = 'RS256'): string
{
    $input = twBase64Url(json_encode(['alg' => $algorithm, 'typ' => 'JWT', 'kid' => $kid]))
        .'.'.twBase64Url(json_encode($claims));
    openssl_sign($input, $signature, $privatePem, OPENSSL_ALGO_SHA256);

    return $input.'.'.twBase64Url($signature);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function twRule(Team $team, User $owner, array $attributes = []): TrustedWorkflow
{
    return TrustedWorkflow::query()->create(array_merge([
        'team_id' => $team->id,
        'user_id' => $owner->id,
        'name' => 'Deploy shop',
        'issuer' => TW_ISSUER,
        'audience' => TW_AUDIENCE,
        'conditions' => [
            'repository_id' => '123456',
            'ref' => 'refs/heads/main',
            'workflow_ref' => '*/.github/workflows/deploy.yml@*',
            'event_name' => 'push',
        ],
        'abilities' => ['read', 'deploy'],
        'scopes' => [['scope' => 'resource', 'uuid' => test()->shopApi->uuid]],
        'token_lifetime_minutes' => 10,
    ], $attributes));
}

function twExchange(string $jwt, ?string $rule = null)
{
    app('auth')->forgetGuards();

    return test()->postJson('/api/v1/auth/oidc/token', array_filter(['token' => $jwt, 'rule' => $rule]));
}

function twApi(string $token, string $method, string $uri)
{
    app('auth')->forgetGuards();

    return test()->withHeaders(['Authorization' => 'Bearer '.$token])->json($method, $uri);
}

test('exchanges a GitHub Actions token for a short-lived token scoped like the rule', function () {
    $rule = twRule($this->team, $this->admin);

    $response = twExchange(twSign(twClaims(), $this->key['private_pem']))
        ->assertOk()
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('abilities', ['read', 'deploy'])
        ->assertJsonPath('scopes', [['scope' => 'resource', 'uuid' => $this->shopApi->uuid]])
        ->assertJsonPath('trusted_workflow', $rule->uuid);

    $token = PersonalAccessToken::findToken($response->json('token'));
    expect($token->trusted_workflow_id)->toBe($rule->id)
        ->and($token->tokenable_id)->toBe($this->admin->id)
        ->and((int) $token->team_id)->toBe($this->team->id)
        ->and((int) round(now()->diffInMinutes($token->expires_at)))->toBe(10)
        ->and($rule->fresh()->last_used_at)->not->toBeNull();

    twApi($response->json('token'), 'POST', "/api/v1/deploy?uuid={$this->shopApi->uuid}")->assertOk();
    twApi($response->json('token'), 'POST', "/api/v1/deploy?uuid={$this->shopWorker->uuid}")->assertNotFound();
    twApi($response->json('token'), 'GET', '/api/v1/servers')->assertForbidden();

    $event = AuditEvent::query()->where('event', 'api.trusted_workflow.token_issued')->sole();
    expect($event->team_id)->toBe($this->team->id)
        ->and($event->resource_uuid)->toBe($rule->uuid)
        ->and(data_get($event->metadata, 'claims.repository'))->toBe('acme/shop')
        ->and(data_get($event->metadata, 'claims.run_id'))->toBe('42');
});

test('the minted token stops working when it expires and expired tokens are pruned', function () {
    $rule = twRule($this->team, $this->admin, ['token_lifetime_minutes' => 5]);
    $first = twExchange(twSign(twClaims(), $this->key['private_pem']))->assertOk()->json('token');

    $this->travel(6)->minutes();
    twApi($first, 'GET', '/api/v1/version')->assertUnauthorized();

    twExchange(twSign(twClaims(), $this->key['private_pem']))->assertOk();
    expect(PersonalAccessToken::query()->where('trusted_workflow_id', $rule->id)->count())->toBe(1);
});

test('denies tokens that fail verification', function (Closure $makeToken) {
    twRule($this->team, $this->admin);

    twExchange($makeToken($this->key))
        ->assertForbidden()
        ->assertExactJson(['message' => 'Token exchange denied.']);

    expect(PersonalAccessToken::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('event', 'api.trusted_workflow.exchange_denied')->exists())->toBeTrue();
})->with([
    'signed by another key' => [fn ($key) => twSign(twClaims(), twKeyPair('key-1')['private_pem'])],
    'expired' => [fn ($key) => twSign(twClaims(['iat' => time() - 900, 'nbf' => time() - 900, 'exp' => time() - 600]), $key['private_pem'])],
    'not yet valid' => [fn ($key) => twSign(twClaims(['nbf' => time() + 600]), $key['private_pem'])],
    'valid for too long' => [fn ($key) => twSign(twClaims(['exp' => time() + 7200]), $key['private_pem'])],
    'issued more than an hour before it expires' => [fn ($key) => twSign(twClaims(['iat' => time() - 7000, 'nbf' => time() - 7000, 'exp' => time() + 120]), $key['private_pem'])],
    'expires before it was issued' => [fn ($key) => twSign(twClaims(['iat' => time() + 20, 'nbf' => time(), 'exp' => time() + 10]), $key['private_pem'])],
    'wrong audience' => [fn ($key) => twSign(twClaims(['aud' => 'https://github.com/acme']), $key['private_pem'])],
    'without jti' => [fn ($key) => twSign(Arr::except(twClaims(), 'jti'), $key['private_pem'])],
    'alg none' => [fn ($key) => twBase64Url(json_encode(['alg' => 'none', 'kid' => 'key-1'])).'.'.twBase64Url(json_encode(twClaims())).'.'],
    'alg HS256' => [fn ($key) => (function () {
        $input = twBase64Url(json_encode(['alg' => 'HS256', 'kid' => 'key-1'])).'.'.twBase64Url(json_encode(twClaims()));

        return $input.'.'.twBase64Url(hash_hmac('sha256', $input, 'secret', true));
    })()],
    'malformed' => [fn ($key) => 'not-a-jwt'],
]);

test('never contacts an issuer that no rule names', function () {
    twRule($this->team, $this->admin);

    twExchange(twSign(twClaims(['iss' => 'https://attacker.example.com']), $this->key['private_pem']))->assertForbidden();

    Http::assertNothingSent();
});

test('denies a token whose claims do not match the rule', function (array $overrides) {
    twRule($this->team, $this->admin);

    twExchange(twSign(twClaims($overrides), $this->key['private_pem']))->assertForbidden();

    $event = AuditEvent::query()->where('event', 'api.trusted_workflow.exchange_denied')->sole();
    expect(data_get($event->metadata, 'reason'))->toBe('no_matching_rule');
})->with([
    'other repository with the same name' => [['repository_id' => '777']],
    'other branch' => [['ref' => 'refs/heads/feature']],
    'other workflow' => [['workflow_ref' => 'acme/shop/.github/workflows/other.yml@refs/heads/main']],
    'other event' => [['event_name' => 'pull_request']],
    'missing claim' => [['event_name' => null]],
]);

test('matches glob patterns and integer claims', function () {
    twRule($this->team, $this->admin, ['conditions' => ['project_id' => '42', 'ref' => 'refs/tags/v*']]);

    twExchange(twSign(twClaims(['project_id' => 42, 'ref' => 'refs/tags/v1.2.3']), $this->key['private_pem']))->assertOk();
    twExchange(twSign(twClaims(['project_id' => 42, 'ref' => 'refs/heads/v1']), $this->key['private_pem']))->assertForbidden();
});

test('refreshes the JWKS once when the key id is unknown', function () {
    $rotated = twKeyPair('key-2');
    twFakeIssuer([$this->key['jwk']], [$this->key['jwk'], $rotated['jwk']]);
    twRule($this->team, $this->admin);

    twExchange(twSign(twClaims(), $this->key['private_pem']))->assertOk();
    twExchange(twSign(twClaims(), $rotated['private_pem'], 'key-2'))->assertOk();
});

test('exchanges each token only once', function () {
    twRule($this->team, $this->admin);
    $jwt = twSign(twClaims(), $this->key['private_pem']);

    twExchange($jwt)->assertOk();
    twExchange($jwt)->assertForbidden();

    expect(PersonalAccessToken::query()->count())->toBe(1);
});

test('asks for a rule when more than one matches', function () {
    twRule($this->team, $this->admin, ['name' => 'Read only', 'abilities' => ['read']]);
    $deploy = twRule($this->team, $this->admin);

    twExchange(twSign(twClaims(), $this->key['private_pem']))->assertStatus(409);

    twExchange(twSign(twClaims(), $this->key['private_pem']), $deploy->uuid)
        ->assertOk()
        ->assertJsonPath('trusted_workflow', $deploy->uuid)
        ->assertJsonPath('abilities', ['read', 'deploy']);
});

test('a named rule must match the token', function () {
    $other = twRule($this->team, $this->admin, ['conditions' => ['repository_id' => '777']]);
    twRule($this->team, $this->admin);

    twExchange(twSign(twClaims(), $this->key['private_pem']), $other->uuid)->assertForbidden();
});

test('denies a paused rule', function () {
    $rule = twRule($this->team, $this->admin);
    $rule->forceFill(['paused_at' => now()])->save();

    twExchange(twSign(twClaims(), $this->key['private_pem']))->assertForbidden();
});

test('denies when the owner left the team', function () {
    twRule($this->team, $this->admin);
    $this->team->members()->detach($this->admin->id);

    twExchange(twSign(twClaims(), $this->key['private_pem']))->assertForbidden();
});

test('denies when the owner no longer holds the access of the rule', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, [
        'role' => 'member',
        'access' => json_encode([['scope' => 'project', 'uuid' => $this->shop->uuid, 'levels' => ['read', 'deploy']]]),
    ]);
    twRule($this->team, $member);

    twExchange(twSign(twClaims(), $this->key['private_pem']))->assertOk();

    $this->team->members()->updateExistingPivot($member->id, [
        'access' => json_encode([['scope' => 'project', 'uuid' => $this->shop->uuid, 'levels' => ['read']]]),
    ]);

    twExchange(twSign(twClaims(), $this->key['private_pem']))->assertForbidden();
});

test('respects the API switch and the allowed IPs', function () {
    config()->set('constants.coolify.self_hosted', true);
    twRule($this->team, $this->admin);

    Once::flush();
    // SQLite returns 0 for the uncast column; Postgres returns a real boolean.
    InstanceSettings::get()->is_api_enabled = false;
    twExchange(twSign(twClaims(), $this->key['private_pem']))
        ->assertForbidden()
        ->assertExactJson(['success' => false, 'message' => 'API is disabled.']);

    InstanceSettings::query()->whereKey(0)->update(['allowed_ips' => '192.0.2.10']);
    Once::flush();
    twExchange(twSign(twClaims(), $this->key['private_pem']))
        ->assertForbidden()
        ->assertExactJson(['success' => false, 'message' => 'You are not allowed to access the API.']);

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

test('reports an unreachable issuer without minting a token', function () {
    Http::swap(new Factory);
    Http::fake(['*' => Http::response('down', 502)]);
    twRule($this->team, $this->admin);

    twExchange(twSign(twClaims(), $this->key['private_pem']))->assertStatus(503);
    expect(PersonalAccessToken::query()->count())->toBe(0);
});

test('minted tokens are not listed with API tokens and get no expiry warning', function () {
    twRule($this->team, $this->admin);
    twExchange(twSign(twClaims(), $this->key['private_pem']))->assertOk();
    $this->admin->createToken('manual', ['read'], now()->addDays(3), $this->team->id);

    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire\Livewire::test(ApiTokens::class)
        ->assertSet('tokens', fn ($tokens) => $tokens->pluck('name')->all() === ['manual']);

    (new ApiTokenExpirationWarningJob)->handle();
    expect(PersonalAccessToken::query()->whereNotNull('trusted_workflow_id')->whereNotNull('api_token_expiration_warning_sent_at')->exists())->toBeFalse();
});

test('refuses issuers that resolve to private, loopback, or metadata addresses', function (string $ip) {
    twResolveTo($ip);
    twRule($this->team, $this->admin);

    twExchange(twSign(twClaims(), $this->key['private_pem']))->assertStatus(503);

    Http::assertNothingSent();
    expect(PersonalAccessToken::query()->count())->toBe(0);
})->with(['private' => '10.0.0.5', 'loopback' => '127.0.0.1', 'link-local metadata' => '169.254.169.254']);

test('does not follow redirects from a workload issuer', function () {
    Http::swap(new Factory);
    Http::fake([
        TW_ISSUER.'/.well-known/openid-configuration' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data']),
        '*' => Http::response(['issuer' => TW_ISSUER, 'jwks_uri' => TW_JWKS]),
    ]);
    twRule($this->team, $this->admin);

    twExchange(twSign(twClaims(), $this->key['private_pem']))->assertStatus(503);

    Http::assertSentCount(1);
});

test('refuses a JWKS larger than the size limit', function () {
    Http::swap(new Factory);
    Http::fake([
        TW_ISSUER.'/.well-known/openid-configuration' => Http::response(['issuer' => TW_ISSUER, 'jwks_uri' => TW_JWKS]),
        TW_JWKS => Http::response(json_encode(['keys' => [test()->key['jwk']], 'padding' => str_repeat('x', 1048576)])),
    ]);
    twRule($this->team, $this->admin);

    twExchange(twSign(twClaims(), $this->key['private_pem']))->assertStatus(503);
    expect(PersonalAccessToken::query()->count())->toBe(0);
});

test('issues no token when the rule is paused while the exchange runs', function () {
    $rule = twRule($this->team, $this->admin);
    $exchange = Mockery::mock(TrustedWorkflowTokenExchange::class, [
        app(OidcDiscoveryService::class),
        app(WorkloadTokenValidator::class),
        app(AccessResolver::class),
    ])->makePartial();
    $calls = 0;
    // A pause commits after the first access check and before the token is written.
    $exchange->shouldReceive('ownerCanGrant')->andReturnUsing(function () use ($rule, &$calls) {
        if (++$calls === 1) {
            TrustedWorkflow::query()->whereKey($rule->id)->update(['paused_at' => now()]);
        }

        return true;
    });
    $this->app->instance(TrustedWorkflowTokenExchange::class, $exchange);

    twExchange(twSign(twClaims(), $this->key['private_pem']))->assertForbidden();

    expect(PersonalAccessToken::query()->count())->toBe(0)
        ->and(data_get(AuditEvent::query()->where('event', 'api.trusted_workflow.exchange_denied')->sole()->metadata, 'reason'))->toBe('rule_paused');
});

test('limits exchange attempts per address', function () {
    twRule($this->team, $this->admin);

    foreach (range(1, 20) as $attempt) {
        twExchange('not-a-jwt')->assertForbidden();
    }

    twExchange('not-a-jwt')->assertStatus(429);
});
