@extends('layouts.public')
@section('title', 'Utwórz konto')
@section('body-class', 'public-body auth-body')
@section('content')
<div class="auth-intro">
<h1>Rejestracja</h1>
@unless($registrationEnabled)<p>Rejestracja jest chwilowo wstrzymana. Jeśli chcesz uzyskać dostęp do Kadrelio, napisz do nas na <a class="text-link" href="mailto:kontakt@kadrelio.pl">kontakt@kadrelio.pl</a>.</p>@endunless
</div>
@if($registrationEnabled)<form method="post" action="{{ route('register') }}" class="auth-form">@csrf<x-field name="name" :field="['label' => 'Imię i nazwisko', 'required' => true, 'autocomplete' => 'name']"/>
<x-field name="email" :field="['label' => 'Adres e-mail', 'type' => 'email', 'required' => true, 'autocomplete' => 'email']"/>
<x-field name="password" :field="['label' => 'Hasło', 'type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'hint' => 'Co najmniej 10 znaków, w tym litera i cyfra.']"/>
<x-field name="password_confirmation" :field="['label' => 'Powtórz hasło', 'type' => 'password', 'required' => true, 'autocomplete' => 'new-password']"/>
<button class="button button-primary button-full" type="submit">Utwórz konto <x-icon name="arrow" :size="18" />
</button>
</form>
@else
<a class="button button-primary button-full" href="mailto:kontakt@kadrelio.pl">Napisz do nas <x-icon name="mail" :size="18" /></a>
@endif
<p class="auth-switch">Masz już konto? <a href="{{ route('login') }}">Zaloguj się</a>
</p>
@endsection
