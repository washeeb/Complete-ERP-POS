{{-- Sensors → Temperature log (App\Filament\Pages\TemperatureLog): a month for one sensor, printable for inspections. --}}
@php($d = $this->data())
<x-filament-panels::page>
    <div class="zx-log-tools">
        <label>Sensor
            <select wire:model.live="sensor">
                @foreach ($this->sensors() as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
            </select>
        </label>
        <label>Month <input type="month" wire:model.live="month" max="{{ now()->format('Y-m') }}"></label>
        <button type="button" onclick="window.print()" class="zx-log-print">Print / save as PDF</button>
    </div>

    @if (! $d)
        <p class="text-sm text-gray-500">Add a fridge, cold room, freezer or room sensor first.</p>
    @else
        <div class="zx-log">
            <header>
                <h2>{{ \App\Filament\Support\Erp::user()?->tenant?->name }} — temperature log</h2>
                <p><b>{{ $d['sensor']->name }}</b> ({{ $d['sensor']->number }}), {{ $d['sensor']->branch?->name }} · {{ $d['from']->format('F Y') }} · acceptable range {{ $d['sensor']->range() }}</p>
                <p>Lowest {{ $d['min'] !== null ? (float) $d['min'].' °C' : '—' }}, highest {{ $d['max'] !== null ? (float) $d['max'].' °C' : '—' }},
                    out of range {{ intdiv($d['out'], 60) }} h {{ $d['out'] % 60 }} min this month · {{ $d['alarms']->count() }} alarm(s).</p>
            </header>
            <table>
                <thead><tr><th>Date</th><th>Lowest</th><th>Highest</th><th>Average</th><th>Out of range</th><th>Checks (time, display, signed by)</th></tr></thead>
                <tbody>
                @foreach ($d['rows'] as $r)
                    @php($day = $r['day'])
                    @php($bad = $day && (($d['sensor']->max_temp !== null && (float) $day->max_temp > (float) $d['sensor']->max_temp) || ($d['sensor']->min_temp !== null && $day->min_temp !== null && (float) $day->min_temp < (float) $d['sensor']->min_temp)))
                    <tr @class(['zx-log-bad' => $bad])>
                        <td>{{ $r['date']->format('D j') }}</td>
                        <td>{{ $day?->min_temp !== null ? (float) $day->min_temp.' °C' : '—' }}</td>
                        <td>{{ $day?->max_temp !== null ? (float) $day->max_temp.' °C' : '—' }}</td>
                        <td>{{ $day?->average() !== null ? $day->average().' °C' : '—' }}</td>
                        <td>{{ $day && $day->out_minutes ? $day->out_minutes.' min' : '' }}</td>
                        <td>
                            @forelse ($r['checks'] as $c)
                                <div>{{ $c->checked_at->timezone($d['tz'])->format('H:i') }} · {{ $c->seen_temp !== null ? (float) $c->seen_temp.' °C' : '—' }} · {{ $c->user_name }}@unless ($c->ok) · <b>not well</b>@endunless @if ($c->note) — {{ $c->note }}@endif</div>
                            @empty
                                <span class="zx-log-none">No check signed</span>
                            @endforelse
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <h3>Alarms</h3>
            @if ($d['alarms']->isEmpty())
                <p>None this month.</p>
            @else
                <table>
                    <thead><tr><th>No.</th><th>What</th><th>From</th><th>Until</th><th>Worst</th><th>What was done</th><th>By</th></tr></thead>
                    <tbody>
                    @foreach ($d['alarms'] as $a)
                        <tr>
                            <td>{{ $a->number }}</td>
                            <td>{{ \App\Models\IotAlarm::KINDS[$a->kind] ?? $a->kind }}</td>
                            <td>{{ $a->started_at->timezone($d['tz'])->format('j M H:i') }}</td>
                            <td>{{ $a->ended_at ? $a->ended_at->timezone($d['tz'])->format('j M H:i') : 'still going on' }}</td>
                            <td>{{ $a->worst !== null ? (float) $a->worst.($a->kind === 'humidity' ? ' %' : ' °C') : '—' }}</td>
                            <td>{{ $a->action ? (\App\Models\IotAlarm::ACTIONS[$a->action] ?? $a->action) : 'Not yet said' }}@if ($a->notes) — {{ $a->notes }}@endif</td>
                            <td>{{ $a->acknowledged_by_name }}{{ $a->acknowledged_at ? ', '.$a->acknowledged_at->timezone($d['tz'])->format('j M H:i') : '' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
            <p class="zx-log-sign">Reviewed by (name, signature, date): ____________________________________________</p>
        </div>
    @endif
</x-filament-panels::page>
