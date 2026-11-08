<?php

namespace App\Services\TrustedWorkflows;

use App\Auth\Oidc\Exceptions\OidcDiscoveryException;
use App\Auth\Oidc\Exceptions\OidcException;
use App\Auth\Oidc\Exceptions\OidcJwksException;
use App\Auth\Oidc\Exceptions\OidcSigningKeyNotFoundException;
use App\Auth\Oidc\OidcDiscoveryService;
use App\Auth\Oidc\WorkloadTokenValidator;
use App\Enums\AccessLevel;
use App\Models\PersonalAccessToken;
use App\Models\TrustedWorkflow;
use App\Services\Access\AccessResolver;
use App\Services\Access\Grant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Exchanges a CI job's OIDC token for a short-lived, scoped API token of the matching trusted workflow.
 */
class TrustedWorkflowTokenExchange
{
    public const ALLOWED_ABILITIES = ['read', 'read:sensitive', 'write', 'write:sensitive', 'deploy', 'delete', 'root'];

    /**
     * Claims copied into the audit log, so an event shows which run used the token.
     */
    private const AUDITED_CLAIMS = [
        'sub', 'jti', 'repository', 'repository_id', 'repository_owner', 'repository_owner_id', 'ref', 'ref_type', 'sha',
        'event_name', 'workflow_ref', 'job_workflow_ref', 'environment', 'run_id', 'run_attempt', 'actor', 'runner_environment',
        'project_id', 'project_path', 'namespace_id', 'pipeline_id', 'job_id',
    ];

    public function __construct(
        private readonly OidcDiscoveryService $discovery,
        private readonly WorkloadTokenValidator $validator,
        private readonly AccessResolver $access,
    ) {}

    /**
     * @return array{token: string, expires_at: string, abilities: list<string>, scopes: list<array{scope: string, uuid: string}>|null, trusted_workflow: TrustedWorkflow, claims: array<string, mixed>, token_id: int}
     *
     * @throws TrustedWorkflowExchangeDenied
     */
    public function exchange(string $jwt, ?string $ruleUuid = null): array
    {
        $issuer = $this->validator->unverifiedIssuer($jwt);
        if ($issuer === null) {
            throw new TrustedWorkflowExchangeDenied('malformed_token');
        }

        // Only issuers that a rule names are ever contacted, so a caller cannot make Coolify fetch other URLs.
        // Nothing else is read before the signature is verified.
        if (! TrustedWorkflow::query()->where('issuer', $issuer)->exists()) {
            throw new TrustedWorkflowExchangeDenied('no_rule_for_issuer', context: ['issuer' => $issuer]);
        }

        $claims = $this->verify($jwt, $issuer);
        $audiences = WorkloadTokenValidator::audiences($claims);
        $context = ['issuer' => $issuer, 'claims' => self::auditedClaims($claims)];

        $matching = TrustedWorkflow::query()
            ->where('issuer', $claims['iss'])
            ->whereIn('audience', $audiences)
            ->when($ruleUuid !== null, fn ($query) => $query->where('uuid', $ruleUuid))
            ->get()
            ->filter(fn (TrustedWorkflow $rule) => ClaimConditions::matches($rule->conditions ?? [], $claims));
        if ($matching->isEmpty()) {
            throw new TrustedWorkflowExchangeDenied('no_matching_rule', context: $context);
        }

        $active = $matching->reject(fn (TrustedWorkflow $rule) => $rule->isPaused());
        if ($active->isEmpty()) {
            $rule = $matching->first();
            throw new TrustedWorkflowExchangeDenied('rule_paused', context: [...$context, ...self::ruleContext($rule)]);
        }
        if ($active->count() > 1) {
            throw new TrustedWorkflowExchangeDenied('ambiguous_rules', 409, 'Matching rules: '.$active->pluck('uuid')->join(', '), $context);
        }

        /** @var TrustedWorkflow $rule */
        $rule = $active->first();
        $context = [...$context, ...self::ruleContext($rule)];

        if (! $this->claimOnce($issuer, (string) $claims['jti'], (int) $claims['exp'])) {
            throw new TrustedWorkflowExchangeDenied('token_replayed', context: $context);
        }

        if (! $this->ownerCanGrant($rule)) {
            throw new TrustedWorkflowExchangeDenied('owner_access', context: $context);
        }

        return $this->mint($rule, $claims, $context);
    }

    /**
     * True when the owner of the rule is still in its team and holds every ability on every scope of the rule.
     * The API checks the same on each request with the minted token.
     */
    public function ownerCanGrant(TrustedWorkflow $rule): bool
    {
        $owner = $rule->owner;
        $teamId = (int) $rule->team_id;
        if ($owner === null || ! $owner->teams()->where('teams.id', $teamId)->exists()) {
            return false;
        }

        $abilities = array_values(array_unique($rule->abilities ?? []));
        if ($abilities === [] || array_diff($abilities, self::ALLOWED_ABILITIES) !== []) {
            return false;
        }

        $owner->unsetRelation('teams');
        if ($owner->isAdminOfTeam($teamId)) {
            return true;
        }

        // Abilities outside the access levels (write:sensitive) are for owners and admins only.
        $levels = AccessLevel::parseMany($abilities);
        if (count($levels) !== count($abilities)) {
            return false;
        }

        $grants = $rule->scopes === null
            ? [Grant::team($levels)]
            : array_map(fn (Grant $scope) => new Grant($scope->scope, $scope->uuid, $levels), Grant::listFrom($rule->scopes));

        return $grants !== [] && $this->access->userHolds($owner, $teamId, $grants);
    }

