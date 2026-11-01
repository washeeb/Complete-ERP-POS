<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="referrer" content="no-referrer">
    <title>@yield('title') · {{ $company['name'] ?? '' }}</title>
    <style>
        :root { color-scheme: light dark; --bg: #f6f6f4; --card: #fff; --text: #1d1d1b; --muted: #6b6b66; --line: #e2e2dd; --accent: #031E4C; --ok: #166534; --err: #b91c1c; --warn: #92400e; }
        @media (prefers-color-scheme: dark) { :root { --bg: #111; --card: #1c1c1c; --text: #f2f2f0; --muted: #a3a39e; --line: #333; --accent: #8fb3ff; --ok: #4ade80; --err: #f87171; --warn: #fbbf24; } }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font: 16px/1.45 system-ui, -apple-system, "Segoe UI", sans-serif; }
        main { max-width: 48rem; margin: 0 auto; padding: 16px; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 16px; margin-bottom: 12px; }
        h1 { font-size: 1.25rem; margin: 0 0 4px; } h2 { font-size: 1.05rem; margin: 0 0 8px; }
        .muted { color: var(--muted); font-size: .9rem; }
        label { display: block; margin: .6rem 0 .2rem; font-weight: 600; }
        input, textarea, select { width: 100%; padding: .6rem; border: 1px solid var(--line); border-radius: 8px; background: transparent; color: inherit; font: inherit; }
        button, .btn { display: inline-block; margin-top: 1rem; padding: .75rem 1.1rem; border: 0; border-radius: 8px; background: var(--accent); color: #fff; font: 600 1rem system-ui; text-decoration: none; cursor: pointer; }
        button.secondary { background: transparent; color: var(--accent); border: 1px solid var(--line); }
        @media (prefers-color-scheme: dark) { button, .btn { color: #111; } button.secondary { color: var(--accent); } }
        .ok { color: var(--ok); font-weight: 600; } .err { color: var(--err); font-weight: 600; } .warn { color: var(--warn); }
        .table { width: 100%; border-collapse: collapse; font-size: .92rem; }
        .table th, .table td { text-align: left; padding: 6px 4px; border-top: 1px solid var(--line); vertical-align: top; }
        .table th { border-top: 0; color: var(--muted); font-weight: 600; font-size: .82rem; }
        .num { text-align: right !important; white-space: nowrap; }
        .scroll { overflow-x: auto; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr)); gap: 0 12px; }
        .badge { display: inline-block; padding: 1px 8px; border-radius: 999px; font-size: .8rem; border: 1px solid var(--line); }
        footer { text-align: center; color: var(--muted); font-size: .8rem; padding: 12px 0 24px; }
        details > summary { cursor: pointer; font-weight: 600; }
    </style>
</head>
<body>
<main>
    <div class="card">
        <div class="muted">{{ $company['name'] ?? '' }}</div>
        @yield('heading')
    </div>
    @if (! empty($error))<div class="card"><p class="err" role="alert">{{ $error }}</p></div>@endif
    @if ($errors->any())<div class="card" role="alert">@foreach ($errors->all() as $e)<p class="err">{{ $e }}</p>@endforeach</div>@endif
    @if (! empty($done))<div class="card"><p class="ok" role="status">{{ $done }}</p></div>@endif
    @yield('content')
    <footer>@if ($by = \App\Domain\Branding\Branding::poweredBy()){{ $by }} · @endif<span>Keep this link private.</span></footer>
</main>
</body>
</html>
