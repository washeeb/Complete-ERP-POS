{{-- Opening a page (owner 2026-11-08): the ZionEX loader while the next page comes from the server, so a slow line shows
     that something is happening. Only after half a second, so quick pages never flash it. Uses the browser's Navigation
     API where there is one (Edge, Chrome, ZionEX Suite): it says whether a link is a download or only changes the
     address of this page, which must not show it. Older browsers: links and forms. Never blocks a click. --}}
<div id="zx-page-loader" hidden role="status" aria-live="polite">
    <div class="zx-pl-card">@include('branding.loader', ['size' => 44])<span>Opening…</span></div>
</div>
<style>
    :root { --zx-ink: #031E4C; --zx-mist: #BFEDEA; --zx-teal: #06AAAA; }
    .dark { --zx-ink: #e5e7eb; --zx-mist: #1e4e5a; }
    .zx-loader-wrap { display: inline-flex; line-height: 0; }
    #zx-page-loader { position: fixed; inset: 0; z-index: 70; display: grid; place-items: center; pointer-events: none;
        background: rgb(15 23 42 / 0); transition: background .25s ease; }
    #zx-page-loader.on { background: rgb(15 23 42 / .08); }
    #zx-page-loader .zx-pl-card { display: grid; justify-items: center; gap: 10px; padding: 20px 28px; border-radius: 16px;
        background: rgb(255 255 255 / .92); box-shadow: 0 10px 30px rgb(3 30 76 / .18); color: #334155; font-size: .875rem;
        opacity: 0; transform: translateY(6px) scale(.98); transition: opacity .2s ease, transform .2s ease; backdrop-filter: blur(6px); }
    #zx-page-loader.on .zx-pl-card { opacity: 1; transform: none; }
    .dark #zx-page-loader .zx-pl-card { background: rgb(17 24 39 / .92); color: #cbd5e1; }
    @media (prefers-reduced-motion: reduce) { #zx-page-loader, #zx-page-loader .zx-pl-card { transition: none; } }
    @media print { #zx-page-loader { display: none !important; } }
</style>
<script>
    (() => {
        const box = document.getElementById('zx-page-loader');
        if (!box || box.dataset.ready) return;
        box.dataset.ready = '1';
        let wait = null, safety = null;
        const hide = () => { clearTimeout(wait); clearTimeout(safety); box.classList.remove('on'); box.hidden = true; };
        // Files (exports, reports, prints) open without leaving the page: no loader for them.
        const isFile = (url) => /\/(download|export|print|pdf)\b|\.(csv|xlsx?|pdf|zip)(\?|$)/i.test(url.pathname + url.search);
        const show = (url) => {
            if (url.origin !== location.origin || isFile(url)) return;
            clearTimeout(wait);
            wait = setTimeout(() => { box.hidden = false; requestAnimationFrame(() => box.classList.add('on')); }, 500);
            clearTimeout(safety);
            safety = setTimeout(hide, 15000); // a file the server sends instead of a page: the page stays, so take it away
        };
        addEventListener('pageshow', hide); // back/forward from the browser's memory
        addEventListener('pagehide', () => clearTimeout(wait));
        if ('navigation' in window) {
            navigation.addEventListener('navigate', (e) => {
                // Same page (an address change by the page itself, a #section), a download, or a reload stopped: nothing to show.
                if (e.hashChange || e.destination.sameDocument || e.downloadRequest !== null) return;
                show(new URL(e.destination.url));
            });
            return;
        }
        document.addEventListener('click', (e) => {
            const a = e.target.closest && e.target.closest('a[href]');
            if (!a || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
            if ((a.target && a.target !== '_self') || a.hasAttribute('download') || a.getAttribute('href').startsWith('#')) return;
            show(new URL(a.href, location.href));
        });
        document.addEventListener('submit', (e) => { if (!e.defaultPrevented && e.target.action) show(new URL(e.target.action, location.href)); });
    })();
</script>
