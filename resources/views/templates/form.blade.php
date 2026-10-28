@extends('layouts.app')
@section('title', $template ? $template->name : 'Dodaj wzór dokumentu')
@section('content')
@php
$status = $template?->status ?? 'draft';
$statusLabels = ['draft' => 'Wersja robocza', 'active' => 'Aktywny', 'archived' => 'Wycofany'];
$primary = in_array(old('kind', $template?->kind ?? 'contract'), ['contract', 'amendment', 'termination'], true);
$selectedPackage = old('package_kinds', $template?->package_kinds ?? ['contract', 'declaration', 'attachment']);
$selectedPackage = is_array($selectedPackage) ? $selectedPackage : [];
@endphp
<header class="board-header"><div>
<a class="back-link" href="{{ route('companies.templates.index', $company) }}">← Szablony dokumentów</a>
<h1>{{ $template ? $template->name : 'Dodaj wzór dokumentu' }}</h1>
<p>{{ $company->name }} @if($template) · Wersja {{ $template->version }} · {{ $statusLabels[$status] ?? $status }} @endif</p>
</div></header>
<div class="template-workspace">
<ol class="template-steps" aria-label="Przygotowanie wzoru"><li class="is-complete">1. Plik i zastosowanie</li><li class="{{ $template ? 'is-complete' : '' }}">2. Przypisanie pól</li><li class="{{ $template?->hasCurrentPreview() ? 'is-complete' : '' }}">3. Podgląd i aktywacja</li></ol>
@if($template && $status !== 'draft')<p class="template-notice">Zmiany zapiszą się jako nowa wersja robocza. Obecna wersja i wcześniej wygenerowane dokumenty zachowają swoją treść.</p>@endif
<form id="template-edit-form" class="form-page template-form template-editor" method="post" enctype="multipart/form-data" action="{{ $template ? route('companies.templates.update', [$company, $template]) : route('companies.templates.store', $company) }}" data-template-editor>
@csrf @if($template) @method('patch')<input type="hidden" name="revision" value="{{ $template->revision }}"> @endif
<fieldset><legend>Plik wzoru</legend>
<div class="field"><label for="file">{{ $template?->source_path ? 'Nowa wersja pliku' : 'Wybierz plik' }}</label><div class="template-file-picker"><input type="file" id="file" name="file" accept=".docx,.pdf,.txt" aria-describedby="template-upload-hint"><span class="button">Wybierz plik</span><span data-template-picked-file>Nie wybrano pliku</span></div>
<p class="field-hint" id="template-upload-hint">DOCX zachowuje tabele, obrazy, nagłówki i układ. PDF służy do stałych załączników bez zmiennych. Do 10 MB.</p><p class="field-hint" data-template-file-name aria-live="polite"></p>
@error('file')<p class="field-error">{{ $message }}</p>@enderror
@if($template?->source_path)<a class="text-link" href="{{ route('companies.templates.source', [$company, $template]) }}">Pobierz oryginał: {{ $template->source_name }}</a>@endif
</div>
@if(!$template || ($template->format ?? 'text') === 'text')<details class="template-text-editor" @if(old('body') || (!$template?->source_path && $template?->body)) open @endif><summary>Treść tekstowa zamiast pliku</summary><x-field name="body" :value="$template?->body" :field="['label' => 'Treść wzoru', 'type' => 'textarea', 'rows' => 8, 'max' => 150000, 'hint' => 'Dla wzorów tekstowych możesz używać pól w podwójnych nawiasach klamrowych, np. {{full_name}}.']" /></details>@endif
</fieldset>
<fieldset><legend>Zastosowanie wzoru</legend><div class="form-grid">
<x-field name="name" :value="$template?->name" :field="['label' => 'Nazwa wzoru', 'required' => true]"/>
<x-field name="kind" :value="$template?->kind ?? 'contract'" :field="['label' => 'Rodzaj dokumentu', 'type' => 'select', 'required' => true, 'options' => $kinds]"/>
<x-field name="agreement_type" :value="$template?->agreement_type ?? 'mandate'" :field="['label' => 'Typ umowy', 'type' => 'select', 'required' => true, 'options' => ['mandate' => 'Umowa zlecenie', 'b2b' => 'Umowa B2B']]"/>
<x-field name="department" :value="$template?->department" :field="['label' => 'Dział', 'type' => 'select', 'empty_label' => 'Wszystkie działy', 'options' => array_combine(\App\Support\HrForms::departments(), \App\Support\HrForms::departments())]"/>
<x-field name="market" :value="$template?->market ?? $template?->language ?? 'PL'" :field="['label' => 'Rynek', 'type' => 'select', 'required' => true, 'options' => ['PL' => 'Polska', 'BG' => 'Bułgaria', 'RO' => 'Rumunia'], 'hint' => 'Wybiera wariant umowy, niezależnie od języka treści.']"/>
<x-field name="language" :value="$template?->language ?? 'PL'" :field="['label' => 'Język treści dokumentu', 'type' => 'select', 'required' => true, 'options' => ['PL' => 'Polski', 'BG' => 'Bułgarski', 'RO' => 'Rumuński']]"/>
</div></fieldset>
<fieldset data-template-package @if(!$primary) hidden @endif><legend>Pełny zestaw dokumentów</legend><p class="field-hint">Ten wzór jest dokumentem głównym. Zaznacz osobne wzory, które mają być do niego dołączane. Nie zaznaczaj treści już zawartej w tym samym pliku.</p>
<input type="hidden" name="package_kinds[]" value="{{ old('kind', $template?->kind ?? 'contract') }}" data-template-primary @disabled(!$primary)>
<div class="template-package-options">@foreach($kinds as $kind => $label) @if(!in_array($kind, ['contract', 'amendment', 'termination', 'multisport', 'equipment_assignment', 'equipment_return']))<label class="template-package-option"><input type="checkbox" name="package_kinds[]" value="{{ $kind }}" @checked(in_array($kind, $selectedPackage)) @disabled(!$primary)><span>{{ $label }}</span></label>@endif @endforeach</div>
@error('package_kinds')<p class="field-error">{{ $message }}</p>@enderror
</fieldset>
@if($template)<fieldset id="fields"><legend>Pola do automatycznego uzupełnienia</legend>
@if(count($template->placeholders ?? []))<p class="field-hint">Dopasuj pola z pliku do danych Kadrelio. Rozpoznane pola zostały przypisane automatycznie.</p>
<div class="template-field-mapping">@foreach($template->placeholders as $index => $token)
@php($mapped = old('field_mapping.'.$index, $template->field_mapping[$token] ?? null))
<div class="template-mapping-row"><div><label for="mapping-{{ $index }}">Pole {{ $index + 1 }}</label><code>{{ $token }}</code></div><div class="field"><label for="mapping-{{ $index }}" class="sr-only">Dane dla pola {{ $index + 1 }}</label><select id="mapping-{{ $index }}" name="field_mapping[{{ $index }}]" aria-label="Dane dla pola {{ $index + 1 }}"><option value="">Wybierz dane Kadrelio</option>@foreach($fields as $key => $label)<option value="{{ $key }}" @selected($mapped === $key)>{{ $label }}</option>@endforeach<option value="__blank" @selected($mapped === '__blank')>Pozostaw puste</option></select></div></div>
@endforeach</div>
@else<p class="field-hint">W pliku nie znaleziono pól zmiennych. Dokument będzie dołączany w tej samej postaci. Sprawdź, czy jest wzorem i nie zawiera danych poprzedniej osoby.</p>@endif
@error('field_mapping')<p class="field-error">{{ $message }}</p>@enderror
</fieldset>@endif
<div class="form-footer"><a class="button" href="{{ route('companies.templates.index', $company) }}">Wróć do listy</a><button class="button button-primary" type="submit">{{ $template && $status !== 'draft' ? 'Zapisz jako nową wersję' : ($template ? 'Zapisz zmiany' : 'Zapisz i przypisz pola') }}</button></div>
</form>
@if($template)<section id="preview" class="template-preview-section"><div class="section-heading"><h2>Podgląd i aktywacja</h2><p>PDF używa przykładowych danych. Aktywacja włączy tę wersję dla nowych dokumentów.</p></div>
<p class="field-hint" data-template-pending hidden aria-live="polite">Zapisz zmiany, aby wygenerować aktualny podgląd i aktywować wzór.</p>
@if($status === 'draft')
@if($template->format === 'docx' && !$docxAvailable)<p class="template-notice">Wzór jest zapisany. Podgląd DOCX wymaga jeszcze uruchomienia konwertera przez administratora.</p>@endif
<form method="post" action="{{ route('companies.templates.preview', [$company, $template]) }}">@csrf<input type="hidden" name="revision" value="{{ $template->revision }}"><button type="submit" class="button button-primary" data-template-preview @disabled($template->format === 'docx' && !$docxAvailable)><x-icon name="file" :size="17"/> Generuj próbny PDF</button></form>
@endif
@if($template->hasCurrentPreview())<div data-template-saved-preview class="template-pdf-preview"><div class="template-preview-toolbar"><strong>Podgląd wersji {{ $template->version }}</strong><a class="text-link" href="{{ route('companies.templates.preview-file', [$company, $template]) }}" target="_blank" rel="noopener" data-template-pdf-link>Otwórz PDF ↗</a></div><iframe data-template-pdf-src="{{ route('companies.templates.preview-file', [$company, $template]) }}" title="Próbny PDF wzoru {{ $template->name }}" loading="lazy" data-template-pdf-frame hidden></iframe><p class="template-preview-fallback" data-template-pdf-fallback>Pobierz podgląd PDF i otwórz go przed aktywacją wzoru, aby sprawdzić cały dokument.</p></div>@endif
@error('preview')<p class="field-error">{{ $message }}</p>@enderror
@if($status === 'draft')<form method="post" action="{{ route('companies.templates.activate', [$company, $template]) }}" class="template-activation">@csrf<input type="hidden" name="revision" value="{{ $template->revision }}"><label class="template-package-option"><input type="checkbox" name="preview_confirmed" value="1" required @disabled(!$template->hasCurrentPreview())><span>Podgląd został sprawdzony, a wzór jest właściwy dla wybranej firmy i zastosowania.</span></label><button type="submit" class="button button-primary" data-template-activate @disabled(!$template->hasCurrentPreview())>Aktywuj tę wersję</button>@error('preview_confirmed')<p class="field-error">{{ $message }}</p>@enderror</form>@endif
</section>
@if($versions->count() > 1)<section class="template-history"><h2>Historia wersji</h2><ul>@foreach($versions as $version)<li><a class="text-link" href="{{ route('companies.templates.edit', [$company, $version]) }}">Wersja {{ $version->version }}</a><span class="template-state template-state-{{ $version->status }}">{{ $statusLabels[$version->status] ?? $version->status }}</span><span>{{ $version->created_at->format('d.m.Y H:i') }}</span></li>@endforeach</ul></section>@endif
@if($status !== 'archived')<form method="post" action="{{ route('companies.templates.archive', [$company, $template]) }}" class="template-archive">@csrf<input type="hidden" name="revision" value="{{ $template->revision }}"><button type="submit" class="button">Wycofaj tę wersję</button><p class="field-hint">Wycofanie zatrzyma używanie wzoru w nowych dokumentach. Plik i historia pozostaną zachowane.</p></form>@endif
@endif
</div>
@endsection
