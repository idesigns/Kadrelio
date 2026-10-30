<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRecruitmentRequest;
use App\Models\Company;
use App\Services\HrWorkflow;
use App\Services\RecruitmentAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RecruitmentController extends Controller
{
    public function show(Request $request, Company $company, RecruitmentAccess $access): View
    {
        abort_unless($access->enabled($company), 404);

        return view($access->allows($request, $company) ? 'recruitment.form' : 'recruitment.unlock', compact('company'));
    }

    public function unlock(Request $request, Company $company, RecruitmentAccess $access): RedirectResponse
    {
        abort_unless($access->enabled($company), 404);
        $data = $request->validate(['password' => ['required', 'string', 'max:72']]);
        if (! Hash::check($data['password'], $company->recruitment_password)) {
            throw ValidationException::withMessages(['password' => 'Nieprawidłowe hasło formularza.']);
        }
        $access->grant($request, $company);

        return redirect()->route('recruitment.show', $company->public_token);
    }

    public function store(StoreRecruitmentRequest $request, Company $company, HrWorkflow $workflow): RedirectResponse
    {
        $workflow->create($company, 'contract', $request->validated(), null);

        return redirect()->route('recruitment.show', $company->public_token)
            ->with('success', 'Zgłoszenie zostało przyjęte. Dalszą obsługą zajmie się dział HR.');
    }
}
