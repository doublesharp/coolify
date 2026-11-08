<?php

use App\Services\TrustedWorkflows\ClaimConditions;

it('matches exact values, globs, and integer and boolean claims', function () {
    $claims = ['repository_id' => '123', 'project_id' => 42, 'ref' => 'refs/tags/v1.2.0', 'protected' => true];

    expect(ClaimConditions::matches(['repository_id' => '123'], $claims))->toBeTrue()
        ->and(ClaimConditions::matches(['project_id' => '42'], $claims))->toBeTrue()
        ->and(ClaimConditions::matches(['ref' => 'refs/tags/v*'], $claims))->toBeTrue()
        ->and(ClaimConditions::matches(['protected' => 'true'], $claims))->toBeTrue()
        ->and(ClaimConditions::matches(['repository_id' => '12'], $claims))->toBeFalse()
        ->and(ClaimConditions::matches(['ref' => 'refs/heads/*'], $claims))->toBeFalse()
        ->and(ClaimConditions::matches(['missing' => '*'], $claims))->toBeFalse()
        ->and(ClaimConditions::matches([], $claims))->toBeFalse();
});

it('treats regex characters in patterns literally', function () {
    expect(ClaimConditions::patternMatches('a.b*', 'a.bc'))->toBeTrue()
        ->and(ClaimConditions::patternMatches('a.b*', 'axbc'))->toBeFalse()
        ->and(ClaimConditions::patternMatches('refs/heads/(main|dev)', 'refs/heads/main'))->toBeFalse();
});

it('does not match array claims', function () {
    expect(ClaimConditions::matches(['aud_list' => '*'], ['aud_list' => ['a', 'b']]))->toBeFalse();
});

it('requires an exact identity condition', function (array $conditions, ?string $problem) {
    $problems = ClaimConditions::problems($conditions);

    $problem === null
        ? expect($problems)->toBe([])
        : expect(implode(' ', $problems))->toContain($problem);
})->with([
    'repository id' => [['repository_id' => '1', 'ref' => 'refs/heads/*'], null],
    'gitlab project id' => [['project_id' => '7'], null],
    'no identity' => [['ref' => 'refs/heads/main'], 'Add an exact'],
    'owner id alone' => [['repository_owner_id' => '999', 'workflow_ref' => '*/.github/workflows/deploy.yml@*'], 'Add an exact'],
    'namespace id alone' => [['namespace_id' => '5'], 'Add an exact'],
    'owner id with repository id' => [['repository_id' => '1', 'repository_owner_id' => '999'], null],
    'glob identity' => [['repository_id' => '1*'], 'must be exact'],
    'empty' => [[], 'at least one condition'],
    'reserved claim' => [['repository_id' => '1', 'iss' => 'x'], 'issuer and audience'],
    'bad claim name' => [['repository_id' => '1', 'bad-name' => 'x'], 'letters, digits'],
    'control character' => [['repository_id' => "1\n"], 'printable'],
]);
