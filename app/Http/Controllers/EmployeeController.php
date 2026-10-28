<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Employee;
use App\Services\EmployeeFiles;
use App\Services\HrWorkflow;
use App\Services\MondayProjector;
use App\Services\OutgoingMail;
use App\Support\HrForms;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeController extends Controller
{
    public function show(Company $company, Employee $employee): View
    {
        Gate::authorize('view', $company);
        $records = $employee->records()->with('mondayItem')->latest('id')->get();
        $profileReviewItem = ($employee->personal_data['profile_review_required'] ?? false) ? $employee->mondayItems()->whereHas('board', fn (Builder $query): Builder => $query->where('kind', 'profiles'))->orderBy('id')->first(['id', 'monday_board_id']) : null;

        $sourceProfileFiles = $employee->mondayItems()->whereHas('board', fn (Builder $query): Builder => $query->where('kind', 'profiles'))->with(['assets', 'board'])->get();

        return view('employees.show', compact('company', 'employee', 'records', 'profileReviewItem', 'sourceProfileFiles'));
    }

    public function invite(Company $company, Employee $employee, HrWorkflow $workflow): RedirectResponse
    {
        Gate::authorize('view', $company);
        $workflow->inviteEmployee($employee);

        return back()->with('success', app(OutgoingMail::class)->enabled() ? 'Przypomnienie dodano do kolejki e-mail.' : 'Link jest gotowy. Wysyłka e-mail jest wyłączona w tym środowisku.');
    }

    public function update(Request $request, Company $company, Employee $employee, MondayProjector $projector): RedirectResponse
    {
        Gate::authorize('view', $company);
        Gate::authorize('update', $company);
        if (is_string($request->input('email'))) {
            $request->merge(['email' => mb_strtolower($request->input('email'))]);
        }
        if (is_string($request->input('bank_account'))) {
            $request->merge(['bank_account' => preg_replace('/\s+/', '', $request->input('bank_account'))]);
        }
        $fields = HrForms::profileFields();
        unset($fields['first_name'], $fields['last_name'], $fields['email'], $fields['pesel']);
        foreach ($fields as &$field) {
            $field['required'] = false;
            unset($field['when']);
        }
        unset($field);
        $data = $request->validate(array_replace(HrForms::fieldRules($fields), [
            'first_name' => ['required', 'string', 'max:120'], 'last_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'bank_account' => ['nullable', 'string', 'regex:/^(?:[0-9]{26}|[A-Z]{2}[0-9A-Z]{13,32})$/'], 'street' => ['nullable', 'string', 'max:255'],
            'house_number' => ['nullable', 'string', 'max:60'], 'city' => ['nullable', 'string', 'max:120'], 'postal_code' => ['nullable', 'string', 'max:20'],
        ]) + HrForms::profileUploadRules());
        DB::transaction(function () use ($employee, $data, $request, $projector): void {
            $employee = Employee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $employee->fill(collect($data)->only(['first_name', 'last_name', 'email', 'phone'])->all());
            $employee->personal_data = array_merge($employee->personal_data ?? [], collect($data)->except(['first_name', 'last_name', 'email', 'phone', 'student_documents'])->all());
            $employee->save();
            app(EmployeeFiles::class)->store($employee, $data['student_documents'] ?? []);
            $projector->syncProfile($employee, array_keys($data), $request->user());
        });

        return back()->with('success', 'Dane pracownika zostały zaktualizowane. Wygenerowane wcześniej dokumenty zachowują oryginalne dane.');
    }

    public function file(Company $company, Employee $employee, string $file, EmployeeFiles $files): StreamedResponse
    {
        Gate::authorize('view', $company);
        $document = $files->find($employee, $file);

        return Storage::disk('local')->download($document['path'], $document['name'], ['Content-Type' => $document['mime'], 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
