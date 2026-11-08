<?php

namespace App\Policies;

use App\Models\TrustedWorkflow;
use App\Models\User;

/**
 * Members manage their own trusted workflows, like their own API tokens. Team admins and owners also see,
 * pause, and delete the trusted workflows of other members, because those issue tokens for the team.
 */
class TrustedWorkflowPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * The access of a new trusted workflow is checked against the access of the user when it is saved.
     */
    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, TrustedWorkflow $trustedWorkflow): bool
    {
        return $this->isOwnerInTeam($user, $trustedWorkflow) || $user->isAdminOfTeam((int) $trustedWorkflow->team_id);
    }

    /**
     * Pause or resume.
     */
    public function update(User $user, TrustedWorkflow $trustedWorkflow): bool
    {
        return $this->view($user, $trustedWorkflow);
    }

    public function delete(User $user, TrustedWorkflow $trustedWorkflow): bool
    {
        return $this->view($user, $trustedWorkflow);
    }

    private function isOwnerInTeam(User $user, TrustedWorkflow $trustedWorkflow): bool
    {
        return $user->id === (int) $trustedWorkflow->user_id
            && $user->teams()->where('teams.id', $trustedWorkflow->team_id)->exists();
    }
}
