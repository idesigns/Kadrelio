@extends('layouts.base')
@section('body-class', 'app-body'.(request()->boolean('panel') ? ' panel-body' : ''))
@section('body')
<a href="#main-content" class="skip-link">Przejdź do treści</a>
<header class="global-bar">
    <button class="icon-button mobile-menu" type="button" aria-label="Otwórz menu" aria-controls="app-sidebar" aria-expanded="false" data-sidebar-toggle>
<x-icon name="menu" />
</button>
    <a class="brand" href="{{ route('dashboard') }}">
<img class="brand-mark" src="{{ asset('kadrelio-icon.png') }}" width="32" height="32" alt="" aria-hidden="true">
<strong>Kadrelio</strong>
</a>
    <div class="global-actions">
        <button type="button" class="icon-button" aria-label="Szukaj na tablicy" data-focus-search>
<x-icon name="search" />
</button>
        <span class="global-divider">
</span>
        <details class="dropdown account-menu">
<summary class="avatar avatar-purple" aria-label="Menu konta {{ auth()->user()->name }}">{{ auth()->user()->initials() }}</summary>
<div class="dropdown-panel">
<strong>{{ auth()->user()->name }}</strong>
<small>{{ auth()->user()->email }}</small>
<span class="role-label">{{ auth()->user()->roleLabel() }}</span>@if(auth()->user()->isAdmin())<a href="{{ route('admin.users') }}">
<x-icon name="users" :size="17" /> Administracja</a>@endif<form method="post" action="{{ route('logout') }}">@csrf<button type="submit">
<x-icon name="logout" :size="17" /> Wyloguj się</button>
</form>
</div>
</details>
    </div>
</header>
<button class="sidebar-backdrop" aria-label="Zamknij menu" data-sidebar-toggle>
</button>
<aside class="sidebar" id="app-sidebar" aria-label="Nawigacja główna">
    <div class="sidebar-top">
<a class="nav-item {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}">
<x-icon name="home" /> Strona główna</a>
</div>
    <div class="workspace-title">
<span>Obszar roboczy</span>
<span>{{ $shellCompanies->count() }} {{ $shellCompanies->count() === 1 ? 'firma' : 'firmy' }}</span>
</div>
    <div class="workspace-controls">
<details class="dropdown company-switcher">
<summary>
<span class="company-icon company-{{ $company?->color ?? 'purple' }}">{{ mb_substr($company?->name ?? 'K', 0, 1) }}</span>
<span>{{ $company?->name ?? 'Wybierz firmę' }}</span>
<x-icon name="chevron" :size="16" />
</summary>
<div class="dropdown-panel">@forelse($shellCompanies as $workspace)<a href="{{ route('companies.board', [$workspace, 'contracts']) }}">
<span class="company-icon company-{{ $workspace->color }}">{{ mb_substr($workspace->name, 0, 1) }}</span>{{ $workspace->name }}@if($company?->id === $workspace->id)<x-icon name="check" :size="15" />@endif</a>@empty<p>Nie masz jeszcze przypisanej firmy.</p>@endforelse</div>
</details>@if(auth()->user()->canCreateCompanies())<a class="button button-primary company-add" href="{{ route('companies.create') }}" aria-label="Dodaj firmę">
<x-icon name="plus" />
</a>@endif</div>
    @if($company)
        <button class="workspace-folder" type="button" data-toggle-group aria-controls="company-boards" aria-expanded="true">
<x-icon name="chevron" :size="14" />
<x-icon name="folder" :size="17" /> Kadry i dokumenty</button>
        <div class="group-content sidebar-navigation" id="company-boards">
        <nav class="board-navigation" aria-label="Tablice firmy">
            @foreach(\App\Support\HrForms::boards() as $key => $item)
                <a href="{{ route('companies.board', [$company, $key]) }}" class="nav-item {{ ($board ?? '') === $key ? 'active' : '' }}" @if(($board ?? '') === $key) aria-current="page" @endif>
