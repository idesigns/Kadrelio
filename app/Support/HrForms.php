<?php

namespace App\Support;

class HrForms
{
    public static function departments(): array
    {
        return ['p1', 'p2', 'p2W', 'p2Z', 'p3', 'p3Z', 'p3z2', 'p4', 'p4B', 'p4Z', 'p5', 'p6 hotlead', 'p7', 'P2 BG', 'P3BG', 'P2Z RO', 'P7W', 'P7C', 'R1', 'P3B', 'P6 HOT', 'P6 COLD', 'P7 HOT', 'P2 Rumunia'];
    }

    public static function statuses(): array
    {
        return ['draft' => 'W przygotowaniu', 'awaiting_data' => 'Oczekuje na dane', 'awaiting_confirmation' => 'Oczekuje na potwierdzenie e-mail', 'ready' => 'Gotowa do wysłania', 'sent' => 'Wysłana do podpisu', 'signed' => 'Podpisana', 'terminated' => 'Rozwiązana', 'not_concluded' => 'Niezawarta', 'submitted' => 'Złożone', 'reviewed' => 'Zweryfikowane'];
    }

    public static function kinds(): array
    {
        return ['contract' => 'Umowa', 'amendment' => 'Aneks', 'termination' => 'Rozwiązanie umowy', 'data_change' => 'Zmiana danych osobowych', 'multisport' => 'Zgoda na Multisport'];
    }

    public static function boards(): array
    {
        return [
            'links' => ['title' => 'Linki do formularzy', 'icon' => 'link', 'description' => 'Wszystkie formularze Twojej firmy w jednym miejscu.'],
            'requests' => ['title' => 'Zgłoszenia umów', 'icon' => 'inbox', 'description' => 'Od zgłoszenia nowej osoby do kompletu danych.'],
            'employees' => ['title' => 'Baza pracowników', 'icon' => 'users', 'description' => 'Dane Twojego zespołu, zawsze pod ręką.'],
            'contracts' => ['title' => 'Umowy pracowników', 'icon' => 'board', 'description' => 'Wszystkie umowy i dokumenty Twojego zespołu.'],
            'amendments' => ['title' => 'Aneksy', 'icon' => 'file-pen', 'description' => 'Zmiany stawek i działów z pełną historią.'],
            'terminations' => ['title' => 'Rozwiązania umów', 'icon' => 'file-minus', 'description' => 'Zakończenia współpracy i potwierdzenia pracowników.'],
            'statements' => ['title' => 'Oświadczenia', 'icon' => 'signature', 'description' => 'Złożone podpisy pod umowami, aneksami i rozwiązaniami.'],
            'changes' => ['title' => 'Zmiany danych', 'icon' => 'user-pen', 'description' => 'Zgłoszenia zmian danych osobowych do weryfikacji HR.'],
            'multisport' => ['title' => 'Multisport', 'icon' => 'activity', 'description' => 'Zgody na karty i potrącenia z wynagrodzenia.'],
        ];
    }

    public static function boardGroups(string $board): array
    {
        if ($board === 'statements') {
            return ['source_contract' => ['Oświadczenia podpisania umów', 'green'], 'source_amendment' => ['Oświadczenia podpisania aneksów', 'green'], 'source_termination' => ['Oświadczenia podpisania rozwiązań', 'green'], 'signed' => ['Nowe podpisy', 'green'], 'terminated' => ['Nowe podpisy rozwiązanych umów', 'red']];
        }

        return $board === 'employees' ? ['completed' => ['Dane uzupełnione', 'green'], 'awaiting_data' => ['Oczekuje na dane', 'blue']] : ['draft' => ['W przygotowaniu', 'blue'], 'ready' => ['Gotowe do wysłania', 'blue'], 'sent' => ['Do podpisu', 'orange'], 'signed' => ['Podpisane', 'green'], 'awaiting_data' => ['Oczekuje na dane', 'purple'], 'submitted' => ['Złożone oświadczenia', 'green'], 'reviewed' => ['Zweryfikowane przez HR', 'green'], 'terminated' => ['Rozwiązane', 'red'], 'not_concluded' => ['Niezawarte', 'gray']];
    }

