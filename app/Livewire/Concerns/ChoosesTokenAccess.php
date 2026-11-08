<?php

namespace App\Livewire\Concerns;

use App\Enums\AccessLevel;
use App\Enums\AccessScope;
use App\Livewire\Forms\ApiTokenForm;
use App\Models\Environment;
use App\Models\Project;
use App\Services\Access\AccessResolver;
use App\Services\Access\Grant;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * The permissions and scope picker of an API token form, shared by API tokens and trusted workflows.
 * A token never gets more access than the user who gives it.
 */
trait ChoosesTokenAccess
{
    #[Locked]
    public bool $canUseRootPermissions = false;

    #[Locked]
    public bool $canUseWritePermissions = false;

    #[Locked]
    public bool $canUseDeployPermissions = false;

    #[Locked]
    public bool $canUseSensitivePermissions = false;

    #[Locked]
    public bool $canUseDeletePermissions = false;

    #[Locked]
    public bool $canUseTeamScope = false;

    protected function mountTokenAccess(): void
    {
        $this->canUseRootPermissions = auth()->user()->can('useRootPermissions', PersonalAccessToken::class);
        $this->canUseWritePermissions = auth()->user()->can('useWritePermissions', PersonalAccessToken::class);
        $this->canUseDeployPermissions = auth()->user()->can('useDeployPermissions', PersonalAccessToken::class);
        $this->canUseSensitivePermissions = auth()->user()->can('useSensitivePermissions', PersonalAccessToken::class);
        $this->canUseDeletePermissions = auth()->user()->can('useDeletePermissions', PersonalAccessToken::class);
        $this->canUseTeamScope = app(AccessResolver::class)->coversWholeTeam(auth()->user(), currentTeam()->id);
    }

    /**
     * Check that the user may give these abilities on the chosen scopes in the team.
     * Never trust the $this->canUse* booleans: check the current user's access in the team again.
     *
     * @param  list<string>  $permissions  validated abilities
     * @return array{permissions: list<string>, scopes: list<array{scope: string, uuid: string}>|null}
     */
    protected function checkTokenAccess(ApiTokenForm $form, array $permissions, int $teamId): array
    {
        $form->selectedScopes = $this->withoutCoveredScopes($form->selectedScopes);
        $scopes = $form->scopeMode === 'selected' ? $this->parseSelectedScopes($form->selectedScopes, $teamId) : null;

        if (in_array('write:sensitive', $permissions, true) && ! auth()->user()->isAdminOfTeam($teamId)) {
            throw new \Exception('You do not have permission to give tokens write:sensitive permissions.');
        }
        $levels = AccessLevel::parseMany($permissions);
        $grants = $scopes === null
            ? [Grant::team($levels)]
            : array_map(fn (Grant $scope) => new Grant($scope->scope, $scope->uuid, $levels), $scopes);
        if (! app(AccessResolver::class)->userHolds(auth()->user(), $teamId, $grants)) {
            throw new \Exception('A token cannot have more access than you have. Choose fewer permissions or narrower projects, environments, or resources.');
        }

        return [
            'permissions' => $permissions,
            'scopes' => $scopes === null ? null : array_map(fn (Grant $scope) => ['scope' => $scope->scope->value, 'uuid' => $scope->uuid], $scopes),
        ];
    }

    /**
     * @param  list<string>  $selected
     * @return list<string>
     */
    private function withoutCoveredScopes(array $selected): array
    {
        $parents = collect($this->scopeOptions)->pluck('parents', 'value');

        return array_values(array_filter(
            array_unique($selected),
            fn (string $value) => array_intersect($parents[$value] ?? [], $selected) === [],
        ));
    }

