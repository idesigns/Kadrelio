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
<fieldset>
<legend>{{ $kind === 'contract' ? 'Pracownik' : 'Umowa, której dotyczy zgłoszenie' }}</legend>
<div class="form-grid">
@if($kind === 'contract')
    <div class="field field-wide">
<label for="employee_id">Osoba z bazy pracowników</label>
<select id="employee_id" name="employee_id">
<option value="">Nowa osoba - wpiszę dane poniżej</option>@foreach($employees as $employee)<option value="{{ $employee->id }}" @selected((string) old('employee_id') === (string) $employee->id)>{{ $employee->fullName() }}</option>@endforeach</select>
</div>
    @foreach(['first_name' => 'Imię', 'last_name' => 'Nazwisko', 'email' => 'E-mail strony umowy', 'pesel' => 'PESEL strony umowy'] as $key => $label)<x-field :name="$key" :field="['label' => $label, 'type' => $key === 'email' ? 'email' : 'text', 'required' => true, 'when' => ['employee_id', '']]"/>@endforeach
@else
    <div class="field field-wide">
<label for="contract_id">Podpisana umowa <span class="required">*</span>
</label>
<select id="contract_id" name="contract_id" required>
<option value="">Wybierz umowę</option>@foreach($contracts as $contract)<option value="{{ $contract->id }}" @selected((string) old('contract_id', request('contract')) === (string) $contract->id)>{{ $contract->employee->fullName() }} · {{ $contract->concluded_at?->format('d.m.Y') }} · {{ $contract->department }} · {{ $contract->language }}</option>@endforeach</select>@if($contracts->isEmpty())<p class="field-hint">Najpierw potrzebna jest podpisana umowa w tej firmie.</p>@endif</div>
@endif
</div>
</fieldset>
<fieldset>
<legend>Dane dokumentu</legend>
<div class="form-grid">
<x-field name="concluded_at" :value="today()->format('Y-m-d')" :field="['label' => $kind === 'amendment' ? 'Data zawarcia aneksu' : ($kind === 'termination' ? 'Data sporządzenia rozwiązania' : 'Data zawarcia umowy'), 'type' => 'date', 'required' => true]"/>
@if($kind === 'contract')
<x-field name="agreement_type" value="mandate" :field="['label' => 'Rodzaj umowy', 'type' => 'select', 'required' => true, 'options' => ['mandate' => 'Umowa zlecenie', 'b2b' => 'Umowa B2B']]"/>
<x-field name="contractor_tax_id" :field="['label' => 'NIP działalności zleceniobiorcy (10 cyfr)', 'required' => true, 'inputmode' => 'numeric', 'when' => ['agreement_type', 'b2b']]"/>
<x-field name="starts_at" :field="['label' => 'Data rozpoczęcia umowy', 'type' => 'date', 'required' => true]"/>
<x-field name="ends_at" :field="['label' => 'Data zakończenia umowy', 'type' => 'date', 'required' => true]"/>
<x-field name="language" value="PL" :field="['label' => 'Typ umowy / język', 'type' => 'select', 'options' => ['PL' => 'PL - polska', 'BG' => 'BG - bułgarska', 'RO' => 'RO - rumuńska'], 'required' => true]"/>
<x-field name="department" :field="['label' => 'Dział / typ załącznika', 'type' => 'select', 'options' => array_combine(\App\Support\HrForms::departments(), \App\Support\HrForms::departments()), 'required' => true]"/>@elseif($kind === 'termination')<x-field name="ends_at" :field="['label' => 'Umowa rozwiązana ze skutkiem na dzień (ostatni dzień pracy)', 'type' => 'date', 'required' => true]"/>@endif
@foreach(\App\Support\HrForms::additionalFields($kind) as $key => $field)<x-field :name="$key" :field="$field"/>@endforeach
</div>
</fieldset>
<fieldset>
<legend>Osoba zgłaszająca</legend>
<div class="form-grid">
<x-field name="reporter_name" :value="auth()->user()->name" :field="['label' => 'Imię i nazwisko osoby zgłaszającej', 'required' => true]"/>
<x-field name="reporter_email" :value="auth()->user()->email" :field="['label' => 'E-mail osoby zgłaszającej', 'type' => 'email', 'required' => true]"/>
</div>
</fieldset>
<div class="form-footer">
<a class="button button-secondary" href="{{ route('companies.board', [$company, 'contracts']) }}">Anuluj</a>
<button type="submit" class="button button-primary">Zatwierdź zgłoszenie <x-icon name="arrow" :size="17" />
</button>
</div>
</form>
@endsection
