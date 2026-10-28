<?php

namespace App\Http\Requests;

use App\Support\HrForms;
use App\Support\TemplateFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('view', $this->route('company'));
        Gate::authorize('update', $this->route('company'));

        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['market' => $this->input('market', $this->input('language'))]);
        $department = $this->input('department');
        foreach (HrForms::departments() as $candidate) {
            if (is_string($department) && mb_strtolower($candidate) === mb_strtolower(trim($department))) {
                $this->merge(['department' => $candidate]);
                break;
            }
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'kind' => ['required', Rule::in(array_keys(TemplateFields::kinds()))],
            'agreement_type' => ['required', 'in:mandate,b2b'],
            'market' => ['required', 'in:PL,BG,RO'],
            'language' => ['required', 'in:PL,BG,RO'],
            'department' => ['nullable', Rule::in(HrForms::departments())],
            'body' => [Rule::requiredIf(! $this->route('template') && ! $this->hasFile('file')), 'nullable', 'string', 'max:150000'],
            'file' => ['nullable', 'file', 'mimes:docx,txt,pdf,zip', 'extensions:docx,txt,pdf', 'max:10240'],
            'revision' => [Rule::requiredIf((bool) $this->route('template')), 'integer', 'min:0'],
            'field_mapping' => ['nullable', 'array', 'max:300'],
            'field_mapping.*' => ['nullable', 'string', Rule::in(['__blank', ...array_keys(TemplateFields::catalogue())])],
            'package_kinds' => ['nullable', 'array', 'max:12'],
            'package_kinds.*' => ['required', 'string', 'distinct', Rule::in(array_keys(TemplateFields::kinds()))],
        ];
    }

    public function attributes(): array
    {
        return ['file' => 'plik wzoru', 'market' => 'rynek', 'language' => 'język dokumentu', 'field_mapping' => 'przypisanie pól', 'package_kinds' => 'zestaw dokumentów'];
    }
}
