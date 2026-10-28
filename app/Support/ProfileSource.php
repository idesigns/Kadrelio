<?php

namespace App\Support;

use App\Models\MondayItem;

class ProfileSource
{
    public static function labels(): array
    {
        return [
            'last_name' => ['Name', 'Nazwisko/name and last name'], 'first_name' => ['Imię/Name*'],
            'second_name' => ['Drugie imię/Second name'], 'family_name' => ['Nazwisko rodowe/Family name'],
            'father_name' => ["Imię ojca/Father's name"], 'mother_name' => ["Imię matki/Mother's name"],
            'pesel' => ['PESEL/Personal ID number'], 'passport_number' => ['Nr paszportu/Passport no'],
            'residence_card' => ['Nr karty stałego pobytu/Permanent residence card number'],
            'age' => ['Wiek/Age'], 'birth_date' => ['Data urodzenia/Date of birth'], 'birth_place' => ['Miejsce urodzenia/Place of birth'],
            'gender' => ['Płeć/Gender'], 'identity_card_number' => ['Nr dowodu osobistego/Permanent residence card number'],
            'identity_card_issuer' => ['Dowód osobisty wydany przez/Identity card issued by'], 'identity_card_expires_at' => ['Data ważności Dowodu Osobistego/ID card expiration date'],
            'country' => ['Kraj pochodzenia/Country origin'], 'nationality' => ['Obywatelstwo/Nationality'],
            'voivodeship' => ['Województwo/Voivodeship'], 'county' => ['Powiat/Country'], 'community' => ['Gmina /Community'],
            'street' => ['Ulica/Street'], 'city' => ['Miejscowość/Locality'], 'house_number' => ['Nr domu/House number'],
            'apartment_number' => ['Nr lokalu/Apartment number'], 'postal_code' => ['Kod pocztowy/Zip code'], 'post_office' => ['Poczta/Post'],
            'same_registered_address' => ['Czy adres zamieszkania jest taki sam jak zameldowania?/Is the residential address the same as the registered address?'],
            'registered_country' => ['Kraj zameldowania / Country of residence', 'Kraj zameldowania / Home Country'],
            'registered_community' => ['Gmina zameldowania/commune of residence', 'Gmina zamieszkania/commune of residence'],
            'registered_street' => ['Ulica zameldowania/street of residence', 'Ulica zamieszkania/street of residence'],
            'registered_voivodeship' => ['Województwo zameldowania/province of residence'], 'registered_county' => ['Powiat zamieszkania/county of residence'],
            'registered_house_number' => ['Nr domu zameldowania/house number of residence', 'Nr domu zamieszkania/house number of residence'],
            'registered_apartment_number' => ['Nr lokalu zameldowania/residence number'], 'registered_postal_code' => ['Kod pocztowy zamieszkania/ Zip code of residence'],
            'registered_city' => ['Miejscowość zameldowania/place of residence', 'Miejscowość zamieszkania/place of residence'],
            'registered_post_office' => ['Poczta zameldowania/residence post office', 'Poczta zamieszkania/residence post office'],
            'bank_account' => ['Numer konta bankowego/account number'], 'email' => ['E-mail'], 'phone' => ['Telefon/phone number'],
            'nfz_branch' => ['Oddział NFZ/NFZ branch'], 'tax_office' => ['Nazwa Urzędu Skarbowego, bez adresu/name of tax office, without address'],
            'tax_office_address' => ['Urząd skarbowy adres/Tax office address', 'Adres Urzędu Skarbowego adres/Tax office address'],
            'is_student' => ['Posiadam status ucznia/studenta (zg. z art. 83 ust. Prawo o szkolnictwie wyższym) oraz nie ukończyłem/łam 26 roku życia / The Higher Education Act) and I have not yet turned 26 years old'],
            'university' => ['Nazwa i adres uczelni/Name and address of the university'],
            'voluntary_sickness_insurance' => ['Proszę o objęcie mnie dobrowolnym, ubezpieczeniem chorobowym/Please cover me with voluntary health insurance'],
            'voluntary_pension_insurance' => ['Proszę o objęcie mnie dobrowolnym ubezpieczeniem emerytalno-rentowym / formularz'],
            'other_mandate' => ['Mam podpisane inne umowy zlecenia zawarte na okres/I am employed on the basis of many contracts of mandate concluded for a period of'],
            'mandate_company' => ['Nazwa  firmy zlecena /Job (1)'], 'mandate_from' => ['Zlecenie (1) Od/From'], 'mandate_to' => ['Zlecenie (1) Do/To'],
            'second_mandate_company' => ['Nazwa firmy zlecenia (2)/Job 2 2'], 'second_mandate_from' => ['Zlecenie (2) Od'], 'second_mandate_to' => ['Zlecenie (2) Do'],
            'additional_mandate_company' => ['Nazwa firmy zlecenia (2)'],
            'other_insurance' => ['Posiadam inną umowę zlecenia gdzie jestem objęty/a obowiązkowym ubezpieczeniem społecznym /I have other contract where I am covered by compulsory social insurance'],
            'insurance_from' => ['Umowa z ubezpieczeniem od'], 'insurance_to' => ['Umowa z ubezpieczeniem do'], 'insurance_company' => ['Zleceniodawca umowy z ubezpieczeniem'],
            'mandate_gross_pay' => ['Wynagrodzenie z tytułu innej umowy zlecenie za miesiąc w kwocie brutto wynosi/The remuneration under the contract of mandate for the month in the gross amount is:'],
            'salary_level' => ['Jeśli wynagrodzenie nie jest stałe wskaż czy wynosi mniej/co najmniej minimalne'],
            'is_unemployed' => ['Jestem zarejestrowany/a jako osoba bezrobotna'],
            'is_employed' => ['Jestem również zatrudniony/a na podstawie Umowy o Pracę/I am also employed'], 'employer' => ['Nazwa zakładu pracy (UoP)'],
            'employment_period' => ['Czas umowy o pracę/Time of employment contract'], 'employment_from' => ['Umowa o pracę Od/From'], 'employment_to' => ['Umowa o pracę Do/To'],
            'working_time' => ['Jaki wymiar etatu'],
            'employment_gross_pay' => ['Wynagrodzenie z tytułu umowy pracy w kwocie brutto wynosi/The remuneration under the employment relationship in the gross amount is:', 'Wynagrodzenie z tytułu umowy o pracy w kwocie brutto wynosi/The remuneration under the employment relationship in the gross amount is:'],
            'on_leave' => ['Przebywam na urlopie macierzyńskim/wychowawczym/bezpłatnym'], 'leave_from' => ['Macierzyński Od / From'], 'leave_to' => ['Macierzyński Do / To'],
            'runs_business' => ['Prowadzę działalność gospodarczą i opłacam składki ZUS/I run a business and pay social security contributions'],
            'zus_level' => ['Wysokość opłacanych składek z tytułu B2B'], 'zus_legal_basis' => ['Proszę o wskaznie podstawy prawnej'],
            'pension' => ['Jesem emerytem lub rencistą/I am a pensioner', 'Jestem emerytem lub rencistą/I am a pensioner'],
            'pension_decision' => ['Nr decyzji/decision number'], 'pension_payer' => ['Kto wypłaca?/Who pays?'],
            'has_disability' => ['Posiadam orzeczenie o niepełnosprawności/I have a disability certificate'], 'disability_level' => ['Posiadany stopień niepełnosprawności'],
            'privacy_consent' => ['Oświadczam, że zapoznałem/am się z informacjami dot. RODO pod poniższym linkiem oraz wyrażam zgodę na przetwarzanie moich danych osobowych:'],
        ];
    }

