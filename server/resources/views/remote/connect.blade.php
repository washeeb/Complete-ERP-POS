<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Connecting to {{ $computer }}</title>
    <meta http-equiv="refresh" content="3;url={{ $url }}">
    <style>
        :root { --bg: #f8fafc; --card: #fff; --text: #1d1d1b; --muted: #64748b; --line: #e2e8f0; --accent: #2563eb; }
        @media (prefers-color-scheme: dark) { :root { --bg: #0f172a; --card: #111827; --text: #e5e7eb; --muted: #94a3b8; --line: #1f2937; --accent: #60a5fa; } }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: var(--bg); color: var(--text); font: 15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; padding: 16px; }
        .card { max-width: 520px; width: 100%; background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 24px; }
        h1 { font-size: 1.2rem; margin: 0 0 8px; } .muted { color: var(--muted); font-size: .9rem; }
        a.btn { display: inline-block; margin-top: 14px; padding: 9px 14px; border-radius: 9px; background: var(--accent); color: #fff; text-decoration: none; }
    </style>
</head>
<body>
<main class="card">
    <h1>{{ $mode === 'control' ? 'Taking control of' : 'Viewing' }} {{ $computer }}</h1>
    <p>{{ $device->name }} — opening it on the remote support relay.</p>
    <p class="muted">
        @if ($asks)
            The person at the device will be asked to accept the connection first (this branch's rule during trading hours).
        @else
            Unattended: the device shows a notice while you are connected.
        @endif
        @if ($mode === 'view') Keep to <strong>View only</strong> on the relay's screen. @endif
        This connection is in the audit log, and the relay records the session.
    </p>
    <a class="btn" href="{{ $url }}">Open now</a>
</main>
</body>
</html>
