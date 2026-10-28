<?php

namespace App\Support;

use App\Models\DocumentTemplate;
use App\Models\WorkflowRecord;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TemplateFields
{
    public const Pattern = '/\{\{\s*([^{}]{1,300}?)\s*\}\}|<<\s*([^<>]{1,500}?)\s*>>/u';

    private const RequiredFields = ['full_name', 'pesel', 'contractor_tax_id', 'company_name', 'company_address', 'company_tax_id', 'concluded_at', 'starts_at', 'ends_at', 'original_contract_date', 'email', 'department'];

    public static function kinds(): array
    {
        return ['contract' => 'Umowa', 'declaration' => 'Oświadczenie zleceniobiorcy', 'attachment' => 'Załącznik wynagrodzenia', 'amendment' => 'Aneks', 'termination' => 'Rozwiązanie', 'data_processing' => 'Przetwarzanie / powierzenie danych', 'monitoring' => 'Monitoring', 'regulations' => 'Regulamin', 'nda' => 'NDA', 'multisport' => 'Multisport', 'equipment_assignment' => 'Powierzenie sprzętu', 'equipment_return' => 'Zwrot sprzętu'];
    }

    public static function catalogue(): array
    {
        $fields = ['full_name' => 'Imię i nazwisko', 'first_name' => 'Imię', 'last_name' => 'Nazwisko', 'pesel' => 'PESEL', 'contractor_tax_id' => 'NIP kontrahenta B2B', 'email' => 'E-mail pracownika', 'phone' => 'Telefon', 'company_name' => 'Nazwa firmy', 'company_address' => 'Adres firmy', 'company_tax_id' => 'NIP firmy', 'agreement_type' => 'Typ umowy', 'department' => 'Dział', 'language' => 'Wariant umowy', 'concluded_at' => 'Data zawarcia dokumentu', 'starts_at' => 'Data rozpoczęcia', 'ends_at' => 'Data zakończenia', 'original_contract_date' => 'Data umowy pierwotnej', 'current_address' => 'Aktualny adres zamieszkania', 'today' => 'Data wygenerowania'];
        foreach ([...HrForms::profileFields(), ...HrForms::additionalFields('amendment'), ...HrForms::additionalFields('termination')] as $key => $field) {
            $fields[$key] ??= $field['label'];
        }

        return $fields;
    }

    public static function tokens(string $text): array
    {
        preg_match_all(self::Pattern, $text, $matches);

        return array_values(array_unique($matches[0]));
    }

    public static function supported(string $token): bool
    {
        return str_starts_with($token, '{{') || preg_match('/^<<\s*(?:column_firstrow\s*:|today\s*>>)/iu', $token) === 1;
    }

    public static function suggest(string $token, string $agreementType = 'mandate'): ?string
    {
        $label = trim(preg_replace('/^(?:\{\{|<<)|(?:\}\}|>>)$/u', '', $token));
        $label = preg_replace('/^column_firstrow\s*:\s*/iu', '', $label);
        if (array_key_exists($label, self::catalogue())) {
            return $label;
        }
        $aliases = ['Name' => 'full_name', 'Item' => 'full_name', 'Nazwa' => 'full_name', 'Imię i nazwisko strony umowy' => 'full_name', 'PESEL strony umowy' => $agreementType === 'b2b' ? 'contractor_tax_id' : 'pesel', 'PESEL Pracownika' => $agreementType === 'b2b' ? 'contractor_tax_id' : 'pesel', 'NIP' => 'contractor_tax_id', 'E-mail strony umowy' => 'email', 'Adres e-mail' => 'email', 'Data zawarcia umowy formularz' => 'concluded_at', 'Data zawarcia aneksu' => 'concluded_at', 'Data rozpoczęcia umowy formularz' => 'starts_at', 'Data zakończenia umowy formularz' => 'ends_at', 'Data pierwotnej umowy' => 'original_contract_date', 'Data rozwiązania umowy' => 'ends_at', 'Umowa zostaje rozwiązana ze skutkiem na dzień:' => 'ends_at', 'Załącznik UZ' => 'department', 'Typ Umowy' => 'agreement_type', 'Tel. kontaktowy' => 'phone', 'Nr konta bankowego' => 'bank_account'];
        foreach (self::catalogue() as $key => $value) {
            if (self::normalized($label) === self::normalized((string) $value)) {
                return $key;
            }
        }
        foreach ($aliases as $alias => $key) {
            if (self::normalized($label) === self::normalized($alias)) {
                return $key;
            }
        }
        foreach (ProfileSource::labels() as $key => $labels) {
            foreach ($labels as $alias) {
                if (self::normalized($label) === self::normalized($alias)) {
                    return $key;
                }
            }
        }

        return null;
    }

    private static function normalized(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(Str::ascii($value)));
    }

    public static function values(WorkflowRecord $record): array
    {
        $record->loadMissing(['employee', 'company', 'contract']);
        $employee = $record->employee;

        $values = array_merge($employee->personal_data ?? [], $record->data ?? [], [
            'company_name' => $record->company->name, 'company_address' => $record->company->address, 'company_tax_id' => $record->company->tax_id,
            'first_name' => $employee->first_name, 'last_name' => $employee->last_name, 'full_name' => $employee->fullName(), 'pesel' => $employee->pesel,
            'agreement_type' => $record->agreementLabel(), 'email' => $employee->email, 'phone' => $employee->phone, 'department' => $record->department, 'language' => $record->language,
            'concluded_at' => $record->concluded_at?->format('d.m.Y'), 'starts_at' => $record->starts_at?->format('d.m.Y'), 'ends_at' => $record->ends_at?->format('d.m.Y'),
            'original_contract_date' => $record->contract?->concluded_at?->format('d.m.Y'), 'today' => now()->format('d.m.Y'),
        ]);
        foreach (HrForms::profileFields() as $key => $field) {
            if (! isset($values[$key]) || is_array($values[$key])) {
                continue;
            }
            if (($field['type'] ?? '') === 'date' && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $values[$key])) {
                $values[$key] = Carbon::parse($values[$key])->format('d.m.Y');
            } else {
                $values[$key] = ProfileSource::display($key, $values[$key]);
            }
        }

        return $values;
    }

    public static function sample(DocumentTemplate $template): array
    {
        $values = array_fill_keys(array_keys(self::catalogue()), 'Dane przykładowe');

        return array_replace($values, ['full_name' => 'Anna Przykładowa', 'first_name' => 'Anna', 'last_name' => 'Przykładowa', 'pesel' => '00000000000', 'contractor_tax_id' => '0000000000', 'company_name' => $template->company->name, 'company_address' => $template->company->address ?: 'Przykładowa 1, 00-000 Warszawa', 'company_tax_id' => $template->company->tax_id ?: '0000000000', 'email' => 'anna@example.com', 'phone' => '000000000', 'department' => $template->department ?: 'P1', 'language' => $template->market ?: $template->language, 'concluded_at' => '15.01.2026', 'starts_at' => '16.01.2026', 'ends_at' => '31.12.2026', 'original_contract_date' => '01.01.2026', 'today' => now()->format('d.m.Y'), 'bank_account' => '00000000000000000000000000', 'agreement_type' => $template->agreement_type === 'b2b' ? 'Umowa B2B' : 'Umowa zlecenie']);
    }

    public static function replacements(DocumentTemplate $template, array $values): array
    {
        $replacements = [];
        foreach ($template->placeholders ?? self::tokens($template->body) as $token) {
            if (! self::supported($token)) {
                throw ValidationException::withMessages(['field_mapping' => 'Pole '.$token.' tworzy tabelę lub elementy podrzędne DocuGen. Zastąp je pojedynczymi polami we wzorze Word.']);
            }
            $key = $template->field_mapping[$token] ?? self::suggest($token, $template->agreement_type ?? 'mandate');
            if ($key === '__blank') {
                if (self::isRequired(self::suggest($token, $template->agreement_type ?? 'mandate'), $values)) {
                    throw ValidationException::withMessages(['field_mapping' => 'Pole '.$token.' jest wymagane i nie może pozostać puste.']);
                }
                $replacements[$token] = '';

                continue;
            }
            if (! $key || ! array_key_exists($key, self::catalogue())) {
                throw ValidationException::withMessages(['field_mapping' => 'Przypisz dane Kadrelio do pola '.$token.'.']);
            }
            $value = $values[$key] ?? null;
            if (($value === null || $value === '') && self::isRequired($key, $values)) {
                throw ValidationException::withMessages(['document' => 'Brakuje wartości pola w szablonie: '.$key.'. Uzupełnij dane.']);
            }
            if (is_array($value) || is_object($value)) {
                throw ValidationException::withMessages(['document' => 'Pole '.$key.' wymaga pojedynczej wartości.']);
            }
            $replacements[$token] = is_bool($value) ? ($value ? 'Tak' : 'Nie') : (string) ($value ?? '');
        }

        return $replacements;
    }

    private static function isRequired(?string $key, array $values): bool
    {
        if (in_array($key, self::RequiredFields, true)) {
            return true;
        }
        $field = HrForms::profileFields()[$key] ?? [];
        if (! ($field['required'] ?? false)) {
            return false;
        }
        if (isset($field['when'])) {
            [$parent, $expected] = $field['when'];

            return in_array(ProfileSource::decode($parent, (string) ($values[$parent] ?? '')), explode('|', $expected), true);
        }

        return true;
    }
}
