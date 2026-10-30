@extends('layouts.app')
@section('title', isset($record) ? 'Edytuj zgłoszenie' : 'Nowe zgłoszenie')
@section('content')
<header class="board-header">
<div>
<a class="back-link" href="{{ isset($record) ? route('companies.records.show', [$company, $record, 'panel' => request()->boolean('panel') ? 1 : null]) : route('companies.board', [$company, ['contract' => 'contracts', 'amendment' => 'amendments', 'termination' => 'terminations'][$kind]]) }}">← {{ isset($record) ? 'Wróć do zgłoszenia' : 'Wróć do tablicy' }}</a>
<h1>{{ isset($record) ? 'Edytuj zgłoszenie' : ['contract' => 'Zgłoś nową umowę', 'amendment' => 'Zgłoś aneks', 'termination' => 'Zgłoś rozwiązanie umowy'][$kind] }}</h1>
<p>{{ isset($record) ? 'Popraw dane zgłoszone do umowy. Zapis nie wysyła wiadomości e-mail.' : ($kind === 'contract' ? 'Wpisz podstawowe dane. Resztę pracownik uzupełni sam z otrzymanego linku.' : 'Wybierz podpisaną umowę i uzupełnij dane dokumentu.') }}</p>
</div>
</header>
<form method="post" action="{{ isset($record) ? route('companies.records.submission.update', [$company, $record]) : route('companies.records.store', [$company, $kind]) }}" class="form-page" data-conditional-form>@csrf
@if(isset($record))
@method('patch')
<input type="hidden" name="fingerprint" value="{{ $record->submissionFingerprint() }}">
@if($record->generated_at)<p class="info-note">Po zmianie danych wygeneruj dokumenty ponownie przed wysłaniem do podpisu.</p>@endif
@endif
@if(request()->boolean('panel'))<input type="hidden" name="panel" value="1">@endif
@include('records.fields')
<div class="form-footer">
<a class="button button-secondary" href="{{ isset($record) ? route('companies.records.show', [$company, $record, 'panel' => request()->boolean('panel') ? 1 : null]) : route('companies.board', [$company, 'contracts']) }}">Anuluj</a>
<button type="submit" class="button button-primary">{{ isset($record) ? 'Zapisz zmiany' : 'Zatwierdź zgłoszenie' }} <x-icon name="arrow" :size="17" />
</button>
</div>
</form>
@endsection
