<?php

namespace App\Auth\Oidc;

/**
 * The parts of an OpenID Connect discovery document that verifying a signed token needs.
 */
final readonly class OidcIssuerMetadata
{
    public function __construct(
        public string $issuer,
        public string $jwksUri,
    ) {}
}
