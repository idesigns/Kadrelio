@extends('layouts.public')
@section('title', 'Formularz jeszcze nieaktywny')
@section('content')
<div class="submission-success"><span><x-icon name="clock" :size="32" /></span><h1>Formularz jeszcze nieaktywny</h1><p>Firma przygotowuje formularze. Skontaktuj się z działem HR, aby ustalić sposób przekazania danych.</p><a class="button button-secondary" href="{{ route('home') }}">Wróć do Kadrelio</a></div>
@endsection
