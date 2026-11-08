{{-- The ZionEX loader, inlined so the page's light/dark colours reach it: @include('branding.loader', ['size' => 32]). --}}
<span class="zx-loader-wrap" aria-hidden="true">{!! \App\Support\Loader::svg((int) ($size ?? 48)) !!}</span>
