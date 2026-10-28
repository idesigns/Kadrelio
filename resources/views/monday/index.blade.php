@extends('layouts.app')
@section('title', 'Dane z Monday')
@section('content')
<header class="board-header"><div><h1>Dane z Monday</h1><p>Obie firmy, pełna historia i wszystkie kolumny z eksportu.</p></div><a class="button button-secondary" href="{{ route('dashboard') }}">Przejdź do firm <x-icon name="arrow" :size="17" /></a></header>
<div class="board-tabs"><span class="tab active"><x-icon name="board" :size="16" /> Wszystkie tablice</span><span class="tab-end">Tablice: {{ $boards->count() }} · Wpisy: {{ number_format($boards->sum('items_count'), 0, ',', ' ') }}</span></div>
<div class="monday-overview">
@forelse($boards->groupBy(fn ($sourceBoard) => $sourceBoard->company?->name ?? 'Pozostałe tablice konta') as $name => $companyBoards)
<section class="profile-section"><h2>{{ $name }}</h2><div class="table-scroll"><table class="simple-table monday-board-list"><thead><tr><th>Tablica</th><th>Wpisy</th><th>Do sprawdzenia</th><th></th></tr></thead><tbody>
@foreach($companyBoards as $sourceBoard)
<tr><td><a class="person-cell" href="{{ route('monday.boards.show', $sourceBoard) }}"><x-icon name="board" :size="19" /><span>{{ $sourceBoard->name }}</span></a></td><td>{{ number_format($sourceBoard->items_count, 0, ',', ' ') }}</td><td>@if($sourceBoard->review_count)<a class="tag monday-review" href="{{ route('monday.boards.show', [$sourceBoard, 'review' => 1]) }}">{{ $sourceBoard->review_count }} do sprawdzenia</a>
@else
<span class="muted">-</span>
@endif
</td><td><a class="text-link" href="{{ route('monday.boards.show', $sourceBoard) }}">Otwórz <x-icon name="arrow" :size="15" /></a></td></tr>

@endforeach

</tbody></table></div></section>

@empty

<div class="empty-state"><x-icon name="board" :size="40" /><h2>Tablice pojawią się po imporcie</h2><p>Zobaczysz tutaj dane firm, do których masz dostęp.</p></div>

@endforelse

@foreach($reports as $entry)
@php($report = $entry['report'])
<details class="employee-edit"><summary><x-icon name="check" :size="19" /> Raport importu · {{ $entry['batch']->source_key }}</summary><div class="monday-report"><dl class="detail-list">
<div><dt>Zgodność liczby wpisów</dt><dd>{{ $report['balanced'] ? 'Zgodna z eksportem' : 'Wymaga sprawdzenia' }}</dd></div>
<div><dt>Komentarze</dt><dd>{{ number_format($report['actual']['updates'], 0, ',', ' ') }} · Bez powiązania: {{ $report['unlinked_updates'] }}</dd></div>
<div><dt>Oryginalne pliki</dt><dd>{{ $report['available_assets'] }} z {{ number_format($report['assets'], 0, ',', ' ') }} dołączonych z assets</dd></div>
<div><dt>Historia w obiegu kadrowym</dt><dd>{{ number_format($report['workflows'], 0, ',', ' ') }} dokumentów</dd></div>
<div><dt>Wiadomości i podpisy</dt><dd>Import nie uruchamia wysyłki ani nowych podpisów.</dd></div>
</dl>
<details class="monday-source-details"><summary>Zespół z eksportu (bez nadawania dostępów)</summary>
@foreach($entry['batch']->team ?? [] as $table)
<div class="table-scroll"><table class="simple-table"><thead><tr>@foreach($table['headers'] as $label)<th>{{ $label }}</th>
@endforeach
</tr></thead><tbody>@foreach($table['rows'] as $row)<tr>@foreach($table['headers'] as $column => $label)<td>{{ $row['values'][$column] ?? '-' }}</td>
@endforeach
</tr>
@endforeach
</tbody></table></div>

@endforeach

</details></div></details>

@endforeach

</div>

@endsection

