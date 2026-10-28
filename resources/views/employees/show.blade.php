@extends('layouts.app')
@section('title', $employee->fullName())
@section('content')
<header class="board-header">
<div>
<a class="back-link" href="{{ route('companies.board', [$company, 'employees']) }}">← Baza pracowników</a>
<h1>
<span class="avatar avatar-purple employee-title-avatar">{{ $employee->initials() }}</span>{{ $employee->fullName() }}</h1>
<p>{{ $employee->email }} <span class="inline-dot">·</span> {{ $employee->department ?? 'Dział nieprzypisany' }}</p>
</div>
<span class="status status-{{ $employee->profile_completed_at ? 'signed' : 'awaiting_data' }} detail-status">{{ $employee->profile_completed_at ? 'Dane uzupełnione' : 'Oczekuje na dane' }}</span>
</header>
<div class="employee-content">
@if($employee->personal_data['profile_review_required'] ?? false)
<div class="info-note"><x-icon name="users" :size="23" /><div><h3>Sprawdź aktualność danych pracownika</h3><p>Wybierz aktualną wersję formularza przed przygotowaniem kolejnych dokumentów.</p>@if($profileReviewItem)<a class="text-link" href="{{ route('monday.items.show', [$profileReviewItem->monday_board_id, $profileReviewItem]) }}">Sprawdź wersje danych</a>@endif</div></div>
@elseif(!$employee->profile_completed_at)<div class="info-note">
<x-icon name="clock" :size="23" />
<div>
<h3>Pracownik nie uzupełnił jeszcze formularza</h3>
<p>Wyślij przypomnienie lub skopiuj indywidualny link do formularza.</p>
<div class="inline-actions">
<form method="post" action="{{ route('companies.employees.invite', [$company, $employee]) }}">@csrf<button type="submit" class="button button-primary">Wyślij przypomnienie</button>
</form>
<button type="button" class="button button-secondary" data-copy="{{ route('onboarding.show', $employee->form_token) }}">Kopiuj link</button>
</div>
</div>
</div>@endif
@foreach(\App\Support\HrForms::profileSections() as $title => $fields)
@php($sectionValues = collect($fields)->mapWithKeys(fn ($field, $key) => [$key => in_array($key, ['first_name', 'last_name', 'email', 'phone', 'pesel']) ? $employee->{$key} : ($employee->personal_data[$key] ?? null)])->filter(fn ($value) => $value !== null && $value !== ''))
@if($sectionValues->isNotEmpty())
<section class="profile-section">
<h2>{{ explode(' / ', $title)[0] }}</h2>
<dl class="profile-details">@foreach($fields as $key => $field)@php($value = in_array($key, ['first_name', 'last_name', 'email', 'phone', 'pesel']) ? $employee->{$key} : ($employee->personal_data[$key] ?? null))@if($value !== null && $value !== '')<div>
<dt>{{ explode(' / ', $field['label'])[0] }}</dt>
<dd>{{ \App\Support\ProfileSource::display($key, $value) }}</dd>
</div>@endif
@endforeach</dl>
</section>
@endif
@endforeach
@if(!empty($employee->personal_data['attachments']) || $sourceProfileFiles->contains(fn ($profile) => $profile->assets->isNotEmpty()))
<section class="profile-section">
<h2>Załączniki do danych pracownika</h2>
<div class="monday-file-list">
@foreach($employee->personal_data['attachments'] ?? [] as $file)
<div class="monday-file"><x-icon name="file" :size="22" /><div><strong>{{ $file['name'] }}</strong><small>Legitymacja ucznia / studenta - {{ \Carbon\Carbon::parse($file['uploaded_at'])->format('d.m.Y') }}</small></div><a class="button button-secondary" href="{{ route('companies.employees.files', [$company, $employee, $file['id']]) }}" aria-label="Pobierz {{ $file['name'] }}"><x-icon name="download" :size="17" /> Pobierz</a></div>
@endforeach
@foreach($sourceProfileFiles as $profile)
@foreach($profile->assets->unique('id') as $file)
<div class="monday-file"><x-icon name="file" :size="22" /><div><strong>{{ $file->name }}</strong><small>Formularz z {{ $profile->document_date?->format('d.m.Y') ?? $profile->created_at->format('d.m.Y') }} - {{ ($employee->personal_data['monday_profile_id'] ?? null) == $profile->id ? 'wybrana wersja danych' : 'inna wersja danych' }}</small></div>@if($file->path)<a class="button button-secondary" href="{{ route('monday.items.asset', [$profile->board, $profile, $file]) }}" aria-label="Pobierz {{ $file->name }}"><x-icon name="download" :size="17" /> Pobierz</a>@else<span class="tag">Brak pliku</span>@endif</div>
@endforeach
@endforeach
</div>
</section>
@endif
<section class="profile-section">
<h2>Dokumenty pracownika</h2>
<div class="table-scroll">
<table class="simple-table">
<thead>
<tr>
<th>Dokument</th>
<th>Data</th>
<th>Status</th>
<th>
</th>
</tr>
</thead>
<tbody>@forelse($records as $record)<tr>
<td>{{ \App\Support\HrForms::kinds()[$record->kind] }}</td>
<td>{{ ($record->concluded_at ?? $record->mondayItem?->document_date ?? $record->signed_at ?? $record->created_at)->format('d.m.Y') }}</td>
<td>
<span class="tag">{{ $record->statusLabel() }}</span>
</td>
<td>
<a class="text-link" href="{{ route('companies.records.show', [$company, $record]) }}">Otwórz <x-icon name="arrow" :size="15" />
</a>
</td>
</tr>@empty<tr>
<td colspan="4">Nie ma jeszcze dokumentów tej osoby.</td>
</tr>@endforelse</tbody>
</table>
</div>
</section>
@can('update', $company)<details id="edit" class="employee-edit">
<summary>
<x-icon name="user-pen" :size="20" /> Aktualizuj dane po weryfikacji zgłoszenia</summary>
<form class="form-page" method="post" enctype="multipart/form-data" data-conditional-form action="{{ route('companies.employees.update', [$company, $employee]) }}">@csrf @method('patch')
@foreach(\App\Support\HrForms::profileSections() as $title => $fields)
<fieldset class="form-section" @if(array_key_exists('registered_country', $fields)) data-condition="same_registered_address" data-values="0" @endif>
<legend>{{ explode(' / ', $title)[0] }}</legend><div class="form-grid">
@foreach($fields as $key => $field)
@php($field['required'] = in_array($key, ['first_name', 'last_name', 'email']))
@php($field['readonly'] = $key === 'pesel')
<x-field :name="$key" :value="in_array($key, ['first_name', 'last_name', 'email', 'phone', 'pesel']) ? $employee->{$key} : ($employee->personal_data[$key] ?? '')" :field="$field" />
@endforeach
</div></fieldset>
@endforeach
<fieldset class="form-section" data-condition="is_student" data-values="1"><legend>Legitymacja ucznia / studenta</legend><p class="field-hint">PDF, JPG lub PNG. Do 3 nowych plików po 5 MB. Dotychczasowe załączniki zostają zachowane.</p><x-field name="student_documents" :field="['label' => 'Dodaj skany legitymacji', 'type' => 'file']" /></fieldset>
<div class="form-footer">
<button class="button button-primary" type="submit">Zapisz aktualne dane</button>
</div>
</form>
</details>@endcan
</div>
@endsection
