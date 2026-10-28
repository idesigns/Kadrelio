@extends('layouts.public')
@section('title', 'Formularz danych osobowych')
@section('body-class', 'public-body wide-form-body')
@section('content')
<div class="public-form-heading">
<span class="form-eyebrow">FORMULARZ PRACOWNIKA / EMPLOYEE FORM</span>
<h1>Dane do Twojej umowy</h1>
<p>Uzupełnij dane osobowe i oświadczenia. Dzięki nim dział HR przygotuje dokumenty i rozliczenia.</p>
<span class="required-explanation">Pola z <span class="required">*</span> są wymagane / Required fields.</span>
</div>
@if($preview ?? false)<p class="info-note">Podgląd formularza. Dane nie zostaną zapisane ani wysłane.</p>@endif
<form method="post" enctype="multipart/form-data" action="{{ ($preview ?? false) ? route('companies.profile-preview', $company) : ($employee ? route('onboarding.show', $employee->form_token) : route('forms.profile', $company->public_token)) }}" class="public-form" data-conditional-form data-form-progress>@csrf
@foreach(\App\Support\HrForms::profileSections() as $title => $fields)<fieldset @if(str_starts_with($title, 'Adres zameldowania')) data-condition="same_registered_address" data-values="0" @endif>
<legend>
<span class="section-number">{{ $loop->iteration }}</span>{{ $title }}</legend>
<div class="form-grid">@foreach($fields as $key => $field)<x-field :name="$key" :field="$field"/>@endforeach</div>
</fieldset>@endforeach
<fieldset data-condition="is_student" data-values="1"><legend><span class="section-number">{{ count(\App\Support\HrForms::profileSections()) + 1 }}</span>Legitymacja ucznia / studenta</legend><x-field name="student_documents" :field="['label' => 'Dodaj skan legitymacji / Student card scan', 'type' => 'file', 'hint' => 'Do 3 plików PDF, JPG lub PNG, do 5 MB każdy. Pliki będą dostępne wyłącznie dla zespołu HR tej firmy.']"/></fieldset>
<fieldset>
<legend>
<span class="section-number">{{ count(\App\Support\HrForms::profileSections()) + 2 }}</span>Ochrona danych / Privacy</legend>
<label class="checkbox-label consent-label">
<input type="checkbox" name="privacy_consent" value="1" required @checked(old('privacy_consent'))>
<span>Oświadczam, że zapoznałem/am się z @if($company->privacy_url)<a href="{{ $company->privacy_url }}" class="text-link" target="_blank" rel="noopener noreferrer">informacją o przetwarzaniu danych osobowych (RODO)</a>@else informacją o przetwarzaniu danych osobowych (link RODO wymaga ustawienia)@endif i wyrażam zgodę na przetwarzanie moich danych osobowych. / I have read the privacy information and consent to processing my personal data. <span class="required">*</span>
</span>
</label>@error('privacy_consent')<p class="field-error">{{ $message }}</p>@enderror</fieldset>
<div class="public-submit">
<p>
<x-icon name="lock" :size="15" /> Dane trafią wyłącznie do obsługi kadrowej tej firmy.</p>
<button class="button button-primary" type="submit" @disabled($preview ?? false)>Zatwierdź dane / Submit <x-icon name="arrow" :size="18" />
</button>
</div>
</form>
@endsection
