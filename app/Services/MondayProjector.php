<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\MondayImportBatch;
use App\Models\MondayItem;
use App\Models\MondayRevision;
use App\Models\User;
use App\Models\WorkflowRecord;
use App\Support\MondayFields;
use App\Support\ProfileSource;
use Illuminate\Support\Str;

class MondayProjector
{
    public function run(MondayImportBatch $batch): void
    {
        foreach ($batch->boards()->whereNotNull('company_id')->get()->groupBy('company_id') as $companyId => $boards) {
            $employees = [];
            $profiles = $boards->firstWhere('kind', 'profiles');
            if ($profiles) {
                $groups = $profiles->items()->get(['id', 'identity_hash'])->groupBy(fn (MondayItem $item): string => $item->identity_hash ?? 'item:'.$item->id);
                foreach ($groups as $identityKey => $versions) {
                    $item = $profiles->items()->findOrFail($versions->first()->id);
                    $employee = $this->employee($item, $companyId, $batch);
                    if ($versions->count() === 1) {
                        $this->applyProfile($employee, $item);
                    } else {
                        $employee->personal_data = array_merge($employee->personal_data ?? [], ['profile_review_required' => true]);
                        $employee->save();
                        foreach ($versions as $version) {
                            $profile = $profiles->items()->findOrFail($version->id);
                            $profile->issues = array_values(array_unique([...($profile->issues ?? []), 'duplicate_profile']));
                            $profile->save();
                        }
                    }
                    $profiles->items()->whereKey($versions->modelKeys())->update(['employee_id' => $employee->id]);
                    $employees[$identityKey] = $employee;
                }
            }
            foreach (['contracts', 'amendments', 'terminations', 'changes', 'multisport', 'contract_statements', 'amendment_statements', 'termination_statements', 'equipment_assignment', 'equipment_return'] as $kind) {
                $board = $boards->firstWhere('kind', $kind);
                if (! $board) {
                    continue;
                }
                $board->items()->lazyById(150)->each(function (MondayItem $item) use ($companyId, $batch, $kind, &$employees): void {
                    $identityKey = $item->identity_hash ?? 'item:'.$item->id;
                    if (! isset($employees[$identityKey]) && in_array($kind, ['contracts', 'amendments', 'terminations', 'changes', 'multisport'], true)) {
                        $employees[$identityKey] = $this->employee($item, $companyId, $batch);
                        $item->issues = array_values(array_unique([...($item->issues ?? []), 'profile_missing']));
                    }
                    $item->employee_id = $employees[$identityKey]->id ?? null;
                    $workflowKind = ['contracts' => 'contract', 'amendments' => 'amendment', 'terminations' => 'termination', 'changes' => 'data_change', 'multisport' => 'multisport'][$kind] ?? null;
                    if ($workflowKind) {
                        $record = $this->record($item, $workflowKind, $companyId, $batch);
                        $item->workflow_record_id = $record->id;
                    }
                    $item->save();
                });
            }
        }
    }

    public function applyProfile(Employee $employee, MondayItem $item, ?array $columns = null, bool $onlyMissing = false): void
    {
        $mapping = ProfileSource::columns($item->source_data['headers']);
        $values = ProfileSource::values($item);
        $core = ['first_name', 'last_name', 'email', 'phone', 'pesel'];
        $keys = $columns === null ? array_keys(ProfileSource::labels()) : array_keys(array_filter($mapping, fn (string $column): bool => in_array($column, $columns, true)));
        $personal = $employee->personal_data ?? [];
        foreach (array_diff($keys, $core) as $key) {
            if ($onlyMissing && array_key_exists($key, $personal)) {
                continue;
            }
            if (array_key_exists($key, $values)) {
                $personal[$key] = $values[$key];
            } elseif (! $onlyMissing) {
                unset($personal[$key]);
            }
        }
        if (! $onlyMissing) {
            foreach (array_intersect($keys, ['first_name', 'last_name', 'email', 'phone']) as $attribute) {
                $employee->{$attribute} = $attribute === 'last_name' ? $item->name : ($values[$attribute] ?? '');
            }
            $personal = array_merge($personal, ['monday_profile_id' => $item->id, 'profile_review_required' => false]);
            $employee->profile_completed_at = $item->document_date ?? now();
        }
        if ($personal !== ($employee->personal_data ?? [])) {
            $employee->personal_data = $personal;
        }
        if ($employee->isDirty()) {
            $employee->save();
        }
    }

