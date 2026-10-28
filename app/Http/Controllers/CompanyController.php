<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CompanyController extends Controller
{
    public function create(): View
    {
        Gate::authorize('create', Company::class);

        return view('companies.form', ['company' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Company::class);
        $data = $this->validated($request);
        $company = DB::transaction(function () use ($data, $request): Company {
            $company = new Company($data);
            $company->owner_id = $request->user()->id;
            $company->public_token = Str::random(64);
            $company->save();
            $company->users()->attach($request->user());

            return $company;
        });

        return redirect()->route('companies.board', [$company, 'contracts'])->with('success', 'Firma została dodana. Możesz zaprosić zespół i zgłosić pierwszą umowę.');
    }

    public function edit(Company $company): View
    {
        Gate::authorize('view', $company);
        Gate::authorize('update', $company);

        return view('companies.form', compact('company'));
    }

    public function update(Request $request, Company $company): RedirectResponse
    {
        Gate::authorize('view', $company);
        Gate::authorize('update', $company);
        $company->update($this->validated($request));

        return back()->with('success', 'Ustawienia firmy zostały zapisane.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'tax_id' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:1000'],
            'hr_email' => ['required', 'email', 'max:255'],
            'privacy_url' => ['required', 'url:http,https', 'max:1000'],
            'color' => ['required', Rule::in(['green', 'purple', 'blue', 'orange'])],
        ]);
    }
}
