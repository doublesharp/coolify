<div class="mt-8" wire:poll.10s>
    <h2>Automatic sleep</h2>
    <p class="subtitle">Keep a staging resource available after deployments, then stop it when idle.</p>
    <form wire:submit="save" class="flex flex-col gap-4">
        <x-forms.checkbox id="enabled" label="Enable automatic sleep" />
        <x-forms.input id="idleMinutes" type="number" min="1" max="10080" label="Sleep after (minutes)" required />
        <x-forms.input id="dependencies" label="Dedicated dependency UUIDs (comma separated, start order)" />
        @can('viewSensitive', $resource)
            @foreach (['before_sleep', 'after_sleep', 'before_wake', 'after_wake'] as $event)
                <x-forms.input id="webhooks.{{ $event }}" type="password" label="{{ str($event)->replace('_', ' ')->ucfirst() }} webhook" />
            @endforeach
            @if ($policy)
                <x-forms.input readonly value="{{ $policy->webhook_secret }}" type="password" label="Webhook signing secret" />
            @endif
        @endcan
        <div class="flex gap-2">
            @can('update', $resource)
                <x-forms.button type="submit">Save</x-forms.button>
            @endcan
            @if ($policy?->enabled)
                @can('deploy', $resource)
                    <x-forms.button wire:click="transition('sleep')">Sleep now</x-forms.button>
                    <x-forms.button wire:click="transition('wake')">Wake</x-forms.button>
                @endcan
            @endif
        </div>
    </form>
    @if ($policy)
        <p class="mt-3">State: {{ $policy->state }}</p>
        @if ($policy->error)<p class="text-warning">{{ $policy->error }}</p>@endif
    @endif
</div>
