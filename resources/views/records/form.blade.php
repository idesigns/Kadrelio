@extends('layouts.app')
@section('title', 'Nowe zgłoszenie')
@section('content')
<header class="board-header">
<div>
<a class="back-link" href="{{ route('companies.board', [$company, ['contract' => 'contracts', 'amendment' => 'amendments', 'termination' => 'terminations'][$kind]]) }}">← Wróć do tablicy</a>
<h1>{{ ['contract' => 'Zgłoś nową umowę', 'amendment' => 'Zgłoś aneks', 'termination' => 'Zgłoś rozwiązanie umowy'][$kind] }}</h1>
<p>{{ $kind === 'contract' ? 'Wpisz podstawowe dane. Resztę pracownik uzupełni sam z otrzymanego linku.' : 'Wybierz podpisaną umowę i uzupełnij dane dokumentu.' }}</p>
</div>
</header>
<form method="post" action="{{ route('companies.records.store', [$company, $kind]) }}" class="form-page" data-conditional-form>@csrf
@include('records.fields')
<div class="form-footer">
<a class="button button-secondary" href="{{ route('companies.board', [$company, 'contracts']) }}">Anuluj</a>
<button type="submit" class="button button-primary">Zatwierdź zgłoszenie <x-icon name="arrow" :size="17" />
</button>
</div>
</form>
@endsection
