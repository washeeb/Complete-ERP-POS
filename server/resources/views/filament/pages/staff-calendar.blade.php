<x-filament-panels::page>
    @php($g = $this->grid())
    @php($tz = $this->tz())
    <div class="sc-filters">
        <label>Branch <x-filament::input.wrapper><x-filament::input.select wire:model.live="branchId">
            @foreach ($this->branches() as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
        </x-filament::input.select></x-filament::input.wrapper></label>
        <label>Day <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="date" /></x-filament::input.wrapper></label>
        <div class="sc-nav">
            <x-filament::button size="sm" color="gray" wire:click="shift(-1)">◀ Previous day</x-filament::button>
            <x-filament::button size="sm" color="gray" wire:click="shift(1)">Next day ▶</x-filament::button>
        </div>
    </div>

    @if ($g['staff']->isEmpty())
        <x-filament::section>
            Nobody at this branch takes appointments yet. Under HR → Employees, switch on <strong>Takes appointments</strong> for the staff who do services.
        </x-filament::section>
    @else
        <div class="sc-wrap">
            <table class="sc">
                <thead><tr><th class="sc-time"></th>@foreach ($g['staff'] as $s)<th>{{ $s->name }}<br><span class="muted">{{ $s->job_title }}</span></th>@endforeach</tr></thead>
                <tbody>
                @foreach ($g['slots'] as $slot)
                    <tr><th class="sc-time">{{ $slot }}</th>
                        @foreach ($g['staff'] as $s)
                            <td>
                                @foreach ($g['cells'][$s->id][$slot] ?? [] as $a)
                                    <div class="sc-appt sc-{{ $a->status }}" title="{{ $a->number }}{{ $a->note ? ' · '.$a->note : '' }}">
                                        <strong>{{ $a->starts_at->setTimezone($tz)->format('H:i') }}–{{ $a->ends_at->setTimezone($tz)->format('H:i') }}</strong>
                                        {{ $a->customer_name }}<br><span class="muted">{{ $a->product?->name }} · {{ \App\Models\Appointment::STATUSES[$a->status] ?? $a->status }}</span>
                                    </div>
                                @endforeach
                            </td>
                        @endforeach
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="muted">Arrivals, no-shows and changes: Services → Appointments. Payment: at the till (Appointments).</p>
    @endif
    <style>
        .sc-filters { display: flex; gap: 12px; flex-wrap: wrap; align-items: end; } .sc-filters label { display: grid; gap: 4px; font-size: .875rem; } .sc-nav { display: flex; gap: 6px; }
        .sc-wrap { overflow-x: auto; } .sc { border-collapse: collapse; font-size: .8rem; min-width: 100%; }
        .sc th, .sc td { border: 1px solid color-mix(in srgb, currentColor 8%, transparent); padding: 2px 4px; vertical-align: top; min-width: 140px; } .sc .sc-time { min-width: 52px; width: 52px; text-align: right; }
        .sc-appt { border-radius: 6px; padding: 2px 6px; margin: 1px 0; background: rgb(59 130 246 / .15); }
        .sc-arrived { background: rgb(245 158 11 / .2); } .sc-done { background: rgb(34 197 94 / .18); } .sc-no_show { background: rgb(239 68 68 / .15); text-decoration: line-through; }
        .muted { opacity: .65; font-size: .75rem; }
    </style>
</x-filament-panels::page>
