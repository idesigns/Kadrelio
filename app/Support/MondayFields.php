<?php

namespace App\Support;

use App\Models\Company;
use Carbon\CarbonImmutable;

class MondayFields
{
    public static function isAction(string $label): bool
    {
        return in_array(self::normalize($label), ['generuj', 'generuj dokument', 'generuj rozwiązanie umowy', 'generowanie', 'generator', 'przycisk'], true);
    }

    public static function isFile(string $label): bool
    {
        return in_array(self::normalize($label), ['umowa i załączniki', 'aneks', 'rozwiązanie umowy', 'oświadczenie', 'pliki', 'files', 'dokumenty monday', 'dodaj skan legitymacji ucznia/studenta'], true);
    }

    public static function isRelation(string $label): bool
    {
        return str_contains(self::normalize($label), 'baza pracowników') || in_array(self::normalize($label), ['otc umowy', 'approval umowy', 'umowy do stworzenia/połaczenie talic'], true);
    }

    public static function options(string $label, string $kind, string $current): array
    {
        $options = match (self::normalize($label)) {
            'typ umowy' => ['Umowa zlecenie Ogólna Polska', 'Umowa zlecenie Bułgaria', 'UZ Rumunia', 'Umowa B2B Polska', 'Umowa B2B Bułgaria', 'Umowa B2B Rumunia'],
            'załącznik uz', 'aktualny dział', 'nowy dział' => HrForms::departments(),
            'status umowy' => ['W przygotowaniu', 'Wygenerowana', 'Wysłana do podpisu', 'Podpisana', 'Rozwiązana', 'Niezawarta'],
            'status' => match ($kind) {
                'amendments' => ['W przygotowaniu', 'Wygenerowane', 'Wysłana do podpisu', 'Podpisana', 'Niezawarta'],
                'terminations' => ['W przygotowaniu', 'Wygenerowana', 'Wysłana do podpisu', 'Podpisana', 'Niezawarta'],
                'contract_statements', 'amendment_statements', 'termination_statements' => ['Złożone'],
                default => [],
            },
            default => [],
        };
        if ($options !== [] && $current !== '' && ! self::isPlaceholder($current)) {
            $options[] = $current;
        }

        return array_values(array_unique($options));
    }

    public static function isPlaceholder(string $value): bool
    {
        return in_array(self::normalize($value), ['wybierz typ umowy', 'wybierz', 'wybierz załącznik'], true);
    }

    public static function url(string $value): ?string
    {
        $value = trim($value);

        return in_array(parse_url($value, PHP_URL_SCHEME), ['http', 'https'], true) && filter_var($value, FILTER_VALIDATE_URL) ? $value : null;
    }

    public static function formLink(Company $company, string $name): ?array
    {
        $name = self::normalize($name);
        $url = match (true) {
            $name === 'zgłoszenie nowej umowy' => route('companies.records.create', [$company, 'contract']),
            $name === 'formularz danych osobowych' => route('forms.profile', $company->public_token),
            $name === 'formularz zmiany danych osobowych' => route('forms.additional', [$company->public_token, 'data_change']),
            in_array($name, ['fomularz zgloszenia aneksu', 'formularz zgłoszenia aneksu'], true) => route('companies.records.create', [$company, 'amendment']),
            $name === 'rozwiązanie umowy' => route('companies.records.create', [$company, 'termination']),
            str_starts_with($name, 'multisport') => route('forms.additional', [$company->public_token, 'multisport']),
            $name === 'oświadczenie podpisania umowy' => route('companies.board', [$company, 'contracts']),
            $name === 'oświadczenie o zawarciu aneksu' => route('companies.board', [$company, 'amendments']),
            $name === 'oświadczenie o rozwiązaniu umowy' => route('companies.board', [$company, 'terminations']),
            default => null,
        };

        return $url ? ['url' => $url, 'label' => str_starts_with($name, 'oświadczenie') ? 'Wybierz dokument i jego link do podpisu' : 'Otwórz formularz', 'requires_configuration' => ! str_starts_with($name, 'oświadczenie')] : null;
    }

