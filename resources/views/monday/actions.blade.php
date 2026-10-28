<section class="profile-section monday-document-actions" id="document-actions" data-document-actions>
    <h2>Dokumenty i generowanie</h2>
    @if($item->record && ! $item->record->isImported())
        <p>Dokument jest obsługiwany w bieżącym obiegu. Tam pobierzesz PDF i wyślesz go do podpisu.</p>
        <a class="button button-primary" href="{{ route('companies.records.show', [$company, $item->record]) }}">Otwórz bieżący dokument <x-icon name="arrow" :size="16" /></a>
    @elseif($item->record && in_array($item->record->kind, ['contract', 'amendment', 'termination'], true))
        @if($canEdit && in_array($item->record->status, ['draft', 'ready'], true))
            <p>Dokumenty powstaną z aktualnych danych i szablonów firmy. Dołączone wcześniej pliki pozostaną dostępne.</p>
            @if($generationBlockers)
                <ul class="generation-blockers">@foreach($generationBlockers as $blocker)<li>{{ $blocker }}</li>@endforeach</ul>
            @endif
            <form method="post" action="{{ route('companies.records.generate', [$company, $item->record]) }}">
                @csrf
                <input type="hidden" name="revision" value="{{ $item->revision }}">
                @if(request()->boolean('panel'))<input type="hidden" name="panel" value="1">@endif
                <button class="button button-primary" type="submit" @disabled($generationBlockers !== [])><x-icon name="file" :size="17" /> Generuj dokumenty</button>
            </form>
            @if($generationBlockers)
                <div class="document-setup-links">
                    <a class="text-link" href="{{ route('companies.templates.index', $company) }}">Szablony dokumentów <x-icon name="arrow" :size="14" /></a>
                    <a class="text-link" href="{{ route('companies.edit', $company) }}">Ustawienia firmy <x-icon name="arrow" :size="14" /></a>
                    @if($item->employee)<a class="text-link" href="{{ route('companies.employees.show', [$company, $item->employee]) }}">Dane pracownika <x-icon name="arrow" :size="14" /></a>@endif
                </div>
            @endif
        @elseif(in_array($item->record->status, ['draft', 'ready'], true))
            <p>Generowanie dokumentów jest dostępne dla managera lub administratora.</p>
        @else
            <p>Dokument jest wysłany do podpisu, podpisany lub zamknięty. Pobierz dołączone pliki.</p>
        @endif
        @if($canEdit && $item->record->kind === 'contract' && $item->record->status === 'signed')
            <div class="document-setup-links">
                <a class="button button-secondary" href="{{ route('companies.records.create', [$company, 'amendment', 'contract' => $item->record->id]) }}">Zgłoś aneks</a>
                <a class="button button-secondary" href="{{ route('companies.records.create', [$company, 'termination', 'contract' => $item->record->id]) }}">Rozwiąż umowę</a>
            </div>
        @endif
    @elseif(in_array($mondayBoard->kind, ['contract_statements', 'amendment_statements', 'termination_statements', 'changes', 'multisport'], true))
        <p>Złożone oświadczenie pobierzesz z załączników. Podpisanej treści nie generuje się ponownie.</p>
        @if($item->assets->isEmpty())<p>Wpis nie zawiera gotowego pliku oświadczenia.</p>@endif
    @else
        <p>Generator tego rodzaju dokumentu nie jest jeszcze skonfigurowany.</p>
        @if($item->assets->isEmpty())<p>Wpis nie zawiera gotowego pliku.</p>@endif
    @endif
    @if($item->assets->isNotEmpty())<a class="button button-secondary" href="#files"><x-icon name="download" :size="16" /> Przejdź do załączników ({{ $item->assets->unique('id')->count() }})</a>@endif
</section>
