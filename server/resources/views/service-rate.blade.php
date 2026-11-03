<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>How did we do?</title>
    <style>
        :root { color-scheme: light dark; --bg: #f6f6f4; --card: #fff; --text: #1d1d1b; --muted: #6b6b66; --line: #e2e2dd; --accent: #031E4C; --ok: #166534; }
        @media (prefers-color-scheme: dark) { :root { --bg: #111; --card: #1c1c1c; --text: #f2f2f0; --muted: #a3a39e; --line: #333; --accent: #8fb3ff; --ok: #4ade80; } }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font: 16px/1.45 system-ui, -apple-system, "Segoe UI", sans-serif; }
        main { max-width: 30rem; margin: 0 auto; padding: 16px; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 16px; }
        h1 { font-size: 1.2rem; margin: 0 0 8px; } .muted { color: var(--muted); font-size: .9rem; }
        .stars { display: grid; grid-template-columns: repeat(5, 1fr); gap: .5rem; margin-top: 1rem; }
        button { padding: .9rem 0; border: 1px solid var(--line); border-radius: 10px; background: transparent; color: inherit; font: 700 1.1rem system-ui; }
        button:hover, button:focus { border-color: var(--accent); }
        .ok { color: var(--ok); font-weight: 600; }
    </style>
</head>
<body>
<main>
    <div class="card">
        @if ($done)
            <h1 class="ok">Thank you</h1>
            <p>Your answer helps us serve you better.</p>
        @else
            <div class="muted">{{ $ticket->number }} · {{ $ticket->subject }}</div>
            <h1>How did we do?</h1>
            <p class="muted">1 = poor, 5 = excellent</p>
            <form method="post" class="stars">
                @csrf
                @foreach ([1, 2, 3, 4, 5] as $n)
                    <button type="submit" name="rating" value="{{ $n }}" aria-label="{{ $n }} out of 5">{{ $n }}</button>
                @endforeach
            </form>
        @endif
    </div>
</main>
</body>
</html>
