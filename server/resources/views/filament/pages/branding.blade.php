<x-filament-panels::page>
    @php($b = $this->brand())
    @php($assets = $this->assets())
    @php($color = $color ?: $b['color'])
    @php($on = \App\Domain\Branding\Branding::onColor(preg_match('/^#[0-9A-Fa-f]{6}$/', $color) ? $color : $b['color']))

    <x-filament::section heading="Logo and icon" description="Shown on the back office, the tills, receipts, invoices and PDFs, and the online shop. PNG with a transparent background, or SVG, work best. Images are resized and cleaned when you upload them.">
        <div class="br-grid">
            @foreach (\App\Models\BrandAsset::KINDS as $kind => $label)
                @php($a = $assets[$kind])
                <div class="br-card">
                    <div class="br-label">{{ $label }}</div>
                    <div class="br-preview {{ $kind === 'logo_dark' ? 'br-dark' : '' }} {{ $kind === 'icon' ? 'br-square' : '' }}">
                        @if ($a)
                            <img src="/brand/{{ \App\Models\Tenant::find(auth()->user()->tenant_id)->slug }}/{{ $kind }}?v={{ substr($a->sha256, 0, 12) }}" alt="{{ $label }}">
                        @elseif ($kind === 'icon')
                            <img src="{{ $b['icon_url'] }}" alt="Monogram placeholder">
                        @else
                            <span class="br-empty">No logo yet{{ $kind === 'logo_dark' ? ' — the normal logo is used' : '' }}</span>
                        @endif
                    </div>
                    <div class="br-hint">
                        @switch($kind)
                            @case('logo') Wide logo with the name, at least 600 pixels wide. @break
                            @case('logo_dark') A light version for dark mode and dark headers (optional). @break
                            @case('icon') The symbol alone, square, at least 256 × 256. Until you add one, your initials are used. @break
                        @endswitch
                        @if ($a)<br><span class="br-muted">{{ ['image/svg+xml' => 'SVG', 'image/png' => 'PNG', 'image/jpeg' => 'JPEG', 'image/webp' => 'WebP'][$a->mime] ?? $a->mime }} · {{ $a->width }}×{{ $a->height }} · {{ number_format($a->bytes / 1024) }} KB</span>@endif
                    </div>
                    <div class="br-actions">
                        <label class="br-upload">
                            <input type="file" wire:model="files.{{ $kind }}" accept="image/png,image/jpeg,image/webp,image/svg+xml,.svg" aria-label="Upload {{ $label }}">
                            <span>{{ $a ? 'Replace' : 'Upload' }}</span>
                        </label>
                        @if ($a)<x-filament::link tag="button" color="danger" wire:click="remove('{{ $kind }}')" wire:confirm="Remove this image?">Remove</x-filament::link>@endif
                        <span wire:loading wire:target="files.{{ $kind }}" class="br-muted">Uploading…</span>
                    </div>
                    @error("files.{$kind}")<p class="br-bad">{{ $message }}</p>@enderror
                </div>
            @endforeach
        </div>
    </x-filament::section>

    <x-filament::section heading="Colour and receipts">
        <div class="br-row">
            <label class="br-field">Brand colour
                <span class="br-colour">
                    <input type="color" wire:model.live="color" value="{{ $color }}" aria-label="Pick the colour">
                    <x-filament::input.wrapper><x-filament::input type="text" wire:model.live.debounce.400ms="color" placeholder="{{ config('brand.color') }}" maxlength="7" /></x-filament::input.wrapper>
                </span>
            </label>
            @if (($s = $this->suggestion()) && strcasecmp($s, $color) !== 0)
                <x-filament::button color="gray" size="sm" wire:click="useSuggestion"><span style="display:inline-block;width:12px;height:12px;border-radius:3px;background:{{ $s }};vertical-align:middle"></span> Use {{ $s }} from your logo</x-filament::button>
            @endif
        </div>
        @if ($note = $this->contrastNote())<p class="br-warn">{{ $note }}</p>@endif
        <label class="br-check"><input type="checkbox" wire:model="receiptLogo"> Print the logo at the top of till receipts (thermal printers print it in black and white)</label>
        <div style="margin-top:12px"><x-filament::button wire:click="saveSettings">Save colour and receipts</x-filament::button></div>
    </x-filament::section>

    <x-filament::section heading="Preview" description="How your brand looks to staff and customers.">
        <div class="br-previews">
            <figure>
                <div class="br-mock-head" style="background:{{ $color }};color:{{ $on }}">
                    @if ($b['logo_dark_url'])<img src="{{ $b['logo_dark_url'] }}" alt="" style="height:26px">@else<img src="{{ $b['icon_url'] }}" alt="" style="height:26px;border-radius:6px"> <strong>{{ $b['company'] }}</strong>@endif
                    <span class="br-mock-btn" style="background:{{ $on }};color:{{ $color }}">New sale</span>
                </div>
                <figcaption>Till and back-office accent</figcaption>
            </figure>
            <figure>
                <div class="br-receipt">
                    @if ($b['logo_url'] && $receiptLogo)<img src="{{ $b['logo_url'] }}" alt="" class="br-receipt-logo">@endif
                    <strong>{{ $b['company'] }}</strong>
                    <div>Receipt HQ-T01A-000123</div>
                    <div class="br-dash"></div>
                    <div class="br-line"><span>Milo 400g</span><span>58.00</span></div>
                    <div class="br-line"><strong>TOTAL</strong><strong>58.00</strong></div>
                    <div class="br-dash"></div>
                    <div>Thank you for shopping with us.</div>
                    @if ($b['powered_by'])<div class="br-muted" style="font-size:10px">{{ $b['powered_by'] }}</div>@endif
                </div>
                <figcaption>Till receipt (80 mm)</figcaption>
            </figure>
            <figure>
                <div class="br-invoice">
                    <div class="br-inv-head" style="border-color:{{ $color }}">
                        <div>@if ($b['logo_url'])<img src="{{ $b['logo_url'] }}" alt="" style="height:34px">@else<strong>{{ $b['company'] }}</strong>@endif</div>
                        <div style="text-align:right"><strong style="color:{{ $color }}">INVOICE</strong><div class="br-muted">INV-HQ-000045</div></div>
                    </div>
                    <div class="br-bar"></div><div class="br-bar" style="width:70%"></div><div class="br-bar" style="width:85%"></div>
                </div>
                <figcaption>Invoices, statements and PDFs</figcaption>
            </figure>
        </div>
        @if (\App\Domain\Branding\Branding::whiteLabel())
            <p class="br-muted">Your licence includes white-label: “Powered by {{ config('brand.name') }}” is not shown anywhere.</p>
        @else
            <p class="br-muted">A small “Powered by {{ config('brand.name') }}” stays in footers and at the end of receipts. A white-label licence removes it.</p>
        @endif
    </x-filament::section>

    <style>
        .br-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:16px; }
        .br-card { border:1px solid color-mix(in srgb, currentColor 10%, transparent); border-radius:12px; padding:14px; display:grid; gap:8px; }
        .br-label { font-weight:600; font-size:.9rem; }
        .br-preview { height:110px; border-radius:10px; display:grid; place-items:center; padding:12px; background:repeating-conic-gradient(#f3f4f6 0% 25%, #fff 0% 50%) 50% / 16px 16px; }
        .br-preview.br-dark { background:#111827; } .br-preview img { max-height:86px; max-width:100%; object-fit:contain; }
        .br-preview.br-square img { width:86px; height:86px; border-radius:16px; }
        .br-empty { font-size:.8rem; color:#6b7280; } .br-dark .br-empty { color:#9ca3af; }
        .br-hint { font-size:.8rem; opacity:.75; } .br-muted { opacity:.65; font-size:.8rem; } .br-bad { color:rgb(220 38 38); font-size:.85rem; }
        .br-warn { margin-top:8px; padding:8px 10px; border-radius:8px; background:color-mix(in srgb, rgb(234 179 8) 18%, transparent); font-size:.85rem; }
        .br-actions { display:flex; gap:12px; align-items:center; }
        .br-upload { position:relative; display:inline-flex; align-items:center; padding:6px 12px; border-radius:8px; font-size:.85rem; font-weight:600; cursor:pointer; border:1px solid color-mix(in srgb, currentColor 18%, transparent); }
        .br-upload input { position:absolute; inset:0; opacity:0; cursor:pointer; } .br-upload:focus-within { outline:2px solid rgb(59 130 246); }
        .br-row { display:flex; gap:16px; align-items:flex-end; flex-wrap:wrap; } .br-field { display:grid; gap:6px; font-size:.9rem; font-weight:600; }
        .br-colour { display:flex; gap:8px; align-items:center; } .br-colour input[type=color] { width:44px; height:38px; border:0; background:none; padding:0; cursor:pointer; }
        .br-check { display:flex; gap:8px; align-items:center; margin-top:12px; font-size:.9rem; }
        .br-previews { display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:18px; } figure { margin:0; } figcaption { font-size:.8rem; opacity:.7; margin-top:6px; text-align:center; }
        .br-mock-head { display:flex; align-items:center; gap:10px; padding:12px 14px; border-radius:10px; font-size:.9rem; }
        .br-mock-btn { margin-left:auto; padding:4px 10px; border-radius:6px; font-weight:700; font-size:.8rem; }
        .br-receipt { width:220px; margin:0 auto; background:#fff; color:#111; padding:14px 12px; font:12px/1.4 ui-monospace,Consolas,monospace; text-align:center; box-shadow:0 1px 6px rgb(0 0 0 / .15); border-radius:4px; }
        .br-receipt-logo { max-width:150px; max-height:56px; filter:grayscale(1) contrast(1.4); margin-bottom:6px; }
        .br-dash { border-top:1px dashed #999; margin:6px 0; } .br-line { display:flex; justify-content:space-between; }
        .br-invoice { background:#fff; color:#111; padding:14px; border-radius:6px; box-shadow:0 1px 6px rgb(0 0 0 / .15); font-size:12px; }
        .br-inv-head { display:flex; justify-content:space-between; align-items:center; border-bottom:2px solid; padding-bottom:8px; margin-bottom:10px; }
        .br-bar { height:7px; background:#e5e7eb; border-radius:4px; margin:6px 0; }
    </style>
</x-filament-panels::page>