    /**
     * Projects, environments, and resources of the current team that the user can see, for the scope picker.
     *
     * Each option lists its "parents": the project and environment scopes that already include it.
     * A project or environment is an option only when the member has a grant on all of it, because a token
     * cannot have more access than its owner. Parents that a member sees only through a granted child are not options.
     *
     * @return list<array{value: string, label: string, header?: bool, parents?: list<string>}>
     */
    #[Computed]
    public function scopeOptions(): array
    {
        $resolver = app(AccessResolver::class);
        $memberGrants = $resolver->memberGrants(auth()->user(), currentTeam()->id);
        $memberLayer = $memberGrants === null ? null : $resolver->resolve(currentTeam()->id, $memberGrants);

        $projects = Project::query()->where('team_id', currentTeam()->id)->orderBy('name')->get(['id', 'uuid', 'name']);
        $environments = Environment::query()->whereIn('project_id', $projects->pluck('id'))->get(['id', 'uuid', 'name', 'project_id']);
        $selectableProjects = $projects->filter(fn (Project $project) => $memberLayer?->allows(AccessLevel::Read, $project->id) ?? true);
        $selectableEnvironments = $environments->filter(fn (Environment $environment) => $memberLayer?->allows(AccessLevel::Read, $environment->project_id, $environment->id) ?? true);
        $projectNames = $projects->pluck('name', 'id');
        $projectUuids = $projects->pluck('uuid', 'id');
        $environmentParents = $environments->mapWithKeys(fn (Environment $environment) => [
            $environment->id => ["project:{$projectUuids[$environment->project_id]}", "environment:{$environment->uuid}"],
        ]);
        $environmentPaths = $environments->mapWithKeys(fn (Environment $environment) => [
            $environment->id => $projectNames[$environment->project_id].' / '.$environment->name,
        ]);

        $resources = collect(AccessResolver::RESOURCE_MODELS)
            ->flatMap(fn (string $model) => $model::query()->whereIn('environment_id', $environments->pluck('id'))->get(['uuid', 'name', 'environment_id']))
            ->map(fn ($resource) => [
                'value' => "resource:{$resource->uuid}",
                'label' => $environmentPaths[$resource->environment_id].' / '.$resource->name,
                'parents' => $environmentParents[$resource->environment_id],
            ])
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return [
            ...($selectableProjects->isEmpty() ? [] : [['value' => '__projects', 'label' => 'Projects', 'header' => true]]),
            ...$selectableProjects->map(fn (Project $project) => ['value' => "project:{$project->uuid}", 'label' => $project->name])->values()->all(),
            ...($selectableEnvironments->isEmpty() ? [] : [['value' => '__environments', 'label' => 'Environments', 'header' => true]]),
            ...$selectableEnvironments->map(fn (Environment $environment) => [
                'value' => "environment:{$environment->uuid}",
                'label' => $environmentPaths[$environment->id],
                'parents' => ["project:{$projectUuids[$environment->project_id]}"],
            ])
                ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)->values()->all(),
            ...($resources->isEmpty() ? [] : [['value' => '__resources', 'label' => 'Resources', 'header' => true]]),
            ...$resources->all(),
        ];
    }

    /**
     * @param  list<string>  $selectedScopes
     * @return list<Grant> scopes without levels; a token's levels are its abilities
     */
    private function parseSelectedScopes(array $selectedScopes, int $teamId): array
    {
        $resolver = app(AccessResolver::class);
        $scopes = [];
        foreach (array_unique($selectedScopes) as $selected) {
            [$scope, $uuid] = explode(':', $selected, 2);
            $grant = new Grant(AccessScope::from($scope), $uuid, []);
            $layer = $resolver->resolve($teamId, [new Grant($grant->scope, $uuid, [AccessLevel::Read])]);
            $existsInTeam = match ($grant->scope) {
                AccessScope::Project => $layer->visibleProjectIds() !== [],
                AccessScope::Environment => $layer->fullEnvironmentIds() !== [],
                default => $layer->resourceUuids() !== [],
            };
            if (! $existsInTeam) {
                throw new \Exception('A selected project, environment, or resource does not exist in this team.');
            }
            $scopes[] = $grant;
        }

        return $scopes;
    }
}
