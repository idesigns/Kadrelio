@extends('layouts.public')
@section('title', 'Zgłoszenie nowej umowy')
@section('content')
<header class="public-intro"><h1>Zgłoszenie nowej umowy</h1><p>Wpisz podstawowe dane zleceniobiorcy. Dział HR otrzyma zgłoszenie w panelu firmy.</p></header>
<form method="post" action="{{ route('recruitment.store', $company->public_token) }}" class="form-page" data-conditional-form>
@csrf
@include('records.fields', ['kind' => 'contract', 'public' => true])
<div class="form-footer"><button type="submit" class="button button-primary">Zatwierdź zgłoszenie <x-icon name="arrow" :size="17"/></button></div>
</form>
@endsection
