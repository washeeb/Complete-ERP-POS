@extends('storefront.layout', ['title' => 'Your order · '.$shop->name])
@section('content')
    <h2>Your order</h2>
    @if ($lines === [])
        <p class="muted">Nothing here yet. <a href="{{ route('shop.index', $shop->settings['slug']) }}">Browse products</a></p>
    @else
        <form method="post" action="{{ route('shop.update', $shop->settings['slug']) }}">
            @csrf
            <table>
                <thead><tr><th>Item</th><th class="num">Price</th><th class="num">Qty</th><th class="num">Total</th></tr></thead>
                <tbody>
                @foreach ($lines as $l)
                    <tr>
                        <td>{{ $l['name'] }} @if ($l['short'])<br><small class="notice error">Only {{ max(0, $l['available']) }} available</small>@endif</td>
                        <td class="num">{{ \App\Support\Money::display($l['price_minor'], $shop->priceList->currency_code) }}</td>
                        <td class="num"><input type="number" name="qty[{{ $l['id'] }}]" value="{{ $l['qty'] }}" min="0" max="99" style="width:5em" aria-label="Quantity of {{ $l['name'] }}"></td>
                        <td class="num">{{ \App\Support\Money::display($l['total_minor'], $shop->priceList->currency_code) }}</td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot><tr><th colspan="3">Total (pay at pickup)</th><th class="num">{{ \App\Support\Money::display($total, $shop->priceList->currency_code) }}</th></tr></tfoot>
            </table>
            <p><button type="submit">Update quantities</button></p>
        </form>

        <h3>Your details</h3>
        <form method="post" action="{{ route('shop.checkout', $shop->settings['slug']) }}">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <label for="name">Name</label><input id="name" name="name" value="{{ old('name') }}" required maxlength="120" autocomplete="name">
            <label for="phone">Phone</label><input id="phone" name="phone" value="{{ old('phone') }}" required autocomplete="tel" inputmode="tel">
            <label for="email">Email (optional)</label><input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email">
            <label for="note">Note (optional)</label><textarea id="note" name="note" maxlength="500">{{ old('note') }}</textarea>
            <div class="hp" aria-hidden="true"><label for="website">Website</label><input id="website" name="website" tabindex="-1" autocomplete="off"></div>
            <p class="muted">Pickup at {{ $shop->branch->name }}. {{ $text['pickup_note'] }}</p>
            <button type="submit">Place order</button>
        </form>
    @endif
@endsection
