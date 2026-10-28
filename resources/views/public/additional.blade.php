@extends('layouts.public')
@section('title', \App\Support\HrForms::kinds()[$kind])
@section('body-class', 'public-body wide-form-body')
@section('content')
<div class="public-form-heading">
<span class="form-eyebrow">FORMULARZ PRACOWNIKA</span>
<h1>{{ $kind === 'multisport' ? 'Zgoda na kartę Multisport' : 'Zmiana danych osobowych' }}</h1>
<p>{{ $kind === 'multisport' ? 'Wybierz kartę i potwierdź zgodę na potrącenie składki z wynagrodzenia.' : 'Poinformuj dział HR o zmianie. Podaj dotychczasowy e-mail przypisany do Twojego profilu.' }}</p>
<p>Po wypełnieniu potwierdź zgłoszenie linkiem wysłanym na e-mail zapisany w Twoim profilu.</p>
<span class="required-explanation">Pola z <span class="required">*</span> są wymagane.</span>
</div>
<form method="post" action="{{ route('forms.additional', [$company->public_token, $kind]) }}" class="public-form" data-conditional-form>@csrf<fieldset>
<legend>Twoje dane</legend>
<div class="form-grid">
<x-field name="full_name" :field="['label' => 'Imię i nazwisko', 'required' => true, 'autocomplete' => 'name']"/>
<x-field name="email" :field="['label' => 'E-mail przypisany do pracownika', 'type' => 'email', 'required' => true]"/>
<x-field name="pesel" :field="['label' => 'PESEL zleceniobiorcy', 'required' => true, 'inputmode' => 'numeric']"/>
</div>
</fieldset>
<fieldset>
<legend>{{ $kind === 'multisport' ? 'Wybór karty' : 'Informacje o zmianie' }}</legend>
<div class="form-grid">@foreach(\App\Support\HrForms::additionalFields($kind) as $key => $field)<x-field :name="$key" :field="$field"/>@endforeach</div>
</fieldset>
<fieldset>
<legend>Oświadczenie</legend>
<label class="checkbox-label consent-label">
<input type="checkbox" name="accepted" value="1" required @checked(old('accepted'))>
<span>{{ $kind === 'multisport' ? 'Potwierdzam zapoznanie się z zasadami Benefit Systems i wyrażam zgodę na potrącenie z wynagrodzenia składki za wybraną kartę oraz, jeśli dotyczy, kartę osoby towarzyszącej.' : 'Potwierdzam, że podane przeze mnie dane są aktualne i zgodne z prawdą.' }} <span class="required">*</span>
</span>
</label>
</fieldset>
<x-signature/>
<div class="public-submit">
<p>
<x-icon name="calendar" :size="15" /> Data podpisania: {{ today()->format('d.m.Y') }}</p>
<button type="submit" class="button button-primary">Wyślij link potwierdzający <x-icon name="arrow" :size="18" />
</button>
</div>
</form>
@endsection
