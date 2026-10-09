<div>
    <x-filament::modal id="report-problem" width="lg">
        <x-slot name="trigger">
            <x-filament::icon-button icon="heroicon-o-lifebuoy" color="gray" label="Report a problem" tooltip="Report a problem" />
        </x-slot>
        <x-slot name="heading">Report a problem</x-slot>
        <x-slot name="description">What were you doing, and what happened? Support gets this with the page you are on.</x-slot>
        <form wire:submit="send" x-data x-init="$wire.page = window.location.pathname + window.location.search; $wire.browser = navigator.userAgent + ' · ' + window.innerWidth + '×' + window.innerHeight">
            <textarea wire:model="message" rows="5" required minlength="5" maxlength="5000" aria-label="What happened"
                placeholder="e.g. I pressed Post on the stock count and it said “Something went wrong”."
                style="width:100%;border-radius:8px;padding:8px;border:1px solid color-mix(in srgb, currentColor 20%, transparent);background:transparent"></textarea>
            @error('message')<p style="color:rgb(220 38 38);font-size:.85rem">{{ $message }}</p>@enderror
            <div style="margin-top:10px;display:flex;justify-content:flex-end">
                <x-filament::button type="submit" icon="heroicon-o-paper-airplane" wire:loading.attr="disabled">Send</x-filament::button>
            </div>
        </form>
    </x-filament::modal>
</div>
