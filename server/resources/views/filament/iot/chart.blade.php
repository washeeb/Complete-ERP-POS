{{-- The last 24 hours of a sensor (App\Filament\Resources\IotSensors\IotSensorResource::now): readings as a line, the range as a band. --}}
@php
    $rows = \App\Models\IotReading::where('sensor_id', $sensor->id)->where('read_at', '>=', now()->subDay())->whereNotNull('temperature')->orderBy('read_at')->get(['read_at', 'temperature']);
    $w = 720; $h = 160; $pad = 28;
    $lo = $sensor->min_temp; $hi = $sensor->max_temp;
    $vals = $rows->pluck('temperature')->map(fn ($v) => (float) $v);
    $min = min(array_filter([$vals->min(), $lo !== null ? (float) $lo - 1 : null], fn ($v) => $v !== null) ?: [0]);
    $max = max(array_filter([$vals->max(), $hi !== null ? (float) $hi + 1 : null], fn ($v) => $v !== null) ?: [10]);
    $max = $max <= $min ? $min + 1 : $max;
    $start = now()->subDay()->getTimestamp();
    $x = fn ($t) => $pad + ($t->getTimestamp() - $start) / 86400 * ($w - 2 * $pad);
    $y = fn ($v) => $h - $pad - (((float) $v - $min) / ($max - $min)) * ($h - 2 * $pad);
    $points = $rows->map(fn ($r) => round($x($r->read_at), 1).','.round($y($r->temperature), 1))->implode(' ');
@endphp
@if ($sensor->measuresTemperature())
    <div class="zx-iot-chart">
        <div class="text-xs text-gray-500">Last 24 hours</div>
        @if ($rows->isEmpty())
            <p class="text-sm text-gray-500">No readings in the last 24 hours.</p>
        @else
            <svg viewBox="0 0 {{ $w }} {{ $h }}" role="img" aria-label="Temperature over the last 24 hours, {{ $vals->min() }} to {{ $vals->max() }} °C">
                @if ($lo !== null && $hi !== null)
                    <rect x="{{ $pad }}" y="{{ $y($hi) }}" width="{{ $w - 2 * $pad }}" height="{{ max(1, $y($lo) - $y($hi)) }}" class="zx-iot-band"/>
                    <text x="2" y="{{ $y($hi) + 4 }}" class="zx-iot-axis">{{ (float) $hi }}°</text>
                    <text x="2" y="{{ $y($lo) + 4 }}" class="zx-iot-axis">{{ (float) $lo }}°</text>
                @endif
                <polyline points="{{ $points }}" class="zx-iot-line"/>
                @foreach ($rows as $r)
                    @if (($hi !== null && (float) $r->temperature > (float) $hi) || ($lo !== null && (float) $r->temperature < (float) $lo))
                        <circle cx="{{ round($x($r->read_at), 1) }}" cy="{{ round($y($r->temperature), 1) }}" r="2.5" class="zx-iot-out"><title>{{ $r->read_at->timezone($tz)->format('H:i') }} {{ (float) $r->temperature }} °C</title></circle>
                    @endif
                @endforeach
            </svg>
        @endif
    </div>
@endif
