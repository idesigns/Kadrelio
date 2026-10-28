@extends('layouts.public')
@section('title', 'Chwila przerwy')
@section('content')
<div class="submission-success">
<span>
<x-icon name="help" :size="32" />
</span>
<h1>Chwila przerwy</h1>
<p>Wysłano zbyt wiele żądań. Spróbuj ponownie za minutę.</p>
<a class="button button-secondary" href="{{ route('home') }}">Wróć do Kadrelio</a>
</div>
@endsection