    public static function columns(array $headers): array
    {
        $columns = [];
        $headers = array_map(self::normalize(...), $headers);
        $fields = HrForms::profileFields();
        foreach (self::labels() as $key => $aliases) {
            foreach ([...$aliases, $fields[$key]['label'] ?? $key] as $label) {
                $column = array_search(self::normalize($label), $headers, true);
                if ($column !== false) {
                    $columns[$key] = $column;
                    break;
                }
            }
        }

        return $columns;
    }

    public static function values(MondayItem $item): array
    {
        $values = [];
        foreach (self::columns($item->source_data['headers']) as $key => $column) {
            $raw = trim((string) ($item->fields[$column] ?? ''));
            if ($raw !== '') {
                $values[$key] = self::decode($key, $raw, (bool) ($item->source_data['date1904'] ?? false));
            }
        }

        return $values;
    }

    public static function decode(string $key, string $value, bool $date1904 = false): string
    {
        $field = HrForms::profileFields()[$key] ?? [];
        if (($field['type'] ?? '') === 'date') {
            return MondayFields::date($value, $date1904) ?? $value;
        }
        if (($field['type'] ?? '') === 'select' || $key === 'privacy_consent') {
            $normalized = self::normalize($value);
            $options = $field['options'] ?? ['1' => 'Tak', '0' => 'Nie'];
            foreach ($options as $option => $label) {
                if (in_array($normalized, [self::normalize((string) $option), self::normalize($label), self::normalize(explode(' / ', $label)[0])], true)) {
                    return (string) $option;
                }
            }
            if (array_keys($options) === [1, 0] && in_array($normalized, ['true', 'yes', 'checked', 'false', 'no', 'unchecked'], true)) {
                return in_array($normalized, ['true', 'yes', 'checked'], true) ? '1' : '0';
            }
            $aliases = ['working_time' => ['1/2' => 'half', '1/4' => 'quarter', '1/1' => 'full', 'Pełny' => 'full'], 'pension' => ['Nie jestem' => 'none'], 'salary_level' => ['Mniej niż minimalne' => 'below'], 'gender' => ['Female' => 'female', 'Male' => 'male']];
            foreach ($aliases[$key] ?? [] as $label => $option) {
                if ($normalized === self::normalize($label)) {
                    return $option;
                }
            }
        }
        if (($field['type'] ?? '') === 'number') {
            $number = str_replace(',', '.', preg_replace('/\s+/u', '', $value));
            if (is_numeric($number)) {
                return $number;
            }
        }

        return $value;
    }

    public static function display(string $key, mixed $value): string
    {
        $options = HrForms::profileFields()[$key]['options'] ?? [];
        if (array_key_exists((string) $value, $options)) {
            return explode(' / ', $options[$value])[0];
        }

        return is_bool($value) ? ($value ? 'Tak' : 'Nie') : (string) $value;
    }

    private static function normalize(string $value): string
    {
        return mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $value));
    }
}
