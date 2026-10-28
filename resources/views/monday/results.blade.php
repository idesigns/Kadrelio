<div class="board-canvas monday-canvas" data-board-results data-total="{{ $total }}">
@forelse($groups as $group => $data)
@include('monday.group', ['name' => $data['name'], 'color' => $data['color'], 'items' => $data['items']])

@empty

<div class="empty-state"><x-icon name="search" :size="35" /><h2>{{ $mondayBoard->groups ? 'Brak wpisów dla tych filtrów' : 'Ta tablica jest pusta' }}</h2><p>{{ $mondayBoard->groups ? 'Zmień wyszukiwanie lub wyczyść filtry.' : 'Wpisy pojawią się w tej tablicy.' }}</p><a class="button button-secondary" href="{{ route('monday.boards.show', $mondayBoard) }}">Pokaż całą tablicę</a></div>

@endforelse


</div>

