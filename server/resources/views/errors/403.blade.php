@php($why = \App\Security\AccessReason::explain($exception ?? null))
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>No access to this page</title>
    <style>
        :root { --bg: #f8fafc; --card: #fff; --text: #1d1d1b; --muted: #64748b; --line: #e2e8f0; --accent: #2563eb; }
        @media (prefers-color-scheme: dark) { :root { --bg: #0f172a; --card: #111827; --text: #e5e7eb; --muted: #94a3b8; --line: #1f2937; --accent: #60a5fa; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: var(--bg); color: var(--text);
            font: 15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; padding: 16px; }
        .card { max-width: 560px; width: 100%; background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 24px; }
        h1 { font-size: 1.25rem; margin: 0 0 8px; }
        .why { background: color-mix(in srgb, var(--accent) 8%, transparent); border-radius: 10px; padding: 12px 14px; margin: 14px 0; }
        .muted { color: var(--muted); font-size: .9rem; }
        .row { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 18px; }
        a.btn, button.btn { display: inline-flex; align-items: center; padding: 9px 14px; border-radius: 9px; border: 1px solid var(--line);
            background: transparent; color: var(--text); text-decoration: none; font: inherit; cursor: pointer; }
        .btn.primary { background: var(--accent); border-color: var(--accent); color: #fff; }
        textarea { width: 100%; margin-top: 10px; padding: 8px 10px; border-radius: 9px; border: 1px solid var(--line); background: transparent; color: inherit; font: inherit; }
        .ok { color: #15803d; }
    </style>
</head>
<body>
<main class="card" role="main">
    <h1>You can’t open this page</h1>
    <p class="muted">Your account is signed in, but it is not allowed to use this part of the system.</p>
    <div class="why">
        <strong>Why:</strong> {{ $why['reason'] }}
        @if ($why['admins'])<br><span class="muted">Who can give access: {{ $why['admins'] }}.</span>@endif
    </div>
    @if (session('access_requested'))
        <p class="ok" role="status">Your request was sent. You will see the page once access is given (sign out and in again if it does not appear).</p>
    @elseif (auth()->check())
        <form method="post" action="{{ route('access.request') }}">
            @csrf
            <input type="hidden" name="path" value="{{ request()->getRequestUri() }}">
            <input type="hidden" name="permission" value="{{ $why['permission'] }}">
            <label class="muted" for="note">Message to them (optional)</label>
            <textarea id="note" name="note" rows="2" maxlength="300" placeholder="e.g. I need it to receive deliveries at Osu"></textarea>
            <div class="row">
                <button class="btn primary" type="submit">Ask for access</button>
                <a class="btn" href="{{ url()->previous() !== url()->current() ? url()->previous() : url('/admin') }}">Go back</a>
                <a class="btn" href="{{ url('/admin') }}">Home</a>
            </div>
        </form>
    @else
        <div class="row"><a class="btn primary" href="{{ url('/admin/login') }}">Sign in</a></div>
    @endif
</main>
</body>
</html>
