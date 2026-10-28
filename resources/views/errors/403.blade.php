@extends('layouts.public')
@section('title', 'Nie masz dostępu do tej części')
@section('content')
<div class="submission-success">
<span>
<x-icon name="help" :size="32" />
</span>
<h1>Nie masz dostępu do tej części</h1>
<p>Ta czynność wymaga uprawnień managera lub administratora.</p>
<a class="button button-secondary" href="{{ route('home') }}">Wróć do Kadrelio</a>
</div>
@endsection
