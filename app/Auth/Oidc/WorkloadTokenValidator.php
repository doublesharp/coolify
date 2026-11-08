<?php

namespace App\Auth\Oidc;

use App\Auth\Oidc\Exceptions\OidcSigningKeyNotFoundException;
use App\Auth\Oidc\Exceptions\OidcTokenException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Throwable;

/**
 * Validates an OIDC token that a CI system issues to a job (for example GitHub Actions),
 * as opposed to an id_token from a login. There is no client id or nonce: the caller matches
 * the audience and claims against its own rules.
 */
class WorkloadTokenValidator
{
    /**
     * Asymmetric algorithms only. An allowlist prevents "none" and HMAC algorithm confusion.
     */
    public const ALLOWED_ALGORITHMS = ['RS256', 'ES256'];

    /**
     * Tokens valid for longer than this are refused, which also bounds the replay cache.
     */
    public const MAX_LIFETIME_SECONDS = 3600;

    /**
     * @param  array<string, mixed>  $jwks
     * @return array<string, mixed> the verified claims
     */
    public function validate(string $token, string $expectedIssuer, array $jwks, int $clockSkewSeconds = 30): array
    {
        $header = $this->header($token);
        $algorithm = $header['alg'] ?? null;
        if (! in_array($algorithm, self::ALLOWED_ALGORITHMS, true)) {
            throw new OidcTokenException('Token uses a disallowed algorithm.');
        }

        $kid = $header['kid'] ?? null;
        if (! is_string($kid) || $kid === '') {
            throw new OidcTokenException('Token header is missing kid.');
        }

        $signingKeys = $this->signingKeys($jwks);
        if ($signingKeys['keys'] === []) {
            throw new OidcSigningKeyNotFoundException('The JWKS has no signing keys.');
        }

        try {
            $keys = JWK::parseKeySet($signingKeys);
        } catch (Throwable $e) {
            throw new OidcTokenException("Unable to parse JWKS: {$e->getMessage()}", previous: $e);
        }

        // An unknown key is reported on its own, so the caller can refresh the JWKS once after a key rotation.
        if (! array_key_exists($kid, $keys)) {
            throw new OidcSigningKeyNotFoundException('No matching JWKS key found for the token kid.');
        }

        $previousLeeway = JWT::$leeway;
        JWT::$leeway = $clockSkewSeconds;

        try {
            // Checks the signature, that the header alg is the key alg, and exp, nbf and iat.
            $claims = json_decode(json_encode(JWT::decode($token, $keys), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new OidcTokenException("Token validation failed: {$e->getMessage()}", previous: $e);
        } finally {
            JWT::$leeway = $previousLeeway;
        }

        if (! is_array($claims)) {
            throw new OidcTokenException('Token payload is not an object.');
        }

        $this->assertTimes($claims, $clockSkewSeconds);

        if (($claims['iss'] ?? null) !== $expectedIssuer) {
            throw new OidcTokenException('Token issuer does not match.');
        }

        foreach (['sub', 'jti'] as $claim) {
            if (! is_string($claims[$claim] ?? null) || $claims[$claim] === '') {
                throw new OidcTokenException("Token is missing the {$claim} claim.");
            }
        }

        if (self::audiences($claims) === []) {
            throw new OidcTokenException('Token is missing the aud claim.');
        }

        return $claims;
    }

    /**
     * The issuer claim, read without checking the signature. Use it only to find the rules
     * and the keys to verify the token with.
     */
    public function unverifiedIssuer(string $token): ?string
    {
        $segments = explode('.', $token);
        if (count($segments) !== 3) {
            return null;
        }

        try {
            $payload = json_decode($this->base64UrlDecode($segments[1]), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        $issuer = is_array($payload) ? ($payload['iss'] ?? null) : null;

        return is_string($issuer) && $issuer !== '' ? $issuer : null;
    }

    /**
     * @param  array<string, mixed>  $claims
     * @return list<string>
     */
    public static function audiences(array $claims): array
    {
        $audience = $claims['aud'] ?? null;
        if (is_string($audience)) {
            $audience = [$audience];
        }

        return is_array($audience) ? array_values(array_filter($audience, fn ($value) => is_string($value) && $value !== '')) : [];
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function assertTimes(array $claims, int $clockSkewSeconds): void
    {
        foreach (['exp', 'iat'] as $claim) {
            if (! is_numeric($claims[$claim] ?? null)) {
                throw new OidcTokenException("Token is missing the {$claim} claim.");
            }
        }

        $expiresAt = (int) $claims['exp'];
        $issuedAt = (int) $claims['iat'];
        if ($expiresAt <= $issuedAt) {
            throw new OidcTokenException('Token expires before it was issued.');
        }
        if ($expiresAt - $issuedAt > self::MAX_LIFETIME_SECONDS || $expiresAt - time() > self::MAX_LIFETIME_SECONDS + $clockSkewSeconds) {
            throw new OidcTokenException('Token is valid for longer than allowed.');
        }
    }

    /**
     * Keep only signing keys, and give keys without an alg the algorithm of their key type,
     * so a key can never verify a token signed with another algorithm.
     *
     * @param  array<string, mixed>  $jwks
     * @return array{keys: list<array<string, mixed>>}
     */
    private function signingKeys(array $jwks): array
    {
        $keys = [];
        foreach ($jwks['keys'] ?? [] as $jwk) {
            if (! is_array($jwk) || (isset($jwk['use']) && $jwk['use'] !== 'sig')) {
                continue;
            }

            $jwk['alg'] ??= match (true) {
                ($jwk['kty'] ?? null) === 'RSA' => 'RS256',
                ($jwk['kty'] ?? null) === 'EC' && ($jwk['crv'] ?? null) === 'P-256' => 'ES256',
                default => null,
            };
            if (! in_array($jwk['alg'], self::ALLOWED_ALGORITHMS, true)) {
                continue;
            }

            $keys[] = $jwk;
        }

        return ['keys' => $keys];
    }

    /**
     * @return array<string, mixed>
     */
    private function header(string $token): array
    {
        $segments = explode('.', $token);
        if (count($segments) !== 3) {
            throw new OidcTokenException('Malformed token.');
        }

        try {
            $header = json_decode($this->base64UrlDecode($segments[0]), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new OidcTokenException('Token header contains invalid JSON.', previous: $e);
        }

        if (! is_array($header)) {
            throw new OidcTokenException('Token header contains invalid JSON.');
        }

        return $header;
    }

    private function base64UrlDecode(string $value): string
    {
        $remainder = strlen($value) % 4;
        if ($remainder !== 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new OidcTokenException('Invalid base64url value in token.');
        }

        return $decoded;
    }
}
