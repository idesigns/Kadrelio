@php([$label, $color] = \App\Support\HrForms::boardGroups($board)[$group])
@php($statementKind = substr($group, 7))
<section class="board-group group-{{ $color }}" data-paginated-group="{{ $group }}" data-page-name="{{ $items->getPageName() }}" data-current-page="{{ $items->currentPage() }}" aria-label="{{ $label }}">
<div class="group-heading"><button type="button" data-toggle-group aria-expanded="true" aria-controls="group-{{ $group }}"><x-icon name="chevron" :size="18" /><h2>{{ $label }}</h2></button><span>Elementy: {{ number_format($items->total(), 0, ',', ' ') }}</span></div>
<div class="group-content" id="group-{{ $group }}"><div class="group-body"><div class="table-scroll">
<table class="monday-table board-table workflow-table" data-export-table>
<colgroup><col class="board-col-check"><col><col class="board-col-status"><col class="board-col-document-type"><col class="board-col-department"><col class="board-col-date"><col class="board-col-date"><col class="board-col-files"><col class="board-col-actions"></colgroup>
<thead><tr><th class="check-cell"><input type="checkbox" data-select-group aria-label="Zaznacz grupę {{ $label }}"></th><th class="name-cell">Pracownik</th><th>Status oświadczenia</th><th>Rodzaj dokumentu</th><th>Dział</th><th>Data dokumentu</th><th>Powiązanie</th><th>Dokumenty</th><th class="actions-cell"></th></tr></thead>
<tbody>
@foreach($items as $item)
@php($url = route('monday.items.show', [$item->board, $item]))
<tr data-export-row><td class="check-cell"><input type="checkbox" data-select-row aria-label="Zaznacz {{ $item->name }}"></td>
<td class="name-cell"><a class="person-cell" data-open-drawer title="{{ $item->name }}" href="{{ $url }}"><span class="avatar avatar-{{ ['blue', 'purple', 'pink', 'green', 'orange'][$item->id % 5] }}">{{ $item->employee?->initials() ?? mb_strtoupper(mb_substr($item->name, 0, 1)) }}</span><span>{{ $item->name ?: 'Bez nazwy' }}</span></a></td>
<td class="status-cell"><a class="status status-{{ $item->status ? 'signed' : 'draft' }}" data-open-drawer href="{{ $url }}">{{ $item->status ?: 'Status niepodany' }}</a></td>
<td>{{ \App\Support\HrForms::kinds()[$statementKind] }}</td>
<td><span class="department-cell">{{ $item->parent?->record?->department ?? '-' }}</span></td>
<td>{{ $item->document_date?->format('d.m.Y') ?? '-' }}</td>
<td><a class="cell-link" href="{{ $url }}#relation">{{ $item->relationLabel() }}</a></td>
<td>@if($item->available_assets_count)<a class="document-cell" data-open-drawer href="{{ $url }}#files"><x-icon name="file" :size="17" /> Pliki ({{ $item->available_assets_count }})</a>@else<span class="muted">-</span>@endif</td>
<td class="actions-cell"><a class="icon-button row-open" data-open-drawer href="{{ $url }}" aria-label="Otwórz szczegóły {{ $item->name }}"><x-icon name="external" :size="16" /></a></td></tr>
@endforeach
</tbody></table></div>
{{ $items->onEachSide(1)->links('components.group-pagination', ['label' => $label]) }}
</div></div></section>
