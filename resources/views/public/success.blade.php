@extends('layouts.public')
@section('title', 'Formularz wysłany')
@section('content')
<div class="submission-success">
<span>
<x-icon name="check" :size="35" />
</span>
<h1>Gotowe. Dziękujemy!</h1>
<p>{{ session('submitted', 'Twój formularz został zapisany.') }}</p>
<p class="muted">Możesz już zamknąć tę stronę.</p>
</div>
@endsection
