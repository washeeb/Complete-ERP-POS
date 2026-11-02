{{-- Profile page, left: its parts (a click scrolls to the card; the one in view is marked) and where to get help. --}}
@php
    $parts = [
        ['zx-you', 'heroicon-o-user', 'Your profile', 'Photo, name and e-mail address'],
        ['zx-look', 'heroicon-o-paint-brush', 'Look', 'How the back office looks for you'],
        ['zx-prefs', 'heroicon-o-adjustments-horizontal', 'Preferences', 'Home page, lists, light or dark, dates'],
        ['zx-notify', 'heroicon-o-bell', 'Notifications', 'What reaches you, and how'],
        ['zx-security', 'heroicon-o-shield-check', 'Security', 'Password, two-factor sign-in, passkeys'],
    ];
    $phone = config('brand.support_phone');
    $email = config('brand.support_email');
@endphp
<div class="zx-pnav-wrap"
     x-data="{ on: 'zx-you', part: (id) => document.querySelector(`[data-zx-part='${id}']`) }"
     x-init="const o = new IntersectionObserver((es) => es.forEach((e) => { const id = e.target.dataset.zxPart; if (e.isIntersecting) on = id === 'zx-2fa' || id === 'zx-passkeys' ? 'zx-security' : id }), { rootMargin: '-35% 0px -55% 0px' });
             $nextTick(() => ['zx-you', 'zx-look', 'zx-prefs', 'zx-notify', 'zx-security', 'zx-2fa', 'zx-passkeys'].forEach((id) => part(id) && o.observe(part(id))))">
    <nav class="zx-pnav" aria-label="Parts of your profile">
        @foreach ($parts as [$id, $icon, $title, $text])
            <a href="#{{ $id }}" class="zx-pnav-a" x-bind:class="{ 'zx-on': on === '{{ $id }}' }"
               x-on:click.prevent="on = '{{ $id }}'; part('{{ $id }}')?.scrollIntoView({ behavior: 'smooth', block: 'start' })">
                <x-filament::icon :icon="$icon" class="zx-pnav-ic" />
                <span><strong>{{ $title }}</strong><small>{{ $text }}</small></span>
            </a>
        @endforeach
    </nav>
    @if ($phone || $email)
        <div class="zx-help">
            <x-filament::icon icon="heroicon-o-lifebuoy" class="zx-help-ic" />
            <strong>Need help?</strong>
            <p>{{ config('brand.vendor') }} support can help with your account.</p>
            @if ($phone)<a class="zx-help-btn" href="tel:{{ preg_replace('/\s+/', '', $phone) }}">Call {{ $phone }}</a>@endif
            @if ($email)<a class="zx-help-link" href="mailto:{{ $email }}">{{ $email }}</a>@endif
        </div>
    @endif
</div>