    public static function profileSections(): array
    {
        $yesNo = ['1' => 'Tak / Yes', '0' => 'Nie / No'];

        return [
            'Dane osobowe / Personal details' => [
                'last_name' => ['label' => 'Nazwisko / Last name', 'required' => true],
                'first_name' => ['label' => 'Imię / First name', 'required' => true],
                'second_name' => ['label' => 'Drugie imię / Second name'],
                'family_name' => ['label' => 'Nazwisko rodowe / Family name'],
                'father_name' => ['label' => "Imię ojca / Father's name"],
                'mother_name' => ['label' => "Imię matki / Mother's name"],
                'pesel' => ['label' => 'PESEL / Personal ID number', 'required' => true, 'inputmode' => 'numeric'],
                'passport_number' => ['label' => 'Nr paszportu / Passport number'],
                'residence_card' => ['label' => 'Nr karty stałego pobytu / Permanent residence card number'],
                'birth_date' => ['label' => 'Data urodzenia / Date of birth', 'type' => 'date', 'required' => true],
                'birth_place' => ['label' => 'Miejsce urodzenia / Place of birth', 'required' => true],
                'gender' => ['label' => 'Płeć / Gender', 'type' => 'select', 'options' => ['female' => 'Kobieta / Female', 'male' => 'Mężczyzna / Male'], 'required' => true],
                'identity_card_number' => ['label' => 'Nr dowodu osobistego / Identity card number'],
                'identity_card_issuer' => ['label' => 'Dowód osobisty wydany przez / Identity card issued by'],
                'identity_card_expires_at' => ['label' => 'Data ważności dowodu osobistego / ID card expiration date', 'type' => 'date'],
                'country' => ['label' => 'Kraj pochodzenia / Country of origin', 'required' => true],
                'nationality' => ['label' => 'Obywatelstwo / Nationality', 'required' => true],
            ],
            'Adres zamieszkania / Home address' => [
                'voivodeship' => ['label' => 'Województwo / Voivodeship', 'required' => true],
                'county' => ['label' => 'Powiat / County', 'required' => true],
                'community' => ['label' => 'Gmina / Community', 'required' => true],
                'street' => ['label' => 'Ulica / Street', 'required' => true],
                'city' => ['label' => 'Miejscowość / Locality', 'required' => true],
                'house_number' => ['label' => 'Nr domu / House number', 'required' => true],
                'apartment_number' => ['label' => 'Nr lokalu / Apartment number'],
                'postal_code' => ['label' => 'Kod pocztowy / Zip code', 'required' => true],
                'post_office' => ['label' => 'Poczta / Post office', 'required' => true],
                'same_registered_address' => ['label' => 'Adres zameldowania jest taki sam jak zamieszkania / Registered address is the same as home address', 'type' => 'select', 'options' => $yesNo, 'required' => true],
            ],
            'Adres zameldowania / Registered address' => [
                'registered_country' => ['label' => 'Kraj zameldowania / Country', 'when' => ['same_registered_address', '0'], 'required' => true],
                'registered_voivodeship' => ['label' => 'Województwo zameldowania / Voivodeship', 'when' => ['same_registered_address', '0'], 'required' => true],
                'registered_county' => ['label' => 'Powiat zameldowania / County', 'when' => ['same_registered_address', '0'], 'required' => true],
                'registered_community' => ['label' => 'Gmina zameldowania / Community', 'when' => ['same_registered_address', '0'], 'required' => true],
                'registered_street' => ['label' => 'Ulica zameldowania / Street', 'when' => ['same_registered_address', '0'], 'required' => true],
                'registered_house_number' => ['label' => 'Nr domu zameldowania / House number', 'when' => ['same_registered_address', '0'], 'required' => true],
                'registered_apartment_number' => ['label' => 'Nr lokalu zameldowania / Apartment number', 'when' => ['same_registered_address', '0']],
                'registered_city' => ['label' => 'Miejscowość zameldowania / Locality', 'when' => ['same_registered_address', '0'], 'required' => true],
                'registered_postal_code' => ['label' => 'Kod pocztowy zameldowania / Zip code', 'when' => ['same_registered_address', '0'], 'required' => true],
                'registered_post_office' => ['label' => 'Poczta zameldowania / Post office', 'when' => ['same_registered_address', '0'], 'required' => true],
            ],
            'Kontakt i rozliczenia / Contact and payment' => [
                'email' => ['label' => 'E-mail', 'type' => 'email', 'required' => true],
                'phone' => ['label' => 'Telefon / Phone number', 'type' => 'tel', 'required' => true],
                'bank_account' => ['label' => 'Numer konta bankowego / Account number', 'required' => true, 'hint' => '26 cyfr polskiego rachunku lub IBAN (15-34 znaki).'],
                'nfz_branch' => ['label' => 'Oddział NFZ / NFZ branch'],
                'tax_office' => ['label' => 'Nazwa Urzędu Skarbowego / Tax office name'],
                'tax_office_address' => ['label' => 'Adres Urzędu Skarbowego / Tax office address'],
            ],
            'Oświadczenia do rozliczeń / Employment declarations' => [
                'is_student' => ['label' => 'Jestem uczniem/studentem i mam mniej niż 26 lat / I am a student under 26', 'type' => 'select', 'options' => $yesNo, 'required' => true],
                'university' => ['label' => 'Nazwa i adres uczelni / University name and address', 'when' => ['is_student', '1'], 'required' => true],
                'voluntary_sickness_insurance' => ['label' => 'Proszę o objęcie dobrowolnym ubezpieczeniem chorobowym / Voluntary sickness insurance', 'type' => 'select', 'options' => $yesNo],
                'voluntary_pension_insurance' => ['label' => 'Proszę o objęcie dobrowolnym ubezpieczeniem emerytalno-rentowym / Voluntary pension insurance', 'type' => 'select', 'options' => $yesNo],
                'other_mandate' => ['label' => 'Mam podpisane inne umowy zlecenia / I have other mandate contracts', 'type' => 'select', 'options' => $yesNo, 'required' => true],
                'mandate_from' => ['label' => 'Inna umowa zlecenia od / Other mandate from', 'type' => 'date', 'when' => ['other_mandate', '1'], 'required' => true],
                'mandate_to' => ['label' => 'Inna umowa zlecenia do / Other mandate until', 'type' => 'date', 'when' => ['other_mandate', '1'], 'required' => true],
                'mandate_company' => ['label' => 'Nazwa zleceniodawcy / Mandate company', 'when' => ['other_mandate', '1'], 'required' => true],
                'second_mandate_company' => ['label' => 'Nazwa drugiego zleceniodawcy / Second mandate company', 'when' => ['other_mandate', '1']],
                'second_mandate_from' => ['label' => 'Druga umowa zlecenia od / Second mandate from', 'type' => 'date', 'when' => ['other_mandate', '1']],
                'second_mandate_to' => ['label' => 'Druga umowa zlecenia do / Second mandate until', 'type' => 'date', 'when' => ['other_mandate', '1']],
                'additional_mandate_company' => ['label' => 'Kolejna firma zlecenia / Additional mandate company', 'when' => ['other_mandate', '1']],
                'mandate_gross_pay' => ['label' => 'Wynagrodzenie brutto z innej umowy zlecenia / Other mandate gross pay', 'type' => 'number', 'step' => '0.01', 'when' => ['other_mandate', '1']],
                'other_insurance' => ['label' => 'Inna umowa obejmuje obowiązkowe ubezpieczenie społeczne / Other contract with compulsory social insurance', 'type' => 'select', 'options' => $yesNo, 'required' => true],
                'insurance_from' => ['label' => 'Umowa z ubezpieczeniem od / Insured contract from', 'type' => 'date', 'when' => ['other_insurance', '1'], 'required' => true],
                'insurance_to' => ['label' => 'Umowa z ubezpieczeniem do / Insured contract until', 'type' => 'date', 'when' => ['other_insurance', '1'], 'required' => true],
                'insurance_company' => ['label' => 'Zleceniodawca umowy z ubezpieczeniem / Insured contract company', 'when' => ['other_insurance', '1'], 'required' => true],
                'salary_level' => ['label' => 'Jeśli wynagrodzenie nie jest stałe / If salary is not fixed', 'type' => 'select', 'options' => ['minimum' => 'Co najmniej minimalne wynagrodzenie', 'below' => 'Mniej niż minimalne wynagrodzenie']],
                'is_unemployed' => ['label' => 'Jestem zarejestrowany/a jako osoba bezrobotna / I am registered as unemployed', 'type' => 'select', 'options' => $yesNo, 'required' => true],
                'is_employed' => ['label' => 'Mam także umowę o pracę / I also have an employment contract', 'type' => 'select', 'options' => $yesNo, 'required' => true],
                'employer' => ['label' => 'Nazwa zakładu pracy / Employer name', 'when' => ['is_employed', '1'], 'required' => true],
                'employment_period' => ['label' => 'Czas umowy o pracę / Employment period', 'type' => 'select', 'options' => ['indefinite' => 'Nieokreślony', 'fixed' => 'Określony', 'none' => 'Brak umowy o pracę'], 'when' => ['is_employed', '1'], 'required' => true],
                'working_time' => ['label' => 'Wymiar etatu / Working time', 'type' => 'select', 'options' => ['half' => '½ etatu', 'quarter' => '¼ etatu', 'full' => '1/1 (pełny)', 'other' => 'Inny'], 'when' => ['is_employed', '1'], 'required' => true],
                'employment_from' => ['label' => 'Umowa o pracę od / Employment from', 'type' => 'date', 'when' => ['is_employed', '1']],
                'employment_to' => ['label' => 'Umowa o pracę do / Employment until', 'type' => 'date', 'when' => ['is_employed', '1']],
                'employment_gross_pay' => ['label' => 'Wynagrodzenie brutto z umowy o pracę / Employment gross pay', 'type' => 'number', 'step' => '0.01', 'when' => ['is_employed', '1']],
                'on_leave' => ['label' => 'Przebywam na urlopie macierzyńskim, wychowawczym lub bezpłatnym / I am on maternity, parental or unpaid leave', 'type' => 'select', 'options' => $yesNo, 'required' => true],
                'leave_from' => ['label' => 'Urlop od / Leave from', 'type' => 'date', 'when' => ['on_leave', '1'], 'required' => true],
                'leave_to' => ['label' => 'Urlop do / Leave until', 'type' => 'date', 'when' => ['on_leave', '1'], 'required' => true],
                'runs_business' => ['label' => 'Prowadzę działalność i opłacam ZUS / I run a business and pay social security contributions', 'type' => 'select', 'options' => $yesNo, 'required' => true],
                'zus_level' => ['label' => 'Wysokość składek społecznych B2B / Business social contributions', 'type' => 'select', 'options' => ['standard' => 'Standardowe', 'preferential' => 'Preferencyjne', 'other' => 'Inna wysokość składki'], 'when' => ['runs_business', '1'], 'required' => true],
                'zus_legal_basis' => ['label' => 'Podstawa prawna opłacanych składek / Legal basis for contributions', 'when' => ['runs_business', '1']],
                'pension' => ['label' => 'Emerytura lub renta / Pension status', 'type' => 'select', 'options' => ['none' => 'Nie jestem', 'retired' => 'Emeryt', 'disability' => 'Rencista'], 'required' => true],
                'pension_payer' => ['label' => 'Kto wypłaca emeryturę/rentę? / Who pays?', 'when' => ['pension', 'retired|disability'], 'required' => true],
                'pension_decision' => ['label' => 'Nr decyzji rentowej / Pension decision number', 'when' => ['pension', 'disability'], 'required' => true],
                'has_disability' => ['label' => 'Posiadam orzeczenie o niepełnosprawności / I have a disability certificate', 'type' => 'select', 'options' => $yesNo, 'required' => true],
                'disability_level' => ['label' => 'Stopień niepełnosprawności / Disability level', 'type' => 'select', 'options' => ['mild' => 'Lekki', 'moderate' => 'Umiarkowany', 'severe' => 'Znaczny'], 'when' => ['has_disability', '1'], 'required' => true],
            ],
        ];
    }

