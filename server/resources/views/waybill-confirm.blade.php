<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Waybill {{ $w->number }}</title>
    <style>
        :root { color-scheme: light dark; --bg: #f6f6f4; --card: #fff; --text: #1d1d1b; --muted: #6b6b66; --line: #e2e2dd; --accent: #031E4C; --ok: #166534; --err: #b91c1c; }
        @media (prefers-color-scheme: dark) { :root { --bg: #111; --card: #1c1c1c; --text: #f2f2f0; --muted: #a3a39e; --line: #333; --accent: #8fb3ff; --ok: #4ade80; --err: #f87171; } }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font: 16px/1.45 system-ui, -apple-system, "Segoe UI", sans-serif; }
        main { max-width: 32rem; margin: 0 auto; padding: 16px; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 16px; margin-bottom: 12px; }
        h1 { font-size: 1.2rem; margin: 0 0 4px; } .muted { color: var(--muted); font-size: .9rem; }
        ul { padding-left: 1.1rem; margin: .5rem 0 0; } label { display: block; margin: .6rem 0 .2rem; font-weight: 600; }
        input, textarea { width: 100%; padding: .65rem; border: 1px solid var(--line); border-radius: 8px; background: transparent; color: inherit; font: inherit; }
        button { width: 100%; margin-top: 1rem; padding: .85rem; border: 0; border-radius: 8px; background: var(--accent); color: #fff; font: 600 1rem system-ui; }
        @media (prefers-color-scheme: dark) { button { color: #111; } }
        .ok { color: var(--ok); font-weight: 600; } .err { color: var(--err); }
    </style>
</head>
<body>
<main>
    <div class="card">
        <div class="muted">{{ $company }}</div>
        <h1>Waybill {{ $w->number }}</h1>
        <div class="muted">From {{ $w->from_name }} to {{ $w->to_name }}@if ($w->packages) · {{ $w->packages }} package(s)@endif</div>
        <ul>@foreach ($w->lines as $l)<li>{{ rtrim(rtrim($l['qty'], '0'), '.') }} {{ $l['unit'] }} {{ $l['name'] }}</li>@endforeach</ul>
    </div>
    @if ($done)
        <div class="card"><p class="ok">Thank you — received by {{ $w->received_by_name }} on {{ $w->delivered_at?->format('j M Y H:i') }}.</p>
            <p class="muted">{{ $company }} has been told. You can close this page.</p></div>
    @else
        <form class="card" method="post" enctype="multipart/form-data">
            @csrf
            <p class="muted">Check the goods against the list above, then confirm you received them.</p>
            @if ($error)<p class="err">{{ $error }}</p>@endif
            @foreach ($errors->all() as $e)<p class="err">{{ $e }}</p>@endforeach
            <label for="name">Your name</label><input id="name" name="name" required maxlength="120" value="{{ old('name') }}" autocomplete="name">
            <label for="phone">Phone (optional)</label><input id="phone" name="phone" inputmode="tel" maxlength="30" value="{{ old('phone') }}" autocomplete="tel">
            <label for="note">Anything missing or damaged? (optional)</label><textarea id="note" name="note" rows="2" maxlength="500">{{ old('note') }}</textarea>
            <label for="photo">Photo of the goods or the signed waybill (optional)</label><input id="photo" name="photo" type="file" accept="image/*" capture="environment">
            <button type="submit">Confirm received</button>
        </form>
    @endif
</main>
</body>
</html>
