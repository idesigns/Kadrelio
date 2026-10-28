@extends('layouts.public')
@section('title', 'Oświadczenie podpisania dokumentu')
@section('body-class', 'public-body wide-form-body')
@section('content')
<div class="public-form-heading">
<span class="form-eyebrow">OŚWIADCZENIE PRACOWNIKA</span>
<h1>{{ ['contract' => 'Oświadczenie o zawarciu umowy', 'amendment' => 'Oświadczenie o zawarciu aneksu', 'termination' => 'Potwierdzenie rozwiązania umowy'][$record->kind] }}</h1>
<p>Zapoznaj się z dokumentem, potwierdź swoje dane i złóż podpis.</p>
</div>
<div class="signing-document">
<span class="pdf-icon">PDF</span>
<div>
<strong>{{ \App\Support\HrForms::kinds()[$record->kind] }} · {{ $company->name }}</strong>
<small>Wygenerowano {{ $record->generated_at->format('d.m.Y') }}</small>
</div>
<a class="button button-secondary" href="{{ route('signing.document', $record->signing_token) }}" target="_blank" rel="noopener">
<x-icon name="download" :size="16" /> Przeczytaj dokument</a>
</div>
<form method="post" action="{{ route('signing.show', $record->signing_token) }}" class="public-form" data-conditional-form>@csrf<input type="hidden" name="document_hash" value="{{ $record->document_hash }}">
<fieldset>
<legend>Twoje dane</legend>
<div class="form-grid">
<x-field name="full_name" :field="['label' => 'Imię i nazwisko', 'required' => true, 'autocomplete' => 'name']"/>
<x-field name="pesel" :field="['label' => $record->isB2b() ? 'NIP działalności (umowa B2B)' : 'PESEL zleceniobiorcy', 'required' => true, 'inputmode' => 'numeric']"/>@if($record->kind !== 'contract')<x-field name="effective_date" :field="['label' => $record->kind === 'termination' ? 'Data rozwiązania umowy (ostatni dzień pracy)' : 'Data zawarcia aneksu', 'type' => 'date', 'required' => true]"/>@endif</div>
</fieldset>
<fieldset>
<legend>Oświadczenie</legend>
<label class="checkbox-label consent-label">
<input type="checkbox" name="accepted" value="1" required @checked(old('accepted'))>
<span>@if($record->kind === 'contract')Oświadczam, że zawieram {{ $record->isB2b() ? 'umowę B2B' : 'umowę zlecenia' }} z {{ $company->name }}, a znajdujące się w niej dane są aktualne i zgodne z prawdą.@elseif($record->kind === 'amendment')Oświadczam, że akceptuję zmiany zawarte w aneksie do umowy z {{ $company->name }}.@else Potwierdzam rozwiązanie umowy z {{ $company->name }}. Wszystkie informacje poufne zostały zniszczone lub zwrócone zleceniodawcy. Wszystkie kanały komunikacji, z których korzystałem/am podczas wykonywania umowy, zostały wyczyszczone, a rozmowy skasowane.@endif <span class="required">*</span>
</span>
</label>
</fieldset>
<x-signature/>
<div class="public-submit">
<p>
<x-icon name="lock" :size="15" /> Podpisane oświadczenie trafi do działu HR.</p>
<button type="submit" class="button button-primary">Zatwierdź i podpisz <x-icon name="check" :size="18" />
</button>
</div>
</form>
@endsection
