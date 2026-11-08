<?php

namespace App\Services\TrustedWorkflows;

/**
 * The claims a CI token must have to match a trusted workflow.
 *
 * A condition is a claim name and a pattern. A pattern without `*` matches the claim value exactly.
 * `*` matches any characters, for claims such as `ref` (`refs/tags/v*`) or `workflow_ref`.
 */
final class ClaimConditions
{
    /**
     * Claims that name one repository or project by an ID that never changes, also when it is renamed or
     * transferred (GitHub `repository_id`, GitLab `project_id`). Every rule needs one of them with an exact
     * value, so neither a recreated repository with the same name nor another repository of the same owner
     * can match. Owner and namespace IDs cover many repositories, so they are only extra conditions.
     */
    public const IDENTITY_CLAIMS = ['repository_id', 'project_id'];

    /**
     * Claims that the exchange checks itself.
     */
    public const RESERVED_CLAIMS = ['iss', 'aud', 'exp', 'nbf', 'iat', 'jti'];

    public const MAX_CONDITIONS = 20;

    /**
     * @param  array<mixed>  $conditions
     * @return list<string> problems, empty when the conditions are valid
     */
    public static function problems(array $conditions): array
    {
        $problems = [];
        if ($conditions === []) {
            return ['Add at least one condition.'];
        }
        if (count($conditions) > self::MAX_CONDITIONS) {
            $problems[] = 'Use at most '.self::MAX_CONDITIONS.' conditions.';
        }

        $hasIdentity = false;
        foreach ($conditions as $claim => $pattern) {
            if (! is_string($claim) || preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $claim) !== 1) {
                $problems[] = 'Claim names may contain only letters, digits, and underscores.';

                continue;
            }
            if (in_array($claim, self::RESERVED_CLAIMS, true)) {
                $problems[] = "The {$claim} claim is checked by the issuer and audience settings.";

                continue;
            }
            if (! is_string($pattern) || $pattern === '' || mb_strlen($pattern) > 500 || preg_match('/[\x00-\x1F\x7F]/', $pattern) === 1) {
                $problems[] = "The value for {$claim} must be 1 to 500 printable characters.";

                continue;
            }
            if (in_array($claim, self::IDENTITY_CLAIMS, true)) {
                if (str_contains($pattern, '*')) {
                    $problems[] = "The {$claim} value must be exact, without *.";
                } else {
                    $hasIdentity = true;
                }
            }
        }

        if (! $hasIdentity) {
            $problems[] = 'Add an exact repository_id (GitHub) or project_id (GitLab) condition. It names one repository and does not change when the repository is renamed or transferred.';
        }

        return array_values(array_unique($problems));
    }

    /**
     * @param  array<string, string>  $conditions
     * @param  array<string, mixed>  $claims
     */
    public static function matches(array $conditions, array $claims): bool
    {
        foreach ($conditions as $claim => $pattern) {
            $value = self::claimValue($claims[$claim] ?? null);
            if ($value === null || ! self::patternMatches((string) $pattern, $value)) {
                return false;
            }
        }

        return $conditions !== [];
    }

    public static function patternMatches(string $pattern, string $value): bool
    {
        if (! str_contains($pattern, '*')) {
            return hash_equals($pattern, $value);
        }

        $regex = '/^'.str_replace('\*', '.*', preg_quote($pattern, '/')).'$/s';

        return preg_match($regex, $value) === 1;
    }

    private static function claimValue(mixed $value): ?string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value) => (string) $value,
            is_bool($value) => $value ? 'true' : 'false',
            default => null,
        };
    }
}
