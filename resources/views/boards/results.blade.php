<div class="board-canvas" data-board-results data-total="{{ $total }}">
@php
$groupDefinitions = \App\Support\HrForms::boardGroups($board);
$visibleGroups = collect($groupDefinitions)->keys()->filter(fn ($key) => $groups->has($key))->mapWithKeys(fn ($key) => [$key => $groups[$key]]);
$hasFilters = !empty($filters['q']) || !empty($filters['status']) || !empty($filters['department']) || !empty($filters['kind']);
@endphp
@foreach($visibleGroups as $group => $items)
    @include(str_starts_with($group, 'source_') ? 'boards.statement-group' : 'boards.group')
@endforeach
@if($total === 0)
<div class="empty-board">
    <span class="empty-icon empty-board-icon"><x-icon name="{{ $definition['icon'] }}" :size="32" /></span>
    <h2>{{ $hasFilters ? 'Brak wyników dla wybranych filtrów' : ['requests' => 'Pierwsze zgłoszenie, prostszy obieg', 'contracts' => 'Twoje umowy znajdą tu swoje miejsce', 'employees' => 'Poznaj swój zespół w jednym miejscu', 'amendments' => 'Każda zmiana z pełną historią', 'terminations' => 'Brak zgłoszonych rozwiązań', 'statements' => 'Tutaj czekamy na pierwszy podpis', 'changes' => 'Aktualne dane, uporządkowana historia', 'multisport' => 'Zgody Twojego zespołu w jednym miejscu'][$board] }}</h2>
    <p>{{ $hasFilters ? 'Spróbuj innego nazwiska lub zmień ustawienia filtrów.' : ['requests' => 'Zgłoś nową osobę. Pracownik uzupełni dane, a Ty przygotujesz dokumenty.', 'contracts' => 'Dodaj zgłoszenie, uzupełnij dane i przeprowadź umowę aż do podpisu.', 'employees' => 'Zgłoś pierwszą osobę lub udostępnij formularz danych osobowych.', 'amendments' => 'Wybierz podpisaną umowę i zgłoś zmianę stawki lub działu.', 'terminations' => 'Gdy zakończycie współpracę, zgłoś rozwiązanie podpisanej umowy.', 'statements' => 'Oświadczenia pojawią się automatycznie, gdy pracownik podpisze dokument.', 'changes' => 'Udostępnij formularz. Zgłoszenia trafią tutaj po potwierdzeniu przez pracownika.', 'multisport' => 'Udostępnij formularz wyboru karty. Potwierdzone zgody pojawią się na tej tablicy.'][$board] }}</p>
    @if($hasFilters)
        <a class="button button-secondary" href="{{ route('companies.board', [$company, $board]) }}">Wyczyść filtry</a>
    @elseif(in_array($board, ['requests', 'contracts', 'employees', 'amendments', 'terminations']))
        <a class="button button-primary" href="{{ route('companies.records.create', [$company, $kind ?? 'contract']) }}"><x-icon name="plus" :size="17" /> {{ ['requests' => 'Zgłoś pierwszą umowę', 'contracts' => 'Dodaj pierwszą umowę', 'employees' => 'Zgłoś nową osobę', 'amendments' => 'Zgłoś aneks', 'terminations' => 'Zgłoś rozwiązanie'][$board] }}</a>
    @elseif($board === 'statements')
        <a class="button button-secondary" href="{{ route('companies.board', [$company, 'contracts']) }}">Przejdź do umów <x-icon name="arrow" :size="17" /></a>
    @else
        <button class="button button-primary" type="button" data-copy="{{ route('forms.additional', [$company->public_token, $kind]) }}"><x-icon name="copy" :size="17" /> Kopiuj link do formularza</button>
    @endif
</div>
@endif
</div>
