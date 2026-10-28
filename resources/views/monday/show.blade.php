@extends('layouts.app')
@section('title', $item->name ?: 'Szczegóły wpisu')
@section('content')
@php($canEdit = auth()->user()->isAdmin() || ($company && auth()->user()->can('update', $company)))
@php($workspaceBoard = ['profiles' => 'employees', 'contracts' => 'contracts', 'amendments' => 'amendments', 'terminations' => 'terminations', 'contract_statements' => 'statements', 'amendment_statements' => 'statements', 'termination_statements' => 'statements', 'changes' => 'changes', 'multisport' => 'multisport'][$mondayBoard->kind] ?? null)
<header class="board-header"><div><a class="back-link" href="{{ $company && $workspaceBoard ? route('companies.board', [$company, $workspaceBoard]) : route('monday.boards.show', $mondayBoard) }}">← {{ $company && $workspaceBoard ? \App\Support\HrForms::boards()[$workspaceBoard]['title'] : $mondayBoard->name }}</a><h1>{{ $item->name ?: 'Bez nazwy' }}</h1><p>{{ $item->group_name ?? 'Bez grupy' }}@if($item->document_date)<span class="inline-dot">·</span>{{ $item->document_date->format('d.m.Y') }}
@endif
</p></div>@if($item->status)<span class="status detail-status monday-source-status">{{ $item->status }}</span>
@endif
</header>
<div class="record-content monday-detail" data-detail-title="{{ $mondayBoard->kind === 'profiles' ? 'Profil pracownika' : 'Szczegóły dokumentu' }}">
@if($item->issues)<div class="info-note"><x-icon name="inbox" :size="22" /><div><h3>Dane do sprawdzenia</h3><p>{{ implode(' · ', array_map(fn ($issue) => \App\Support\MondayFields::issueLabels()[$issue] ?? $issue, $item->issues)) }}</p></div></div>
@endif

<div class="monday-detail-columns"><div>
<section class="profile-section"><h2>Dane wpisu</h2><dl class="detail-list monday-field-list">
@foreach($item->source_data['headers'] as $column => $label)
@php($value = $item->fields[$column] ?? '')
@if($label !== 'Item ID (auto generated)' && ($value !== '' || \App\Support\MondayFields::isAction($label)))
@include('monday.field')

@endif

@endforeach

</dl><details class="monday-source-details"><summary>Pozostałe pola</summary><dl class="detail-list">@foreach($item->source_data['headers'] as $column => $label)@if($label !== 'Item ID (auto generated)' && ! \App\Support\MondayFields::isAction($label) && ($item->fields[$column] ?? '') === '')
@include('monday.field', ['value' => ''])
@endif
 
@endforeach
</dl></details></section>
<section class="profile-section" id="files"><h2>Załączniki <span class="monday-count">{{ $item->assets->unique('id')->count() }}</span></h2><div class="monday-file-list">@forelse($item->assets->unique('id') as $file)<div class="monday-file"><x-icon name="file" :size="22" /><div><strong>{{ $file->name }}</strong><small>{{ $file->path ? 'Dokument pracownika' : 'Plik nie został jeszcze dołączony' }}</small></div>@if($file->path)<a class="button button-secondary" href="{{ route('monday.items.asset', [$mondayBoard, $item, $file]) }}" aria-label="Pobierz {{ $file->name }}"><x-icon name="download" :size="17" /> Pobierz</a>
@else
<span class="tag">Brak pliku</span>
@endif
</div>
@empty
<p class="muted">Brak załączników.</p>
@endforelse
</div></section>
<section class="profile-section" id="updates"><h2>Komentarze <span class="monday-count">{{ $item->updates->count() }}</span></h2>@forelse($item->updates as $update)
@php($updateValue = fn ($label) => \App\Support\MondayFields::value($update->payload['headers'], $update->payload['values'], $label))
<article class="monday-comment"><header><span class="avatar avatar-blue">{{ mb_strtoupper(mb_substr($updateValue('User'), 0, 1)) }}</span><div><strong>{{ $updateValue('User') }}</strong><small>{{ $updateValue('Created At') }}</small></div></header><p class="preserve-lines">{{ $updateValue('Update Content') }}</p></article>

