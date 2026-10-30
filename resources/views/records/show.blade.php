@extends('layouts.app')
@section('title', \App\Support\HrForms::kinds()[$record->kind].' · '.$record->employee->fullName())
@section('content')
@php($canManageRecord = ! isset($record->data['monday_source']) || auth()->user()->can('update', $company))
<header class="board-header">
<div>
<a class="back-link" href="{{ route('companies.board', [$company, ['contract' => 'contracts', 'amendment' => 'amendments', 'termination' => 'terminations', 'data_change' => 'changes', 'multisport' => 'multisport'][$record->kind]]) }}">← Wróć do tablicy</a>
<h1>{{ $record->employee->fullName() }}</h1>
<p>{{ \App\Support\HrForms::kinds()[$record->kind] }} <span class="inline-dot">·</span> {{ $record->department ?? $company->name }}</p>
</div>
<div class="board-header-actions">
@if($record->canEditSubmission())<a class="button button-secondary" href="{{ route('companies.records.edit', [$company, $record, 'panel' => request()->boolean('panel') ? 1 : null]) }}"><x-icon name="file-pen" :size="16" /> Edytuj zgłoszenie</a>@endif
<span class="status status-{{ $record->status }} detail-status">{{ $record->statusLabel() }}</span>
</div>
</header>
<div class="record-content">
@if(in_array($record->kind, ['contract', 'amendment', 'termination']))<ol class="workflow-steps">@foreach(['Zgłoszenie' => true, 'Dane pracownika' => (bool) $record->employee->profile_completed_at, 'Dokumenty' => (bool) $record->generated_at, 'Podpis' => (bool) $record->signed_at] as $label => $complete)<li class="{{ $complete ? 'complete' : '' }}">
<span class="workflow-step-marker">@if($complete)<x-icon name="check" :size="15" />@else{{ $loop->iteration }}@endif</span><span class="workflow-step-label">{{ $label }}</span></li>@endforeach</ol>@endif
<div class="record-columns">
<section>
<h2>Dane zgłoszenia</h2>
<dl class="detail-list">
<div>
<dt>Pracownik</dt>
<dd>
<a class="text-link" href="{{ route('companies.employees.show', [$company, $record->employee]) }}">{{ $record->employee->fullName() }} <x-icon name="external" :size="14" />
</a>
</dd>
</div>
<div>
<dt>E-mail</dt>
<dd>{{ $record->employee->email }}</dd>
</div>@if(in_array($record->kind, ['contract', 'amendment', 'termination']))<div>
<dt>Typ umowy</dt>
<dd>{{ $record->agreementLabel() }} ({{ $record->language }})</dd>
</div>
<div>
<dt>Dział / załącznik</dt>
<dd>{{ $record->department ?? '-' }}</dd>
</div>
<div>
<dt>Data zawarcia</dt>
<dd>{{ $record->concluded_at?->format('d.m.Y') ?? '-' }}</dd>
</div>
<div>
<dt>Data rozpoczęcia</dt>
<dd>{{ $record->starts_at?->format('d.m.Y') ?? '-' }}</dd>
</div>
<div>
<dt>{{ $record->kind === 'termination' ? 'Ostatni dzień pracy' : 'Data zakończenia' }}</dt>
<dd>{{ $record->ends_at?->format('d.m.Y') ?? '-' }}</dd>
</div>@endif<div>
<dt>Osoba zgłaszająca</dt>
<dd>{{ $record->data['reporter_name'] ?? $record->employee->fullName() }}</dd>
</div>
<div>
<dt>Zgłoszono</dt>
<dd>{{ $record->created_at->format('d.m.Y, H:i') }}</dd>
</div>@if($record->contract)<div>
<dt>Umowa pierwotna</dt>
<dd>
<a class="text-link" href="{{ route('companies.records.show', [$company, $record->contract]) }}">Umowa #{{ $record->contract_id }} · {{ $record->contract->concluded_at?->format('d.m.Y') }}</a>
</dd>
</div>@endif
@foreach(\App\Support\HrForms::additionalFields($record->kind) as $key => $field)@if(isset($record->data[$key]))<div>
<dt>{{ $field['label'] }}</dt>
<dd class="preserve-lines">{{ $field['options'][$record->data[$key]] ?? $record->data[$key] }}</dd>
</div>@endif
@endforeach
</dl>
@if($record->signed_at)<section class="signed-section">
<h2>
<x-icon name="signature" :size="21" /> Oświadczenie złożone</h2>
<p>Podpisujący: <strong>{{ $record->data['signer_name'] ?? $record->employee->fullName() }}</strong>
<br>{{ $record->signed_at->format('d.m.Y, H:i') }}</p>@if(isset($record->data['signature_image']))<img class="signature-preview" src="{{ $record->data['signature_image'] }}" alt="Podpis pracownika">@endif<a class="button button-secondary" href="{{ route('companies.records.document', [$company, $record, 'statement' => 1]) }}">
<x-icon name="download" :size="17" /> Pobierz oświadczenie PDF</a>
</section>@endif
</section>
<aside class="record-actions-panel">
<h2>Dokumenty i kolejne kroki</h2>
@if($record->status === 'awaiting_data')<div class="action-state">
<span class="state-icon">
<x-icon name="clock" :size="24" />
</span>
<h3>Czekamy na dane pracownika</h3>
<p>Pracownik uzupełni dane przez indywidualny link. Potem wygenerujesz dokumenty.</p>
<form method="post" action="{{ route('companies.employees.invite', [$company, $record->employee]) }}">@csrf<button class="button button-primary" type="submit">
<x-icon name="mail" :size="17" /> Wyślij przypomnienie</button>
</form>
<button class="button button-secondary" type="button" data-copy="{{ route('onboarding.show', $record->employee->form_token) }}">
<x-icon name="copy" :size="16" /> Kopiuj link do danych</button>
</div>
@elseif(in_array($record->kind, ['contract', 'amendment', 'termination']))
    @if($record->generated_at)<div class="document-ready">
