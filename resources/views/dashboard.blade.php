@extends('layouts.app')
@section('title', 'Twój obszar pracy')
@section('content')
<header class="board-header dashboard-header">
<div>
<div class="breadcrumb">{{ now()->locale('pl')->translatedFormat('l, j F Y') }}</div>
<h1>Cześć, {{ explode(' ', auth()->user()->name)[0] }} <span class="greeting-dot">
</span>
</h1>
<p>Zadbajmy o sprawy Twojego zespołu.</p>
</div>@if(auth()->user()->canCreateCompanies())<a class="button button-primary" href="{{ route('companies.create') }}">
<x-icon name="plus" :size="18" /> Nowa firma</a>@endif</header>
<div class="dashboard-content">
@if($companies->isNotEmpty())
<section class="work-overview" aria-label="Twój obieg w skrócie">
    <div class="overview-intro"><span class="overview-icon"><x-icon name="inbox" :size="25" /></span><div><h2>Co wymaga Twojej uwagi?</h2><p>Sprawy ze wszystkich Twoich firm w jednym miejscu.</p></div></div>
    <div class="overview-stats">
        <a href="#tasks"><span class="count-dot count-purple"></span><strong>{{ $companies->sum('awaiting_data_count') }}</strong><span>czeka na dane</span></a>
        <a href="#tasks"><span class="count-dot count-orange"></span><strong>{{ $companies->sum('awaiting_signature_count') }}</strong><span>czeka na podpis</span></a>
        <div><x-icon name="users" :size="17" /><strong>{{ $companies->sum('employees_count') }}</strong><span>pracowników</span></div>
    </div>
</section>
@endif
<div class="section-heading">
<h2>Twoje firmy</h2>
<span>{{ $companies->count() }} {{ $companies->count() === 1 ? 'przestrzeń robocza' : 'przestrzenie robocze' }}</span>
</div>
@forelse($companies as $workspace)<a class="workspace-row" href="{{ route('companies.board', [$workspace, 'contracts']) }}">
<span class="company-icon company-icon-large company-{{ $workspace->color }}">{{ mb_substr($workspace->name, 0, 1) }}</span>
<div class="workspace-row-name">
<h3>{{ $workspace->name }}</h3>
<p>{{ $workspace->tax_id ? 'NIP '.$workspace->tax_id : 'Przestrzeń firmy' }}</p>
</div>
<span class="workspace-stat">
<strong>{{ $workspace->employees_count }}</strong> pracowników</span>
<span class="workspace-stat">
<strong>{{ $workspace->records_count }}</strong> dokumentów</span>
<span class="workspace-stat">
<x-icon name="users" :size="17" /> {{ $workspace->users_count }}</span>
<x-icon name="arrow" :size="19" />
</a>@empty<div class="onboarding-empty">
<span class="empty-icon">
<x-icon name="folder" :size="34" />
</span>
<h2>{{ auth()->user()->canCreateCompanies() ? 'Twoja pierwsza firma czeka na swoje miejsce' : 'Czekasz na dostęp do firmy' }}</h2>
<p>{{ auth()->user()->canCreateCompanies() ? 'Dodaj firmę, ustaw adres działu HR i zaproś zespół. Potem zgłoś pierwszą umowę.' : 'Poproś managera o link zaproszenia. Po jego przyjęciu firma pojawi się w tym miejscu.' }}</p>@if(auth()->user()->canCreateCompanies())<a class="button button-primary" href="{{ route('companies.create') }}">
<x-icon name="plus" :size="17" /> Dodaj pierwszą firmę</a>@endif</div>@endforelse
@if($companies->isNotEmpty())<section id="tasks" class="tasks-section">
<div class="section-heading">
<h2>Moja praca</h2>
<span>Co wymaga kolejnego kroku</span>
</div>
<div class="table-scroll">
<table class="simple-table">
<thead>
<tr>
<th>Firma</th>
<th>Oczekuje na dane</th>
<th>Do podpisu</th>
<th>
</th>
</tr>
</thead>
<tbody>@foreach($companies as $workspace)<tr>
<td>{{ $workspace->name }}</td>
<td>
<a href="{{ route('companies.board', [$workspace, 'contracts', 'status' => 'awaiting_data']) }}">
<span class="count-dot count-purple">
</span>{{ $workspace->awaiting_data_count }}</a>
</td>
<td>
<a href="{{ route('companies.board', [$workspace, 'contracts', 'status' => 'sent']) }}">
<span class="count-dot count-orange">
</span>{{ $workspace->awaiting_signature_count }}</a>
</td>
<td>
<a class="text-link" href="{{ route('companies.board', [$workspace, 'contracts']) }}">Otwórz tablicę <x-icon name="arrow" :size="15" />
</a>
</td>
</tr>@endforeach</tbody>
</table>
</div>
</section>@endif</div>
@endsection
