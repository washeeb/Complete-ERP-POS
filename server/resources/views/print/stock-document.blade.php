@extends('print.layout')
{{-- One layout for stock documents: goods received, transfers, stock requests, purchase requests. --}}
@section('title', $title.' '.$number)
@section('heading')
    <h1>{{ mb_strtoupper($title) }}</h1>
    <div><strong>{{ $number }}</strong></div>
    <div class="small">{{ $date }}@if ($status) · {{ $status }}@endif</div>
@endsection
@section('content')
    <div class="parties">
        @foreach ($boxes as [$label, $lines])
            <div class="box"><div class="small muted">{{ $label }}</div>@foreach ($lines as $i => $line)@if ($i === 0)<strong>{{ $line }}</strong>@else<br><span class="small">{{ $line }}</span>@endif @endforeach</div>
        @endforeach
    </div>
    <table>
        <thead><tr>@foreach ($columns as [$head, $num])<th @class(['num' => $num])>{{ $head }}</th>@endforeach</tr></thead>
        <tbody>@foreach ($rows as $row)
            <tr>@foreach ($row as $i => $cell)<td @class(['num' => $columns[$i][1]])>{!! $cell !!}</td>@endforeach</tr>
        @endforeach</tbody>
    </table>
    @if ($note)<p>{!! nl2br(e($note)) !!}</p>@endif
    <div class="parties" style="margin-top:28px">
        @foreach ($signatures as [$label, $name])
            <div>{{ $label }}: <strong>{{ $name }}</strong><br><br>Signature ______________________</div>
        @endforeach
    </div>
@endsection