@empty
<p class="muted">Brak komentarzy.</p>
@endforelse
</section>
<div data-item-history>
@if($item->revisions->isNotEmpty())<section class="profile-section"><h2>Historia zmian</h2>@foreach($item->revisions->sortByDesc('id') as $revision)<article class="monday-comment"><header><strong>{{ $revision->user->name }}</strong><small>{{ $revision->created_at->format('d.m.Y, H:i') }}</small></header><p>{{ $revision->changes['reason'] }}</p>@if(isset($revision->changes['fields']))<dl class="detail-list">@foreach($revision->changes['fields'] as $column => $change)<div><dt>{{ $item->source_data['headers'][$column] ?? 'Grupa' }}</dt><dd><del>{{ $change['before'] ?: '-' }}</del> → {{ $change['after'] ?: '-' }}</dd></div>
@endforeach
</dl>
@else
<p class="muted">{{ isset($revision->changes['review']) ? 'Potwierdzono sprawdzenie danych.' : (isset($revision->changes['active_profile']) ? 'Wybrano aktualną wersję danych pracownika.' : 'Zatwierdzono powiązanie dokumentów.') }}</p>
@endif
</article>
@endforeach
</section>
@endif

</div>
</div><aside class="monday-detail-aside">
@if($hasDocumentActions)@include('monday.actions')@endif
@if($item->issues)
<section class="profile-section" data-item-review><h2>Sprawdzenie danych</h2>
@if($item->reviewed_at)
<span class="tag monday-matched">Sprawdzono {{ $item->reviewed_at->format('d.m.Y, H:i') }}</span>
@elseif(auth()->user()->isAdmin() || ($company && auth()->user()->can('update', $company)))
<form class="monday-compact-form" method="post" action="{{ route('monday.items.review', [$mondayBoard, $item]) }}">@csrf<input type="hidden" name="revision" value="{{ $item->revision }}"><label>Wynik sprawdzenia<textarea name="reason" required minlength="3" maxlength="1000" rows="2"></textarea></label><button class="button button-secondary" type="submit">Potwierdź sprawdzenie danych</button></form>
@endif
</section>
@endif
@if($item->employee)<section class="profile-section"><h2>Pracownik</h2><a class="person-cell" href="{{ route('companies.employees.show', [$company, $item->employee]) }}"><span class="avatar avatar-purple">{{ $item->employee->initials() }}</span><span data-item-employee-name>{{ $item->employee->fullName() }}</span><x-icon name="arrow" :size="16" /></a></section>
@endif

@if($candidateKind)<section class="profile-section" id="relation"><h2>Powiązany dokument</h2><span class="tag {{ in_array($item->relation_state, ['matched', 'manual']) ? 'monday-matched' : 'monday-review' }}">{{ $item->relationLabel() }}</span>@if($item->parent)<p><a class="text-link" href="{{ route('monday.items.show', [$item->parent->board, $item->parent]) }}">{{ $item->parent->name }} · {{ $item->parent->document_date?->format('d.m.Y') ?? 'Bez daty' }} <x-icon name="arrow" :size="14" /></a></p>
@endif

@if($canEdit && ! ($item->record?->data['monday_workflow_active'] ?? false))
<details class="monday-source-details" @if(!in_array($item->relation_state, ['matched', 'manual']) || request()->filled('candidate_q')) open 
@endif
><summary>{{ $item->parent_id ? 'Sprawdź lub zmień powiązanie' : 'Wybierz właściwy dokument' }}</summary><form method="get" class="monday-compact-form"><label>Szukaj dokumentu po nazwisku lub ID<input name="candidate_q" value="{{ request('candidate_q') }}" maxlength="150"></label><button type="submit" class="button button-secondary">Szukaj dokumentu</button></form><form method="post" action="{{ route('monday.items.relation', [$mondayBoard, $item]) }}" class="monday-compact-form">@csrf<input type="hidden" name="revision" value="{{ $item->revision }}"><label>Dokument<select name="parent_id" required><option value="">Wybierz dokument</option>@foreach($candidates as $candidate)<option value="{{ $candidate->id }}" @selected($candidate->id === $item->parent_id)>{{ $candidate->name }} · {{ $candidate->document_date?->format('d.m.Y') ?? 'Bez daty' }} · {{ $candidate->source_id }}</option>
@endforeach
</select></label>@if($candidates->isEmpty())<p class="muted">Brak dopasowań. Wyszukaj dokument po nazwisku lub identyfikatorze dokumentu.</p>
@endif
<label>Uzasadnienie wyboru<textarea name="reason" required minlength="3" maxlength="1000" rows="2"></textarea></label><button type="submit" class="button button-primary" @disabled($candidates->isEmpty())>Zatwierdź powiązanie</button></form></details>

