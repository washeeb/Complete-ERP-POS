<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? $shop->name }}</title>
    <script>try{var t=localStorage.getItem('shop-theme');if(t==='light'||t==='dark')document.documentElement.dataset.theme=t;}catch(e){}</script>
    <style>
        :root { --bg:#f7f7f5; --card:#fff; --ink:#1d1d1b; --muted:#6b6b66; --line:#e3e3de; --accent:#14532d; --warn:#9a3412; }
        :root[data-theme="dark"] { --bg:#141413; --card:#1f1f1d; --ink:#ececea; --muted:#a3a39c; --line:#33332f; --accent:#4ade80; --warn:#fb923c; color-scheme:dark; }
        @media (prefers-color-scheme: dark) { :root:not([data-theme="light"]) { --bg:#141413; --card:#1f1f1d; --ink:#ececea; --muted:#a3a39c; --line:#33332f; --accent:#4ade80; --warn:#fb923c; color-scheme:dark; } }
        .theme-picker { width:auto; padding:4px 8px; font-size:.8rem; }
        * { box-sizing: border-box; }
        body { margin:0; font:16px/1.5 system-ui, sans-serif; background:var(--bg); color:var(--ink); }
        header, main, footer { max-width:1040px; margin:0 auto; padding:16px; }
        header { display:flex; gap:12px; align-items:center; justify-content:space-between; flex-wrap:wrap; }
        header a { color:inherit; text-decoration:none; }
        h1 { font-size:1.4rem; margin:0; }
        .muted { color:var(--muted); }
        .grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(200px, 1fr)); gap:12px; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:14px; display:flex; flex-direction:column; gap:6px; transition:box-shadow .3s; }
        .card.out { opacity:.6; }
        .card.flash { box-shadow:0 0 0 3px var(--accent); }
        .price { font-weight:600; font-size:1.1rem; }
        button, .btn { background:var(--accent); color:var(--bg); border:0; border-radius:8px; padding:10px 14px; font:inherit; cursor:pointer; text-decoration:none; display:inline-block; }
        button:disabled { opacity:.4; cursor:not-allowed; }
        input, select, textarea { font:inherit; padding:9px; border:1px solid var(--line); border-radius:8px; background:var(--card); color:var(--ink); width:100%; }
        form.inline { display:flex; gap:8px; flex-wrap:wrap; }
        form.inline > * { width:auto; }
        .notice { padding:10px 14px; border-radius:8px; border:1px solid var(--line); background:var(--card); margin-bottom:12px; }
        .notice.error { border-color:var(--warn); color:var(--warn); }
        table { width:100%; border-collapse:collapse; }
        td, th { padding:8px 4px; border-bottom:1px solid var(--line); text-align:left; }
        td.num, th.num { text-align:right; }
        .hp { position:absolute; left:-10000px; }
        [data-live]::before { content:'●'; margin-right:4px; color:var(--muted); }
        [data-live="connected"]::before { color:var(--accent); }
        label { display:block; margin:8px 0 4px; }
    </style>
</head>
<body>
<div data-shop="{{ $shop->id }}" data-currency="{{ $shop->priceList->currency_code }}" data-exponent="{{ \App\Support\Money::exponent($shop->priceList->currency_code) }}" data-zero="{{ \App\Support\Money::display(0, $shop->priceList->currency_code) }}"
     data-realtime="{{ ($rt = \App\Http\Controllers\StorefrontController::realtime()) ? json_encode($rt) : '' }}">
<header>
    <a href="{{ route('shop.index', $shop->settings['slug']) }}"><h1>{{ $shop->name }}</h1></a>
    <nav>
        @if ($rt)<small class="muted" data-live="connecting">Live</small>@endif
        <a class="btn" href="{{ route('shop.cart', $shop->settings['slug']) }}">Your order{{ ! empty($cartCount) ? " ({$cartCount})" : '' }}</a>
    </nav>
</header>
<main>
    @if (session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
    @if (session('error'))<div class="notice error" role="alert">{{ session('error') }}</div>@endif
    @if ($errors->any())<div class="notice error" role="alert">{{ implode(' ', $errors->all()) }}</div>@endif
    @yield('content')
</main>
<footer class="muted"><small>{{ $shop->branch->name }}</small>
    <select class="theme-picker" aria-label="Theme" onchange="var t=this.value;var d=document.documentElement;if(t==='system'){delete d.dataset.theme;try{localStorage.removeItem('shop-theme')}catch(e){}}else{d.dataset.theme=t;try{localStorage.setItem('shop-theme',t)}catch(e){}}">
        <option value="system">Theme: System</option><option value="light">Theme: Light</option><option value="dark">Theme: Dark</option>
    </select>
    <script>try{var s=document.querySelector('.theme-picker'),v=localStorage.getItem('shop-theme');if(v)s.value=v;}catch(e){}</script>
</footer>
</div>
@if ($rt) @vite('resources/js/storefront.js') @endif
</body>
</html>
