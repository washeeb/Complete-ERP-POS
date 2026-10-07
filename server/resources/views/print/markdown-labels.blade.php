@extends('print.layout')
@section('title', 'Markdown stickers')
@section('heading')<h1>MARKDOWN STICKERS</h1>@endsection
@section('content')
    <div class="labels">
        @foreach ($labels as $m)
            @for ($i = 0; $i < $m->labels; $i++)
                <div class="label">
                    <div class="lname">{{ $m->product->name }}</div>
                    <div class="lprice"><s>{{ \App\Support\Money::display($m->list_price_minor, $currency) }}</s> <strong>{{ \App\Support\Money::display($m->price_minor, $currency) }}</strong></div>
                    <div class="lsmall">{{ $m->percent_bp / 100 }} % off · use by {{ $m->valid_until->format('j M Y') }}</div>
                    {!! \App\Support\Ean13::svg($m->code, 34) !!}
                </div>
            @endfor
        @endforeach
    </div>
    <style>
        .labels { display: grid; grid-template-columns: repeat(3, 1fr); gap: 4mm; }
        .label { border: 1px dashed #bbb; padding: 3mm; text-align: center; page-break-inside: avoid; }
        .label svg { width: 100%; height: auto; max-height: 22mm; }
        .lname { font-size: 9pt; font-weight: 600; } .lprice { font-size: 12pt; } .lprice s { color: #888; font-size: 9pt; } .lsmall { font-size: 7pt; }
    </style>
@endsection