    /**
     * @return array<string, mixed>
     */
    private function verify(string $jwt, string $issuer): array
    {
        try {
            $metadata = $this->discovery->issuerMetadata($issuer);
            try {
                return $this->validator->validate($jwt, $metadata->issuer, $this->discovery->workloadJwks($metadata->jwksUri));
            } catch (OidcSigningKeyNotFoundException) {
                // The issuer may have rotated its keys since the JWKS was cached.
                return $this->validator->validate($jwt, $metadata->issuer, $this->discovery->workloadJwks($metadata->jwksUri, forceRefresh: true));
            }
        } catch (OidcDiscoveryException|OidcJwksException $e) {
            throw new TrustedWorkflowExchangeDenied('issuer_unavailable', 503, $e->getMessage(), ['issuer' => $issuer]);
        } catch (OidcException $e) {
            throw new TrustedWorkflowExchangeDenied('invalid_token', detail: $e->getMessage(), context: ['issuer' => $issuer]);
        } catch (Throwable $e) {
            throw new TrustedWorkflowExchangeDenied('issuer_unavailable', 503, $e::class, ['issuer' => $issuer]);
        }
    }

    /**
     * Each CI token can be exchanged once, until it expires.
     */
    private function claimOnce(string $issuer, string $jti, int $expiresAt): bool
    {
        $seconds = max(60, min($expiresAt - time() + 60, WorkloadTokenValidator::MAX_LIFETIME_SECONDS + 120));

        return Cache::add('trusted-workflow:jti:'.hash('sha256', $issuer.'|'.$jti), true, $seconds);
    }

    /**
     * @param  array<string, mixed>  $claims
     * @param  array<string, mixed>  $context  audit context of a denial
     * @return array{token: string, expires_at: string, abilities: list<string>, scopes: list<array{scope: string, uuid: string}>|null, trusted_workflow: TrustedWorkflow, claims: array<string, mixed>, token_id: int}
     */
    private function mint(TrustedWorkflow $rule, array $claims, array $context): array
    {
        $minted = DB::transaction(function () use ($rule, $context) {
            // Pausing and deleting lock the same row, so a token is never issued after a pause or a delete
            // has revoked the rule's tokens. Mint from the locked row, not from the row read before.
            $locked = TrustedWorkflow::query()->whereKey($rule->getKey())->lockForUpdate()->first();
            if ($locked === null || $locked->isPaused()) {
                throw new TrustedWorkflowExchangeDenied($locked === null ? 'rule_deleted' : 'rule_paused', context: $context);
            }
            if (! $this->ownerCanGrant($locked)) {
                throw new TrustedWorkflowExchangeDenied('owner_access', context: $context);
            }

            // Tokens of this rule that expired are removed here too, not only by the hourly prune.
            PersonalAccessToken::query()
                ->where('trusted_workflow_id', $locked->id)
                ->where('expires_at', '<', now())
                ->delete();

            $lifetime = max(1, min((int) $locked->token_lifetime_minutes, TrustedWorkflow::MAX_TOKEN_LIFETIME_MINUTES));
            $expiresAt = now()->addMinutes($lifetime);
            $abilities = array_values($locked->abilities ?? []);
            $scopes = $locked->scopes === null
                ? null
                : array_map(fn (Grant $scope) => ['scope' => $scope->scope->value, 'uuid' => $scope->uuid], Grant::listFrom($locked->scopes));

            $newToken = $locked->owner->createToken(mb_substr($locked->name.' (trusted workflow)', 0, 255), $abilities, $expiresAt, $locked->team_id);
            $newToken->accessToken->forceFill(['scopes' => $scopes, 'trusted_workflow_id' => $locked->id])->save();
            $locked->forceFill(['last_used_at' => now()])->saveQuietly();

            return [$newToken, $locked, $abilities, $scopes, $expiresAt];
        });
        [$newToken, $rule, $abilities, $scopes, $expiresAt] = $minted;

        return [
            'token' => $newToken->plainTextToken,
            'expires_at' => $expiresAt->toIso8601String(),
            'abilities' => $abilities,
            'scopes' => $scopes,
            'trusted_workflow' => $rule,
            'claims' => self::auditedClaims($claims),
            'token_id' => $newToken->accessToken->getKey(),
        ];
    }

    /**
     * @param  array<string, mixed>  $claims
     * @return array<string, mixed>
     */
    public static function auditedClaims(array $claims): array
    {
        return Collection::make($claims)
            ->only(self::AUDITED_CLAIMS)
            ->filter(fn ($value) => is_scalar($value))
            ->map(fn ($value) => is_string($value) ? mb_substr($value, 0, 255) : $value)
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private static function ruleContext(TrustedWorkflow $rule): array
    {
        return [
            'team_id' => $rule->team_id,
            'trusted_workflow_uuid' => $rule->uuid,
            'trusted_workflow_name' => $rule->name,
        ];
    }
}
