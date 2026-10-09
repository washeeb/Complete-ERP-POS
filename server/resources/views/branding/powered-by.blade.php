@if ($text = \App\Domain\Branding\Branding::poweredBy())
    <div class="erp-powered-by" style="display:flex;justify-content:center;align-items:center;gap:6px;padding:14px 0 10px;font-size:12px;opacity:.65">
        <img src="{{ config('brand.logo') }}" alt="" height="12" style="height:12px;width:auto">
        @if ($site = config('brand.website'))<a href="{{ $site }}" target="_blank" rel="noopener" style="color:inherit">{{ $text }}</a>@else<span>{{ $text }}</span>@endif
    </div>
@endif
