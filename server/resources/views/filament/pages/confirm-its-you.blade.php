<x-filament-panels::page>
    <div style="max-width:32rem">
        @if ($done)
            <x-filament::section>
                <p><strong>Confirmed.</strong> For the next {{ \App\Security\Reconfirm::MINUTES }} minutes you can make payments and changes that ask for it.</p>
                <p style="margin-top:.5rem">Go back to the other tab and press the button again. You can close this tab.</p>
            </x-filament::section>
        @else
            <x-filament::section>
                <p>Paying out a larger amount, writing off what a customer owes, or changing bank or MoMo details asks you to prove it is really you. <strong>Why:</strong> a computer left signed in, or a stolen sign-in, must not be able to send money away.</p>
            </x-filament::section>

            <form wire:submit="confirm" style="margin-top:1rem;display:grid;gap:.75rem">
                @if ($this->hasApp() || $sentTo)
                    <label>
                        <span style="display:block;font-weight:600;margin-bottom:.25rem">{{ $sentTo ? 'The code we sent' : 'The 6-digit code from your authenticator app' }}</span>
                        <x-filament::input.wrapper><x-filament::input type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="10" wire:model="code" autofocus /></x-filament::input.wrapper>
                    </label>
                @elseif (! $this->hasPasskey())
                    <label>
                        <span style="display:block;font-weight:600;margin-bottom:.25rem">Your password</span>
                        <x-filament::input.wrapper><x-filament::input type="password" autocomplete="current-password" wire:model="password" autofocus /></x-filament::input.wrapper>
                    </label>
                @endif
                @if ($this->hasApp() || $sentTo || ! $this->hasPasskey())
                    <x-filament::button type="submit">Confirm</x-filament::button>
                @endif
            </form>

            <div style="margin-top:1rem;display:grid;gap:.5rem">
                @if ($this->hasPasskey())
                    <x-authenticate-passkey><x-filament::button color="gray" icon="heroicon-o-finger-print" class="w-full">Confirm with a passkey</x-filament::button></x-authenticate-passkey>
                @endif
                @if ($sentTo)
                    <p style="font-size:.875rem;opacity:.8">Code sent to {{ $sentTo }}.</p>
                @else
                    <x-filament::button color="gray" wire:click="sendCode">Send me a code by WhatsApp or e-mail instead</x-filament::button>
                @endif
            </div>
        @endif
    </div>
</x-filament-panels::page>
