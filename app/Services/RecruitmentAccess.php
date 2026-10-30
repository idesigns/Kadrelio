<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Http\Request;

class RecruitmentAccess
{
    public function enabled(Company $company): bool
    {
        return filled($company->recruitment_password) && $company->hasFormConfiguration();
    }

    public function allows(Request $request, Company $company): bool
    {
        $access = $request->session()->get('recruitment.'.$company->id);

        return $this->enabled($company)
            && is_array($access)
            && ($access['expires_at'] ?? 0) > now()->timestamp
            && is_string($access['version'] ?? null)
            && hash_equals(hash('sha256', $company->recruitment_password), $access['version']);
    }

    public function grant(Request $request, Company $company): void
    {
        $request->session()->regenerate();
        $request->session()->put('recruitment.'.$company->id, [
            'version' => hash('sha256', $company->recruitment_password),
            'expires_at' => now()->addHours(2)->timestamp,
        ]);
    }
}
