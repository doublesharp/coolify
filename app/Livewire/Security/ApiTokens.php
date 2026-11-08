<?php

namespace App\Livewire\Security;

use App\Livewire\Concerns\ChoosesTokenAccess;
use App\Livewire\Forms\ApiTokenForm;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Component;

class ApiTokens extends Component
{
    use AuthorizesRequests;
    use ChoosesTokenAccess;

    public ApiTokenForm $form;

    /**
     * The token in the edit modal. The browser fills it from ApiTokenForm::valuesOf(), so opening the modal needs no request.
     */
    public ApiTokenForm $editForm;

    public $tokens = [];

    public array $expirationOptions = [
        7 => '7 days',
        30 => '30 days',
        60 => '60 days',
        90 => '90 days',
        365 => '1 year',
    ];

    public function render()
    {
        return view('livewire.security.api-tokens', [
            'scopeLabels' => collect($this->scopeOptions)->reject(fn (array $option) => $option['header'] ?? false)->pluck('label', 'value'),
        ]);
    }

    public function mount()
    {
        $this->mountTokenAccess();
        $this->form->scopeMode = $this->canUseTeamScope ? 'team' : 'selected';
        $this->getTokens();
    }

    private function getTokens()
    {
        $this->tokens = auth()->user()->tokens()
            ->where('team_id', currentTeam()->id)
            // Short-lived tokens of trusted workflows are listed with their workflow, not here.
            ->whereNull('trusted_workflow_id')
            ->latest()
            ->get();
    }

