<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="utf-8">
<title>Oświadczenie #{{ $record->id }}</title>
<style>@page{size:A4;margin:22mm}body{font-family:Arial,sans-serif;color:#20212b;font-size:11pt;line-height:1.6}h1{font-size:18pt;line-height:1.35;margin-bottom:24pt}.company{font-size:10pt;color:#676879;margin-bottom:16pt}table{width:100%;border-collapse:collapse;margin:20pt 0}td{border:1px solid #d5d8e4;padding:8pt;vertical-align:top}td:first-child{width:42%;color:#676879}img{max-width:280pt;max-height:100pt;object-fit:contain}footer{font-size:8pt;color:#676879;margin-top:26pt;overflow-wrap:anywhere}.preserve{white-space:pre-wrap}</style>
</head>
<body>
<div class="company">{{ $record->data['company_name'] ?? $record->company->name }}</div>
<h1>{{ ['contract' => 'Oświadczenie o zawarciu umowy', 'amendment' => 'Oświadczenie o zawarciu aneksu', 'termination' => 'Potwierdzenie rozwiązania umowy', 'data_change' => 'Zmiana danych osobowych', 'multisport' => 'Oświadczenie zgody na kartę Multisport'][$record->kind] }}</h1>
<table>
<tr>
<td>Imię i nazwisko</td>
<td>{{ $record->data['signer_name'] ?? '' }}</td>
</tr>
<tr>
<td>{{ $record->data['identifier_label'] ?? 'PESEL' }}</td>
<td>{{ $record->data['signer_identifier'] ?? $record->data['signer_pesel'] ?? '' }}</td>
</tr>
<tr>
<td>Data podpisania</td>
<td>{{ $record->signed_at?->format('d.m.Y, H:i:s') }}</td>
</tr>@if($record->kind === 'termination')<tr>
<td>Data rozwiązania umowy</td>
<td>{{ $record->ends_at?->format('d.m.Y') }}</td>
</tr>@elseif($record->kind === 'amendment')<tr>
<td>Data zawarcia aneksu</td>
<td>{{ $record->concluded_at?->format('d.m.Y') }}</td>
</tr>@endif @foreach(\App\Support\HrForms::additionalFields($record->kind) as $key => $field)@if(isset($record->data[$key]))<tr>
<td>{{ $field['label'] }}</td>
<td class="preserve">{{ $field['options'][$record->data[$key]] ?? $record->data[$key] }}</td>
</tr>@endif @endforeach</table>
<p>@if($record->kind === 'contract')Oświadczam, że zawieram {{ $record->isB2b() ? 'umowę B2B' : 'umowę zlecenia' }} z {{ $record->data['company_name'] ?? $record->company->name }}, a znajdujące się w niej dane są aktualne i zgodne z prawdą.@elseif($record->kind === 'amendment')Oświadczam, że akceptuję zmiany zawarte w aneksie.@elseif($record->kind === 'termination')Potwierdzam rozwiązanie umowy. Wszystkie informacje poufne zostały zniszczone lub zwrócone zleceniodawcy. Wszystkie kanały komunikacji wykorzystywane podczas wykonywania umowy zostały wyczyszczone, a rozmowy skasowane.@elseif($record->kind === 'multisport')Potwierdzam zapoznanie się z zasadami Benefit Systems i zgodę na potrącenie składki za wybrane karty.@else Potwierdzam, że podane dane są aktualne i zgodne z prawdą.@endif</p>
<h2 style="font-size:12pt;margin-top:28pt">Podpis</h2>@if(isset($record->data['signature_image']))<img src="{{ $record->data['signature_image'] }}" alt="Podpis graficzny">@endif<p>{{ $record->data['signer_name'] ?? '' }}</p>
<footer>Oświadczenie #{{ $record->id }} · Kadrelio<br>Data zapisu: {{ $record->signed_at?->toIso8601String() }}<br>@if(isset($record->data['signed_document_hash']))SHA-256 dokumentu: {{ $record->data['signed_document_hash'] }}<br>@endif Adres IP: {{ $record->data['signed_ip'] ?? '-' }}@if(isset($record->data['email_confirmed_at']))<br>Potwierdzenie e-mail: {{ $record->data['email_confirmed_at'] }}<br>Adres IP potwierdzenia: {{ $record->data['confirmation_ip'] }}@endif</footer>
</body>
</html>