    public static function profileFields(): array
    {
        static $fields = null;

        return $fields ??= array_merge(...array_values(self::profileSections()));
    }

    public static function profileUploadRules(): array
    {
        return [
            'student_documents' => ['exclude_unless:is_student,1', 'nullable', 'array', 'max:3'],
            'student_documents.*' => ['file', 'mimetypes:application/pdf,image/jpeg,image/png', 'extensions:pdf,jpg,jpeg,png', 'max:5120'],
        ];
    }

    public static function additionalFields(string $kind): array
    {
        return match ($kind) {
            'amendment' => [
                'current_address' => ['label' => 'Aktualny adres zamieszkania', 'required' => true],
                'change_type' => ['label' => 'Rodzaj zmiany', 'type' => 'select', 'options' => ['commission' => 'Zmiana stawki prowizyjnej %', 'hourly' => 'Zmiana stawki godzinowej', 'department' => 'Zmiana działu'], 'required' => true],
                'commission_rate' => ['label' => 'Nowa stawka prowizyjna (%)', 'type' => 'number', 'step' => '0.01', 'when' => ['change_type', 'commission'], 'required' => true],
                'hourly_rate' => ['label' => 'Nowa stawka godzinowa (PLN/h)', 'type' => 'number', 'step' => '0.01', 'when' => ['change_type', 'hourly'], 'required' => true],
                'old_department' => ['label' => 'Aktualny dział', 'type' => 'select', 'options' => array_combine(self::departments(), self::departments()), 'when' => ['change_type', 'department'], 'required' => true],
                'new_department' => ['label' => 'Nowy dział', 'type' => 'select', 'options' => array_combine(self::departments(), self::departments()), 'when' => ['change_type', 'department'], 'required' => true],
            ],
            'termination' => ['current_address' => ['label' => 'Aktualny adres zamieszkania', 'required' => true]],
            'data_change' => [
                'changed_at' => ['label' => 'Data powstania zmiany', 'type' => 'date', 'required' => true],
                'changed_fields' => ['label' => 'Jakich danych dotyczy zmiana?', 'required' => true, 'hint' => 'Wpisz po przecinku pola, które uległy zmianie.'],
                'new_values' => ['label' => 'Aktualne dane', 'type' => 'textarea', 'required' => true, 'hint' => 'Podaj aktualne imię i nazwisko, rachunek lub adres.'],
            ],
            'multisport' => [
                'card_type' => ['label' => 'Rodzaj karty', 'type' => 'select', 'options' => ['classic' => 'MultiSport Classic - 54,71 zł', 'plus' => 'MultiSport Plus - 66,20 zł'], 'required' => true],
                'companion' => ['label' => 'Karta dla osoby towarzyszącej?', 'type' => 'select', 'options' => ['1' => 'Tak', '0' => 'Nie'], 'required' => true],
                'companion_consent' => ['label' => 'Zgoda na potrącenie za osobę towarzyszącą', 'type' => 'select', 'options' => ['1' => 'Wyrażam zgodę', '0' => 'Nie wyrażam zgody'], 'when' => ['companion', '1'], 'required' => true],
                'companion_card' => ['label' => 'Karta dla osoby towarzyszącej', 'type' => 'select', 'options' => ['classic' => 'MultiSport Classic - 218,85 zł', 'plus' => 'MultiSport Plus - 244,85 zł', 'none' => 'Żadnej kwoty'], 'when' => ['companion', '1'], 'required' => true],
            ],
            default => [],
        };
    }

    public static function fieldRules(array $fields): array
    {
        $rules = [];
        foreach ($fields as $key => $field) {
            $required = ($field['required'] ?? false) ? 'required' : 'nullable';
            $conditions = [];
            if (isset($field['when'])) {
                [$parent, $value] = $field['when'];
                $values = str_replace('|', ',', $value);
                $conditions[] = 'exclude_unless:'.$parent.','.$values;
                $required = ($field['required'] ?? false) ? 'required_if:'.$parent.','.$values : 'nullable';
            }
            $type = $field['type'] ?? 'text';
            $validation = match ($type) {
                'select' => ['in:'.implode(',', array_keys($field['options']))],
                'date' => ['date_format:Y-m-d'],
                'number' => ['numeric', 'min:0', 'max:1000000'],
                'email' => ['email', 'max:255'],
                'textarea' => ['string', 'max:5000'],
                default => ['string', 'max:255'],
            };
            $rules[$key] = [...$conditions, $required, ...$validation];
        }

        return $rules;
    }
}
