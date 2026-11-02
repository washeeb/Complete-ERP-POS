{{-- The look chosen for this person (App\Support\Look), set on the page's root before anything is drawn, and the
     new look's styles (scoped to html.zx-new, so the classic look is untouched). --}}
<script>document.documentElement.classList.add(...@js(explode(' ', \App\Support\Look::htmlClasses())));</script>
<link rel="stylesheet" href="{{ asset('css/seslabs/new-look.css') }}?v={{ @filemtime(public_path('css/seslabs/new-look.css')) }}">
