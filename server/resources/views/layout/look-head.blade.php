{{-- The look chosen for this person (App\Support\Look), set on the page's root before anything is drawn, and the
     new look's styles (scoped to html.zx-new, so the classic look is untouched). The menu colour is the company's own
     when it set one (Company → Branding), placed after the styles so it wins in light and dark mode; otherwise the
     styles take a dark shade of the brand colour.
     Their preferences (App\Support\UserPrefs): compact lists, and light/dark — applied once on each computer whenever
     it changes, so the light/dark switcher in their menu still works for that computer afterwards. --}}
@php($prefs = \App\Support\UserPrefs::for(auth()->user()))
<script>
    document.documentElement.classList.add(...@js(array_filter([...explode(' ', \App\Support\Look::htmlClasses()), $prefs['density'] === 'compact' ? 'zx-compact' : null])));
    (() => {
        const theme = @js($prefs['theme']);
        try {
            if (localStorage.getItem('zx.theme') === theme) return;
            localStorage.setItem('zx.theme', theme);
            localStorage.setItem('theme', theme);
            document.documentElement.classList.toggle('dark', theme === 'dark' || (theme === 'system' && matchMedia('(prefers-color-scheme: dark)').matches));
        } catch {}
    })();
</script>
<script>
    // Lists (new look): a slim page header once scrolled; rows per page remembered on this computer, per list.
    (() => {
        const root = document.documentElement
        if (!root.classList.contains('zx-new')) return
        let slim = false, queued = false
        // Where things pin: the bottom of the bars across the top of the window (the top bar, and the tab band when it
        // is there). Only bars count: the side menu is also a "Main menu" but runs down the side, so anything taller
        // than a quarter of the window is left out, and the result is capped (owner 2026-11-08: in the side-menu look
        // the whole menu was measured, pinning the title halfway down and squashing lists).
        const stick = () => {
            const bars = [...document.querySelectorAll('nav[aria-label="Main menu"], .fi-topbar-ctn')].map((e) => e.getBoundingClientRect())
                .filter((r) => r.height > 0 && r.height < innerHeight / 4 && r.top < 160 && r.bottom > 0)
            const bottom = Math.min(160, Math.max(0, ...bars.map((r) => r.bottom)))
            if (bottom > 0) root.style.setProperty('--zx-stick', bottom + 'px')
        }
        const scrolled = () => {
            queued = false
            const room = root.scrollHeight - innerHeight
            // Hysteresis, and only with room to spare, so shrinking the header never bounces the page back.
            if (!slim && scrollY > 60 && room > 140) { slim = true; root.classList.add('zx-slim') }
            else if (slim && scrollY < 8) { slim = false; root.classList.remove('zx-slim') }
            if (slim) stick()
        }
        addEventListener('scroll', () => { if (!queued) { queued = true; requestAnimationFrame(scrolled) } }, { passive: true })
        // The first column gets an edge once a wide list is scrolled sideways.
        document.addEventListener('scroll', (e) => { const c = e.target
            if (c instanceof Element && c.classList.contains('fi-ta-content-ctn')) c.classList.toggle('zx-sideways', c.scrollLeft > 0) }, { capture: true, passive: true })

        const sel = '.fi-pagination-records-per-page-select select'
        const key = (s) => 'zx.perPage:' + location.pathname + ':' + [...document.querySelectorAll(sel)].indexOf(s)
        document.addEventListener('change', (e) => {
            if (e.target instanceof Element && e.target.matches(sel) && !e.target.dataset.zxRestoring) try { localStorage.setItem(key(e.target), e.target.value) } catch {}
        }, true)
        const restore = () => document.querySelectorAll(sel).forEach((s) => {
            let v = null
            try { v = localStorage.getItem(key(s)) } catch {}
            if (!v || v === s.value || ![...s.options].some((o) => o.value === v)) return
            s.dataset.zxRestoring = '1'; s.value = v; s.dispatchEvent(new Event('change', { bubbles: true })); delete s.dataset.zxRestoring
        })
        document.addEventListener('livewire:navigated', () => setTimeout(restore))
        addEventListener('load', () => { restore(); stick() })
        addEventListener('resize', stick)
    })();
</script>
<link rel="stylesheet" href="{{ asset('css/seslabs/new-look.css') }}?v={{ @filemtime(public_path('css/seslabs/new-look.css')) }}">
@if ($menu = \App\Support\Look::menuColor())
    <style>:root.zx-new, :root.zx-new.dark { --zx-menu: {{ $menu }}; }</style>
@endif
{{-- Lists keep one line per row; a value with more on hover (a breakdown, a full name) is underlined with dots. --}}
<style>.fi-ta-cell .fi-ta-text-item [x-tooltip] { text-decoration: underline dotted; text-underline-offset: 3px; text-decoration-color: color-mix(in srgb, currentColor 45%, transparent); cursor: help; }</style>
