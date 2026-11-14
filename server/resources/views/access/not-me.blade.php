<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>This wasn't me</title>
    <style>
        :root { color-scheme: light dark; --bg: #f6f6f4; --card: #fff; --text: #1d1d1b; --muted: #6b6b66; --line: #e2e2dd; --accent: #b91c1c; --ok: #166534; }
        @media (prefers-color-scheme: dark) { :root { --bg: #111; --card: #1c1c1c; --text: #f2f2f0; --muted: #a3a39e; --line: #333; --accent: #f87171; --ok: #4ade80; } }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font: 16px/1.45 system-ui, -apple-system, "Segoe UI", sans-serif; }
        main { max-width: 34rem; margin: 0 auto; padding: 16px; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 16px; }
        h1 { font-size: 1.2rem; margin: 0 0 8px; } .muted { color: var(--muted); font-size: .9rem; }
        label { display: flex; gap: .5rem; align-items: flex-start; margin-top: 1rem; }
        button { width: 100%; margin-top: 1rem; padding: .85rem; border: 0; border-radius: 8px; background: var(--accent); color: #fff; font: 600 1rem system-ui; }
        @media (prefers-color-scheme: dark) { button { color: #111; } }
        .ok { color: var(--ok); font-weight: 600; }
    </style>
</head>
<body>
<main>
    <div class="card">
        @if ($done)
            <h1 class="ok">Done: {{ $name }} is signed out everywhere</h1>
            @if ($locked)
                <p>The account is locked. SyncLytics support has been told and will call you to check it is you, then give you a new password and a new authenticator.</p>
            @else
                <p>Change your password as soon as you sign in again (your name at the top → Profile).</p>
            @endif
            <p class="muted">Everything that happened is in the audit log. You can close this page.</p>
        @else
            <h1>A sign-in to {{ $name }} was not you?</h1>
            <p>This signs the account out on every computer, phone and till at once, and stops trusting that computer.</p>
            <form method="post">
                @csrf
                <label><input type="checkbox" name="lock" value="1" checked> Also lock the account until SyncLytics support calls me. <span class="muted">(Recommended: whoever signed in may know the password.)</span></label>
                <button type="submit">Sign the account out everywhere</button>
            </form>
        @endif
    </div>
</main>
</body>
</html>
