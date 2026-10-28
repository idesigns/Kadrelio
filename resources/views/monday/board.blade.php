@extends('layouts.app')
@section('title', $mondayBoard->name)
@section('content')
@php($nativeBoard = ['profiles' => 'employees', 'contracts' => 'contracts', 'amendments' => 'amendments', 'terminations' => 'terminations', 'contract_statements' => 'statements', 'amendment_statements' => 'statements', 'termination_statements' => 'statements', 'changes' => 'changes', 'multisport' => 'multisport'][$mondayBoard->kind] ?? null)
<header class="board-header"><div><a class="back-link" href="{{ $company && $nativeBoard ? route('companies.board', [$company, $nativeBoard]) : route('dashboard') }}">← {{ $company && $nativeBoard ? \App\Support\HrForms::boards()[$nativeBoard]['title'] : 'Obszar roboczy' }}</a><h1>{{ $mondayBoard->name }}</h1><p>Dane pracowników i dokumentów. Otwórz wpis, aby zobaczyć szczegóły i komentarze.</p></div><details class="dropdown"><summary class="button button-secondary"><x-icon name="download" :size="17" /> Eksportuj <x-icon name="chevron" :size="14" /></summary><div class="dropdown-panel"><a href="{{ route('monday.boards.source', $mondayBoard) }}">Pobierz arkusz XLSX</a><a href="{{ route('monday.boards.export', $mondayBoard) }}">Pobierz dane JSON</a></div></details></header>
<div class="board-tabs"><a class="tab {{ empty($filters['review']) ? 'active' : '' }}" href="{{ route('monday.boards.show', $mondayBoard) }}"><x-icon name="board" :size="16" /> Tabela główna</a><a class="tab {{ !empty($filters['review']) ? 'active' : '' }}" href="{{ route('monday.boards.show', [$mondayBoard, 'review' => 1]) }}">Do sprawdzenia</a>
<span class="tab-end" data-board-total data-total-label="Wpisy">Wpisy: {{ number_format($total, 0, ',', ' ') }}</span></div>
<form method="get" class="board-toolbar monday-toolbar" data-filter-form>
@if(request()->boolean('columns_custom'))
<input type="hidden" name="columns_custom" value="1">
@endif
@if(!empty($filters['review']))<input type="hidden" name="review" value="1">
@endif

<label class="search-control"><x-icon name="search" :size="19" /><input type="search" name="q" maxlength="150" aria-label="Szukaj we wszystkich kolumnach" placeholder="Szukaj we wszystkich kolumnach" value="{{ $filters['q'] ?? '' }}"></label>
<details class="dropdown filter-dropdown"><summary class="toolbar-button"><x-icon name="filter" :size="18" /> Filtruj <x-icon name="chevron" :size="13" /></summary><div class="dropdown-panel"><strong>Filtruj tablicę</strong><label>Grupa<select name="group"><option value="">Wszystkie grupy</option>@foreach($mondayBoard->groups as $groupName)<option @selected(($filters['group'] ?? '') === $groupName)>{{ $groupName }}</option>
@endforeach
</select></label><label>Status<select name="status"><option value="">Wszystkie statusy</option>@foreach($statuses as $status)<option @selected(($filters['status'] ?? '') === $status)>{{ $status }}</option>
@endforeach
</select></label><button class="button button-primary" type="submit">Zastosuj filtry</button><a class="text-link" href="{{ route('monday.boards.show', $mondayBoard) }}">Wyczyść filtry</a></div></details>
<label class="sort-control"><x-icon name="sort" :size="18" /><select name="sort" aria-label="Sortuj wpisy" data-auto-submit>@foreach(['source' => 'Kolejność tablicy', 'newest' => 'Najnowsze', 'oldest' => 'Najstarsze', 'name' => 'Nazwa A-Z'] as $value => $label)<option value="{{ $value }}" @selected(($filters['sort'] ?? 'source') === $value)>{{ $label }}</option>
@endforeach
</select></label>
<details class="dropdown monday-columns-menu"><summary class="toolbar-button"><x-icon name="board" :size="18" /> Kolumny <span class="monday-count">{{ count($columns) }}/{{ count($mondayBoard->columns) - (int) in_array('Item ID (auto generated)', $mondayBoard->columns, true) }}</span></summary><div class="dropdown-panel"><strong>Widoczne kolumny</strong><div class="monday-column-options">@foreach($mondayBoard->columns as $column => $label)@if($label !== 'Item ID (auto generated)')<label><input type="checkbox" name="columns[]" value="{{ $column }}" @checked(isset($columns[$column]))><span>{{ $label }}</span></label>
@endif
 
@endforeach
</div><button class="button button-primary" name="columns_custom" value="1" type="submit">Pokaż kolumny</button></div></details>
<button type="submit" class="button button-secondary monday-search-submit">Szukaj</button>
</form>
@include('monday.results')

@endsection

