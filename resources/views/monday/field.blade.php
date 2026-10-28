@php
$fieldLabel = $column === 'A' ? 'Nazwa' : $label;
$canEditField = $canEdit && isset($editableColumns[$column]);
$options = \App\Support\MondayFields::options($label, $mondayBoard->kind, $value);
$readOnlyReason = str_contains($value, '/resources/') ? 'Pliki są dostępne w sekcji Załączniki.' : (($item->source_data['cells'][$column]['formula'] ?? null) !== null ? 'Pole wyliczane automatycznie.' : 'Pole chronione: identyfikator lub data używana do powiązania dokumentów.');
@endphp
<div class="inline-field" data-field-column="{{ $column }}">
    <dt id="field-label-{{ $column }}">{{ $fieldLabel }}</dt>
    <dd>
        <div class="inline-field-display" data-field-display>
            @include('monday.value', ['compact' => false])
            @if($canEditField)
                <button type="button" class="icon-button inline-edit-button" data-edit-field aria-label="Edytuj: {{ $fieldLabel }}" title="Edytuj: {{ $fieldLabel }}" aria-expanded="false" aria-controls="field-editor-{{ $column }}"><x-icon name="pencil" :size="16" /></button>
            @elseif($canEdit && ! \App\Support\MondayFields::isAction($label) && ! \App\Support\MondayFields::isFile($label) && ! \App\Support\MondayFields::isRelation($label))
                <span class="inline-field-readonly" role="img" title="{{ $readOnlyReason }}" aria-label="{{ $readOnlyReason }}"><x-icon name="lock" :size="14" /></span>
            @endif
        </div>
        @if($canEditField)
            <form method="post" action="{{ route('monday.items.update', [$mondayBoard, $item]) }}" class="inline-field-form" id="field-editor-{{ $column }}" data-inline-edit hidden>
                @csrf
                @method('patch')
                <input type="hidden" name="revision" value="{{ $item->revision }}">
                @if($options)
                    <select name="fields[{{ $column }}]" aria-labelledby="field-label-{{ $column }}" data-field-input>
                        <option value="" @selected(! in_array($value, $options, true))>Wybierz wartość</option>
                        @foreach($options as $option)<option @selected($option === $value)>{{ $option }}</option>@endforeach
                    </select>
                @else
                    <textarea name="fields[{{ $column }}]" rows="2" maxlength="30000" aria-labelledby="field-label-{{ $column }}" data-field-input>{{ $value }}</textarea>
                @endif
                <label>Powód zmiany<input name="reason" value="{{ mb_substr('Korekta pola: '.$fieldLabel, 0, 1000) }}" required minlength="3" maxlength="1000"></label>
                <p class="inline-field-error" data-field-error role="alert" hidden></p>
                <div class="inline-field-actions">
                    <button type="submit" class="button button-primary"><x-icon name="check" :size="16" /> Zapisz</button>
                    <button type="button" class="button button-secondary" data-cancel-field>Anuluj</button>
                </div>
            </form>
        @endif
    </dd>
</div>
