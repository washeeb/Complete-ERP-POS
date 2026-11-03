{{-- A direct sensor's address (App\Filament\Resources\IotSensors\IotSensorResource): where the device sends readings, with examples. --}}
@php($url = route('iot.reading', ['token' => $sensor->token]))
<div class="space-y-3 text-sm">
    <p>The device sends its readings to this address every few minutes. Keep it private: anyone who has it can send readings.</p>
    <pre class="rounded-lg bg-gray-100 p-3 text-xs dark:bg-white/5" style="white-space: pre-wrap; overflow-wrap: anywhere;">{{ $url }}</pre>
    <p><b>JSON (most devices, The Things Network)</b>: POST <code>{"temperature": 4.6, "humidity": 52, "battery": 88}</code> — also understood: <code>temp</code>, <code>hum</code>,
        <code>power</code> (on/off), <code>door</code> (open/closed), Tasmota's <code>StatusSNS</code> and The Things Network's <code>decoded_payload</code>.</p>
    <p><b>A link (Shelly H&amp;T and devices that can only call a web address)</b>: <code>{{ $url }}?temp=4.6&amp;hum=52</code> — in Shelly, use its report / action URL
        with its own placeholders for the values.</p>
    <p>The sensor shows <b>No reading yet</b> until the first reading arrives.</p>
</div>
