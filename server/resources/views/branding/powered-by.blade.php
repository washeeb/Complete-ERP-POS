@if ($text = \App\Domain\Branding\Branding::poweredBy())
    {{-- The product's lettermark: the normal one on light themes, the light-lettered one on dark themes (Filament puts .dark on <html>). --}}
    <style>
        .erp-pb-dark { display: none; }
        .dark .erp-pb-light { display: none; } .dark .erp-pb-dark { display: inline; }
    </style>
    <div class="erp-powered-by" style="display:flex;justify-content:center;align-items:center;gap:6px;padding:14px 0 10px;font-size:12px;opacity:.75">
        <img class="erp-pb-light" src="{{ config('brand.logo') }}" alt="" height="14" style="height:14px;width:auto">
        <img class="erp-pb-dark" src="{{ config('brand.logo_dark') }}" alt="" height="14" style="height:14px;width:auto">
        @if ($site = config('brand.website'))<a href="{{ $site }}" target="_blank" rel="noopener" style="color:inherit">{{ $text }}</a>@else<span>{{ $text }}</span>@endif
    </div>
@endif
