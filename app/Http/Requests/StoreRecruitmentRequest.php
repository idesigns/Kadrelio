<?php

namespace App\Http\Requests;

use App\Services\RecruitmentAccess;

class StoreRecruitmentRequest extends StoreWorkflowRequest
{
    public function authorize(): bool
    {
        return app(RecruitmentAccess::class)->allows($this, $this->route('company'));
    }

    public function rules(): array
    {
        return ['employee_id' => ['prohibited']] + parent::rules();
    }
}
