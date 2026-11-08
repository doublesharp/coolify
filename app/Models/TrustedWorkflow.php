<?php

namespace App\Models;

/**
 * A CI workflow that may exchange its OIDC token for a short-lived API token.
 *
 * The rule matches the token issuer, audience and claims. The minted token belongs to the owner,
 * has the rule's abilities and scopes, and never has more access than the owner has in the team.
 */
class TrustedWorkflow extends BaseModel
{
    public const DEFAULT_ISSUER = 'https://token.actions.githubusercontent.com';

    public const DEFAULT_TOKEN_LIFETIME_MINUTES = 10;

    public const MAX_TOKEN_LIFETIME_MINUTES = 60;

    protected $fillable = [
        'team_id',
        'user_id',
        'name',
        'issuer',
        'audience',
        'conditions',
        'abilities',
        'scopes',
        'token_lifetime_minutes',
    ];

    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'abilities' => 'array',
            'scopes' => 'array',
            'token_lifetime_minutes' => 'integer',
            'paused_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function tokens()
    {
        return $this->hasMany(PersonalAccessToken::class);
    }

    public function isPaused(): bool
    {
        return $this->paused_at !== null;
    }
}
