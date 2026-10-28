<?php

namespace App\Http\Requests;

use App\Support\HrForms;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('view', $this->route('company'));

        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->route('kind') === 'contract') {
            $this->merge(['agreement_type' => $this->input('agreement_type', 'mandate')]);
        }
    }

    public function rules(): array
    {
        $kind = $this->route('kind');
        $company = $this->route('company');
        $rules = [
            'reporter_name' => ['required', 'string', 'max:120'],
            'reporter_email' => ['required', 'email', 'max:255'],
            'concluded_at' => ['required', 'date_format:Y-m-d'],
        ];
        if ($kind === 'contract') {
            $rules += [
                'employee_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->where('company_id', $company->id)],
                'first_name' => [Rule::excludeIf($this->filled('employee_id')), 'required_without:employee_id', 'string', 'max:120'],
                'last_name' => [Rule::excludeIf($this->filled('employee_id')), 'required_without:employee_id', 'string', 'max:120'],
                'email' => [Rule::excludeIf($this->filled('employee_id')), 'required_without:employee_id', 'email', 'max:255'],
                'pesel' => [Rule::excludeIf($this->filled('employee_id')), 'required_without:employee_id', 'digits:11'],
                'agreement_type' => ['required', 'in:mandate,b2b'],
                'contractor_tax_id' => ['exclude_unless:agreement_type,b2b', 'required_if:agreement_type,b2b', 'digits:10'],
                'language' => ['required', Rule::in(['PL', 'BG', 'RO'])],
                'department' => ['required', Rule::in(HrForms::departments())],
                'starts_at' => ['required', 'date_format:Y-m-d'],
                'ends_at' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_at'],
            ];
        } else {
            $rules['contract_id'] = ['required', 'integer', Rule::exists('workflow_records', 'id')->where('company_id', $company->id)->where('kind', 'contract')->where('status', 'signed')];
            if ($kind === 'termination') {
                $rules['ends_at'] = ['required', 'date_format:Y-m-d'];
            }
        }

        return $rules + HrForms::fieldRules(HrForms::additionalFields($kind));
    }

    public function attributes(): array
    {
        return ['first_name' => 'imię', 'last_name' => 'nazwisko', 'reporter_name' => 'osoba zgłaszająca', 'reporter_email' => 'e-mail zgłaszającego', 'concluded_at' => 'data zawarcia', 'starts_at' => 'data rozpoczęcia', 'ends_at' => 'data zakończenia', 'contract_id' => 'podpisana umowa', 'department' => 'dział'] + array_map(fn (array $field): string => $field['label'], HrForms::additionalFields($this->route('kind')));
    }
}
