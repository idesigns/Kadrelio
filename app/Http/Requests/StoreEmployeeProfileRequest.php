<?php

namespace App\Http\Requests;

use App\Support\HrForms;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreEmployeeProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => mb_strtolower(trim((string) $this->input('email'))), 'bank_account' => preg_replace('/\s+/', '', (string) $this->input('bank_account'))]);
    }

    public function rules(): array
    {
        $rules = HrForms::fieldRules(HrForms::profileFields()) + HrForms::profileUploadRules();
        $rules['pesel'] = ['required', 'digits:11'];
        $rules['birth_date'][] = 'before_or_equal:today';
        $rules['bank_account'] = ['required', 'string', 'regex:/^(?:[0-9]{26}|[A-Z]{2}[0-9A-Z]{13,32})$/'];
        $rules['privacy_consent'] = ['accepted'];
        foreach (['mandate', 'insurance', 'leave', 'second_mandate', 'employment'] as $prefix) {
            $rules[$prefix.'_to'][] = 'after_or_equal:'.$prefix.'_from';
        }

        return $rules;
    }

    public function attributes(): array
    {
        return array_map(fn (array $field): string => $field['label'], HrForms::profileFields()) + ['privacy_consent' => 'zgoda na przetwarzanie danych'];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('birth_date')) {
                return;
            }
            $age = Carbon::parse($this->input('birth_date'))->age;
            if ($age > 120) {
                $validator->errors()->add('birth_date', 'Data urodzenia wskazuje wiek powyżej 120 lat.');
            }
            if ($this->input('is_student') === '1' && $age >= 26) {
                $validator->errors()->add('is_student', 'Ta deklaracja dotyczy osób poniżej 26 roku życia.');
            }
        }];
    }
}
