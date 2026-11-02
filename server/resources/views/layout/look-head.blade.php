{{-- The look chosen for this person (App\Support\Look), set on the page's root before anything is drawn, and the
     new look's styles (scoped to html.zx-new, so the classic look is untouched). The menu colour is the company's own
     when it set one (Company → Branding), placed after the styles so it wins in light and dark mode; otherwise the
     styles take a dark shade of the brand colour. --}}
<script>document.documentElement.classList.add(...@js(explode(' ', \App\Support\Look::htmlClasses())));</script>
<link rel="stylesheet" href="{{ asset('css/seslabs/new-look.css') }}?v={{ @filemtime(public_path('css/seslabs/new-look.css')) }}">
@if ($menu = \App\Support\Look::menuColor())
    <style>:root.zx-new, :root.zx-new.dark { --zx-menu: {{ $menu }}; }</style>
@endif
