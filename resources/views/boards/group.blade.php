    @php([$label, $color] = \App\Support\HrForms::boardGroups($board)[$group] ?? ['Wszystkie elementy', 'blue'])
    <section class="board-group group-{{ $color }}" data-paginated-group="{{ $group }}" data-page-name="{{ $items->getPageName() }}" data-current-page="{{ $items->currentPage() }}" aria-label="{{ $label }}">
        <div class="group-heading">
<button type="button" data-toggle-group aria-expanded="true" aria-controls="group-{{ $group }}">
<x-icon name="chevron" :size="18" />
<h2>{{ $label }}</h2>
</button>
<span>{{ $board === 'employees' ? 'Osoby' : 'Elementy' }}: {{ number_format($items->total(), 0, ',', ' ') }}</span>
</div>
        <div class="group-content" id="group-{{ $group }}"><div class="group-body"><div class="table-scroll">
<table class="monday-table board-table {{ $board === 'employees' ? 'employee-table' : 'workflow-table' }}" data-export-table>
<colgroup>
    <col class="board-col-check">
    <col>
    <col class="board-col-status">
    @if($board === 'employees')
        <col class="board-col-email">
        <col class="board-col-phone">
        <col class="board-col-department">
        <col class="board-col-date">
    @else
        <col class="board-col-document-type">
        <col class="board-col-department">
        <col class="board-col-date">
        <col class="board-col-date">
        <col class="board-col-files">
    @endif
    <col class="board-col-actions">
</colgroup>
<thead>
<tr>
<th class="check-cell">
<input type="checkbox" data-select-group aria-label="Zaznacz grupę {{ $label }}">
</th>
<th class="name-cell">Pracownik</th>@if($board === 'employees')<th>Status danych</th>
<th>E-mail</th>
<th>Telefon</th>
<th>Dział</th>
<th>Data wypełnienia</th>@else<th>{{ in_array($board, ['changes', 'multisport', 'statements']) ? 'Status oświadczenia' : 'Status umowy' }}</th>
<th>{{ in_array($board, ['changes', 'multisport', 'statements']) ? 'Rodzaj dokumentu' : 'Typ umowy' }}</th>
<th>Dział</th>
<th>{{ in_array($board, ['changes', 'multisport', 'statements']) ? 'Data złożenia' : 'Data rozpoczęcia' }}</th>
<th>{{ in_array($board, ['changes', 'multisport']) ? 'Zaktualizowano' : ($board === 'terminations' ? 'Ostatni dzień pracy' : 'Data zakończenia') }}</th>
<th>Dokumenty</th>@endif<th class="actions-cell">
</th>
</tr>
</thead>
<tbody>
        @foreach($items as $item)
            @php($employee = $board === 'employees' ? $item : $item->employee)
            <tr data-export-row>
<td class="check-cell">
<input type="checkbox" data-select-row aria-label="Zaznacz {{ $employee->fullName() }}">
</td>
<td class="name-cell">
<a class="person-cell" data-open-drawer title="{{ $employee->fullName() }}" href="{{ $board === 'employees' ? route('companies.employees.show', [$company, $employee]) : route('companies.records.show', [$company, $item]) }}">
<span class="avatar avatar-{{ ['blue', 'purple', 'pink', 'green', 'orange'][$employee->id % 5] }}">{{ $employee->initials() }}</span>
<span>{{ $employee->fullName() }}</span>
</a>
</td>
            @if($board === 'employees')
                <td class="status-cell">
<span class="status status-{{ $employee->profile_completed_at ? 'signed' : 'awaiting_data' }}">{{ $employee->profile_completed_at ? 'Dane uzupełnione' : 'Oczekuje na dane' }}</span>
</td>
<td>
<a class="cell-link" title="{{ $employee->email }}" href="mailto:{{ $employee->email }}">{{ $employee->email }}</a>
</td>
<td>{{ $employee->phone ?? '-' }}</td>
<td>{{ $employee->department ?? '-' }}</td>
<td>{{ $employee->profile_completed_at?->format('d.m.Y') ?? '-' }}</td>
            @else
                <td class="status-cell">
<a href="{{ route('companies.records.show', [$company, $item]) }}" data-open-drawer class="status status-{{ $item->status }}">{{ $board === 'statements' ? 'Złożone' : $item->statusLabel() }}</a>
</td>
@php($documentType = in_array($board, ['changes', 'multisport', 'statements']) ? \App\Support\HrForms::kinds()[$item->kind] : $item->agreementLabel().' ('.$item->documentLanguage().')')
<td title="{{ $documentType }}">{{ $documentType }}</td>
<td>
<span class="department-cell">{{ $item->department ?? $employee->department ?? '-' }}</span>
</td>
<td>{{ in_array($board, ['changes', 'multisport', 'statements']) ? $item->signed_at?->format('d.m.Y') : $item->starts_at?->format('d.m.Y') ?? '-' }}</td>
<td>{{ in_array($board, ['changes', 'multisport']) ? $item->updated_at->format('d.m.Y') : ($item->ends_at?->format('d.m.Y') ?? '-') }}</td>
@php($hasCurrentDocument = ! $item->isImported() && ($item->generated_at || $item->signed_at))
<td>@if(! $hasCurrentDocument && $item->mondayItem?->available_assets_count)<a class="document-cell" data-open-drawer href="{{ route('monday.items.show', [$item->mondayItem->monday_board_id, $item->mondayItem]) }}#files" aria-label="Załączniki {{ $employee->fullName() }}"><x-icon name="file" :size="17" /> Pliki ({{ $item->mondayItem->available_assets_count }})</a>@elseif($hasCurrentDocument)<a class="document-cell" href="{{ route('companies.records.document', [$company, $item, 'statement' => $board === 'statements' || in_array($item->kind, ['data_change', 'multisport']) ? 1 : 0]) }}" aria-label="Pobierz dokument {{ $employee->fullName() }}">
<x-icon name="file" :size="17" /> PDF</a>@else<span class="muted">-</span>@endif</td>
            @endif
            <td class="actions-cell">
<a class="icon-button row-open" data-open-drawer href="{{ $board === 'employees' ? route('companies.employees.show', [$company, $employee]) : route('companies.records.show', [$company, $item]) }}" aria-label="Otwórz szczegóły {{ $employee->fullName() }}">
<x-icon name="external" :size="16" />
</a>
</td>
</tr>
        @endforeach
        </tbody>
<tfoot>
<tr>
<td>
</td>
<td colspan="{{ $board === 'employees' ? 6 : 7 }}">@if(in_array($board, ['requests', 'contracts', 'employees', 'amendments', 'terminations']))<a href="{{ route('companies.records.create', [$company, $kind ?? 'contract']) }}" class="add-row">
<x-icon name="plus" :size="16" /> {{ ['requests' => 'Dodaj zgłoszenie', 'contracts' => 'Dodaj umowę', 'employees' => 'Dodaj pracownika', 'amendments' => 'Dodaj aneks', 'terminations' => 'Dodaj rozwiązanie'][$board] }}</a>@else<span class="add-row muted">Zgłoszenia pojawią się tutaj po zatwierdzeniu formularza.</span>@endif</td>
<td>
</td>
</tr>
</tfoot>
</table>
</div>
{{ $items->onEachSide(1)->links('components.group-pagination', ['label' => $label]) }}
</div></div>
    </section>
