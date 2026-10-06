@extends('storefront.layout')
@section('content')
    <h2>{{ $text['headline'] }}</h2>
    <p class="muted">{{ $text['intro'] }}</p>
    <form class="inline" method="get" action="{{ route('shop.index', $shop->settings['slug']) }}" role="search">
        <input type="search" name="q" value="{{ $q }}" placeholder="Search products" aria-label="Search products">
        <select name="category" aria-label="Category">
            <option value="">All categories</option>
            @foreach ($categories as $c)<option value="{{ $c->id }}" @selected(request('category') === $c->id)>{{ $c->name }}</option>@endforeach
        </select>
        <button type="submit">Search</button>
    </form>
    <p></p>
    @if ($products->isEmpty())
        <p class="muted">No products found.</p>
    @endif
    <div class="grid">
        @foreach ($products as $p)
            @php($out = $p['available'] !== null && $p['available'] <= 0)
            <div class="card {{ $out ? 'out' : '' }}" data-product="{{ $p['id'] }}">
                <strong>{{ $p['name'] }}</strong>
                @if ($p['category'])<small class="muted">{{ $p['category'] }}</small>@endif
                <span class="price" data-price>{{ \App\Support\Money::display($p['price_minor'], $shop->priceList->currency_code) }}</span>
                <small class="muted" data-stock>{{ $p['available'] === null ? '' : ($out ? 'Out of stock' : $p['available'].' available') }}</small>
                <form method="post" action="{{ route('shop.add', $shop->settings['slug']) }}">
                    @csrf
                    <input type="hidden" name="product_id" value="{{ $p['id'] }}">
                    <button type="submit" @disabled($out)>Add</button>
                </form>
            </div>
        @endforeach
    </div>
@endsection
