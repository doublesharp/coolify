<div>
    <x-application.settings-section title="Trusted workflows" flush
        description="A CI job (for example GitHub Actions with id-token: write) sends its OIDC token to POST /api/v1/auth/oidc/token and gets a short-lived API token with the access below. No API token is stored in the CI system.">
        @can('create', App\Models\TrustedWorkflow::class)
            <x-slot:actions>
                <button type="button" class="button button-highlighted" @click="$dispatch('open-trusted-workflow-create')">
                    <x-reicon name="plus" class="size-3.5" />
                    New trusted workflow
                </button>
            </x-slot:actions>
        @endcan

        @if ($workflows->isEmpty())
            <div class="p-4">
                <x-empty title="No trusted workflows"
                    description="Add one so a CI workflow can deploy without a stored API token." icon-name="keys"
                    size="sm" />
            </div>
        @else
            <div class="data-table">
                <div class="data-table-header api-tokens-table-grid">
                    <span>Name</span>
                    <span>Permissions</span>
                    <span>Last used</span>
                    <span>Token lifetime</span>
                    <span>Owner</span>
                    <span class="text-right">Actions</span>
                </div>
                @foreach ($workflows as $workflow)
                    <div wire:key="trusted-workflow-{{ $workflow->uuid }}"
                        class="data-table-row api-tokens-table-grid border-b border-neutral-200 last:border-b-0 dark:border-white/[0.07]">
                        <div class="min-w-0">
                            <div class="flex min-w-0 items-center gap-1.5">
                                <span class="truncate text-[12px] font-medium text-black dark:text-fg">{{ $workflow->name }}</span>
                                @if ($workflow->isPaused())
                                    <x-status-badge label="Paused" type="warning" size="sm" />
                                @endif
                            </div>
                            <div class="truncate font-mono text-[11px] text-neutral-500 dark:text-fg-dim"
                                title="{{ $workflow->issuer }} · aud {{ $workflow->audience }}">
                                {{ collect($workflow->conditions)->map(fn ($value, $claim) => $claim.'='.$value)->join(' · ') }}
                            </div>
                            <div class="truncate text-[11px] text-neutral-500 dark:text-fg-dim">
                                @if ($workflow->scopes === null)
                                    All resources in the team
                                @else
                                    {{ collect($workflow->scopes)->map(fn ($scope) => $scopeLabels[($scope['scope'] ?? '').':'.($scope['uuid'] ?? '')] ?? 'Unavailable '.($scope['scope'] ?? 'scope'))->join(', ') }}
                                @endif
                                · <span class="font-mono">{{ $workflow->uuid }}</span>
                            </div>
                        </div>
                        <div class="flex min-w-0 flex-wrap items-center gap-1.5">
                            @foreach ($workflow->abilities ?? [] as $ability)
                                <span @class([
                                    'inline-flex min-h-5 items-center rounded-md border px-2 py-0.5 text-[10px] font-medium',
                                    'border-error/20 bg-error/10 text-error' => in_array($ability, ['root', 'delete']),
                                    'border-warning/20 bg-warning/10 text-warning' => in_array($ability, ['write', 'write:sensitive']),
                                    'border-coollabs/20 bg-coollabs/10 text-coollabs dark:border-brand-light/20 dark:bg-brand/15 dark:text-brand-light' => $ability === 'deploy',
                                    'border-neutral-200 bg-neutral-100 text-neutral-600 dark:border-white/[0.08] dark:bg-white/[0.06] dark:text-fg-dim' => in_array($ability, ['read', 'read:sensitive']),
                                ])>{{ $ability }}</span>
                            @endforeach
                        </div>
                        <div class="text-[11px] text-neutral-500 dark:text-fg-dim">
                            {{ $workflow->last_used_at?->diffForHumans() ?? 'Never' }}
                        </div>
                        <div class="text-[11px] text-neutral-500 dark:text-fg-dim">
                            {{ $workflow->token_lifetime_minutes }} min
                        </div>
                        <div class="truncate text-[11px] text-neutral-500 dark:text-fg-dim">
                            {{ $workflow->owner?->name ?? 'Unknown' }}
                        </div>
                        <div class="flex items-center justify-end gap-1">
                            @can('update', $workflow)
                                <x-table.row-actions label="Trusted workflow actions">
                                    <x-table.row-action
                                        wire:click="setPaused('{{ $workflow->uuid }}', {{ $workflow->isPaused() ? 'false' : 'true' }})">
                                        {{ $workflow->isPaused() ? 'Resume' : 'Pause' }}
                                    </x-table.row-action>
                                    <x-slot:danger>
                                        <x-modal-confirmation title="Delete trusted workflow?" buttonFullWidth
                                            submitAction="delete('{{ $workflow->uuid }}')" :actions="[
                                                'CI jobs can no longer exchange tokens with this trusted workflow.',
                                                'The tokens it already issued are revoked.',
                                            ]"
                                            confirmationText="{{ $workflow->name }}"
                                            confirmationLabel="Enter the trusted workflow name to confirm"
                                            shortConfirmationLabel="Name" :confirmWithPassword="false"
                                            step2ButtonText="Delete trusted workflow">
                                            <x-slot:trigger>
                                                <x-table.row-action>Delete</x-table.row-action>
                                            </x-slot:trigger>
                                        </x-modal-confirmation>
                                    </x-slot:danger>
                                </x-table.row-actions>
                            @endcan
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-application.settings-section>

    @can('create', App\Models\TrustedWorkflow::class)
        <x-modal-input title="New trusted workflow" :wireIgnore="false" :closeOutside="false"
            @open-trusted-workflow-create.window="modalOpen = true" @trusted-workflow-created.window="modalOpen = false">
            <x-slot:content><span></span></x-slot:content>

            <form wire:submit="addTrustedWorkflow" id="trusted-workflow-create-form" class="flex flex-col gap-4 lg:w-3xl">
                <div class="grid gap-4 lg:grid-cols-2">
                    <x-forms.input required id="form.description" label="Name" placeholder="Deploy from main" />
                    <x-forms.listbox id="form.tokenLifetimeMinutes" label="Token lifetime" :options="collect($lifetimeOptions)
                        ->map(fn ($label, $minutes) => ['value' => (string) $minutes, 'label' => $label])
                        ->values()
                        ->all()" />
                    <x-forms.input required id="form.issuer" label="Issuer"
                        helper="The OIDC issuer of the CI system. GitHub Actions: https://token.actions.githubusercontent.com" />
                    <x-forms.input required id="form.audience" label="Audience"
                        helper="The audience the CI job requests its token for. Use a value only this Coolify instance accepts, such as its URL." />
                </div>

                <div class="border-t border-neutral-200 pt-3 dark:border-white/[0.08]">
                    <div class="mb-2 flex items-center gap-2">
                        <h4 class="text-[12px] font-semibold text-black dark:text-fg">Conditions</h4>
                        <x-helper helper="Every condition must match a claim of the token. A value with * matches any characters. The repository ID does not change when a repository is renamed or transferred. Find it with: gh api repos/OWNER/REPO --jq .id" />
                    </div>
                    <div class="grid gap-4 lg:grid-cols-2">
                        <x-forms.input id="form.repositoryId" label="Repository ID" placeholder="123456789" />
                        <x-forms.input id="form.repositoryOwnerId" label="Owner ID (optional)" placeholder="987654" />
                        <x-forms.input id="form.ref" label="Branch or tag" placeholder="refs/heads/main or refs/tags/v*" />
                        <x-forms.input id="form.workflowPath" label="Workflow file (optional)"
                            placeholder=".github/workflows/deploy.yml" />
                        <x-forms.input id="form.eventName" label="Event (optional)" placeholder="push" />
                    </div>
                    <div class="mt-4">
                        <x-forms.textarea id="form.extraConditions" label="More conditions (optional)" monospace
                            placeholder="environment=production&#10;runner_environment=github-hosted"
                            helper="One claim=value per line. Use this for other CI systems, for example project_id=42 for GitLab." />
                    </div>
                </div>

                <div>
                    @include('livewire.security.partials.api-token-fields', [
                        'form' => 'form',
                        'expiryOptions' => [],
                        'inModal' => true,
                        'showNameAndExpiry' => false,
                    ])
                </div>
            </form>

            <x-slot:footer>
                <x-forms.button @click="modalOpen = false">Cancel</x-forms.button>
                <x-forms.button isHighlighted type="submit" form="trusted-workflow-create-form">Create trusted
                    workflow</x-forms.button>
            </x-slot:footer>
        </x-modal-input>
    @endcan
</div>
