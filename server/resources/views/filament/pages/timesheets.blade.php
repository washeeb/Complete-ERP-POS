<x-filament-panels::page>
    <div class="ts-filters">
        <label>Branch <x-filament::input.wrapper><x-filament::input.select wire:model.live="branch">
            @foreach ($this->branches() as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
        </x-filament::input.select></x-filament::input.wrapper></label>
        <label>Month <x-filament::input.wrapper><x-filament::input type="month" wire:model.live="month" /></x-filament::input.wrapper></label>
    </div>
    <x-filament::section>
        <div class="ts-scroll"><table class="ts">
            <thead><tr><th>Employee</th><th class="num">Days</th><th class="num">Hours</th><th class="num">Overtime</th><th>Clock-ins without a clock-out</th></tr></thead>
            <tbody>
            @forelse ($this->rows() as $r)
                <tr @class(['ts-sel' => $person === $r['employee']->user_id])>
                    <td><button type="button" class="ts-link" wire:click="$set('person', '{{ $r['employee']->user_id }}')">{{ $r['employee']->name }}</button></td>
                    <td class="num">{{ $r['days'] }}</td><td class="num">{{ number_format($r['hours'], 2) }}</td><td class="num">{{ number_format($r['overtime'], 2) }}</td>
                    <td>@if ($r['open'])<span style="color:#dc2626">{{ implode(', ', $r['open']) }}</span>@endif</td>
                </tr>
            @empty
                <tr><td colspan="5">No employees with a sign-in at this branch. Link each employee to their sign-in so their clock-ins count.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </x-filament::section>
    @if ($person)
        <x-filament::section :heading="'Clock events — '.$this->personName()">
            <table class="ts">
                <thead><tr><th>When</th><th>In / out</th><th>How</th><th>Note</th><th></th></tr></thead>
                <tbody>
                @foreach ($this->events() as $e)
                    <tr @class(['ts-void' => $e->voided || $e->is_void])>
                        <td>{{ $e->at }}</td><td>{{ $e->is_void ? 'cancels '.$e->kind : $e->kind }}</td><td>{{ $e->source }} · {{ $e->by }}</td><td>{{ $e->reason }}</td>
                        <td>@if (! $e->voided && ! $e->is_void)<x-filament::link tag="button" color="danger" wire:click="voidEvent('{{ $e->id }}')">Cancel</x-filament::link>@endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <div class="ts-filters" style="margin-top:12px">
                <x-filament::input.wrapper><x-filament::input.select wire:model="addKind"><option value="in">Clock in</option><option value="out">Clock out</option></x-filament::input.select></x-filament::input.wrapper>
                <x-filament::input.wrapper><x-filament::input type="datetime-local" wire:model="addAt" /></x-filament::input.wrapper>
                <x-filament::input.wrapper><x-filament::input type="text" wire:model="reason" placeholder="Reason (needed to add or cancel)" /></x-filament::input.wrapper>
                <x-filament::button wire:click="addEvent">Add</x-filament::button>
            </div>
        </x-filament::section>
    @endif
    <style>
        .ts-filters { display: flex; flex-wrap: wrap; gap: 12px; align-items: end; } .ts-filters label { display: grid; gap: 4px; font-size: .875rem; }
        .ts-scroll { overflow-x: auto; } .ts { width: 100%; border-collapse: collapse; font-size: .875rem; }
        .ts th, .ts td { padding: 6px 8px; border-bottom: 1px solid color-mix(in srgb, currentColor 6%, transparent); text-align: left; } .ts .num { text-align: right; }
        .ts-link { text-decoration: underline; } .ts-sel td { background: rgb(59 130 246 / .08); } .ts-void td { opacity: .5; text-decoration: line-through; }
    </style>
</x-filament-panels::page>
