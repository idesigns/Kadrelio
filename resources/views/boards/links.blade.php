@extends('layouts.app')
@section('title', 'Linki do formularzy')
@section('content')
<header class="board-header">
<div>
<div class="breadcrumb">{{ $company->name }}<span>/</span> Kadry i dokumenty</div>
<h1>Linki do formularzy <x-icon name="chevron" :size="20" />
</h1>
<p>Wszystkie formularze Twojej firmy w jednym miejscu.</p>
</div>
</header>
<div class="board-tabs">
<a class="tab active" href="{{ route('companies.board', [$company, 'links']) }}">
<x-icon name="board" :size="16" /> Tabela główna</a>
</div>
<div class="links-intro">
<x-icon name="link" :size="20" />
<p>Formularze pracownika działają bez logowania. Zgłoszenia HR są dostępne dla zespołu firmy po zalogowaniu. @can('update', $company)<a class="text-link" href="{{ route('companies.profile-preview', $company) }}" target="_blank" rel="noopener">Podgląd pełnego formularza danych</a>@endcan</p>
</div>
<div class="board-canvas">
<section class="board-group group-blue">
<div class="group-heading">
<h2>Lista formularzy</h2>
<span>6 formularzy</span>
</div>
<div class="table-scroll">
<table class="monday-table links-table">
<thead>
<tr>
<th>Formularz</th>
<th>Wypełnia</th>
<th>Link do formularza</th>
<th>
</th>
</tr>
</thead>
<tbody>
@php($forms = [['Zgłoszenie nowej umowy', 'Zespół HR', route('companies.records.create', [$company, 'contract'])], ['Formularz danych osobowych', 'Pracownik', route('forms.profile', $company->public_token)], ['Zgłoszenie aneksu', 'Zespół HR', route('companies.records.create', [$company, 'amendment'])], ['Rozwiązanie umowy', 'Zespół HR', route('companies.records.create', [$company, 'termination'])], ['Zmiana danych osobowych', 'Pracownik', route('forms.additional', [$company->public_token, 'data_change'])], ['Multisport - zgoda na potrącenia', 'Pracownik', route('forms.additional', [$company->public_token, 'multisport'])]])
@foreach($forms as [$label, $audience, $url])<tr>
<td>
<x-icon name="{{ $audience === 'Pracownik' ? 'users' : 'file-pen' }}" :size="17" /> {{ $label }}</td>
<td>
<span class="tag {{ $audience === 'Pracownik' ? 'tag-purple' : '' }}">{{ $audience }}</span>
</td>
<td>
@if($company->hasFormConfiguration())
<a class="cell-link" href="{{ $url }}" target="_blank" rel="noopener">{{ $url }}</a>
@else
<span class="muted">Formularz wymaga uzupełnienia ustawień firmy.</span>
@can('update', $company)<a class="text-link" href="{{ route('companies.edit', $company) }}">Uzupełnij ustawienia <x-icon name="arrow" :size="14" /></a>@endcan
@endif
</td>
<td>
<button class="icon-button" type="button" @if($company->hasFormConfiguration()) data-copy="{{ $url }}" @endif @disabled(! $company->hasFormConfiguration()) aria-label="Kopiuj link: {{ $label }}">
<x-icon name="copy" :size="17" />
</button>
</td>
</tr>@endforeach
</tbody>
</table>
</div>
</section>
<div class="info-note">
<x-icon name="signature" :size="23" />
<div>
<h3>Podpisy mają indywidualne linki</h3>
<p>Link do podpisania umowy, aneksu lub rozwiązania znajdziesz w szczegółach danego dokumentu. Pracownik otrzyma go także razem z dokumentem w wiadomości e-mail.</p>
</div>
</div>
</div>
@endsection
