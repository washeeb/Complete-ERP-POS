{{-- Overrides spatie/laravel-passkeys' unstyled view with Filament components. Behaviour is the package's. --}}
<div>
    <x-filament::section icon="heroicon-o-finger-print">
        <x-slot name="heading">Passkeys</x-slot>
        <x-slot name="description">
            Sign in with your phone, fingerprint or face instead of a password. A passkey also counts as
            two-factor sign-in. Give each one a name you will recognise, e.g. "Work laptop".
        </x-slot>

        <form id="passkeyForm" wire:submit="validatePasskeyProperties" class="flex flex-wrap items-end gap-3">
            <div class="grow">
                <label for="passkey-name" class="fi-fo-field-label text-sm font-medium">Passkey name</label>
                <x-filament::input.wrapper :valid="! $errors->has('name')">
                    <x-filament::input id="passkey-name" type="text" autocomplete="off" wire:model="name" />
                </x-filament::input.wrapper>
                @error('name')
                    <p class="fi-fo-field-wrp-error-message text-sm text-danger-600">{{ $message }}</p>
                @enderror
            </div>
            <x-filament::button type="submit" icon="heroicon-o-plus">Add passkey</x-filament::button>
        </form>

        @if ($passkeys->isNotEmpty())
            <ul class="mt-6 divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($passkeys as $passkey)
                    <li class="flex items-center justify-between gap-4 py-3">
                        <div>
                            <div class="font-medium">{{ $passkey->name }}</div>
                            <div class="text-sm text-gray-500">
                                Last used: {{ $passkey->last_used_at?->diffForHumans() ?? 'not yet' }}
                            </div>
                        </div>
                        <x-filament::button color="danger" size="sm" outlined
                            wire:click="deletePasskey({{ $passkey->id }})"
                            wire:confirm="Remove the passkey “{{ $passkey->name }}”?">
                            Remove
                        </x-filament::button>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</div>

@include('passkeys::livewire.partials.createScript')