    public function syncItem(MondayItem $item, ?array $columns = null): void
    {
        if ($item->workflow_record_id) {
            $record = $this->record($item, $item->record->kind, $item->board->company_id, $item->board->batch, $item->record);
            if (! $record->isImported() && $columns !== null) {
                $record->forceFill(['status' => 'draft', 'generated_at' => null, 'document_html' => null, 'document_hash' => null, 'pdf_path' => null])->save();
                $this->syncRecord($record);
                $item->refresh();
            }
        }
        if ($item->board->kind === 'profiles' && $item->employee && ($item->employee->personal_data['monday_profile_id'] ?? null) === $item->id) {
            $this->applyProfile($item->employee, $item, $columns);
        }
    }

    public function syncProfile(Employee $employee, array $attributes, User $user): void
    {
        $item = $employee->mondayItems()->whereKey($employee->personal_data['monday_profile_id'] ?? null)->lockForUpdate()->first();
        if (! $item) {
            return;
        }
        $map = ProfileSource::columns($item->source_data['headers']);
        $fields = $item->fields;
        $changes = [];
        foreach (array_intersect_key($map, array_flip($attributes)) as $attribute => $column) {
            if ($column === false) {
                continue;
            }
            $value = ProfileSource::display($attribute, $employee->{$attribute} ?? $employee->personal_data[$attribute] ?? '');
            if (($fields[$column] ?? '') !== $value) {
                $changes[$column] = ['before' => $fields[$column] ?? '', 'after' => $value];
                $fields[$column] = $value;
            }
        }
        if ($changes === []) {
            return;
        }
        $item->fields = $fields;
        $item->name = $fields['A'] ?? $item->name;
        $item->revision++;
        $item->save();
        $revision = new MondayRevision;
        $revision->forceFill(['monday_item_id' => $item->id, 'user_id' => $user->id, 'changes' => ['reason' => 'Aktualizacja profilu pracownika', 'fields' => $changes]])->save();
    }

    public function syncRecord(WorkflowRecord $record): void
    {
        $item = $record->mondayItem()->with('board')->lockForUpdate()->first();
        if (! $item) {
            return;
        }
        $status = match ($record->status) {
            'draft', 'awaiting_data' => 'W przygotowaniu',
            'ready' => $record->kind === 'amendment' ? 'Wygenerowane' : 'Wygenerowana',
            'sent' => 'Wysłana do podpisu', 'signed' => 'Podpisana', 'terminated' => 'Rozwiązana', 'not_concluded' => 'Niezawarta',
            default => null,
        };
        if ($status === null || $item->status === $status) {
            return;
        }
        $fields = $item->fields;
        foreach ($item->source_data['headers'] as $column => $label) {
            if (in_array(MondayFields::normalize($label), ['status umowy', 'status'], true)) {
                $fields[$column] = $status;
            }
        }
        $group = match (true) {
            $record->kind === 'contract' && $record->status === 'terminated' => 'Umowy rozwiązane',
            $record->kind === 'contract' && $record->status === 'signed' => 'Aktualne umowy',
            $record->kind === 'amendment' && $record->status === 'ready' => 'Aneksy wygenerowane',
            $record->kind === 'termination' && $record->status === 'ready' => 'Rozwiązania wygenerowane',
            default => $item->group_name,
        };
        $item->fields = $fields;
        $item->status = $status;
        if (in_array($group, $item->board->groups, true)) {
            $item->group_name = $group;
        }
        $item->revision++;
        $item->save();
    }

