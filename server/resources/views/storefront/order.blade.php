@extends('storefront.layout', ['title' => 'Order '.$order->number.' · '.$shop->name])
@section('content')
    <h2>Thank you — order {{ $order->number }}</h2>
    <p>Status: <strong>{{ ['confirmed' => 'Received', 'ready' => 'Ready for pickup', 'fulfilled' => 'Collected', 'cancelled' => 'Cancelled'][$order->status] ?? ucfirst($order->status) }}</strong></p>
    <p class="muted">Pickup at {{ $order->branch->name }}. {{ $text['pickup_note'] }}</p>
    <table>
        <thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Total</th></tr></thead>
        <tbody>
        @foreach ($order->lines as $l)
            <tr><td>{{ $l->name }}</td><td class="num">{{ rtrim(rtrim($l->qty, '0'), '.') }}</td><td class="num">{{ \App\Support\Money::display($l->line_total_minor, $order->currency_code) }}</td></tr>
        @endforeach
        </tbody>
        <tfoot><tr><th colspan="2">Total{{ $order->payment_status === 'paid' ? ' (paid)' : ' (pay at pickup)' }}</th><th class="num">{{ \App\Support\Money::display($order->total_minor, $order->currency_code) }}</th></tr></tfoot>
    </table>
@endsection
