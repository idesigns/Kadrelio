@extends('layouts.public')
@section('title', 'Zaproszenie do firmy')
@section('content')
<div class="auth-intro">
<span class="auth-symbol">
<x-icon name="user-plus" :size="29" />
</span>
<h1>Dołącz do zespołu.</h1>
<p>Firma <strong>{{ $invitation->company->name }}</strong> zaprasza Cię do wspólnej pracy w Kadrelio.</p>
</div>
<dl class="invitation-details">
<div>
<dt>Twoje konto</dt>
<dd>{{ $invitation->email }}</dd>
</div>
<div>
<dt>Dostęp</dt>
<dd>Użytkownik przypisanej firmy</dd>
</div>
</dl>
@if($exists && (!auth()->check() || auth()->user()->email !== $invitation->email))<p>Zaloguj się na zaproszone konto, a potem otwórz ten link ponownie.</p>
@auth
<form method="post" action="{{ route('logout') }}">@csrf<button class="button button-primary button-full" type="submit">Wyloguj się i zmień konto</button></form>
@else
<a class="button button-primary button-full" href="{{ route('login') }}">Zaloguj się</a>
@endauth
@else<form method="post" action="{{ route('invitations.show', $invitation->token) }}" class="auth-form">@csrf @unless($exists)<x-field name="password" :field="['label' => 'Ustaw hasło', 'type' => 'password', 'required' => true, 'hint' => 'Co najmniej 10 znaków, litera i cyfra.', 'autocomplete' => 'new-password']"/>
<x-field name="password_confirmation" :field="['label' => 'Powtórz hasło', 'type' => 'password', 'required' => true, 'autocomplete' => 'new-password']"/>@endunless<button class="button button-primary button-full" type="submit">Akceptuję zaproszenie <x-icon name="arrow" :size="17" />
</button>
</form>@endif
@endsection
