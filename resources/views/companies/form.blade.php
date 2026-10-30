@extends('layouts.app')
@section('title', $company ? 'Ustawienia firmy' : 'Nowa firma')
@section('content')
<header class="board-header">
<div>
<a class="back-link" href="{{ $company ? route('companies.board', [$company, 'contracts']) : route('dashboard') }}">← Obszar roboczy</a>
<h1>{{ $company ? 'Ustawienia firmy' : 'Dodaj nową firmę' }}</h1>
<p>Oddzielna przestrzeń, właściwy zespół i dokumenty.</p>
</div>
</header>
<form class="settings-form form-page" method="post" action="{{ $company ? route('companies.update', $company) : route('companies.store') }}">@csrf @if($company) @method('patch') @endif<fieldset>
<legend>Informacje o firmie</legend>
<div class="form-grid">
<x-field name="name" :value="$company?->name" :field="['label' => 'Pełna nazwa firmy', 'required' => true, 'placeholder' => 'np. OTC 7 sp. z o.o.']"/>
<x-field name="tax_id" :value="$company?->tax_id" :field="['label' => 'NIP']"/>
<x-field name="address" :value="$company?->address" :field="['label' => 'Adres siedziby', 'type' => 'textarea', 'rows' => 2]"/>
</div>
</fieldset>
<fieldset>
<legend>Obieg dokumentów</legend>
<p class="fieldset-description">Na ten adres będą trafiać oświadczenia i dokumenty podpisane przez pracowników.</p>
<div class="form-grid">
<x-field name="hr_email" :value="$company?->hr_email" :field="['label' => 'Adres e-mail działu HR', 'type' => 'email', 'required' => true, 'placeholder' => 'umowy@firma.pl']"/>
<x-field name="privacy_url" :value="$company?->privacy_url" :field="['label' => 'Link do informacji RODO', 'required' => true, 'placeholder' => 'https://firma.pl/rodo.pdf', 'hint' => 'Link będzie widoczny w formularzu danych pracownika.']"/>
</div>
</fieldset>
<fieldset>
<legend>Zewnętrzne zgłoszenia umów</legend>
<p class="fieldset-description">Rekruterka może zgłosić nową umowę bez konta, korzystając z linku i hasła tej firmy.</p>
<div class="form-grid">
<x-field name="password" :field="['label' => $company?->recruitment_password ? 'Nowe hasło formularza' : 'Hasło formularza', 'type' => 'password', 'autocomplete' => 'new-password', 'hint' => 'Minimum 8 znaków. Puste pole zachowuje dotychczasowe hasło. Zmiana hasła wymaga ponownego odblokowania formularza.']"/>
</div>
@if($company && app(\App\Services\RecruitmentAccess::class)->enabled($company))
<p class="field-hint">Link dla rekrutera: <a class="text-link" href="{{ route('recruitment.show', $company->public_token) }}" target="_blank" rel="noopener">Otwórz formularz zgłoszenia</a></p>
<button class="button button-secondary" type="button" data-copy="{{ route('recruitment.show', $company->public_token) }}">Kopiuj link dla rekrutera</button>
@endif
</fieldset>
<fieldset>
<legend>Oznaczenie przestrzeni</legend>
<div class="color-options">@foreach(['green' => 'Zielony', 'purple' => 'Fioletowy', 'blue' => 'Niebieski', 'orange' => 'Pomarańczowy'] as $value => $label)<label>
<input type="radio" name="color" value="{{ $value }}" @checked(old('color', $company?->color ?? 'green') === $value) required>
<span class="color-swatch company-{{ $value }}">
</span>{{ $label }}</label>@endforeach</div>
</fieldset>
<div class="form-footer">
<a class="button button-secondary" href="{{ route('dashboard') }}">Anuluj</a>
<button class="button button-primary" type="submit">{{ $company ? 'Zapisz ustawienia' : 'Utwórz firmę' }} <x-icon name="arrow" :size="17" />
</button>
</div>
</form>
@endsection
