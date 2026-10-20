<div>
    <x-filament::modal id="report-problem" width="lg">
        <x-slot name="trigger">
            <x-filament::icon-button icon="heroicon-o-lifebuoy" color="gray" label="Report a problem" tooltip="Report a problem" />
        </x-slot>
        <x-slot name="heading">Report a problem</x-slot>
        <x-slot name="description">What were you doing, and what happened? Support gets this with the page you are on.</x-slot>
        {{-- A picture of the screen helps most: Win+Shift+S, then Ctrl+V here (or attach a file). --}}
        <form wire:submit="send" x-data
            x-init="$wire.page = window.location.pathname + window.location.search; $wire.browser = navigator.userAgent + ' · ' + window.innerWidth + '×' + window.innerHeight"
            x-on:paste="const f = [...($event.clipboardData?.files ?? [])].find((x) => x.type.startsWith('image/')); if (f) { $event.preventDefault(); $wire.upload('screenshot', f) }">
            <textarea wire:model="message" rows="5" required minlength="5" maxlength="5000" aria-label="What happened"
                placeholder="e.g. I pressed Post on the stock count and it said “Something went wrong”."
                style="width:100%;border-radius:8px;padding:8px;border:1px solid color-mix(in srgb, currentColor 20%, transparent);background:transparent"></textarea>
            @error('message')<p style="color:rgb(220 38 38);font-size:.85rem">{{ $message }}</p>@enderror
            <label style="display:block;margin-top:10px;font-size:.875rem">
                Picture of the screen (optional): press <strong>Win+Shift+S</strong>, then <strong>Ctrl+V</strong> here — or choose a file
                <input type="file" accept="image/*" wire:model="screenshot" style="display:block;margin-top:4px">
            </label>
            <div wire:loading wire:target="screenshot" style="font-size:.8rem">Adding the picture…</div>
            @if ($screenshot)
                <p style="font-size:.8rem;margin-top:4px">Picture added ✓</p>
            @endif
            @error('screenshot')<p style="color:rgb(220 38 38);font-size:.85rem">{{ $message }}</p>@enderror
            @if ($this->canGrant())
                <label style="display:flex;gap:8px;align-items:flex-start;margin-top:10px;font-size:.875rem">
                    <input type="checkbox" wire:model="letSupportConnect" style="margin-top:3px">
                    <span>Let SyncLytics support connect to the tills and computers until this problem is solved (free; you can withdraw it under System → Support access)</span>
                </label>
            @endif
            <div style="margin-top:10px;display:flex;justify-content:flex-end">
                <x-filament::button type="submit" icon="heroicon-o-paper-airplane" wire:loading.attr="disabled">Send</x-filament::button>
            </div>
        </form>
    </x-filament::modal>
</div>
