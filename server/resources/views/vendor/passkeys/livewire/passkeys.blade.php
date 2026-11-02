{{-- Overrides spatie/laravel-passkeys' unstyled view with a Filament card like the profile page's other cards. Layout classes are our own (admin.css, .zx-pk-*): Filament ships only the styles it uses itself, so
     general utility classes (flex, gap-3…) would do nothing here. Behaviour is the package's. --}}
<div>
    <x-filament::section icon="heroicon-o-finger-print" id="zx-passkeys">
        <x-slot name="heading">Passkeys</x-slot>
        <x-slot name="description">
            Sign in with your phone, fingerprint or face instead of a password. A passkey also counts as two-factor
            sign-in.
        </x-slot>

        <form id="passkeyForm" wire:submit="validatePasskeyProperties" class="zx-pk-form">
            <div class="zx-pk-field">
                <label for="passkey-name" class="zx-pk-label">Name for this passkey</label>
                <x-filament::input.wrapper :valid="! $errors->has('name')">
                    <x-filament::input id="passkey-name" type="text" autocomplete="off" wire:model="name" placeholder="e.g. Work laptop" />
                </x-filament::input.wrapper>
                @error('name')
                    <p class="zx-pk-error">{{ $message }}</p>
                @enderror
            </div>
            <x-filament::button type="submit" icon="heroicon-o-plus">Add passkey</x-filament::button>
        </form>

        @if ($passkeys->isNotEmpty())
            <ul class="zx-pk-list">
                @foreach ($passkeys as $passkey)
                    <li>
                        <div>
                            <div class="zx-pk-name">{{ $passkey->name }}</div>
                            <div class="zx-pk-meta">Last used: {{ $passkey->last_used_at?->diffForHumans() ?? 'not yet' }}</div>
                        </div>
                        <x-filament::button color="danger" size="sm" outlined
                            wire:click="deletePasskey({{ $passkey->id }})"
                            wire:confirm="Remove the passkey “{{ $passkey->name }}”?">
                            Remove
                        </x-filament::button>
                    </li>
                @endforeach
            </ul>
        @else
            <p class="zx-pk-meta zx-pk-none">No passkey yet on this account.</p>
        @endif
    </x-filament::section>
</div>

@include('passkeys::livewire.partials.createScript')
