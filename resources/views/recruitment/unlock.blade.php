@extends('layouts.public')
@section('title', 'Zgłoszenie nowej umowy')
@section('body-class', 'public-body auth-body')
@section('content')
<div class="auth-intro"><h1>Zgłoszenie nowej umowy</h1><p>Wpisz hasło otrzymane od działu HR tej firmy.</p></div>
<form method="post" action="{{ route('recruitment.unlock', $company->public_token) }}" class="auth-form">
@csrf
<x-field name="password" :field="['label' => 'Hasło formularza', 'type' => 'password', 'required' => true, 'autocomplete' => 'current-password']"/>
<button class="button button-primary button-full" type="submit">Otwórz formularz <x-icon name="arrow" :size="18"/></button>
</form>
@endsection