<x-icon :name="$item['icon']" :size="18" />
<span>{{ $item['title'] }}</span>
</a>
            @endforeach
            @foreach($additionalBoards as $additionalBoard)
                <a href="{{ route('monday.boards.show', $additionalBoard) }}" class="nav-item {{ ($mondayBoard->id ?? null) === $additionalBoard->id ? 'active' : '' }}" title="{{ $additionalBoard->name }}" @if(($mondayBoard->id ?? null) === $additionalBoard->id) aria-current="page" @endif>
<x-icon name="board" :size="18" /><span>{{ $additionalBoard->name }}</span>
</a>
            @endforeach
        </nav>
        </div>
        <div class="sidebar-bottom">@can('update', $company)<a class="nav-item {{ request()->routeIs('companies.templates.*') ? 'active' : '' }}" href="{{ route('companies.templates.index', $company) }}"><x-icon name="file" /> Szablony dokumentów</a><a class="nav-item {{ request()->routeIs('companies.members.*') ? 'active' : '' }}" href="{{ route('companies.members.index', $company) }}">
<x-icon name="users" /> Użytkownicy</a>
<a class="nav-item {{ request()->routeIs('companies.edit') ? 'active' : '' }}" href="{{ route('companies.edit', $company) }}">
<x-icon name="settings" /> Ustawienia firmy</a>@endcan
</div>
    @else
        <div class="sidebar-empty">
<x-icon name="folder" :size="32" />
<p>Twoja przestrzeń pracy zaczyna się od firmy.</p>
</div>
    @endif
</aside>
<main id="main-content" class="main-content">
    @if(session('success'))<div class="flash flash-success" role="status">
<x-icon name="check" :size="18" />
<span>{{ session('success') }}</span>
<button class="icon-button" type="button" aria-label="Zamknij komunikat" data-dismiss>
<x-icon name="close" :size="16" />
</button>
</div>@endif
    @if($errors->any())<div class="flash flash-error" role="alert">
<x-icon name="help" :size="18" />
<div>
<strong>{{ $errors->has('document') ? 'Nie udało się przygotować dokumentu.' : 'Sprawdź dane formularza.' }}</strong>
<ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
</div>
</div>@endif
    @if($company && !$company->hasFormConfiguration() && !request()->boolean('panel') && !request()->routeIs('companies.edit'))
    <div class="flash flash-info"><x-icon name="settings" :size="18" /><div>Uzupełnij ustawienia firmy, aby uruchomić formularze i nowe zgłoszenia. @can('update', $company)<a class="text-link" href="{{ route('companies.edit', $company) }}">Przejdź do ustawień</a>@endcan</div></div>
    @endif
    @unless(request()->boolean('panel'))
    @if(!app(\App\Services\OutgoingMail::class)->enabled())<div class="flash flash-info"><x-icon name="mail" :size="18"/><div>Wysyłka e-mail jest wyłączona. Wiadomości nie są wysyłane ani odkładane do późniejszej wysyłki.</div></div>
    @elseif(app(\App\Services\OutgoingMail::class)->mode() === 'test')<div class="flash flash-info"><x-icon name="mail" :size="18"/><div>Tryb testowy poczty: wszystkie wiadomości trafiają wyłącznie na {{ config('hr.mail_test_address') }}.</div></div>@endif
    @endunless
    @yield('content')
</main>
@unless(request()->boolean('panel'))
<dialog class="detail-drawer" id="detail-drawer" aria-labelledby="drawer-title">
    <header class="drawer-toolbar">
        <div><x-icon name="file" :size="18" /><span id="drawer-title">Szczegóły</span></div>
        <div>
            <a class="icon-button" data-drawer-expand aria-label="Otwórz na całej stronie"><x-icon name="external" :size="18" /></a>
            <button class="icon-button" type="button" data-drawer-close aria-label="Zamknij szczegóły" autofocus><x-icon name="close" :size="21" /></button>
        </div>
    </header>
    <div class="drawer-loading" role="status"><span>Wczytuję szczegóły…</span><i></i><i></i><i></i><i></i></div>
    <iframe class="drawer-frame" title="Szczegóły dokumentu lub pracownika" referrerpolicy="same-origin"></iframe>
</dialog>
@endunless
@endsection
