<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEmployeeProfileRequest;
use App\Jobs\SendWorkflowMail;
use App\Mail\HrMessage;
use App\Models\Company;
use App\Models\Employee;
use App\Models\WorkflowRecord;
use App\Rules\SignatureImage;
use App\Services\HrWorkflow;
use App\Services\MondayProjector;
use App\Services\OutgoingMail;
use App\Support\HrForms;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PublicFormController extends Controller
{
    public function preview(Company $company): View
    {
        Gate::authorize('view', $company);
        Gate::authorize('update', $company);

        return view('public.profile', ['company' => $company, 'employee' => null, 'preview' => true]);
    }

    public function profile(Company $company): View
    {
        abort_unless($company->hasFormConfiguration(), 503, 'Firma nie uruchomiła jeszcze formularzy.');

        return view('public.profile', ['company' => $company, 'employee' => null]);
    }

    public function onboarding(Employee $employee): View
    {
        abort_unless($employee->company->hasFormConfiguration(), 503, 'Firma nie uruchomiła jeszcze formularzy.');
        $this->checkOnboarding($employee);

        return view('public.profile', ['company' => $employee->company, 'employee' => $employee]);
    }

    public function storeProfile(StoreEmployeeProfileRequest $request, Company $company, HrWorkflow $workflow): RedirectResponse
    {
        abort_unless($company->hasFormConfiguration(), 503, 'Firma nie uruchomiła jeszcze formularzy.');
        $employee = $workflow->completeProfile($company, $request->validated());
        $this->notifyProfile($employee);

        return redirect()->route('forms.success')->with('submitted', 'Dane zostały przekazane do działu HR.');
    }

    public function completeOnboarding(StoreEmployeeProfileRequest $request, Employee $employee, HrWorkflow $workflow): RedirectResponse
    {
        abort_unless($employee->company->hasFormConfiguration(), 503, 'Firma nie uruchomiła jeszcze formularzy.');
        $this->checkOnboarding($employee);
        $employee = $workflow->completeProfile($employee->company, $request->validated(), $employee);
        $this->notifyProfile($employee);

        return redirect()->route('forms.success')->with('submitted', 'Dane zostały przekazane do działu HR. Możemy już przygotować Twoją umowę.');
    }

    public function signing(WorkflowRecord $record): View
    {
        $this->checkSigning($record);

        return view('public.signing', ['record' => $record, 'company' => $record->company]);
    }

    public function sign(Request $request, WorkflowRecord $record): RedirectResponse
    {
        $this->checkSigning($record);
        $rules = $this->signatureRules($record->isB2b() ? 10 : 11) + ['document_hash' => ['required', 'string', 'size:64']];
        if ($record->kind !== 'contract') {
            $rules['effective_date'] = ['required', 'date_format:Y-m-d'];
        }
        $data = $request->validate($rules);
        $this->verifyIdentity($record->employee, $data, $record->data['document_identity'] ?? null);
        if (! hash_equals($record->document_hash, $data['document_hash'])) {
            throw ValidationException::withMessages(['accepted' => 'Dokument zmienił się. Odśwież stronę i przeczytaj go ponownie.']);
        }
        $expectedDate = $record->kind === 'termination' ? $record->ends_at : $record->concluded_at;
        if ($record->kind !== 'contract' && $data['effective_date'] !== $expectedDate?->format('Y-m-d')) {
            throw ValidationException::withMessages(['effective_date' => 'Data musi zgadzać się z dokumentem.']);
        }
        DB::transaction(function () use ($record, $data, $request): void {
            if ($record->contract_id) {
                WorkflowRecord::whereKey($record->contract_id)->lockForUpdate()->firstOrFail();
            }
            $locked = WorkflowRecord::whereKey($record->id)->lockForUpdate()->firstOrFail();
            $this->checkSigning($locked);
            $this->verifyIdentity($locked->employee, $data, $locked->data['document_identity'] ?? null);
            if (! hash_equals($locked->document_hash, $data['document_hash'])) {
                throw ValidationException::withMessages(['accepted' => 'Dokument zmienił się. Odśwież stronę.']);
            }
            $locked->data = array_merge($locked->data ?? [], $this->signatureSnapshot($request, $data, $locked->isB2b() ? 'NIP' : 'PESEL'), ['signed_document_hash' => $locked->document_hash, 'company_name' => $locked->data['document_company_name'] ?? $locked->company->name]);
            $locked->signed_at = now();
            $locked->status = 'signed';
            $locked->mail_status = app(OutgoingMail::class)->enabled() ? 'queued' : 'suppressed';
            $locked->save();
            app(MondayProjector::class)->syncRecord($locked);
            if ($locked->kind === 'termination' && $locked->contract_id) {
                $locked->contract->status = 'terminated';
                $locked->contract->save();
                app(MondayProjector::class)->syncRecord($locked->contract);
            }
            if ($locked->kind === 'amendment' && ($locked->data['change_type'] ?? '') === 'department') {
                $locked->employee->department = $locked->data['new_department'];
                $locked->employee->save();
            }
            if (app(OutgoingMail::class)->enabled()) {
                SendWorkflowMail::dispatch($locked->id, true)->afterCommit();
            }
        });

        return redirect()->route('forms.success')->with('submitted', app(OutgoingMail::class)->enabled() ? 'Oświadczenie zostało podpisane i zapisane. Kopia zostanie przekazana do działu HR.' : 'Oświadczenie zostało podpisane i zapisane. Wysyłka e-mail jest wyłączona; HR może pobrać dokument w panelu.');
    }

    public function additional(Company $company, string $kind): View
    {
        abort_unless($company->hasFormConfiguration(), 503, 'Firma nie uruchomiła jeszcze formularzy.');

        return view('public.additional', compact('company', 'kind'));
    }

    public function storeAdditional(Request $request, Company $company, string $kind): RedirectResponse
    {
        abort_unless($company->hasFormConfiguration(), 503, 'Firma nie uruchomiła jeszcze formularzy.');
        abort_unless(app(OutgoingMail::class)->enabled(), 409, 'Wysyłka e-mail jest wyłączona. Formularz wymaga potwierdzenia e-mail i nie został wysłany.');
        $rules = $this->signatureRules() + ['email' => ['required', 'email', 'max:255']] + HrForms::fieldRules(HrForms::additionalFields($kind));
        if ($kind === 'multisport' && $request->input('companion') === '1') {
            $rules['companion_consent'] = ['required', 'in:1'];
            $rules['companion_card'] = ['required', 'in:classic,plus'];
        }
        $data = $request->validate($rules);
        $employee = $company->employees()->where('pesel_hash', Employee::identityHash($data['pesel']))->where('email', mb_strtolower($data['email']))->first();
        if (! $employee) {
            throw ValidationException::withMessages(['pesel' => 'Nie udało się dopasować danych do pracownika. Sprawdź e-mail i PESEL lub skontaktuj się z HR.']);
        }
        $this->verifyIdentity($employee, $data);
        $record = new WorkflowRecord;
        $record->company()->associate($company);
        $record->employee()->associate($employee);
        $record->kind = $kind;
        $record->status = 'awaiting_confirmation';
        $record->signing_token = Str::random(64);
        $record->signing_expires_at = now()->addMinutes(30);
        $record->concluded_at = today();
        $record->data = array_merge(collect($data)->only(array_keys(HrForms::additionalFields($kind)))->all(), $this->signatureSnapshot($request, $data), ['company_name' => $company->name, 'email' => $employee->email]);
        $record->save();
        app(OutgoingMail::class)->queue($employee->email, new HrMessage('Potwierdź zgłoszenie: '.HrForms::kinds()[$kind], 'Sprawdź treść zgłoszenia i potwierdź jego wysłanie do HR. Link jest jednorazowy i ważny przez 30 minut. Jeśli to nie Twoje zgłoszenie, zignoruj tę wiadomość.', route('forms.confirmation', $record->signing_token), 'Sprawdź i potwierdź'));

        return redirect()->route('forms.success')->with('submitted', 'Sprawdź swoją skrzynkę e-mail. Zgłoszenie trafi do HR dopiero po potwierdzeniu linku ważnego przez 30 minut.');
    }

    public function confirmation(WorkflowRecord $record): View
    {
        $this->checkConfirmation($record);

        return view('public.confirmation', ['record' => $record, 'company' => $record->company]);
    }

    public function confirm(Request $request, WorkflowRecord $record): RedirectResponse
    {
        $this->checkConfirmation($record);
        $request->validate(['accepted' => ['accepted']]);
        DB::transaction(function () use ($record, $request): void {
            $locked = WorkflowRecord::whereKey($record->id)->lockForUpdate()->firstOrFail();
            $this->checkConfirmation($locked);
            abort_unless(hash_equals($locked->data['email'], $locked->employee->email), 410, 'E-mail pracownika zmienił się. Wypełnij formularz ponownie.');
            $locked->status = 'submitted';
            $locked->signed_at = now();
            $locked->data = array_merge($locked->data, ['email_confirmed_at' => now()->toIso8601String(), 'confirmation_ip' => $request->ip()]);
            $locked->mail_status = app(OutgoingMail::class)->enabled() ? 'queued' : 'suppressed';
            $locked->save();
            if (app(OutgoingMail::class)->enabled()) {
                SendWorkflowMail::dispatch($locked->id, true)->afterCommit();
            }
        });

        return redirect()->route('forms.success')->with('submitted', 'Oświadczenie zostało zapisane i przekazane do obsługi przez HR.');
    }

    public function success(): View
    {
        return view('public.success');
    }

    private function checkOnboarding(Employee $employee): void
    {
        abort_if($employee->profile_completed_at || ! $employee->form_expires_at || $employee->form_expires_at->isPast(), 410, 'Ten formularz został już wysłany lub link wygasł. Poproś dział HR o pomoc.');
    }

    private function checkSigning(WorkflowRecord $record): void
    {
        abort_unless($record->canBeSigned(), 410, 'Link do podpisu wygasł, dokument został już podpisany lub wycofany albo umowa pierwotna nie jest już aktywna.');
    }

    private function checkConfirmation(WorkflowRecord $record): void
    {
        abort_unless(in_array($record->kind, ['data_change', 'multisport'], true) && $record->status === 'awaiting_confirmation' && ! $record->signed_at && $record->signing_expires_at?->isFuture(), 410, 'Ten link wygasł lub zgłoszenie zostało już potwierdzone.');
    }

    private function signatureRules(int $identifierLength = 11): array
    {
        return ['full_name' => ['required', 'string', 'max:255'], 'pesel' => ['required', 'digits:'.$identifierLength], 'signature_name' => ['required', 'string', 'max:255'], 'signature_image' => ['required', new SignatureImage], 'accepted' => ['accepted']];
    }

    private function verifyIdentity(Employee $employee, array $data, ?array $identity = null): void
    {
        $normalize = fn (string $name): string => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name)));
        if (! hash_equals($identity['pesel_hash'] ?? $employee->pesel_hash ?? '', Employee::identityHash($data['pesel'])) || $normalize($data['full_name']) !== $normalize($identity['full_name'] ?? $employee->fullName()) || $normalize($data['signature_name']) !== $normalize($data['full_name'])) {
            throw ValidationException::withMessages(['full_name' => 'Imię, nazwisko, numer identyfikacyjny i dane podpisu muszą zgadzać się z dokumentem.']);
        }
    }

    private function signatureSnapshot(Request $request, array $data, string $identifierLabel = 'PESEL'): array
    {
        return ['signer_name' => $data['signature_name'], 'signer_identifier' => $data['pesel'], 'identifier_label' => $identifierLabel, 'signature_image' => $data['signature_image'], 'accepted' => true, 'signed_ip' => $request->ip(), 'signed_user_agent' => mb_substr((string) $request->userAgent(), 0, 1000)];
    }

    private function notifyProfile(Employee $employee): void
    {
        app(OutgoingMail::class)->queue($employee->company->hr_email, new HrMessage('Dane pracownika są gotowe - '.$employee->fullName(), 'Pracownik uzupełnił formularz danych. Dane są dostępne po zalogowaniu do panelu firmy.', route('companies.employees.show', [$employee->company_id, $employee->id]), 'Otwórz dane pracownika'));
    }
}
