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
<link rel="stylesheet" href="{{ asset('css/seslabs/new-look.css') }}?v={{ @filemtime(public_path('css/seslabs/new-look.css')) }}">
@if ($menu = \App\Support\Look::menuColor())
    <style>:root.zx-new, :root.zx-new.dark { --zx-menu: {{ $menu }}; }</style>
@endif
{{-- Lists keep one line per row; a value with more on hover (a breakdown, a full name) is underlined with dots. --}}
<style>.fi-ta-cell .fi-ta-text-item [x-tooltip] { text-decoration: underline dotted; text-underline-offset: 3px; text-decoration-color: color-mix(in srgb, currentColor 45%, transparent); cursor: help; }</style>
