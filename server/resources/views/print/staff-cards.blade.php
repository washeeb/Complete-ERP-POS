<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Staff cards</title>
    {{-- Staff cards (App\Support\StaffCard): printed straight away; the codes on them exist only on this page. --}}
    <style>
        :root { --brand: {{ $brand['color'] }}; --on-brand: {{ $brand['on_color'] }}; }
        * { box-sizing: border-box; }
        body { margin: 0; font: 10pt/1.3 system-ui, -apple-system, "Segoe UI", sans-serif; color: #1d1d1b; background: #eef0f3; }
        .tools { max-width: 190mm; margin: 12px auto; padding: 12px 14px; background: #fff; border: 1px solid #d4d7dd; border-radius: 6px; }
        .tools p { margin: 0 0 8px; } .tools button { font: inherit; padding: 8px 16px; border: 0; border-radius: 4px; background: var(--brand); color: var(--on-brand); cursor: pointer; }
        .sheet { display: grid; grid-template-columns: repeat(2, 85.6mm); gap: 0; justify-content: center; margin: 0 auto 24px; }
        .card { width: 85.6mm; height: 54mm; position: relative; overflow: hidden; background: #fff; border: 1px dashed #9aa0a6; display: grid;
            grid-template-columns: 24mm 1fr; grid-template-rows: 1fr 15mm; break-inside: avoid; }
        .band { grid-row: 1 / 3; background: var(--brand); color: var(--on-brand); display: flex; flex-direction: column; align-items: center; justify-content: space-between; padding: 3mm 2mm; }
        .band img.logo { max-width: 19mm; max-height: 10mm; object-fit: contain; background: #fff; border-radius: 1.5mm; padding: .8mm; }
        .band .co { font-size: 6.5pt; text-align: center; font-weight: 600; letter-spacing: .3px; }
        .photo { width: 18mm; height: 22mm; border-radius: 2mm; object-fit: cover; background: #fff; border: .5mm solid rgba(255,255,255,.8); display: grid; place-items: center;
            font-size: 16pt; font-weight: 700; color: var(--brand); }
        .who { padding: 3.5mm 3.5mm 0; display: flex; flex-direction: column; gap: .8mm; min-width: 0; }
        .who .name { font-size: 12.5pt; font-weight: 700; line-height: 1.15; }
        .who .role { font-size: 8.5pt; color: var(--brand); font-weight: 600; } .who .where { font-size: 8pt; color: #555; }
        .who .label { font-size: 6.5pt; text-transform: uppercase; letter-spacing: 1px; color: #777; }
        .bars { padding: 1mm 3.5mm 2mm; display: flex; flex-direction: column; justify-content: flex-end; }
        .bars svg { width: 100%; height: 10mm; display: block; }
        .bars .hint { font-size: 5.8pt; color: #666; margin-top: .6mm; }
        .gone { max-width: 150mm; margin: 40px auto; background: #fff; padding: 20px; border-radius: 6px; }
        @media print {
            body { background: #fff; } .tools { display: none; }
            @if ($layout === 'card')
                @page { size: 85.6mm 54mm; margin: 0; }
                .sheet { display: block; margin: 0; } .card { border: 0; page-break-after: always; }
            @else
                @page { size: A4; margin: 13mm 19mm; }
                .sheet { margin: 0; }
            @endif
        }
    </style>
</head>
<body>
@if (! $cards)
    <div class="gone">
        <h1>These cards were already printed</h1>
        <p>A card's code is kept only until its page is opened once, so a stolen link cannot print a copy. If the print did not come out, make new cards (People &amp; access → Staff cards): the cards from this batch then stop working.</p>
    </div>
@else
    <div class="tools">
        <p><strong>{{ count($cards) }} {{ count($cards) === 1 ? 'card' : 'cards' }} ready.</strong> {{ $layout === 'card' ? 'Card printer: one card per page, 85.6 × 54 mm, no margins.' : 'A4: cut along the dashed lines (laminate them if you like).' }}
            Print now: this page cannot be opened again, and the codes are not kept.</p>
        <button type="button" onclick="window.print()">Print the cards</button>
    </div>
    <div class="sheet">
        @foreach ($cards as $c)
            <div class="card">
                <div class="band">
                    @if ($logo)<img class="logo" src="{{ $logo }}" alt="">@endif
                    @if ($c['photo'])<img class="photo" src="{{ $c['photo'] }}" alt="">@else<div class="photo">{{ $c['initials'] }}</div>@endif
                    <span class="co">{{ $brand['company'] }}</span>
                </div>
                <div class="who">
                    <span class="label">Staff card</span>
                    <span class="name">{{ $c['name'] }}</span>
                    @if ($c['role'])<span class="role">{{ $c['role'] }}</span>@endif
                    @if ($c['where'])<span class="where">{{ $c['where'] }}</span>@endif
                </div>
                <div class="bars">{!! $c['barcode'] !!}<span class="hint">Scan at the till. Found this card? Hand it to {{ $brand['company'] }}.</span></div>
            </div>
        @endforeach
    </div>
    <script>window.addEventListener('load', () => setTimeout(() => window.print(), 400))</script>
@endif
</body>
</html>