    private function employee(MondayItem $item, int $companyId, MondayImportBatch $batch): Employee
    {
        $firstName = $item->value('Imię/Name*', 'Imię');
        $lastName = $item->name;
        if ($item->board->kind !== 'profiles' && $firstName !== '' && str_starts_with(MondayFields::normalize($lastName), MondayFields::normalize($firstName).' ')) {
            $lastName = trim(mb_substr($lastName, mb_strlen($firstName)));
        }
        $employee = new Employee;
        $employee->forceFill(['company_id' => $companyId, 'first_name' => $firstName, 'last_name' => $lastName, 'email' => $item->value('E-mail strony umowy', 'E-mail', 'Adres e-mail'), 'phone' => $item->value('Telefon/phone number', 'Tel. kontaktowy'), 'pesel' => MondayFields::identity($item->source_data['headers'], $item->fields) ?: null, 'pesel_hash' => $item->identity_hash, 'form_token' => Str::random(64), 'form_expires_at' => now()->subSecond(), 'personal_data' => ['monday_source' => $batch->source_key, 'identity_type' => MondayFields::identityType(MondayFields::identity($item->source_data['headers'], $item->fields)), 'profile_review_required' => true]])->save();

        return $employee;
    }

    private function record(MondayItem $item, string $kind, int $companyId, MondayImportBatch $batch, ?WorkflowRecord $record = null): WorkflowRecord
    {
        $type = $item->value('Typ Umowy') ?: ($item->parent?->value('Typ Umowy') ?? '');
        $agreementType = MondayFields::agreementType($type);
        $b2b = $agreementType === 'b2b';
        $record ??= new WorkflowRecord;
        $record->forceFill([
            'company_id' => $companyId, 'employee_id' => $item->employee_id, 'kind' => $kind,
            'status' => $this->sourceStatus($item, $kind),
            'language' => MondayFields::language($type),
            'department' => $item->value('Załącznik UZ', 'Aktualny dział') ?: null,
            'concluded_at' => in_array($kind, ['contract', 'amendment'], true) ? $item->document_date : null,
            'starts_at' => MondayFields::date($item->value('Data rozpoczęcia umowy formularz'), $item->source_data['date1904']),
            'ends_at' => $kind === 'termination' ? $item->document_date : MondayFields::date($item->value('Data zakończenia umowy formularz'), $item->source_data['date1904']),
            'contract_id' => $item->parent?->workflow_record_id,
            'signing_token' => $record->signing_token ?? Str::random(64),
            'data' => array_merge($record->data ?? [], ['monday_source' => $batch->source_key, 'monday_item_id' => $item->id, 'monday_status' => $item->status, 'monday_group' => $item->group_name, 'monday_agreement_type' => $type, 'agreement_type' => $agreementType, 'contractor_tax_id' => $b2b ? MondayFields::identity($item->source_data['headers'], $item->fields) : null, 'reporter_name' => $item->value('Imię i nazwisko osoby zgłaszającej', 'Osoba zgłaszająca'), 'reporter_email' => $item->value('E-mail osoby zgłaszającej'), 'change_type' => ['Zmiana działu' => 'department', 'Zmiana stawki godzinowej' => 'hourly', 'Zmiana stawki prowizyjnej %' => 'commission', 'Zmiana NDA' => 'nda'][$item->value('Rodzaj zmiany')] ?? null, 'hourly_rate' => $item->value('Nowa stawka godzinowa (PLN/H)'), 'commission_rate' => $item->value('Nowa stawka prowizyjna w %'), 'old_department' => $item->value('Aktualny dział'), 'new_department' => $item->value('Nowy dział'), 'current_address' => $item->value('Aktualny adres zamieszkania')]),
        ])->save();

        return $record;
    }

    public function sourceStatus(MondayItem $item, string $kind): string
    {
        return match ($item->status) {
            'Podpisana' => 'signed', 'Rozwiązana' => 'terminated', 'Niezawarta' => 'not_concluded', 'Wysłana do podpisu' => 'sent', 'Wygenerowane', 'Wygenerowana' => 'ready',
            default => in_array($kind, ['data_change', 'multisport'], true) ? 'submitted' : 'draft',
        };
    }
}
