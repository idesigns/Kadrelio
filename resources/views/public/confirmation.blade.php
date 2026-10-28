@extends('layouts.public')
@section('title', 'Potwierdź zgłoszenie')
@section('content')
<div class="public-form-heading">
    <span class="form-eyebrow">POTWIERDZENIE E-MAIL</span>
    <h1>{{ \App\Support\HrForms::kinds()[$record->kind] }}</h1>
    <p>Sprawdź treść przed wysłaniem do HR. Jeśli to nie Twoje zgłoszenie, zamknij tę stronę.</p>
</div>
<dl class="detail-list">
    <div><dt>Imię i nazwisko</dt><dd>{{ $record->data['signer_name'] }}</dd></div>
    @foreach(\App\Support\HrForms::additionalFields($record->kind) as $key => $field)
        @if(isset($record->data[$key]))
            <div><dt>{{ $field['label'] }}</dt><dd class="preserve-lines">{{ $field['options'][$record->data[$key]] ?? $record->data[$key] }}</dd></div>
        @endif
    @endforeach
</dl>
<img class="signature-preview" src="{{ $record->data['signature_image'] }}" alt="Podpis ze zgłoszenia">
<form method="post" action="{{ route('forms.confirmation', $record->signing_token) }}" class="public-form">
    @csrf
    <label class="checkbox-label consent-label">
        <input type="checkbox" name="accepted" value="1" required>
        <span>To moje zgłoszenie. Potwierdzam jego treść i wysłanie do działu HR.</span>
    </label>
    <button type="submit" class="button button-primary button-full">Potwierdź i wyślij do HR</button>
</form>
@endsection
