@extends('layouts.app')
@section('title', $definition['title'])
@section('content')
<header class="board-header">
<div>
<div class="board-title-row"><h1>{{ $definition['title'] }}</h1><details class="dropdown"><summary class="icon-button" aria-label="Wybierz tablicę"><x-icon name="chevron" :size="20" /></summary><div class="dropdown-panel">@foreach(\App\Support\HrForms::boards() as $key => $item)<a href="{{ route('companies.board', [$company, $key]) }}">{{ $item['title'] }}</a>@endforeach</div></details></div>
<p>{{ $definition['description'] }}</p>
</div>
<div class="board-header-actions">
<div class="avatar-stack" aria-label="Zespół firmy">@foreach($company->users->take(3) as $member)<span class="avatar avatar-{{ ['orange', 'blue', 'purple'][$loop->index] }}" title="{{ $member->name }}">{{ $member->initials() }}</span>@endforeach @if($company->users->count() > 3)<span class="avatar-more">+{{ $company->users->count() - 3 }}</span>@endif</div>@can('update', $company)<a class="button button-secondary" href="{{ route('companies.members.index', $company) }}#invite">
<x-icon name="user-plus" :size="17" /> Zaproś użytkownika</a>@endcan</div>
</header>
<div class="board-tabs" aria-label="Widoki tablicy">
<a class="tab active" href="{{ route('companies.board', [$company, $board]) }}">
<x-icon name="board" :size="16" /> Tabela główna</a>@if(in_array($board, ['contracts', 'amendments', 'terminations']))<a class="tab" href="{{ route('companies.templates.index', $company) }}">Szablony dokumentów</a>@elseif($board === 'employees')<a class="tab" href="{{ route('forms.profile', $company->public_token) }}" target="_blank" rel="noopener">Formularz danych osobowych <x-icon name="external" :size="14" />
</a>@endif<span class="tab-end" data-board-total data-total-label="{{ $board === 'employees' ? 'Osoby' : 'Elementy' }}">{{ $board === 'employees' ? 'Osoby' : 'Elementy' }}: {{ $total }}</span>
</div>
<div class="board-toolbar">
    @if(in_array($board, ['requests', 'contracts', 'employees', 'amendments', 'terminations']))
        <div class="split-button">
<a class="button button-primary" href="{{ route('companies.records.create', [$company, $kind ?? 'contract']) }}">
<x-icon name="plus" :size="17" />{{ ['requests' => 'Nowe zgłoszenie', 'contracts' => 'Nowa umowa', 'employees' => 'Nowy pracownik', 'amendments' => 'Nowy aneks', 'terminations' => 'Nowe rozwiązanie'][$board] }}</a>
<details class="dropdown">
<summary class="button button-primary" aria-label="Więcej opcji tworzenia">
<x-icon name="chevron" :size="16" />
</summary>
<div class="dropdown-panel">
<a href="{{ route('companies.records.create', [$company, 'contract']) }}">Zgłoś nową umowę</a>
<a href="{{ route('companies.board', [$company, 'links']) }}">Wszystkie formularze</a>
</div>
</details>
</div>
    @elseif(in_array($board, ['changes', 'multisport']))
        <a class="button button-primary" href="{{ route('forms.additional', [$company->public_token, $kind]) }}" target="_blank" rel="noopener">
<x-icon name="plus" :size="17" /> Otwórz formularz</a>
    @else
        <a class="button button-primary" href="{{ route('companies.board', [$company, 'links']) }}">
<x-icon name="link" :size="16" /> Formularze</a>
    @endif
    <form method="get" class="toolbar-filters" data-filter-form>
        <label class="search-control">
<x-icon name="search" :size="19" />
<input type="search" name="q" maxlength="150" aria-label="Szukaj na tablicy" placeholder="Szukaj" value="{{ $filters['q'] ?? '' }}">

</label>
        <details class="dropdown filter-dropdown">
<summary class="toolbar-button {{ !empty($filters['status']) || !empty($filters['department']) || !empty($filters['kind']) ? 'is-filtered' : '' }}">
<x-icon name="filter" :size="19" /> Filtruj <x-icon name="chevron" :size="13" />
</summary>
<div class="dropdown-panel">
<strong>Filtruj tablicę</strong>
@if($board === 'statements')
<label>Rodzaj oświadczenia<select name="kind"><option value="">Wszystkie dokumenty</option>@foreach(['contract' => 'Umowy', 'amendment' => 'Aneksy', 'termination' => 'Rozwiązania'] as $value => $label)<option value="{{ $value }}" @selected(($filters['kind'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></label>
@endif
@if($board !== 'employees')<label>Status<select name="status">
<option value="">Wszystkie statusy</option>@foreach(\App\Support\HrForms::statuses() as $value => $label)<option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>@endforeach</select>
</label>@endif<label>Dział<select name="department">
<option value="">Wszystkie działy</option>@foreach(\App\Support\HrForms::departments() as $department)<option @selected(($filters['department'] ?? '') === $department)>{{ $department }}</option>@endforeach</select>
</label>
<button type="submit" class="button button-primary">Zastosuj filtry</button>
<a class="text-link" href="{{ route('companies.board', [$company, $board]) }}">Wyczyść filtry</a>
</div>
</details>
        <label class="sort-control">
<x-icon name="sort" :size="18" />
<select name="sort" aria-label="Sortuj" data-auto-submit>
<option value="newest" @selected(($filters['sort'] ?? '') === 'newest')>Najnowsze</option>
<option value="oldest" @selected(($filters['sort'] ?? '') === 'oldest')>Najstarsze</option>
<option value="name" @selected(($filters['sort'] ?? '') === 'name')>Nazwisko A-Z</option>
</select>
</label>
    </form>
    <button type="button" class="toolbar-button export-button" data-export @disabled($total === 0)>
<x-icon name="download" :size="18" /> Eksportuj</button>
</div>
<div class="selection-bar" hidden>
<span data-selection-count>
</span>
<button type="button" class="text-link" data-export>Eksportuj zaznaczone</button>
<button type="button" class="icon-button" aria-label="Odznacz wszystkie" data-clear-selection>
<x-icon name="close" :size="17" />
</button>
</div>
@include('boards.results')

@endsection
