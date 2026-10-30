<?php

namespace App\Http\Requests;

class UpdateSubmissionRequest extends StoreWorkflowRequest
{
    public function authorize(): bool
    {
        parent::authorize();
        abort_unless($this->route('record')->canEditSubmission(), 409, 'Zgłoszenie można edytować przed wysłaniem dokumentu do podpisu.');

        return true;
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        $this->merge([
            'email' => mb_strtolower(trim((string) $this->input('email'))),
            'reporter_email' => mb_strtolower(trim((string) $this->input('reporter_email'))),
        ]);
    }

    public function rules(): array
    {
        $rules = parent::rules();
        $rules['employee_id'] = ['prohibited'];
        $rules['fingerprint'] = ['required', 'string', 'size:64'];
        if (! $this->route('record')->canEditSubmissionIdentity()) {
            foreach (['first_name', 'last_name', 'email', 'pesel'] as $field) {
                $rules[$field] = ['exclude'];
            }
        }

        return $rules;
    }
}
