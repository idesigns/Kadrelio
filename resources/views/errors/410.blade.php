@extends('layouts.public')
@section('title', 'Ten link nie jest już aktywny')
@section('content')
<div class="submission-success">
<span>
<x-icon name="clock" :size="32" />
</span>
<h1>Ten link nie jest już aktywny</h1>
<p>Formularz został już zatwierdzony lub link wygasł. Poproś dział HR o nowy link.</p>
<a class="button button-secondary" href="{{ route('home') }}">Wróć do Kadrelio</a>
</div>
@endsection
