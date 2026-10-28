<section class="board-group group-{{ $color }}" data-paginated-group="{{ $group }}" data-page-name="{{ $items->getPageName() }}" data-current-page="{{ $items->currentPage() }}" aria-label="{{ $name }}"><div class="group-heading"><button type="button" data-toggle-group aria-expanded="true" aria-controls="source-group-{{ $group }}"><x-icon name="chevron" :size="18" /><h2>{{ $name }}</h2></button><span>Elementy: {{ number_format($items->total(), 0, ',', ' ') }}</span></div><div class="group-content" id="source-group-{{ $group }}"><div class="group-body"><div class="table-scroll"><table class="monday-table monday-source-table board-table">
<colgroup>
    @foreach($columns as $column => $label)
        <col @class(['board-col-source' => $column !== 'A', 'board-col-name' => $column === 'A'])>
    @endforeach
    <col class="board-col-relation">
    <col class="board-col-files">
    <col class="board-col-comments">
</colgroup>
<thead><tr>@foreach($columns as $column => $label)<th @class(['name-cell' => $column === 'A'])><span title="{{ $label }}">{{ $column === 'A' ? 'Nazwa' : $label }}</span></th>
@endforeach
<th>Powiązanie</th><th>Załączniki</th><th>Komentarze</th></tr></thead><tbody>
@foreach($items as $item)<tr>
@foreach($columns as $column => $label)
@php($value = $item->fields[$column] ?? '')
<td @class(['name-cell' => $column === 'A'])>
@if($column === 'A')<a class="person-cell" data-open-drawer href="{{ route('monday.items.show', [$mondayBoard, $item]) }}"><span class="avatar avatar-{{ ['blue', 'purple', 'green', 'pink'][$item->id % 4] }}">{{ mb_strtoupper(mb_substr($item->name, 0, 1)) }}</span><span>{{ $item->name ?: 'Bez nazwy' }}</span></a>

@elseif(str_starts_with($label, 'Status') && $value !== '')<span class="tag monday-source-status">{{ $value }}</span>

@else
@include('monday.value', ['compact' => true])
@endif

</td>

@endforeach

<td>@if($item->relation_state !== 'not_required')<a class="tag {{ in_array($item->relation_state, ['matched', 'manual']) ? 'monday-matched' : 'monday-review' }}" href="{{ route('monday.items.show', [$mondayBoard, $item]) }}#relation">{{ $item->relationLabel() }}</a>
@elseif($item->issues)<a class="tag monday-review" href="{{ route('monday.items.show', [$mondayBoard, $item]) }}">Do sprawdzenia</a>
@else
<span class="muted">-</span>
@endif
</td><td>@if($item->assets_count)<a class="cell-link" href="{{ route('monday.items.show', [$mondayBoard, $item]) }}#files">Pliki ({{ $item->assets_count }})</a>@else<span class="muted">-</span>@endif</td><td>@if($item->updates_count)<a class="cell-link" href="{{ route('monday.items.show', [$mondayBoard, $item]) }}#updates">{{ $item->updates_count }}</a>
@else
<span class="muted">-</span>
@endif
</td>
</tr>
@endforeach

</tbody></table></div>{{ $items->onEachSide(1)->links('components.group-pagination', ['label' => $name]) }}</div></div></section>
