{{-- New look: the round icon at the left of each page's banner — the page's own menu icon, or the person's photo on
     their profile. --}}
@if (request()->routeIs('filament.admin.auth.profile'))
    <span class="zx-hero-ic zx-hero-photo" aria-hidden="true"><img src="{{ filament()->getUserAvatarUrl(auth()->user()) }}" alt=""></span>
@elseif ($icon = \App\Support\Look::pageIcon())
    <span class="zx-hero-ic" aria-hidden="true"><x-filament::icon :icon="$icon" /></span>
@endif
