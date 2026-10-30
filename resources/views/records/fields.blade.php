@php($public = $public ?? false)
@php($editing = isset($record))
@php($values = $values ?? [])
<fieldset>
<legend>{{ $kind === 'contract' ? 'Pracownik' : 'Umowa, której dotyczy zgłoszenie' }}</legend>
<div class="form-grid">
@if($kind === 'contract')
@if(!$public && !$editing)
    <div class="field field-wide">
<label for="employee_id">Osoba z bazy pracowników</label>
<select id="employee_id" name="employee_id">
<option value="">Nowa osoba - wpiszę dane poniżej</option>@foreach($employees as $employee)<option value="{{ $employee->id }}" @selected((string) old('employee_id') === (string) $employee->id)>{{ $employee->fullName() }}</option>@endforeach</select>
</div>
@endif
    @foreach(['first_name' => 'Imię', 'last_name' => 'Nazwisko', 'email' => 'E-mail strony umowy', 'pesel' => 'PESEL strony umowy'] as $key => $label)<x-field :name="$key" :value="$values[$key] ?? null" :field="['label' => $label, 'type' => $key === 'email' ? 'email' : 'text', 'required' => true, 'readonly' => $editing && !$canEditIdentity, 'when' => $public || $editing ? null : ['employee_id', '']]"/>@endforeach
    @if($editing && !$canEditIdentity)<p class="field-hint field-wide">Dane osoby są już powiązane z profilem lub innymi dokumentami. Zmiany danych osobowych wprowadzisz w <a class="text-link" href="{{ route('companies.employees.show', [$company, $record->employee, 'panel' => request()->boolean('panel') ? 1 : null]) }}">profilu pracownika</a>.</p>@elseif($editing)<p class="field-hint field-wide">Po zmianie adresu e-mail lub numeru PESEL wyślij pracownikowi nowy link do uzupełnienia danych.</p>@endif
@else
    <div class="field field-wide">
<label for="contract_id">Podpisana umowa <span class="required">*</span>
</label>
<select id="contract_id" name="contract_id" required>
<option value="">Wybierz umowę</option>@foreach($contracts as $contract)<option value="{{ $contract->id }}" @selected((string) old('contract_id', request('contract')) === (string) $contract->id)>{{ $contract->employee->fullName() }} · {{ $contract->concluded_at?->format('d.m.Y') }} · {{ $contract->department }} · {{ $contract->language }}</option>@endforeach</select>@if($contracts->isEmpty())<p class="field-hint">Najpierw potrzebna jest podpisana umowa w tej firmie.</p>@endif</div>
@endif
</div>
</fieldset>
<fieldset>
<legend>Dane dokumentu</legend>
<div class="form-grid">
<x-field name="concluded_at" :value="$values['concluded_at'] ?? today()->format('Y-m-d')" :field="['label' => $kind === 'amendment' ? 'Data zawarcia aneksu' : ($kind === 'termination' ? 'Data sporządzenia rozwiązania' : 'Data zawarcia umowy'), 'type' => 'date', 'required' => true]"/>
@if($kind === 'contract')
<x-field name="agreement_type" :value="$values['agreement_type'] ?? 'mandate'" :field="['label' => 'Rodzaj umowy', 'type' => 'select', 'required' => true, 'options' => ['mandate' => 'Umowa zlecenie', 'b2b' => 'Umowa B2B']]"/>
<x-field name="contractor_tax_id" :value="$values['contractor_tax_id'] ?? null" :field="['label' => 'NIP działalności zleceniobiorcy (10 cyfr)', 'required' => true, 'inputmode' => 'numeric', 'when' => ['agreement_type', 'b2b']]"/>
<x-field name="starts_at" :value="$values['starts_at'] ?? null" :field="['label' => 'Data rozpoczęcia umowy', 'type' => 'date', 'required' => true]"/>
<x-field name="ends_at" :value="$values['ends_at'] ?? null" :field="['label' => 'Data zakończenia umowy', 'type' => 'date', 'required' => true]"/>
<x-field name="language" :value="$values['language'] ?? 'PL'" :field="['label' => 'Typ umowy / język', 'type' => 'select', 'options' => ['PL' => 'PL - polska', 'BG' => 'BG - bułgarska', 'RO' => 'RO - rumuńska'], 'required' => true]"/>
<x-field name="department" :value="$values['department'] ?? null" :field="['label' => 'Dział / typ załącznika', 'type' => 'select', 'options' => array_combine(\App\Support\HrForms::departments(), \App\Support\HrForms::departments()), 'required' => true]"/>@elseif($kind === 'termination')<x-field name="ends_at" :field="['label' => 'Umowa rozwiązana ze skutkiem na dzień (ostatni dzień pracy)', 'type' => 'date', 'required' => true]"/>@endif
@foreach(\App\Support\HrForms::additionalFields($kind) as $key => $field)<x-field :name="$key" :field="$field"/>@endforeach
</div>
</fieldset>
<fieldset>
<legend>Osoba zgłaszająca</legend>
<div class="form-grid">
<x-field name="reporter_name" :value="$values['reporter_name'] ?? ($public ? '' : auth()->user()?->name)" :field="['label' => 'Imię i nazwisko osoby zgłaszającej', 'required' => true]"/>
<x-field name="reporter_email" :value="$values['reporter_email'] ?? ($public ? '' : auth()->user()?->email)" :field="['label' => 'E-mail osoby zgłaszającej', 'type' => 'email', 'required' => true]"/>
</div>
</fieldset>
