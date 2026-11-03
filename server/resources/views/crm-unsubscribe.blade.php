<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Stop these e-mails</title>
    <style>
        :root { color-scheme: light dark; --bg: #f6f6f4; --card: #fff; --text: #1d1d1b; --muted: #6b6b66; --line: #e2e2dd; --accent: #031E4C; --ok: #166534; }
        @media (prefers-color-scheme: dark) { :root { --bg: #111; --card: #1c1c1c; --text: #f2f2f0; --muted: #a3a39e; --line: #333; --accent: #8fb3ff; --ok: #4ade80; } }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font: 16px/1.45 system-ui, -apple-system, "Segoe UI", sans-serif; }
        main { max-width: 32rem; margin: 0 auto; padding: 16px; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 16px; }
        h1 { font-size: 1.2rem; margin: 0 0 8px; } .muted { color: var(--muted); font-size: .9rem; }
        button { width: 100%; margin-top: 1rem; padding: .85rem; border: 0; border-radius: 8px; background: var(--accent); color: #fff; font: 600 1rem system-ui; }
        @media (prefers-color-scheme: dark) { button { color: #111; } }
        .ok { color: var(--ok); font-weight: 600; }
    </style>
</head>
<body>
<main>
    <div class="card">
        @if ($done)
            <h1 class="ok">Done</h1>
            <p>You will no longer receive these e-mails{{ $company ? ' from '.$company : '' }}.</p>
            <p class="muted">Receipts and invoices you ask for still come. You can close this page.</p>
        @else
            <h1>Stop these e-mails?</h1>
            <p>You will no longer receive offers and news by e-mail{{ $company ? ' from '.$company : '' }}.</p>
            <form method="post">
                @csrf
                <button type="submit">Yes, stop them</button>
            </form>
        @endif
    </div>
</main>
</body>
</html>
