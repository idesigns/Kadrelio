@extends('layouts.app')
@section('title', 'Administracja')
@section('content')
<header class="board-header">
<div>
<div class="breadcrumb">Administracja globalna</div>
<h1>Użytkownicy systemu</h1>
<p>Role i przypisane firmy w jednym widoku.</p>
</div>
</header>
<div class="settings-content">
@include('admin.user-list')
</div>
@endsection
