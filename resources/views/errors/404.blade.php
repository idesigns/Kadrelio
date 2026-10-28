@extends('layouts.public')
@section('title', 'Nie znaleźliśmy tej strony')
@section('content')
<div class="submission-success">
<span>
<x-icon name="help" :size="32" />
</span>
<h1>Nie znaleźliśmy tej strony</h1>
<p>Sprawdź adres lub wróć do swojego obszaru pracy.</p>
<a class="button button-secondary" href="{{ route('home') }}">Wróć do Kadrelio</a>
</div>
@endsection
