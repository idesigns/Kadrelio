@props(['name', 'field' => [], 'value' => null])
@php
$type = $field['type'] ?? 'text';
$required = $field['required'] ?? false;
$current = old($name, $value ?? $field['value'] ?? '');
@endphp
<div class="field {{ $type === 'textarea' ? 'field-wide' : '' }}" @if(isset($field['when'])) data-condition="{{ $field['when'][0] }}" data-values="{{ $field['when'][1] }}" @endif>
    <label for="{{ $name }}">{{ $field['label'] ?? $name }} @if($required)<span class="required" aria-label="wymagane">*</span>@endif</label>
    @if($type === 'select')
        <select id="{{ $name }}" name="{{ $name }}" @required($required) @if($errors->has($name)) aria-invalid="true" aria-describedby="error-{{ $name }}" @endif>
            <option value="">{{ $field['empty_label'] ?? 'Wybierz / Select' }}</option>
            @if($current !== '' && $current !== null && !array_key_exists((string)$current, $field['options']))<option value="{{ $current }}" selected>Do sprawdzenia: {{ $current }}</option>@endif
            @foreach($field['options'] as $option => $label)<option value="{{ $option }}" @selected((string) $current === (string) $option)>{{ $label }}</option>@endforeach
        </select>
    @elseif($type === 'textarea')
        <textarea id="{{ $name }}" name="{{ $name }}" rows="{{ $field['rows'] ?? 4 }}" maxlength="{{ $field['max'] ?? 5000 }}" @required($required) @if($errors->has($name)) aria-invalid="true" aria-describedby="error-{{ $name }}" @endif>{{ $current }}</textarea>
    @elseif($type === 'file')
        <div class="profile-file-picker"><input id="{{ $name }}" name="{{ $name }}[]" type="file" accept=".pdf,.jpg,.jpeg,.png" multiple data-profile-upload @required($required)><span class="button">Wybierz pliki</span><span data-upload-label>Nie wybrano plików</span></div>
    @else
        <input id="{{ $name }}" name="{{ $name }}" type="{{ $type }}" value="{{ $type === 'password' ? '' : $current }}" @required($required) @readonly($field['readonly'] ?? false) @if(isset($field['inputmode'])) inputmode="{{ $field['inputmode'] }}" @endif @if($type === 'number') min="0" step="{{ $field['step'] ?? 1 }}" @endif @if(isset($field['autocomplete'])) autocomplete="{{ $field['autocomplete'] }}" @endif @if(isset($field['placeholder'])) placeholder="{{ $field['placeholder'] }}" @endif @if($errors->has($name)) aria-invalid="true" aria-describedby="error-{{ $name }}" @endif>
    @endif
    @if(isset($field['hint']))<p class="field-hint">{{ $field['hint'] }}</p>@endif
    @error($name)<p class="field-error" id="error-{{ $name }}">{{ $message }}</p>@enderror
</div>
