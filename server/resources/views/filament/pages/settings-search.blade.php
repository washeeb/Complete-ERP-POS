{{-- Settings search (owner request 2026-10-24): type words from a setting's name or explanation; only matching settings show. --}}
<div class="ses-settings-find">
    <x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass">
        <x-filament::input type="search" wire:model.live.debounce.300ms="find" placeholder="Find a setting — e.g. receipt, momo, expiry, backup" aria-label="Find a setting" autofocus />
    </x-filament::input.wrapper>
    @if (trim($this->find) !== '')
        <p class="ses-settings-hint">Showing the settings with “{{ $this->find }}”. Clear the box to see every group.</p>
    @else
        <p class="ses-settings-hint">Open a group, or type to find a setting.</p>
    @endif
</div>
<style>
    .ses-settings-find { position: sticky; top: calc(var(--topbar-height, 4rem) + .5rem); z-index: 10; display: grid; gap: 4px; padding: 8px 0;
        background: var(--gray-50); }
    .dark .ses-settings-find { background: var(--gray-950); }
    .ses-settings-hint { font-size: .8rem; opacity: .65; }
</style>
