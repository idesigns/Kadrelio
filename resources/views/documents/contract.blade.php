<!DOCTYPE html>
<html lang="{{ strtolower($record->language) }}">
<head>
<meta charset="utf-8">
<title>Dokument #{{ $record->id }}</title>
<style>@page{size:A4;margin:22mm}*{box-sizing:border-box}body{font-family:Arial,sans-serif;color:#20212b;font-size:11pt;line-height:1.55}section+section{break-before:page}h1{font-size:17pt;line-height:1.3;margin:0 0 22pt}.document-body{white-space:pre-wrap;overflow-wrap:anywhere}.document-meta{font-size:9pt;color:#676879;margin-bottom:24pt}footer{margin-top:30pt;font-size:8pt;color:#676879;border-top:1px solid #d5d8e4;padding-top:8pt}</style>
</head>
<body>@foreach($sections as $section)<section>
<div class="document-meta">{{ $record->company->name }} · {{ $record->language }} · Dokument #{{ $record->id }}</div>
<h1>{{ $section['title'] }}</h1>
<div class="document-body">{{ $section['body'] }}</div>
<footer>Wygenerowano w Kadrelio · {{ now()->format('d.m.Y H:i') }}</footer>
</section>@endforeach</body>
</html>
