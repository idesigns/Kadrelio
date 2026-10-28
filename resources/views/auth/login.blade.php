@extends('layouts.public')
@section('title', 'Zaloguj się')
@section('body-class', 'public-body auth-body')
@section('content')
<div class="auth-intro">
<h1>Logowanie</h1>
</div>
<form method="post" action="{{ route('login') }}" class="auth-form">@csrf<x-field name="email" :field="['label' => 'Adres e-mail', 'type' => 'email', 'required' => true, 'autocomplete' => 'email', 'placeholder' => 'ty@firma.pl']"/>
<x-field name="password" :field="['label' => 'Hasło', 'type' => 'password', 'required' => true, 'autocomplete' => 'current-password']"/>
<label class="checkbox-label">
<input type="checkbox" name="remember" value="1"> Zapamiętaj mnie</label>
<button class="button button-primary button-full" type="submit">Zaloguj się <x-icon name="arrow" :size="18" />
</button>
</form>
<p class="auth-switch">Pierwszy raz w Kadrelio? <a href="{{ route('register') }}">Utwórz konto</a>
</p>
@endsection