    public static function normalize(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value)));
    }

    public static function value(array $headers, array $values, string ...$labels): string
    {
        foreach ($labels as $label) {
            foreach ($headers as $column => $header) {
                if (self::normalize($header) === self::normalize($label) && ($values[$column] ?? '') !== '') {
                    return (string) $values[$column];
                }
            }
        }

        return '';
    }

    public static function identity(array $headers, array $values): string
    {
        foreach ($headers as $column => $header) {
            if (str_contains(self::normalize($header), 'pesel') && ($values[$column] ?? '') !== '') {
                return preg_replace('/\s+/u', '', (string) $values[$column]);
            }
        }

        return '';
    }

    public static function identityType(string $value): string
    {
        if (preg_match('/^\d{10}$/', $value)) {
            $sum = 0;
            foreach ([6, 5, 7, 2, 3, 4, 5, 6, 7] as $index => $weight) {
                $sum += (int) $value[$index] * $weight;
            }

            return $sum % 11 === (int) $value[9] ? 'nip' : 'unverified';
        }
        if (preg_match('/^\d{11}$/', $value)) {
            $sum = 0;
            foreach ([1, 3, 7, 9, 1, 3, 7, 9, 1, 3] as $index => $weight) {
                $sum += (int) $value[$index] * $weight;
            }
            $month = (int) substr($value, 2, 2);
            $century = match (true) {
                $month >= 81 => 1800,
                $month >= 61 => 2200,
                $month >= 41 => 2100,
                $month >= 21 => 2000,
                default => 1900,
            };
            if ((10 - $sum % 10) % 10 === (int) $value[10] && checkdate($month % 20, (int) substr($value, 4, 2), $century + (int) substr($value, 0, 2))) {
                return 'pesel';
            }
        }

        return $value === '' ? 'missing' : 'unverified';
    }

    public static function agreementType(string $value): string
    {
        $value = self::normalize($value);

        return match (true) {
            str_contains($value, 'b2b') => 'b2b',
            str_contains($value, 'zlecen'), str_starts_with($value, 'uz ') => 'mandate',
            default => 'unknown',
        };
    }

    public static function language(string $type): string
    {
        $type = self::normalize($type);

        return match (true) {
            str_contains($type, 'rumuni'), str_contains($type, 'rumuń'), preg_match('/\bro\b/u', $type) === 1 => 'RO',
            str_contains($type, 'bułgar'), preg_match('/\bbg\b/u', $type) === 1 => 'BG',
            default => 'PL',
        };
    }

    public static function date(string $value, bool $date1904 = false): ?string
    {
        $value = trim($value);
        if (preg_match('/^\d{5}(?:\.\d+)?$/', $value)) {
            return CarbonImmutable::parse($date1904 ? '1904-01-01' : '1899-12-30')->addDays((int) $value)->toDateString();
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T ]\d{2}:\d{2}.*)?$/', $value, $parts)) {
            [$year, $month, $day] = [(int) $parts[1], (int) $parts[2], (int) $parts[3]];
        } elseif (preg_match('/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})$/', $value, $parts)) {
            [$year, $month, $day] = [(int) $parts[3], (int) $parts[2], (int) $parts[1]];
        } elseif (preg_match('/^(\d{2})(\d{2})(\d{4})$/', $value, $parts)) {
            [$year, $month, $day] = [(int) $parts[3], (int) $parts[2], (int) $parts[1]];
        } else {
            return null;
        }

        return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
    }

    public static function issueLabels(): array
    {
        return ['duplicate_profile' => 'Kilka wersji danych pracownika', 'identity_unverified' => 'Identyfikator wymaga sprawdzenia', 'identity_missing' => 'Brak identyfikatora', 'nip_in_pesel' => 'NIP zapisany w kolumnie PESEL', 'email_invalid' => 'E-mail wymaga sprawdzenia', 'date_unverified' => 'Data wymaga sprawdzenia', 'status_conflict' => 'Grupa i status umowy są sprzeczne', 'profile_missing' => 'Brak formularza danych pracownika'];
    }
}
