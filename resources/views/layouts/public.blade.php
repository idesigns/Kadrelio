@extends('layouts.base')
@section('body-class', 'public-body')
@section('body')
<header class="public-bar">
<a href="{{ route('home') }}" class="brand">
<img class="brand-mark" src="{{ asset('kadrelio-icon.png') }}" width="32" height="32" alt="" aria-hidden="true">
<strong>Kadrelio</strong>
</a>
<span>
<x-icon name="lock" :size="15" /> Bezpieczny formularz</span>
</header>
<main class="public-content">
    @if(isset($company))<div class="public-company">
<span class="company-icon company-{{ $company->color }}">{{ mb_substr($company->name, 0, 1) }}</span>{{ $company->name }}</div>@endif
    @if(session('success'))<div class="flash flash-success" role="status">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="flash flash-error" role="alert">
<div>
<strong>{{ request()->routeIs('login') ? 'Nie udało się zalogować.' : 'Uzupełnij lub popraw zaznaczone pola.' }}</strong>
<ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
</div>
</div>@endif
    @yield('content')
</main>
<footer class="public-footer">Kadrelio <span>·</span> Kadry, które po prostu działają.</footer>
@endsection
