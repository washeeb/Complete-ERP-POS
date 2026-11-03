<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Help — {{ $company }}</title>
    <style>
        :root { color-scheme: light dark; --bg: #f6f6f4; --card: #fff; --text: #1d1d1b; --muted: #6b6b66; --line: #e2e2dd; --accent: #031E4C; --ok: #166534; --err: #b91c1c; }
        @media (prefers-color-scheme: dark) { :root { --bg: #111; --card: #1c1c1c; --text: #f2f2f0; --muted: #a3a39e; --line: #333; --accent: #8fb3ff; --ok: #4ade80; --err: #f87171; } }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font: 16px/1.45 system-ui, -apple-system, "Segoe UI", sans-serif; }
        main { max-width: 34rem; margin: 0 auto; padding: 16px; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 16px; margin-bottom: 12px; }
        h1 { font-size: 1.25rem; margin: 0 0 4px; } .muted { color: var(--muted); font-size: .9rem; }
        label { display: block; margin: .7rem 0 .2rem; font-weight: 600; }
        input, textarea, select { width: 100%; padding: .65rem; border: 1px solid var(--line); border-radius: 8px; background: transparent; color: inherit; font: inherit; }
        textarea { min-height: 7rem; }
        button { width: 100%; margin-top: 1rem; padding: .85rem; border: 0; border-radius: 8px; background: var(--accent); color: #fff; font: 600 1rem system-ui; }
        @media (prefers-color-scheme: dark) { button { color: #111; } }
        .ok { color: var(--ok); font-weight: 600; } .err { color: var(--err); }
        .hp { position: absolute; left: -9999px; }
    </style>
</head>
<body>
<main>
    <div class="card">
        <div class="muted">{{ $company }}</div>
        <h1>How can we help?</h1>
        <p class="muted">Tell us about a problem, a return or a question. We answer by phone, WhatsApp or e-mail.</p>
    </div>
    @if ($done)
        <div class="card"><p class="ok">Thank you — we have it.</p><p>Your reference is <strong>{{ $done }}</strong>. Quote it if you contact us about it.</p></div>
    @else
        <form class="card" method="post">
            @csrf
            @foreach ($errors->all() as $e)<p class="err">{{ $e }}</p>@endforeach
            <label for="name">Your name</label><input id="name" name="contact_name" required maxlength="120" value="{{ old('contact_name') }}" autocomplete="name">
            <label for="phone">Phone (WhatsApp if you have it)</label><input id="phone" name="contact_phone" maxlength="30" value="{{ old('contact_phone') }}" autocomplete="tel" inputmode="tel">
            <label for="email">E-mail</label><input id="email" name="contact_email" type="email" maxlength="255" value="{{ old('contact_email') }}" autocomplete="email">
            <p class="muted">A phone number or an e-mail address, so we can answer.</p>
            <label for="kind">It is about</label>
            <select id="kind" name="kind">
                <option value="support">A question or help</option>
                <option value="complaint" @selected(old('kind') === 'complaint')>A complaint</option>
                <option value="return" @selected(old('kind') === 'return')>Returning something</option>
                <option value="warranty" @selected(old('kind') === 'warranty')>Something under warranty</option>
            </select>
            <label for="receipt">Receipt number (if it is about something you bought)</label><input id="receipt" name="receipt_no" maxlength="40" value="{{ old('receipt_no') }}">
            <label for="subject">In a few words</label><input id="subject" name="subject" required maxlength="200" value="{{ old('subject') }}">
            <label for="description">Tell us more</label><textarea id="description" name="description" required maxlength="5000">{{ old('description') }}</textarea>
            <div class="hp" aria-hidden="true"><label for="website">Leave this empty</label><input id="website" name="website" tabindex="-1" autocomplete="off"></div>
            <button type="submit">Send</button>
        </form>
    @endif
</main>
</body>
</html>
