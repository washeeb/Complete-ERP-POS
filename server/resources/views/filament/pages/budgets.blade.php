<x-filament-panels::page>
    <div class="bud-filters">
        <label>Financial year <x-filament::input.wrapper><x-filament::input type="number" wire:model.live.debounce.500ms="year" min="2000" max="2100" /></x-filament::input.wrapper></label>
        <label>Branch
            <x-filament::input.wrapper><x-filament::input.select wire:model.live="branch">
                @foreach ($this->branches() as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
            </x-filament::input.select></x-filament::input.wrapper>
        </label>
        @if ($this->canSave())<x-filament::button wire:click="save" icon="heroicon-o-check">Save budget</x-filament::button>@endif
    </div>
    <p class="bud-note">Enter income and costs as positive amounts. Type a yearly figure and press “Spread” to share it over the 12 months. Compare under Reports → Budget vs actual.</p>
    <x-filament::section>
        <div class="bud-scroll">
            <table class="bud">
                <thead><tr><th>Account</th><th>Year</th><th></th>@foreach ($this->months() as $m)<th>{{ $m }}</th>@endforeach</tr></thead>
                <tbody>
                @foreach ($this->accounts() as $a)
                    <tr>
                        <td class="acc">{{ $a->label() }}</td>
                        <td><input class="cell" inputmode="decimal" wire:model="annual.{{ $a->id }}" aria-label="Year {{ $a->code }}" @disabled(! $this->canSave())></td>
                        <td>@if ($this->canSave())<button type="button" class="spread" wire:click="spread('{{ $a->id }}')">Spread</button>@endif</td>
                        @for ($i = 0; $i < 12; $i++)
                            <td><input class="cell" inputmode="decimal" wire:model="amounts.{{ $a->id }}.{{ $i }}" aria-label="{{ $a->code }} month {{ $i + 1 }}" @disabled(! $this->canSave())></td>
                        @endfor
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
    <style>
        .bud-filters { display: flex; flex-wrap: wrap; gap: 12px; align-items: end; } .bud-filters label { display: grid; gap: 4px; font-size: .875rem; }
        .bud-note { font-size: .8rem; opacity: .75; }
        .bud-scroll { overflow-x: auto; }
        .bud { border-collapse: collapse; font-size: .8rem; } .bud th, .bud td { padding: 2px 4px; text-align: left; white-space: nowrap; }
        .bud .acc { min-width: 220px; white-space: normal; }
        .bud .cell { width: 84px; padding: 2px 4px; text-align: right; border: 1px solid rgb(0 0 0 / .15); border-radius: 4px; background: transparent; color: inherit; }
        .bud .spread { font-size: .7rem; text-decoration: underline; }
    </style>
</x-filament-panels::page>