    public function addNewToken()
    {
        try {
            $this->authorize('create', PersonalAccessToken::class);

            $teamId = currentTeam()->id;
            ['permissions' => $permissions, 'scopes' => $storedScopes, 'expiresInDays' => $expiresInDays] = $this->validateTokenForm($this->form, $teamId);
            $expiresAt = filled($expiresInDays) ? now()->addDays((int) $expiresInDays) : null;
            // One transaction, so a token never exists team-wide when saving its scopes fails.
            $token = DB::transaction(function () use ($permissions, $expiresAt, $teamId, $storedScopes) {
                $token = auth()->user()->createToken($this->form->description, $permissions, $expiresAt, $teamId);
                $token->accessToken->forceFill(['scopes' => $storedScopes])->save();

                return $token;
            });
            auditLog('ui.api_token.created', [
                'team_id' => $teamId,
                'api_token_name' => $this->form->description,
                'abilities' => $permissions,
                'scopes' => $storedScopes,
                'expires_at' => $expiresAt?->toIso8601String(),
            ]);
            $this->getTokens();
            $this->form->reset();
            $this->form->scopeMode = $this->canUseTeamScope ? 'team' : 'selected';
            // Do NOT strip the numeric prefix (e.g. "69|...") — Sanctum uses it to index and look up tokens.
            $this->dispatch('api-token-issued', token: $token->plainTextToken);
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    /**
     * Save the edited token. The token value does not change, and the next request with it uses the new access.
     */
    public function updateToken(int $id)
    {
        try {
            $token = $this->findOwnToken($id);
            $this->authorize('update', $token);

            $teamId = currentTeam()->id;
            ['permissions' => $permissions, 'scopes' => $storedScopes, 'expiresInDays' => $expiresInDays] = $this->validateTokenForm($this->editForm, $teamId, editing: true);
            $before = ['abilities' => $token->abilities, 'scopes' => $token->scopes, 'expires_at' => $token->expires_at?->toIso8601String()];

            $token->forceFill([
                'name' => $this->editForm->description,
                'abilities' => $permissions,
                'scopes' => $storedScopes,
            ]);
            if ($expiresInDays !== ApiTokenForm::KEEP_EXPIRY) {
                $token->forceFill([
                    'expires_at' => filled($expiresInDays) ? now()->addDays((int) $expiresInDays) : null,
                    // A new expiry date needs a new expiration warning.
                    'api_token_expiration_warning_sent_at' => null,
                ]);
            }
            $token->save();

            auditLog('ui.api_token.updated', [
                'team_id' => $teamId,
                'api_token_name' => $token->name,
                'before' => $before,
                'after' => ['abilities' => $permissions, 'scopes' => $storedScopes, 'expires_at' => $token->expires_at?->toIso8601String()],
            ]);
            $this->editForm->reset();
            $this->getTokens();
            $this->dispatch('api-token-updated');
            $this->dispatch('success', 'API token updated.');
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    /**
     * Validate the form and check that the user may give the token this access.
     * Never trust the $this->canUse* booleans: check the current user's access in the team again.
     *
     * @return array{permissions: list<string>, scopes: list<array{scope: string, uuid: string}>|null, expiresInDays: int|string|null}
     */
    private function validateTokenForm(ApiTokenForm $form, int $teamId, bool $editing = false): array
    {
        $expiryChoices = array_keys($this->expirationOptions);
        if ($editing) {
            $expiryChoices[] = ApiTokenForm::KEEP_EXPIRY;
        }

        $validated = $form->validate([
            'description' => 'required|min:3|max:255',
            'expiresInDays' => ['nullable', Rule::in($expiryChoices)],
            'permissions' => 'required|array|min:1',
            'permissions.*' => ['required', 'string', Rule::in(['read', 'read:sensitive', 'write', 'write:sensitive', 'deploy', 'delete', 'root'])],
            'scopeMode' => ['required', Rule::in(['team', 'selected'])],
            'selectedScopes' => ['exclude_unless:scopeMode,selected', 'required', 'array', 'min:1'],
            'selectedScopes.*' => ['string', 'regex:/^(project|environment|resource):[A-Za-z0-9_-]+$/'],
        ], [
            'selectedScopes.required' => 'Select at least one project, environment, or resource.',
        ]);
        $access = $this->checkTokenAccess($form, array_values($validated['permissions']), $teamId);

        return [...$access, 'expiresInDays' => $validated['expiresInDays'] ?? null];
    }

    private function findOwnToken(int $id): PersonalAccessToken
    {
        return auth()->user()->tokens()
            ->where('team_id', currentTeam()->id)
            ->whereNull('trusted_workflow_id')
            ->where('id', $id)
            ->firstOrFail();
    }

    /**
     * Pause a token so it stops authenticating requests, or resume a paused token. The token value does not change.
     * The caller sends the wanted state, so a stale page cannot resume a token that another page paused.
     */
    public function setPaused(int $id, bool $paused)
    {
        try {
            $token = $this->findOwnToken($id);
            $this->authorize('update', $token);

            if ($token->isPaused() !== $paused) {
                $token->forceFill(['paused_at' => $paused ? now() : null])->save();
                auditLog($paused ? 'ui.api_token.paused' : 'ui.api_token.resumed', [
                    'team_id' => currentTeam()->id,
                    'api_token_name' => $token->name,
                ]);
            }
            $this->getTokens();
            $this->dispatch('success', $paused ? 'API token paused.' : 'API token resumed.');
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    public function roll(int $id)
    {
        try {
            $token = $this->findOwnToken($id);
            $this->authorize('update', $token);
            $plainTextToken = $token->roll();
            auditLog('ui.api_token.rolled', [
                'team_id' => $token->team_id,
                'api_token_name' => $token->name,
            ]);
            $this->getTokens();
            $this->dispatch('api-token-issued', token: $plainTextToken);
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    public function revoke(int $id)
    {
        try {
            $token = $this->findOwnToken($id);
            $this->authorize('delete', $token);
            $tokenName = $token->name;
            $token->delete();
            auditLog('ui.api_token.revoked', [
                'team_id' => currentTeam()->id,
                'api_token_name' => $tokenName,
            ]);
            $this->getTokens();
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }
}
