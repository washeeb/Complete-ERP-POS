{{-- Top of the side look's menu: the company's logo — its own version for dark backgrounds when it uploaded one,
     otherwise the normal logo on a small white card so dark lettering still reads — or, with no logo yet, its square
     icon and name. Links home. --}}
@php
    $b = \App\Domain\Branding\Branding::forTenant(\App\Models\Tenant::find(auth()->user()?->tenant_id));
    $hasDark = $b['has_logo'] && $b['logo_dark_url'] !== $b['logo_url'];
@endphp
<a href="{{ \App\Support\Look::homeUrl() }}" class="zx-sb-brand" title="{{ $b['company'] }}">
    @if ($b['has_logo'])
        <span @class(['zx-sb-logo', 'zx-sb-chip' => ! $hasDark])><img src="{{ $hasDark ? $b['logo_dark_url'] : $b['logo_url'] }}" alt="{{ $b['company'] }}"></span>
        <img src="{{ $b['icon_url'] }}" alt="" class="zx-sb-icon zx-sb-folded">
    @else
        <img src="{{ $b['icon_url'] }}" alt="" class="zx-sb-icon">
        <span class="zx-sb-name">{{ $b['company'] }}</span>
    @endif
</a>
