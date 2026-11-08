<?php

use App\Auth\Oidc\Exceptions\OidcDiscoveryException;
use App\Auth\Oidc\Exceptions\OidcSigningKeyNotFoundException;
use App\Auth\Oidc\Exceptions\OidcTokenException;
use App\Auth\Oidc\OidcDiscoveryService;
use App\Auth\Oidc\WorkloadTokenValidator;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

function workloadBase64Url(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function workloadClaims(array $overrides = []): array
{
    return array_merge([
        'iss' => 'https://ci.example.com',
        'aud' => 'https://coolify.example.com',
        'sub' => 'project_path:group/app:ref_type:branch:ref:main',
        'jti' => 'jti-1',
        'iat' => time(),
        'exp' => time() + 300,
        'project_id' => 42,
    ], $overrides);
}

/**
 * An ES256 key and a token signed with it. JWT ES256 signatures are raw r||s, not DER.
 *
 * @return array{jwks: array, token: string}
 */
function workloadEs256(array $claims, bool $withAlg = true): array
{
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $details = openssl_pkey_get_details($key);
    $jwk = array_filter([
        'kty' => 'EC',
        'crv' => 'P-256',
        'kid' => 'ec-1',
        'alg' => $withAlg ? 'ES256' : null,
        'x' => workloadBase64Url(str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT)),
        'y' => workloadBase64Url(str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT)),
    ]);

    $input = workloadBase64Url(json_encode(['alg' => 'ES256', 'kid' => 'ec-1'])).'.'.workloadBase64Url(json_encode($claims));
    openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256);
    $signature = JWT::urlsafeB64Decode(workloadBase64Url($der));
    // Convert DER to raw r||s.
    $offset = 3;
    $rLength = ord($signature[$offset]);
    $r = substr($signature, $offset + 1, $rLength);
    $offset += 1 + $rLength + 1;
    $sLength = ord($signature[$offset]);
    $s = substr($signature, $offset + 1, $sLength);
    $raw = str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT).str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);

    return ['jwks' => ['keys' => [$jwk]], 'token' => $input.'.'.workloadBase64Url($raw)];
}

it('accepts ES256 tokens, also from keys without an alg', function (bool $withAlg) {
    $signed = workloadEs256(workloadClaims(), $withAlg);

    $claims = app(WorkloadTokenValidator::class)->validate($signed['token'], 'https://ci.example.com', $signed['jwks']);

    expect($claims['project_id'])->toBe(42);
})->with(['with alg' => true, 'without alg' => false]);

it('reports an unknown key id so the caller can refresh the keys', function () {
    $signed = workloadEs256(workloadClaims());
    $signed['jwks']['keys'][0]['kid'] = 'other';

    app(WorkloadTokenValidator::class)->validate($signed['token'], 'https://ci.example.com', $signed['jwks']);
})->throws(OidcSigningKeyNotFoundException::class);

it('ignores keys that are not for signing', function () {
    $signed = workloadEs256(workloadClaims());
    $signed['jwks']['keys'][0]['use'] = 'enc';

    app(WorkloadTokenValidator::class)->validate($signed['token'], 'https://ci.example.com', $signed['jwks']);
})->throws(OidcSigningKeyNotFoundException::class);

it('rejects a token from another issuer', function () {
    $signed = workloadEs256(workloadClaims(['iss' => 'https://other.example.com']));

    app(WorkloadTokenValidator::class)->validate($signed['token'], 'https://ci.example.com', $signed['jwks']);
})->throws(OidcTokenException::class, 'issuer');

it('reads the issuer without verifying, and only from well-formed tokens', function () {
    $validator = app(WorkloadTokenValidator::class);
    $signed = workloadEs256(workloadClaims());

    expect($validator->unverifiedIssuer($signed['token']))->toBe('https://ci.example.com')
        ->and($validator->unverifiedIssuer('a.b'))->toBeNull()
        ->and($validator->unverifiedIssuer('a.@@@.c'))->toBeNull();
});

it('discovers a workload issuer that publishes no login endpoints', function () {
    Cache::flush();
    Http::fake([
        'https://ci.example.com/.well-known/openid-configuration' => Http::response([
            'issuer' => 'https://ci.example.com',
            'jwks_uri' => 'https://ci.example.com/keys',
        ]),
    ]);

    $metadata = app(OidcDiscoveryService::class)->issuerMetadata('https://ci.example.com/');

    expect($metadata->issuer)->toBe('https://ci.example.com')
        ->and($metadata->jwksUri)->toBe('https://ci.example.com/keys');
});

it('rejects workload discovery documents that are unsafe or incomplete', function (array $document) {
    Cache::flush();
    Http::fake(['https://ci.example.com/.well-known/openid-configuration' => Http::response($document)]);

    app(OidcDiscoveryService::class)->issuerMetadata('https://ci.example.com');
})->throws(OidcDiscoveryException::class)->with([
    'issuer mismatch' => [['issuer' => 'https://evil.example.com', 'jwks_uri' => 'https://ci.example.com/keys']],
    'plain HTTP keys' => [['issuer' => 'https://ci.example.com', 'jwks_uri' => 'http://ci.example.com/keys']],
    'no keys' => [['issuer' => 'https://ci.example.com']],
]);
