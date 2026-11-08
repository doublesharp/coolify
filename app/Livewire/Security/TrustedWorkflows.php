<?php

namespace App\Livewire\Security;

use App\Livewire\Concerns\ChoosesTokenAccess;
use App\Livewire\Forms\TrustedWorkflowForm;
use App\Models\PersonalAccessToken;
use App\Models\TrustedWorkflow;
use App\Services\TrustedWorkflows\ClaimConditions;
use App\Services\TrustedWorkflows\TrustedWorkflowTokenExchange;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * CI workflows that may exchange their OIDC token for a short-lived API token (POST /api/v1/auth/oidc/token).
 */
class TrustedWorkflows extends Component
{
    use AuthorizesRequests;
    use ChoosesTokenAccess;

    public TrustedWorkflowForm $form;

    /**
     * @var Collection<int, TrustedWorkflow>
     */
    public $workflows;

    /**
     * Admins and owners see the trusted workflows of every member of the team.
     */
    #[Locked]
    public bool $seesTeamWorkflows = false;

    public array $lifetimeOptions = [
        5 => '5 minutes',
        10 => '10 minutes',
        15 => '15 minutes',
        30 => '30 minutes',
        60 => '1 hour',
    ];

    public function mount(): void
    {
        $this->mountTokenAccess();
        $this->resetForm();
        $this->loadWorkflows();
    }

    public function render()
    {
        return view('livewire.security.trusted-workflows', [
            'scopeLabels' => collect($this->scopeOptions)->reject(fn (array $option) => $option['header'] ?? false)->pluck('label', 'value'),
        ]);
    }

    public function addTrustedWorkflow()
    {
        try {
            $this->authorize('create', TrustedWorkflow::class);
            $teamId = currentTeam()->id;

            $validated = $this->form->validate([
                'description' => 'required|min:3|max:255',
                'issuer' => ['required', 'string', 'max:255', 'url:https'],
                'audience' => ['required', 'string', 'max:255'],
                'tokenLifetimeMinutes' => ['required', 'integer', Rule::in(array_keys($this->lifetimeOptions))],
                'repositoryId' => ['nullable', 'regex:/^[0-9]+$/'],
                'repositoryOwnerId' => ['nullable', 'regex:/^[0-9]+$/'],
                'permissions' => 'required|array|min:1',
                'permissions.*' => ['required', 'string', Rule::in(TrustedWorkflowTokenExchange::ALLOWED_ABILITIES)],
                'scopeMode' => ['required', Rule::in(['team', 'selected'])],
                'selectedScopes' => ['exclude_unless:scopeMode,selected', 'required', 'array', 'min:1'],
                'selectedScopes.*' => ['string', 'regex:/^(project|environment|resource):[A-Za-z0-9_-]+$/'],
            ], [
                'description.required' => 'Name the trusted workflow.',
                'selectedScopes.required' => 'Select at least one project, environment, or resource.',
                'repositoryId.regex' => 'The repository ID is a number.',
                'repositoryOwnerId.regex' => 'The owner ID is a number.',
            ]);

            ['conditions' => $conditions, 'problems' => $problems] = $this->form->conditions();
            $problems = [...$problems, ...ClaimConditions::problems($conditions)];
            if ($problems !== []) {
                $this->addError('form.extraConditions', implode(' ', array_unique($problems)));

                return;
            }

            ['permissions' => $permissions, 'scopes' => $scopes] = $this->checkTokenAccess($this->form, array_values($validated['permissions']), $teamId);

            $workflow = TrustedWorkflow::query()->create([
                'team_id' => $teamId,
                'user_id' => auth()->id(),
                'name' => $validated['description'],
                'issuer' => rtrim($validated['issuer'], '/'),
                'audience' => $validated['audience'],
                'conditions' => $conditions,
                'abilities' => $permissions,
                'scopes' => $scopes,
                'token_lifetime_minutes' => (int) $validated['tokenLifetimeMinutes'],
            ]);
            auditLog('ui.trusted_workflow.created', [
                'team_id' => $teamId,
                'trusted_workflow_uuid' => $workflow->uuid,
                'trusted_workflow_name' => $workflow->name,
                'issuer' => $workflow->issuer,
                'audience' => $workflow->audience,
                'conditions' => $conditions,
                'abilities' => $permissions,
                'scopes' => $scopes,
                'token_lifetime_minutes' => $workflow->token_lifetime_minutes,
            ]);

            $this->resetForm();
            $this->loadWorkflows();
            $this->dispatch('trusted-workflow-created');
            $this->dispatch('success', 'Trusted workflow created.');
        } catch (ValidationException $e) {
            // Show validation problems at their fields, not in a toast.
            throw $e;
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    /**
     * Pause or resume. Pausing also revokes the tokens the workflow already issued.
     * The caller sends the wanted state, so a stale page cannot resume a workflow that another page paused.
     */
    public function setPaused(string $uuid, bool $paused)
    {
        try {
            $workflow = $this->findWorkflow($uuid);
            $this->authorize('update', $workflow);

            if ($workflow->isPaused() !== $paused) {
                DB::transaction(function () use ($workflow, $paused): void {
                    $workflow->forceFill(['paused_at' => $paused ? now() : null])->save();
                    if ($paused) {
                        PersonalAccessToken::query()->where('trusted_workflow_id', $workflow->id)->delete();
                    }
                });
                auditLog($paused ? 'ui.trusted_workflow.paused' : 'ui.trusted_workflow.resumed', [
                    'team_id' => $workflow->team_id,
                    'trusted_workflow_uuid' => $workflow->uuid,
                    'trusted_workflow_name' => $workflow->name,
                ]);
            }
            $this->loadWorkflows();
            $this->dispatch('success', $paused ? 'Trusted workflow paused.' : 'Trusted workflow resumed.');
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    /**
     * Delete the trusted workflow and the tokens it issued.
     */
    public function delete(string $uuid)
    {
        try {
            $workflow = $this->findWorkflow($uuid);
            $this->authorize('delete', $workflow);

            DB::transaction(function () use ($workflow): void {
                PersonalAccessToken::query()->where('trusted_workflow_id', $workflow->id)->delete();
                $workflow->delete();
            });
            auditLog('ui.trusted_workflow.deleted', [
                'team_id' => $workflow->team_id,
                'trusted_workflow_uuid' => $workflow->uuid,
                'trusted_workflow_name' => $workflow->name,
            ]);
            $this->loadWorkflows();
            $this->dispatch('success', 'Trusted workflow deleted.');
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    private function findWorkflow(string $uuid): TrustedWorkflow
    {
        return TrustedWorkflow::query()
            ->where('team_id', currentTeam()->id)
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    private function loadWorkflows(): void
    {
        $teamId = currentTeam()->id;
        $this->seesTeamWorkflows = auth()->user()->isAdminOfTeam($teamId);

        $this->workflows = TrustedWorkflow::query()
            ->where('team_id', $teamId)
            ->when(! $this->seesTeamWorkflows, fn ($query) => $query->where('user_id', auth()->id()))
            ->with('owner:id,name,email')
            ->latest()
            ->get();
    }

    private function resetForm(): void
    {
        $this->form->reset();
        $this->resetErrorBag();
        $this->form->scopeMode = $this->canUseTeamScope ? 'team' : 'selected';
        $this->form->permissions = ['read', 'deploy'];
        if (! $this->canUseDeployPermissions) {
            $this->form->permissions = ['read'];
        }
        $this->form->audience = rtrim(base_url(), '/');
    }
}
