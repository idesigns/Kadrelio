@extends('layouts.app')
@section('title', 'Użytkownicy firmy')
@section('content')
<header class="board-header">
<div>
<div class="breadcrumb">{{ $company->name }}<span>/</span> Zespół</div>
<h1>Razem pracuje się prościej.</h1>
<p>Zarządzaj dostępem do tej przestrzeni firmy.</p>
</div>
</header>
<div class="settings-content">
<div class="section-heading">
<h2>Użytkownicy firmy</h2>
<span>{{ $members->count() }} osób</span>
</div>
<div class="table-scroll">
<table class="simple-table">
<thead>
<tr>
<th>Użytkownik</th>
<th>E-mail</th>
<th>Rola</th>
</tr>
</thead>
<tbody>@foreach($members as $member)<tr>
<td>
<span class="person-cell">
<span class="avatar avatar-{{ $member->id % 2 ? 'purple' : 'blue' }}">{{ $member->initials() }}</span>{{ $member->name }}</span>
</td>
<td>{{ $member->email }}</td>
<td>
<span class="tag">{{ $member->roleLabel() }}</span>@if($company->owner_id === $member->id)<span class="owner-label">Właściciel firmy</span>@endif</td>
</tr>@endforeach</tbody>
</table>
</div>
<section id="invite" class="invite-section">
<h2>Zaproś do firmy</h2>
<p>Nowa osoba otrzyma rolę użytkownika i dostęp tylko do firm, do których zostanie zaproszona. Istniejące konto zachowa swoją rolę.</p>
<form method="post" action="{{ route('companies.members.store', $company) }}" class="invite-form">@csrf<x-field name="name" :field="['label' => 'Imię i nazwisko', 'required' => true]"/>
<x-field name="email" :field="['label' => 'Adres e-mail', 'type' => 'email', 'required' => true]"/>
<button class="button button-primary" type="submit">
<x-icon name="user-plus" :size="17" /> Wyślij zaproszenie</button>
</form>
</section>
@if($invitations->isNotEmpty())<div class="section-heading">
<h2>Oczekujące zaproszenia</h2>
</div>
<div class="table-scroll">
<table class="simple-table">
<thead>
<tr>
<th>Osoba</th>
<th>E-mail</th>
<th>Ważne do</th>
<th>
</th>
</tr>
</thead>
<tbody>@foreach($invitations as $invitation)<tr>
<td>{{ $invitation->name }}</td>
<td>{{ $invitation->email }}</td>
<td>{{ $invitation->expires_at->format('d.m.Y') }}</td>
<td>
<button type="button" class="button button-secondary" data-copy="{{ route('invitations.show', $invitation->token) }}">
<x-icon name="copy" :size="16" /> Kopiuj link</button>
</td>
</tr>@endforeach</tbody>
</table>
</div>@endif</div>
@endsection
