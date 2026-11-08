<?php

namespace App\Auth\Oidc;

use App\Auth\Oidc\Exceptions\OidcDiscoveryException;
use App\Auth\Oidc\Exceptions\OidcJwksException;
use App\Rules\SafeWebhookUrl;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class OidcDiscoveryService
{
    /**
     * Largest discovery document or JWKS read from a workload issuer.
     */
    public const MAX_WORKLOAD_RESPONSE_BYTES = 1048576;

    /**
     * @param  (Closure(string): array<int, string>)|null  $resolver  DNS resolver for workload issuers, for tests
     */
    public function __construct(private readonly ?Closure $resolver = null) {}

    public function discover(string $issuerUrl): OidcDiscoveryDocument
    {
        $this->assertHttpsUrl($issuerUrl, new OidcDiscoveryException('Issuer URL must be an absolute HTTPS URL.'));

        $issuerUrl = rtrim($issuerUrl, '/');
        $cacheKey = 'oidc:discovery:'.hash('sha256', $issuerUrl);

        return Cache::remember($cacheKey, 3600, function () use ($issuerUrl): OidcDiscoveryDocument {
            $discovery = OidcDiscoveryDocument::fromArray($this->fetchDiscoveryDocument($issuerUrl));
            if (rtrim($discovery->issuer, '/') !== $issuerUrl) {
                throw new OidcDiscoveryException('Discovery issuer does not match the configured issuer URL.');
            }

            return $discovery;
        });
    }

    /**
     * The issuer and signing keys location of a token issuer that only signs tokens, such as a CI system.
     *
     * Unlike discover(), it needs no login endpoints: workload issuers like GitHub Actions publish only
     * the issuer, the JWKS URI and the signing algorithms. Team members configure these issuers and
     * unauthenticated requests make Coolify fetch them, so the request is guarded: see guardedJson().
     */
    public function issuerMetadata(string $issuerUrl): OidcIssuerMetadata
    {
        $this->assertHttpsUrl($issuerUrl, new OidcDiscoveryException('Issuer URL must be an absolute HTTPS URL.'));

        $issuerUrl = rtrim($issuerUrl, '/');
        $cacheKey = 'oidc:issuer-metadata:'.hash('sha256', $issuerUrl);

        return Cache::remember($cacheKey, 3600, function () use ($issuerUrl): OidcIssuerMetadata {
            $json = $this->guardedJson($issuerUrl.'/.well-known/openid-configuration', OidcDiscoveryException::class);

            $issuer = $json['issuer'] ?? null;
            $jwksUri = $json['jwks_uri'] ?? null;
            if (! is_string($issuer) || ! is_string($jwksUri) || trim($jwksUri) === '') {
                throw new OidcDiscoveryException('Discovery document is missing the issuer or jwks_uri.');
            }
            if (rtrim($issuer, '/') !== $issuerUrl) {
                throw new OidcDiscoveryException('Discovery issuer does not match the configured issuer URL.');
            }
            $this->assertHttpsUrl($jwksUri, new OidcDiscoveryException('JWKS URI must be an absolute HTTPS URL.'));

            return new OidcIssuerMetadata(issuer: $issuer, jwksUri: $jwksUri);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchDiscoveryDocument(string $issuerUrl): array
    {
        $url = $issuerUrl.'/.well-known/openid-configuration';

        try {
            $response = Http::timeout(15)->acceptJson()->get($url);
        } catch (Throwable $e) {
            throw new OidcDiscoveryException("Failed to fetch discovery document: {$e->getMessage()}", previous: $e);
        }

        if ($response->failed()) {
            throw new OidcDiscoveryException("Discovery endpoint returned HTTP {$response->status()}");
        }

        $json = $response->json();
        if (! is_array($json) || $json === []) {
            throw new OidcDiscoveryException('Discovery endpoint returned invalid JSON.');
        }

        return $json;
    }

    /**
     * The JWKS of a workload issuer, fetched with the same guards as issuerMetadata().
     * Like jwks(), a forced refresh bypasses the cache at most once a minute.
     *
     * @return array<string, mixed>
     */
    public function workloadJwks(string $jwksUri, bool $forceRefresh = false): array
    {
        $this->assertHttpsUrl($jwksUri, new OidcJwksException('JWKS URI must be an absolute HTTPS URL.'));

        $cacheKey = 'oidc:workload-jwks:'.hash('sha256', $jwksUri);
        if ($forceRefresh && Cache::add($cacheKey.':refresh', true, 60)) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, 3600, function () use ($jwksUri): array {
            $json = $this->guardedJson($jwksUri, OidcJwksException::class);
            if (! is_array($json['keys'] ?? null)) {
                throw new OidcJwksException("JWKS endpoint returned an invalid payload without 'keys'.");
            }

            return $json;
        });
    }

    /**
     * GET a JSON document from a workload issuer: public addresses only, pinned to the checked DNS answer,
     * no redirects, a short timeout and a size limit.
     *
     * @param  class-string<OidcDiscoveryException|OidcJwksException>  $exception
     * @return array<string, mixed>
     */
    private function guardedJson(string $url, string $exception): array
    {
        try {
            $options = SafeWebhookUrl::httpClientOptions($url, resolver: $this->resolver);
        } catch (Throwable $e) {
            throw new $exception("Refusing to fetch {$url}: {$e->getMessage()}", previous: $e);
        }

        $limit = self::MAX_WORKLOAD_RESPONSE_BYTES;

        try {
            $response = Http::withOptions([
                ...$options,
                'allow_redirects' => false,
                'stream' => true,
            ])->connectTimeout(5)->timeout(10)->acceptJson()->get($url);
        } catch (Throwable $e) {
            throw new $exception("Failed to fetch {$url}: {$e->getMessage()}", previous: $e);
        }

        // A redirect is refused too: the DNS pin covers only the checked host.
        if (! $response->successful()) {
            throw new $exception("{$url} returned HTTP {$response->status()}");
        }

        if ((int) $response->header('Content-Length') > $limit) {
            throw new $exception("{$url} returned more than {$limit} bytes.");
        }

        $body = $response->toPsrResponse()->getBody();
        $content = '';
        while (! $body->eof() && strlen($content) <= $limit) {
            $content .= $body->read(65536);
        }
        $body->close();
        if (strlen($content) > $limit) {
            throw new $exception("{$url} returned more than {$limit} bytes.");
        }

        $json = json_decode($content, true);
        if (! is_array($json) || $json === []) {
            throw new $exception("{$url} returned invalid JSON.");
        }

        return $json;
    }

    /**
     * Fetch the JWKS for the given URI.
     *
     * When $forceRefresh is true the cached document is bypassed so freshly
     * rotated signing keys become visible immediately. A short cooldown still
     * prevents a flood of upstream requests if many logins miss the same kid.
     *
     * @return array<string, mixed>
     */
    public function jwks(string $jwksUri, bool $forceRefresh = false): array
    {
        $this->assertHttpsUrl($jwksUri, new OidcJwksException('JWKS URI must be an absolute HTTPS URL.'));

        $cacheKey = 'oidc:jwks:'.hash('sha256', $jwksUri);

        if ($forceRefresh) {
            $cooldownKey = $cacheKey.':refresh';
            if (Cache::add($cooldownKey, true, 60)) {
                Cache::forget($cacheKey);
            }
        }

        return Cache::remember($cacheKey, 21600, function () use ($jwksUri): array {
            try {
                $response = Http::timeout(15)->acceptJson()->get($jwksUri);
            } catch (Throwable $e) {
                throw new OidcJwksException("Failed to fetch JWKS: {$e->getMessage()}", previous: $e);
            }

            if ($response->failed()) {
                throw new OidcJwksException("JWKS endpoint returned HTTP {$response->status()}");
            }

            $json = $response->json();
            if (! is_array($json) || ! is_array($json['keys'] ?? null)) {
                throw new OidcJwksException("JWKS endpoint returned an invalid payload without 'keys'.");
            }

            return $json;
        });
    }

    private function assertHttpsUrl(string $url, Throwable $exception): void
    {
        $parts = parse_url($url);

        if (($parts['scheme'] ?? null) !== 'https' || ! is_string($parts['host'] ?? null) || $parts['host'] === '') {
            throw $exception;
        }
    }
}
