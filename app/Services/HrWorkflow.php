<?php

namespace App\Services;

use App\Mail\HrMessage;
use App\Models\Company;
use App\Models\DocumentTemplate;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkflowRecord;
use App\Support\HrForms;
use App\Support\MondayFields;
use App\Support\TemplateFields;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HrWorkflow
{
    public function create(Company $company, string $kind, array $data, ?User $creator): WorkflowRecord
    {
        if (! $company->hasFormConfiguration()) {
            throw ValidationException::withMessages(['company' => 'Najpierw uzupełnij adres HR i link do informacji RODO w ustawieniach firmy.']);
        }
        $record = DB::transaction(function () use ($company, $kind, $data, $creator): WorkflowRecord {
            $contract = null;
            if ($kind !== 'contract') {
                $contract = $company->records()->where('kind', 'contract')->where('status', 'signed')->findOrFail($data['contract_id']);
                if ($contract->isImported()) {
                    if (MondayFields::agreementType($contract->sourceAgreementType()) === 'unknown') {
                        throw ValidationException::withMessages(['contract_id' => 'Najpierw ustal typ umowy w jej wpisie z Monday. Eksport nie wskazuje, czy to zlecenie, czy B2B.']);
                    }
                }
                $employee = $contract->employee;
            } elseif (! empty($data['employee_id'])) {
                $employee = $company->employees()->findOrFail($data['employee_id']);
            } else {
                $hash = Employee::identityHash($data['pesel']);
                $employee = $company->employees()->where('pesel_hash', $hash)->first();
                if ($employee && mb_strtolower($employee->email) !== mb_strtolower($data['email'])) {
                    throw ValidationException::withMessages(['pesel' => 'Pracownik z tym numerem PESEL ma inny adres e-mail. Wybierz go z bazy pracowników.']);
                }
                if (! $employee) {
                    $employee = new Employee(collect($data)->only(['first_name', 'last_name', 'email', 'pesel', 'department'])->all());
                    $employee->email = mb_strtolower($employee->email);
                    $employee->company()->associate($company);
                    $employee->pesel_hash = $hash;
                    $employee->form_token = Str::random(64);
                    $employee->form_expires_at = now()->addDays(config('hr.link_days'));
                    $employee->save();
                }
            }
            $record = new WorkflowRecord(collect($data)->only(['language', 'department', 'concluded_at', 'starts_at', 'ends_at'])->all());
            $record->company()->associate($company);
            $record->employee()->associate($employee);
            $record->creator()->associate($creator);
            $record->kind = $kind;
            $record->status = $employee->profile_completed_at ? 'draft' : 'awaiting_data';
            $record->signing_token = Str::random(64);
            $record->data = collect($data)->only(['reporter_name', 'reporter_email', 'agreement_type', 'contractor_tax_id', ...array_keys(HrForms::additionalFields($kind))])->all();
            if ($contract) {
                $record->contract()->associate($contract);
                $record->language = $contract->documentLanguage();
                $record->department = $contract->department;
                $record->starts_at = $contract->starts_at;
                $record->data = $record->data + collect($contract->data ?? [])->only(['agreement_type', 'contractor_tax_id'])->all() + ['original_contract_date' => $contract->concluded_at?->format('Y-m-d')];
            }
            $record->save();

            return $record;
        });
        if ($kind === 'contract' && ! $record->employee->profile_completed_at) {
            $this->inviteEmployee($record->employee);
        }

        return $record;
    }

    public function inviteEmployee(Employee $employee): void
    {
        abort_if(($employee->personal_data['profile_review_required'] ?? false), 409, 'Najpierw wybierz lub zweryfikuj dane pracownika z Monday.');
        abort_if($employee->profile_completed_at, 409, 'Dane pracownika są już uzupełnione.');
        if (! $employee->form_expires_at || $employee->form_expires_at->isPast()) {
            $employee->form_token = Str::random(64);
            $employee->form_expires_at = now()->addDays(config('hr.link_days'));
            $employee->save();
        }
        app(OutgoingMail::class)->queue($employee->email, new HrMessage('Uzupełnij dane do umowy - '.$employee->company->name, 'Cześć! Wypełnij formularz zgłoszeniowy. Twoje działanie przyspieszy przygotowanie umowy. Link jest ważny przez 30 dni.', route('onboarding.show', $employee->form_token), 'Uzupełnij dane'));
    }

    public function completeProfile(Company $company, array $data, ?Employee $employee = null): Employee
    {
        return DB::transaction(function () use ($company, $data, $employee): Employee {
            $hash = Employee::identityHash($data['pesel']);
            if ($employee) {
                $employee = $company->employees()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
                abort_if($employee->profile_completed_at || $employee->form_expires_at?->isPast(), 410, 'Formularz został już wysłany lub link wygasł.');
                if (! hash_equals($employee->pesel_hash ?? '', $hash) || mb_strtolower($employee->email) !== $data['email']) {
                    throw ValidationException::withMessages(['pesel' => 'PESEL i e-mail muszą zgadzać się ze zgłoszeniem do umowy. Skontaktuj się z działem HR.']);
                }
            } else {
                if ($company->employees()->where('pesel_hash', $hash)->exists()) {
                    throw ValidationException::withMessages(['pesel' => 'Formularz dla tej osoby już istnieje. Użyj indywidualnego linku otrzymanego e-mailem lub skontaktuj się z HR.']);
                }
                $employee = new Employee;
                $employee->company()->associate($company);
                $employee->form_token = Str::random(64);
                $employee->pesel_hash = $hash;
            }
            $employee->fill(collect($data)->only(['first_name', 'last_name', 'email', 'phone', 'pesel'])->all());
            $employee->personal_data = array_merge($employee->personal_data ?? [], collect($data)->except(['first_name', 'last_name', 'email', 'phone', 'pesel', 'student_documents'])->all(), ['consented_at' => now()->toIso8601String(), 'privacy_url' => $company->privacy_url]);
            $employee->profile_completed_at = now();
            $employee->save();
            app(EmployeeFiles::class)->store($employee, $data['student_documents'] ?? []);
            $employee->records()->where('status', 'awaiting_data')->update(['status' => 'draft']);

            return $employee;
        });
    }

    public function generationBlockers(WorkflowRecord $record): array
    {
        $record->loadMissing(['company', 'employee', 'contract', 'mondayItem']);
        if ($record->isImported()) {
            $record = clone $record;
            $record->language = $record->documentLanguage();
        }
        $blockers = [];
        if (! in_array($record->status, ['draft', 'ready'], true) || $record->signed_at) {
            return ['Dokument wysłany do podpisu, podpisany lub zamknięty nie może być generowany ponownie. Oryginalne pliki są dostępne poniżej.'];
        }
        if (! $record->company->hasFormConfiguration()) {
            $blockers[] = 'Uzupełnij adres HR i link RODO w ustawieniach firmy.';
        }
        if (! $record->employee->profile_completed_at || ($record->employee->personal_data['profile_review_required'] ?? false)) {
            $blockers[] = 'Najpierw uzupełnij i zatwierdź dane pracownika.';
        }
        $dates = match ($record->kind) {
            'contract' => ['concluded_at' => 'zawarcia umowy', 'starts_at' => 'rozpoczęcia umowy', 'ends_at' => 'zakończenia umowy'],
            'amendment' => ['concluded_at' => 'zawarcia aneksu'],
            'termination' => ['ends_at' => 'ostatniego dnia pracy'],
            default => [],
        };
        foreach ($dates as $attribute => $label) {
            if (! $record->{$attribute}) {
                $blockers[] = 'Brakuje daty '.$label.'. Uzupełnij zgłoszenie dokumentu.';
            }
        }
        if ($record->isImported() && MondayFields::agreementType($record->sourceAgreementType()) === 'unknown') {
            $blockers[] = 'Wybierz typ umowy. W eksporcie nie określono, czy to zlecenie, czy B2B.';
        }
        $taxId = $record->data['contractor_tax_id'] ?? ($record->mondayItem ? MondayFields::identity($record->mondayItem->source_data['headers'], $record->mondayItem->fields) : '');
        if ($record->isB2b() && ! preg_match('/^\d{10}$/', $taxId)) {
            $blockers[] = 'Umowa B2B wymaga prawidłowego, 10-cyfrowego NIP kontrahenta.';
        }
        if ($record->isImported() && $record->mondayItem?->issues && ! $record->mondayItem->reviewed_at) {
            $blockers[] = 'Najpierw potwierdź sprawdzenie oznaczonych danych tego wpisu.';
        }
        if ($record->isImported() && $record->mondayItem?->children()->whereHas('board', fn (Builder $query): Builder => $query->whereIn('kind', ['contract_statements', 'amendment_statements', 'termination_statements']))->exists()) {
            $blockers[] = 'Do dokumentu istnieje oświadczenie podpisania. Pobierz oryginały; nie generuj ponownie podpisanej treści.';
        }
        if (in_array($record->kind, ['amendment', 'termination'], true) && $record->contract?->status !== 'signed') {
            $blockers[] = 'Najpierw przypisz właściwą, aktywną umowę pierwotną.';
        }
        $set = app(TemplateSet::class)->forRecord($record);
        $blockers = [...$blockers, ...$set['missing']];
        foreach ($set['templates'] as $template) {
            if (! app(TemplateRenderer::class)->available($template->format ?? 'text')) {
                $blockers[] = 'Wzór '.$template->name.' wymaga uruchomienia konwertera DOCX przez administratora.';
            }
        }

        return $blockers;
    }

    public function generate(WorkflowRecord $record, ?int $sourceRevision = null): void
    {
        [$record, $templates, $fingerprint] = DB::transaction(function () use ($record, $sourceRevision): array {
            $record = WorkflowRecord::whereKey($record->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($record->status, ['draft', 'ready'], true) && ! $record->signed_at, 409, 'Dokumentu wysłanego do podpisu nie można zmienić.');
            if ($record->isImported()) {
                $item = $record->mondayItem()->lockForUpdate()->firstOrFail();
                abort_unless($sourceRevision !== null && $item->revision === $sourceRevision, 409, 'Wpis zmienił się. Odśwież stronę przed generowaniem.');
                app(MondayProjector::class)->syncItem($item);
                $record->refresh();
            }
            $blockers = $this->generationBlockers($record);
            if ($blockers !== []) {
                throw ValidationException::withMessages(['document' => $blockers]);
            }
            $record->loadMissing(['company', 'employee', 'contract']);
            if (! $record->employee->profile_completed_at) {
                throw ValidationException::withMessages(['document' => 'Najpierw pracownik musi uzupełnić formularz danych osobowych.']);
            }
            $templates = app(TemplateSet::class)->forRecord($record)['templates'];

            return [$record, $templates, $this->generationFingerprint($record, $templates)];
        });
        $employee = $record->employee;
        $values = TemplateFields::values($record);
        $renderer = app(TemplateRenderer::class);
        $sections = [];
        try {
            foreach ($templates as $template) {
                $sections[] = ['title' => $template->name, 'body' => $renderer->text($template, $values)];
            }
            $native = collect($templates)->contains(fn (DocumentTemplate $template): bool => ($template->format ?? 'text') !== 'text');
            $snapshot = $native ? $renderer->snapshot($templates, $values, $record->company_id) : null;
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['document' => collect($exception->errors())->flatten()->all()]);
        }
        $documentData = ['document_identity' => ['full_name' => $employee->fullName(), 'pesel_hash' => $record->isB2b() ? Employee::identityHash($record->data['contractor_tax_id']) : $employee->pesel_hash], 'document_company_name' => $record->company->name, 'document_parts' => $snapshot['parts'] ?? [], 'template_versions' => array_map(fn (DocumentTemplate $template): array => ['id' => $template->id, 'version' => $template->version ?? 1], $templates)];
        $html = view('documents.contract', compact('record', 'sections'))->render();
        DB::transaction(function () use ($record, $fingerprint, $documentData, $snapshot, $html): void {
            Company::whereKey($record->company_id)->lockForUpdate()->firstOrFail();
            $current = WorkflowRecord::whereKey($record->id)->lockForUpdate()->firstOrFail();
            if ($current->isImported()) {
                $current->setRelation('mondayItem', $current->mondayItem()->lockForUpdate()->firstOrFail());
            }
            $current->loadMissing(['company', 'employee', 'contract', 'mondayItem']);
            $currentTemplates = app(TemplateSet::class)->forRecord($current)['templates'];
            abort_unless(hash_equals($fingerprint, $this->generationFingerprint($current, $currentTemplates)), 409, 'Dane lub wzory zmieniły się podczas generowania. Sprawdź je i wygeneruj dokument ponownie.');
            $blockers = $this->generationBlockers($current);
            if ($blockers !== []) {
                throw ValidationException::withMessages(['document' => $blockers]);
            }
            $current->data = array_merge($current->data ?? [], $documentData);
            if ($current->isImported()) {
                $current->data = array_merge($current->data, ['monday_workflow_active' => true, 'monday_continued_at' => now()->toIso8601String()]);
            }
            $current->document_html = $html;
            $current->document_hash = $snapshot['checksum'] ?? hash('sha256', $html);
            $current->generated_at = now();
            $current->pdf_path = $snapshot['path'] ?? null;
            $current->status = 'ready';
            $current->save();
            app(MondayProjector::class)->syncRecord($current);
        });
    }

    private function generationFingerprint(WorkflowRecord $record, array $templates): string
    {
        return hash('sha256', json_encode([$record->getRawOriginal(), $record->employee->getRawOriginal(), $record->company->getRawOriginal(), $record->contract?->getRawOriginal(), $record->mondayItem?->getRawOriginal(), array_map(fn (DocumentTemplate $template): array => [$template->id, $template->fingerprint()], $templates)], JSON_THROW_ON_ERROR));
    }
}
