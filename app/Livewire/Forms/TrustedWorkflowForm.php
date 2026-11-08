<?php

namespace App\Livewire\Forms;

use App\Models\TrustedWorkflow;

/**
 * The fields of a new trusted workflow. The permissions and access fields are those of an API token,
 * because a trusted workflow issues tokens with that access.
 */
class TrustedWorkflowForm extends ApiTokenForm
{
    public string $issuer = TrustedWorkflow::DEFAULT_ISSUER;

    public string $audience = '';

    /**
     * GitHub Actions claims. Other issuers use $extraConditions.
     */
    public string $repositoryId = '';

    public string $repositoryOwnerId = '';

    public string $ref = 'refs/heads/main';

    public string $workflowPath = '';

    public string $eventName = '';

    /**
     * More conditions, one `claim=value` per line. `*` in a value matches any characters.
     */
    public string $extraConditions = '';

    public int|string $tokenLifetimeMinutes = TrustedWorkflow::DEFAULT_TOKEN_LIFETIME_MINUTES;

    /**
     * The conditions of the form fields. Problems are returned, not thrown, so they show at the field.
     *
     * @return array{conditions: array<string, string>, problems: list<string>}
     */
    public function conditions(): array
    {
        $conditions = [];
        $problems = [];
        $add = function (string $claim, string $value) use (&$conditions, &$problems): void {
            $value = trim($value);
            if ($value === '') {
                return;
            }
            if (array_key_exists($claim, $conditions)) {
                $problems[] = "The {$claim} condition is set twice.";

                return;
            }
            $conditions[$claim] = $value;
        };

        $add('repository_id', $this->repositoryId);
        $add('repository_owner_id', $this->repositoryOwnerId);
        $add('ref', $this->ref);
        $path = ltrim(trim($this->workflowPath), '/');
        if ($path !== '') {
            // workflow_ref is "owner/repo/<path>@<ref>". The repository ID pins the repository, so the
            // owner and name may change (a rename or transfer) without breaking the rule.
            $add('workflow_ref', "*/{$path}@*");
        }
        $add('event_name', $this->eventName);

        foreach (preg_split('/\R/', $this->extraConditions) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (! str_contains($line, '=')) {
                $problems[] = "Write each extra condition as claim=value: \"{$line}\".";

                continue;
            }
            [$claim, $value] = array_map('trim', explode('=', $line, 2));
            $add($claim, $value);
        }

        return ['conditions' => $conditions, 'problems' => $problems];
    }
}