@endif
</section>
@endif

@if($versions->isNotEmpty())<section class="profile-section"><h2>Wersje danych pracownika</h2><div class="monday-version-list">@foreach($versions as $version)<a class="monday-version {{ $version->id === $item->id ? 'is-current' : '' }}" href="{{ route('monday.items.show', [$version->board, $version]) }}"><span>{{ $version->name }}</span><small>{{ $version->document_date?->format('d.m.Y') ?? 'Data niepodana' }}@if(($item->employee->personal_data['monday_profile_id'] ?? null) === $version->id) · Aktualne dane
@endif
</small></a>
@endforeach
</div>
@if($mondayBoard->kind === 'profiles' && (auth()->user()->isAdmin() || auth()->user()->can('update', $company)))<form method="post" action="{{ route('monday.items.profile', [$mondayBoard, $item]) }}" class="monday-compact-form">@csrf<input type="hidden" name="revision" value="{{ $item->revision }}"><label>Dlaczego wybierasz tę wersję?<textarea name="reason" rows="2" required minlength="3" maxlength="1000"></textarea></label><button type="submit" class="button button-primary">Użyj tej wersji danych</button></form>
@endif

</section>
@endif

@if($item->children->isNotEmpty())<section class="profile-section"><h2>Powiązane dokumenty</h2><div class="monday-version-list">@foreach($item->children as $child)<a class="monday-version" href="{{ route('monday.items.show', [$child->board, $child]) }}"><span>{{ $child->board->name }}</span><small>{{ $child->document_date?->format('d.m.Y') ?? 'Bez daty' }} · {{ $child->status ?? $child->relationLabel() }}</small></a>
@endforeach
</div></section>
@endif

</aside></div>
@if(auth()->user()->isAdmin() || ($company && auth()->user()->can('update', $company)))
<details class="employee-edit" id="edit"><summary><x-icon name="file-pen" :size="20" /> Edytuj dane</summary><form method="post" action="{{ route('monday.items.update', [$mondayBoard, $item]) }}" class="form-page" data-full-edit>@csrf @method('patch')<input type="hidden" name="revision" value="{{ $item->revision }}"><p class="muted">Zapisz aktualne dane i podaj powód zmiany. Wcześniejsze wartości pozostaną w historii zmian.</p><div class="form-grid">@foreach($editableColumns as $column => $label)<label class="field-label">{{ $column === 'A' ? 'Nazwa' : $label }}@php($options = \App\Support\MondayFields::options($label, $mondayBoard->kind, $item->fields[$column] ?? ''))
@if($options)<select name="fields[{{ $column }}]"><option value="" @selected(! in_array(old('fields.'.$column, $item->fields[$column] ?? ''), $options, true))>Wybierz wartość</option>@foreach($options as $option)<option @selected(old('fields.'.$column, $item->fields[$column] ?? '') === $option)>{{ $option }}</option>@endforeach</select>
@else<textarea name="fields[{{ $column }}]" rows="2" maxlength="30000">{{ old('fields.'.$column, $item->fields[$column] ?? '') }}</textarea>@endif</label>
@endforeach
<label class="field-label">Grupa<select name="group_name"><option value="">Bez grupy</option>@foreach($mondayBoard->groups as $groupName)<option @selected($item->group_name === $groupName)>{{ $groupName }}</option>
@endforeach
</select></label><label class="field-label">Powód zmiany<textarea name="reason" required minlength="3" maxlength="1000" rows="2">{{ old('reason') }}</textarea></label></div><div class="form-footer"><button type="submit" class="button button-primary">Zapisz zmiany</button></div></form></details>

@endif

</div>

@endsection