<span class="pdf-icon">PDF</span>
<div>
<strong>{{ $record->kind === 'contract' ? 'Umowa, oświadczenie i załącznik' : \App\Support\HrForms::kinds()[$record->kind] }}</strong>
<small>Wygenerowano {{ $record->generated_at->format('d.m.Y, H:i') }}</small>
</div>
</div>
<div class="document-buttons">
<a class="button button-secondary" href="{{ route('companies.records.document', [$company, $record, 'preview' => 1]) }}" target="_blank" rel="noopener">
<x-icon name="external" :size="16" /> Podgląd</a>
<a class="button button-secondary" href="{{ route('companies.records.document', [$company, $record]) }}">
<x-icon name="download" :size="16" /> Pobierz PDF</a>
</div>@else<p class="muted">Dokumenty powstaną z szablonów firmy i danych pracownika.</p>@endif
    @if($canManageRecord && in_array($record->status, ['draft', 'ready']))<form method="post" action="{{ route('companies.records.generate', [$company, $record]) }}">@csrf
@if(request()->boolean('panel'))
<input type="hidden" name="panel" value="1">
@endif
<button class="button {{ $record->generated_at ? 'button-secondary' : 'button-primary' }} button-full" type="submit">
<x-icon name="file" :size="17" /> {{ $record->generated_at ? 'Wygeneruj ponownie' : 'Generuj dokumenty' }}</button>
</form>
<a class="subtle-link" href="{{ route('companies.templates.index', $company) }}">Przejdź do szablonów dokumentów <x-icon name="arrow" :size="14" />
</a>@endif
    @if($canManageRecord && in_array($record->status, ['ready', 'sent']))<form method="post" action="{{ route('companies.records.send', [$company, $record]) }}">@csrf<button class="button button-primary button-full" type="submit">
<x-icon name="mail" :size="17" /> {{ $record->status === 'sent' ? 'Wyślij ponownie do podpisu' : 'Wyślij do podpisu' }}</button>
</form>@endif
    @if($canManageRecord && $record->status === 'sent')<button class="button button-secondary button-full" type="button" data-copy="{{ route('signing.show', $record->signing_token) }}">
<x-icon name="copy" :size="16" /> Kopiuj link do podpisu</button>
<p class="field-hint">Link ważny do {{ $record->signing_expires_at?->format('d.m.Y') }}.</p>@endif
@endif
@if($record->mail_status)<div class="mail-status mail-{{ $record->mail_status }}">
<x-icon name="mail" :size="17" />
<span>{{ ['queued' => 'Wiadomość oczekuje w kolejce e-mail.', 'sent' => 'Wiadomość została wysłana.', 'failed' => 'Nie udało się wysłać wiadomości. Spróbuj ponownie.', 'suppressed' => 'Wysyłka wyłączona. Wiadomość nie została wysłana i nie czeka w kolejce.'][$record->mail_status] ?? 'Sprawdź stan wysyłki.' }}@if($record->mail_status === 'sent')<small>{{ $record->mail_sent_at?->format('d.m.Y, H:i') }}</small>@endif</span>
</div>@endif
@if($canManageRecord && $record->signed_at && in_array($record->mail_status, ['failed','suppressed']) && app(\App\Services\OutgoingMail::class)->enabled())
<form method="post" action="{{ route('companies.records.send', [$company, $record]) }}">
    @csrf
    <button class="button button-primary button-full" type="submit"><x-icon name="mail" :size="17" /> Ponów wysyłkę oświadczenia</button>
</form>
@endif
@if($canManageRecord && $record->kind === 'contract' && $record->status === 'signed')<div class="record-secondary-actions">
<h3>Dalsza obsługa umowy</h3>
<a class="button button-secondary" href="{{ route('companies.records.create', [$company, 'amendment', 'contract' => $record->id]) }}">
<x-icon name="file-pen" :size="16" /> Zgłoś aneks</a>
<a class="button button-secondary" href="{{ route('companies.records.create', [$company, 'termination', 'contract' => $record->id]) }}">
<x-icon name="file-minus" :size="16" /> Rozwiąż umowę</a>
</div>@endif
@if($record->kind === 'data_change' && $record->signed_at)<div class="info-note small-note">
<p>Zgłoszenie zachowuje oryginalną treść. Po sprawdzeniu zaktualizuj dane w profilu pracownika.</p>
</div>
<a class="button button-secondary button-full" href="{{ route('companies.employees.show', [$company, $record->employee]) }}#edit">Otwórz profil pracownika</a>
<form method="post" action="{{ route('companies.records.update', [$company, $record]) }}">@csrf @method('patch')<input type="hidden" name="status" value="reviewed">
<button class="button button-primary button-full" type="submit">Oznacz jako zweryfikowane</button>
</form>@endif
@if($canManageRecord && in_array($record->kind, ['contract', 'amendment', 'termination']) && !$record->signed_at && $record->status !== 'not_concluded')<details class="withdraw-document">
<summary>Dokument nie zostanie zawarty?</summary>
<p>Oznacz dokument jako niezawarty. Link do podpisu przestanie działać.</p>
<form method="post" action="{{ route('companies.records.update', [$company, $record]) }}">@csrf @method('patch')<input type="hidden" name="status" value="not_concluded">
<button class="button button-secondary" type="submit">Oznacz jako niezawartą</button>
</form>
</details>@endif
</aside>
</div>
</div>
@endsection
