@php
    $compact = $compact ?? false;
    $fieldFiles = $item->assets->filter(fn ($file) => $file->pivot->column_key === $column)->unique('id');
    $detailsUrl = route('monday.items.show', [$mondayBoard, $item]);
@endphp
@if(\App\Support\MondayFields::isAction($label))
    @if(! $compact && $item->record && ! $item->record->isImported())
        <a class="button button-secondary" href="{{ route('companies.records.show', [$mondayBoard->company_id, $item->record]) }}">Otwórz dokument <x-icon name="arrow" :size="15" /></a>
    @elseif(! $compact && $canEdit && $item->record && in_array($item->record->kind, ['contract', 'amendment', 'termination'], true) && in_array($item->record->status, ['draft', 'ready'], true))
        <div class="monday-field-files">
            <form method="post" action="{{ route('companies.records.generate', [$mondayBoard->company_id, $item->record]) }}">
                @csrf
                <input type="hidden" name="revision" value="{{ $item->revision }}">
                @if(request()->boolean('panel'))<input type="hidden" name="panel" value="1">@endif
                <button class="button button-primary" type="submit" @disabled($generationBlockers !== [])>Generuj dokumenty</button>
            </form>
            @if($generationBlockers)<a class="text-link" href="#document-actions">Sprawdź, czego brakuje do generowania <x-icon name="arrow" :size="14" /></a>@endif
        </div>
    @else
        <a class="text-link" href="{{ $compact ? $detailsUrl : '' }}#document-actions"><x-icon name="file" :size="16" /> Dokumenty i generowanie</a>
    @endif
@elseif($fieldFiles->isNotEmpty())
    <span class="monday-field-files">
        @foreach($compact ? $fieldFiles->take(1) : $fieldFiles as $file)
            @if($file->path)
                <a class="text-link" href="{{ route('monday.items.asset', [$mondayBoard, $item, $file]) }}"><x-icon name="download" :size="15" /> {{ $compact ? 'Pobierz plik' : $file->name }}</a>
            @else
                <span class="muted">{{ $file->name }} - brak pliku</span>
            @endif
        @endforeach
        @if($compact && $fieldFiles->count() > 1)<a class="text-link" href="{{ $detailsUrl }}#files">Wszystkie pliki ({{ $fieldFiles->count() }})</a>@endif
    </span>
@elseif(str_contains($value, '/resources/'))
    <span class="muted">Plik wymaga przypisania</span>
@elseif(\App\Support\MondayFields::isRelation($label))
    @if(str_contains(\App\Support\MondayFields::normalize($label), 'baza pracowników') && $item->employee)
        <a class="text-link" href="{{ route('companies.employees.show', [$mondayBoard->company_id, $item->employee]) }}">{{ $item->employee->fullName() }} <x-icon name="arrow" :size="14" /></a>
    @elseif($item->parent)
        <a class="text-link" href="{{ route('monday.items.show', [$item->parent->monday_board_id, $item->parent]) }}">{{ $item->parent->name }} <x-icon name="arrow" :size="14" /></a>
    @else
        @if(in_array($mondayBoard->kind, ['amendments', 'terminations', 'contract_statements', 'amendment_statements', 'termination_statements'], true))
            <a class="text-link" href="{{ $compact ? $detailsUrl : '' }}#relation">Sprawdź powiązanie</a>
        @else
            <span class="muted">Brak przypisanego pracownika</span>
        @endif
    @endif
@elseif($url = \App\Support\MondayFields::url($value))
    @if($mondayBoard->kind === 'links' && $mondayBoard->company)
        @php($formLink = \App\Support\MondayFields::formLink($mondayBoard->company, $item->name))
        @if($formLink && (! $formLink['requires_configuration'] || $mondayBoard->company->hasFormConfiguration()))
            <a class="text-link" href="{{ $formLink['url'] }}">{{ $formLink['label'] }} <x-icon name="arrow" :size="14" /></a>
        @elseif($formLink)
            <span class="muted">Formularz wymaga ustawienia adresu HR i RODO.</span>
        @else
            <span class="muted">Ten formularz nie został jeszcze uruchomiony w Kadrelio.</span>
        @endif
    @else
        <a class="text-link" href="{{ $url }}" target="_blank" rel="noopener noreferrer">Otwórz link <x-icon name="external" :size="14" /></a>
    @endif
@elseif(\App\Support\MondayFields::isFile($label))
    <span class="muted">Brak pliku</span>
@elseif(\App\Support\MondayFields::isPlaceholder($value))
    <span class="muted">{{ \App\Support\MondayFields::normalize($label) === 'typ umowy' ? 'Nie wybrano typu umowy' : 'Nie wybrano wartości' }}</span>
@else
    <span class="preserve-lines" data-field-value>{{ $value !== '' ? $value : '-' }}</span>
@endif
