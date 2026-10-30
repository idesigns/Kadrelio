<?php

namespace Tests\Feature;

use App\Mail\HrMessage;
use App\Models\Company;
use App\Models\DocumentTemplate;
use App\Models\Employee;
use App\Models\MondayAsset;
use App\Models\MondayBoard;
use App\Models\MondayItem;
use App\Models\User;
use App\Models\WorkflowRecord;
use App\Support\ProfileSource;
use App\Support\TemplateFields;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class EmployeeProfileFieldsTest extends TestCase
{
    public function test_full_profile_saves_identity_and_separate_registered_address_without_sending_when_disabled(): void
    {
        config(['hr.mail_enabled' => false]);
        Mail::fake();
        $company = Company::factory()->create();
        $data = $this->profileData() + ['second_name' => 'Maria', 'family_name' => 'Nowak', 'father_name' => 'Jan', 'mother_name' => 'Ewa', 'passport_number' => 'AB123', 'identity_card_number' => 'XYZ123', 'tax_office_address' => 'Urzędowa 1'];
        $data['same_registered_address'] = '0';
        $this->post(route('forms.profile', $company->public_token), $data)->assertSessionHasErrors('registered_country');
        $data += ['registered_country' => 'Polska', 'registered_voivodeship' => 'Śląskie', 'registered_county' => 'Katowice', 'registered_community' => 'Katowice', 'registered_city' => 'Katowice', 'registered_street' => 'Inna', 'registered_house_number' => '4', 'registered_apartment_number' => '7', 'registered_postal_code' => '40-001', 'registered_post_office' => 'Katowice'];
        $this->post(route('forms.profile', $company->public_token), $data)->assertSessionHasNoErrors()->assertRedirect(route('forms.success'));
        $employee = $company->employees()->firstOrFail();
        $this->assertSame('Maria', $employee->personal_data['second_name']);
        $this->assertSame('Katowice', $employee->personal_data['registered_city']);
        $this->assertSame('Warszawa', $employee->personal_data['city']);
        $this->assertSame('0', $employee->personal_data['is_unemployed']);
        $this->assertSame(25, $employee->personal_data['age']);
        Mail::assertNothingQueued();
    }

    public function test_profile_ignores_submitted_age_and_calculates_it_from_birth_date(): void
    {
        $company = Company::factory()->create();
        Mail::fake();

        $this->post(route('forms.profile', $company->public_token), $this->profileData() + ['age' => 'niepoprawny wiek'])
            ->assertSessionHasNoErrors()->assertRedirect(route('forms.success'));

        $this->assertSame(25, $company->employees()->firstOrFail()->personal_data['age']);
        Mail::assertQueued(HrMessage::class);
    }

    #[TestWith(['2000-10-24'])]
    #[TestWith(['2000-10-23'])]
    public function test_student_age_limit_uses_birth_date_instead_of_submitted_age(string $birthDate): void
    {
        $company = Company::factory()->create();
        Mail::fake();
        $data = array_replace($this->profileData(), ['birth_date' => $birthDate, 'is_student' => '1', 'university' => 'Uczelnia testowa', 'age' => '18']);

        $this->post(route('forms.profile', $company->public_token), $data)
            ->assertSessionHasErrors(['is_student' => 'Ta deklaracja dotyczy osób poniżej 26 roku życia.']);

        $this->assertDatabaseCount('employees', 0);
        Mail::assertNothingQueued();
    }

    #[TestWith(['niepoprawna-data'])]
    #[TestWith(['2026-10-25'])]
    #[TestWith(['1905-10-24'])]
    public function test_invalid_birth_date_cannot_be_used_to_calculate_age(string $birthDate): void
    {
        $company = Company::factory()->create();
        Mail::fake();

        $this->post(route('forms.profile', $company->public_token), array_replace($this->profileData(), ['birth_date' => $birthDate]))
            ->assertSessionHasErrors('birth_date');

        $this->assertDatabaseCount('employees', 0);
        Mail::assertNothingQueued();
    }

    public function test_student_scan_is_private_and_download_requires_membership_and_matching_company(): void
    {
        Storage::fake('local');
        Mail::fake();
        $company = Company::factory()->create();
        $data = array_replace($this->profileData(), ['birth_date' => '2000-10-25', 'is_student' => '1', 'university' => 'Uczelnia testowa', 'student_documents' => [UploadedFile::fake()->image('legitymacja.png')]]);
        $this->post(route('forms.profile', $company->public_token), $data)->assertSessionHasNoErrors()->assertRedirect();
        $employee = $company->employees()->firstOrFail();
        $file = collect($employee->personal_data['attachments'])->first();
        Storage::disk('local')->assertExists($file['path']);
        $this->assertStringStartsWith('employees/'.$company->id.'/'.$employee->id.'/', $file['path']);
        $this->assertStringNotContainsString('legitymacja.png', $employee->getRawOriginal('personal_data'));
        $url = route('companies.employees.files', [$company, $employee, $file['id']]);
        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get($url)->assertNotFound();
        $this->actingAs($company->owner)->get($url)->assertOk()->assertDownload('legitymacja.png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $other = Employee::factory()->for($company)->create();
        $this->get(route('companies.employees.files', [$company, $other, $file['id']]))->assertNotFound();
        $elsewhere = Company::factory()->create();
        $this->get(route('companies.employees.files', [$elsewhere, $employee, $file['id']]))->assertNotFound();
        Storage::disk('local')->put($file['path'], 'zmieniony plik');
        $this->get($url)->assertNotFound();
    }

    #[TestWith(['script.svg', 'image/svg+xml', 1])]
    #[TestWith(['scan.pdf', 'application/pdf', 5121])]
    public function test_student_upload_rejects_unsafe_types_and_oversized_files(string $name, string $mime, int $size): void
    {
        Storage::fake('local');
        $company = Company::factory()->create();
        $this->post(route('forms.profile', $company->public_token), array_replace($this->profileData(), ['is_student' => '1', 'university' => 'Uczelnia', 'student_documents' => [UploadedFile::fake()->create($name, $size, $mime)]]))->assertSessionHasErrors('student_documents.0');
        $this->assertDatabaseCount('employees', 0);
    }

    public function test_inactive_student_upload_and_registered_answers_are_not_saved(): void
    {
        Mail::fake();
        Storage::fake('local');
        $company = Company::factory()->create();
        $this->post(route('forms.profile', $company->public_token), $this->profileData() + ['registered_city' => 'Nie dotyczy', 'student_documents' => [UploadedFile::fake()->image('scan.png')]])->assertSessionHasNoErrors()->assertRedirect();
        $employee = $company->employees()->firstOrFail();
        $this->assertArrayNotHasKey('attachments', $employee->personal_data);
        $this->assertArrayNotHasKey('registered_city', $employee->personal_data);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_manager_can_edit_all_profile_fields_and_see_original_and_new_attachments(): void
    {
        $this->travelTo('2026-10-24 12:00:00');
        Storage::fake('local');
        $employee = Employee::factory()->completed()->create();
        $board = MondayBoard::factory()->for($employee->company)->create(['kind' => 'profiles']);
        $profile = MondayItem::factory()->for($board, 'board')->create(['employee_id' => $employee->id]);
        $asset = MondayAsset::factory()->create(['monday_import_batch_id' => $board->monday_import_batch_id, 'name' => 'stary-skan.pdf', 'path' => 'original.pdf']);
        $profile->assets()->attach($asset, ['column_key' => 'AV']);
        $this->actingAs($employee->company->owner)->patch(route('companies.employees.update', [$employee->company, $employee]), ['first_name' => $employee->first_name, 'last_name' => $employee->last_name, 'email' => $employee->email, 'pesel' => '99999999999', 'second_name' => 'Maria', 'birth_date' => '2002-01-01', 'age' => '18', 'is_student' => '1', 'university' => 'Nowa uczelnia', 'same_registered_address' => '0', 'registered_city' => 'Łódź', 'student_documents' => [UploadedFile::fake()->image('nowy-skan.png')]])->assertSessionHasNoErrors()->assertRedirect();
        $employee->refresh();
        $this->assertSame('Maria', $employee->personal_data['second_name']);
        $this->assertSame(24, $employee->personal_data['age']);
        $this->assertNotSame('99999999999', $employee->pesel);
        $this->get(route('companies.employees.show', [$employee->company, $employee]))->assertOk()->assertSee('name="father_name"', false)->assertSee('name="registered_city"', false)->assertSee('stary-skan.pdf')->assertSee('nowy-skan.png')->assertSee('Nowa uczelnia')->assertSee(route('monday.items.asset', [$board, $profile, $asset]), false);
    }

    public function test_preview_does_not_require_privacy_configuration_and_cannot_submit(): void
    {
        $company = Company::factory()->create(['privacy_url' => '']);
        $this->get(route('forms.profile', $company->public_token))->assertServiceUnavailable();
        $url = route('companies.profile-preview', $company);
        $this->actingAs($company->owner)->get($url)->assertOk()->assertSee('Podgląd formularza')->assertSee('student_documents[]')->assertSee('name="same_registered_address"', false)->assertSee('disabled');
        $this->post($url, $this->profileData())->assertMethodNotAllowed();
        $this->assertDatabaseCount('employees', 0);
        $reader = User::factory()->create(['role' => 'user']);
        $company->users()->attach($reader);
        $this->actingAs($reader)->get($url)->assertForbidden();
        $this->actingAs(User::factory()->create())->get($url)->assertNotFound();
    }

    public function test_profile_options_and_dates_reach_document_templates_in_readable_form(): void
    {
        $employee = Employee::factory()->completed()->create(['personal_data' => ['gender' => 'female', 'is_student' => '0', 'working_time' => 'half', 'birth_date' => '2001-01-01', 'age' => 25, 'street' => 'Ulica / boczna']]);
        $record = WorkflowRecord::factory()->for($employee->company)->for($employee)->create();
        $template = DocumentTemplate::factory()->for($employee->company)->make(['body' => '{{gender}} {{is_student}} {{working_time}} {{birth_date}} {{age}} {{street}}']);
        $this->assertSame(['{{gender}}' => 'Kobieta', '{{is_student}}' => 'Nie', '{{working_time}}' => '½ etatu', '{{birth_date}}' => '01.01.2001', '{{age}}' => '25', '{{street}}' => 'Ulica / boczna'], TemplateFields::replacements($template, TemplateFields::values($record)));
        $this->assertSame('is_student', TemplateFields::suggest('<<column_firstrow:'.ProfileSource::labels()['is_student'][0].'>>'));
        $this->assertSame('age', TemplateFields::suggest('<<column_firstrow:Wiek/Age>>'));
    }

    #[TestWith(['retired'])]
    #[TestWith(['disability'])]
    public function test_profile_saves_the_decision_number_for_retirement_and_disability_pensions(string $pension): void
    {
        $company = Company::factory()->create();
        Mail::fake();
        $data = array_replace($this->profileData(), ['pension' => $pension, 'pension_payer' => 'ZUS', 'pension_decision' => 'DEC/123']);

        $this->post(route('forms.profile', $company->public_token), $data)->assertSessionHasNoErrors()->assertRedirect(route('forms.success'));

        $this->assertSame('DEC/123', $company->employees()->firstOrFail()->personal_data['pension_decision']);
    }

    public function test_retirement_pension_requires_the_decision_number(): void
    {
        $company = Company::factory()->create();
        Mail::fake();
        $data = array_replace($this->profileData(), ['pension' => 'retired', 'pension_payer' => 'ZUS']);

        $this->post(route('forms.profile', $company->public_token), $data)->assertSessionHasErrors('pension_decision');

        $this->assertDatabaseCount('employees', 0);
        Mail::assertNothingQueued();
    }

    public function test_approval_monday_declaration_renders_answers_saved_by_the_profile_form(): void
    {
        $company = Company::factory()->create();
        Mail::fake();
        $data = array_replace($this->profileData(), [
            'family_name' => 'Nowak', 'father_name' => 'Jan', 'mother_name' => 'Ewa',
            'passport_number' => 'AB1234567', 'residence_card' => 'XYZ12345', 'apartment_number' => '2',
            'nfz_branch' => 'Mazowiecki', 'tax_office' => 'Urząd Skarbowy Warszawa',
            'is_employed' => '1', 'employer' => 'Zakład pracy', 'working_time' => 'half', 'employment_period' => 'fixed',
            'employment_from' => '2026-01-01', 'employment_to' => '2026-12-31', 'employment_gross_pay' => '6000.00',
            'on_leave' => '1', 'leave_from' => '2026-10-01', 'leave_to' => '2026-10-31',
            'other_mandate' => '1', 'mandate_company' => 'Inny zleceniodawca',
            'mandate_from' => '2026-02-01', 'mandate_to' => '2026-11-30', 'mandate_gross_pay' => '2000.00',
            'runs_business' => '1', 'zus_level' => 'other', 'zus_legal_basis' => 'Podstawa wskazana przez pracownika',
            'pension' => 'disability', 'pension_payer' => 'ZUS', 'pension_decision' => 'DEC/123',
            'has_disability' => '1', 'disability_level' => 'moderate',
            'is_student' => '1', 'university' => 'Uczelnia, Warszawa', 'is_unemployed' => '1',
            'voluntary_sickness_insurance' => '1', 'voluntary_pension_insurance' => '0',
        ]);

        $this->post(route('forms.profile', $company->public_token), $data)->assertSessionHasNoErrors()->assertRedirect(route('forms.success'));

        $employee = $company->employees()->firstOrFail();
        $record = WorkflowRecord::factory()->for($company)->for($employee)->create(['concluded_at' => '2026-10-24', 'ends_at' => '2026-12-31']);
        $expected = [
            'Approval Baza pracowników - Legitymacje' => 'Testowa',
            'Imię' => 'Anna',
            'Drugie imię' => 'Maria',
            'Imię Ojca' => 'Jan',
            'Imie Matki' => 'Ewa',
            'Data urodzenia' => '01.01.2001',
            'Miejsce urodzenia' => 'Warszawa',
            'Nazwisko rodowe' => 'Nowak',
            'Obywatelstwo' => 'Polskie',
            'PESEL' => '00000000001',
            'Nr paszportu' => 'AB1234567',
            'Nr karty stałego pobytu' => 'XYZ12345',
            'Województwo' => 'Mazowieckie',
            'Powiat' => 'Warszawa',
            'Gmina zamieszkania' => 'Warszawa',
            'Ulica' => 'Przykładowa',
            'Nr domu' => '1',
            'Nr lokalu' => '2',
            'Kod pocztowy' => '00-001',
            'Miejscowość zamieszkania' => 'Warszawa',
            'Poczta zamieszkania' => 'Warszawa',
            'Oddział NFZ' => 'Mazowiecki',
            'Urząd Skarbowy' => 'Urząd Skarbowy Warszawa',
            'Tel. kontaktowy' => '000000000',
            'Nr konta bankowego' => '00111122223333444455556666',
            'Data zawarcia umowy formularz' => '24.10.2026',
            'Data zakończenia umowy formularz' => '31.12.2026',
            'Jestem również zatrudniony na podstawie umowy o prace' => 'Tak',
            'Nazwa zakładu pracy (UoP)' => 'Zakład pracy',
            'Wymiar etatu' => '½ etatu',
            'Od: data UoP' => '01.01.2026',
            'Do: data UoP' => '31.12.2026',
            'Przebywam na urlopie macierzyńskim/wychowawczym/bezpłatnym' => 'Tak',
            'Macierzyński Od' => '01.10.2026',
            'Macierzyński Do' => '31.10.2026',
            'Jestem zatrudniony na podtsawie innych umów zlecenia' => 'Tak',
            'Data zawarcia umowy zlecenia (1)' => '01.02.2026',
            'Data końca umowy zlecenia (1)' => '30.11.2026',
            'Nazwa firmy zlecenia (1)' => 'Inny zleceniodawca',
            'Prowadzę działalność gospodatrczą i opłacam składki ZUS:' => 'Tak',
            'Wymiar opłacanych składek' => 'Inna wysokość składki',
            'Jestem emerytem lub rencistą:' => 'Rencista',
            'Nr decyzji:' => 'DEC/123',
            'Posiadam orzeczenie o niepełnosprawności:' => 'Tak',
            'Stopień niepełnosprawności:' => 'Umiarkowany',
            'Jestem uczniem szkoły ponadpodstawowej/studentem i nie ukończyłem/am 26 lat:' => 'Tak',
            'Nazwa i adres uczelni' => 'Uczelnia, Warszawa',
            'Jestem zarejestrowany/a jako osoba bezrobotna:' => 'Tak',
            'Proszę o objęcie mnie dobrowolnym ubezpieczeniem chorobowym:' => 'Tak',
            'Proszę o objęcie mnie dobrowolnym ubezpieczniem emerytalno-rentowym:' => 'Nie',
        ];
        $tokens = array_map(fn (string $label): string => '<<column_firstrow:'.$label.'>>', array_keys($expected));
        $template = DocumentTemplate::factory()->for($company)->make(['body' => implode("\n", $tokens)."\n{{employment_gross_pay}}\n{{mandate_gross_pay}}\n{{zus_legal_basis}}"]);
        $replacements = TemplateFields::replacements($template, TemplateFields::values($record));

        $this->assertSame(array_combine($tokens, array_values($expected)) + [
            '{{employment_gross_pay}}' => '6000.00',
            '{{mandate_gross_pay}}' => '2000.00',
            '{{zus_legal_basis}}' => 'Podstawa wskazana przez pracownika',
        ], $replacements);
    }

    public function test_missing_required_profile_answer_cannot_be_silently_omitted_from_template(): void
    {
        $record = WorkflowRecord::factory()->create();
        $template = DocumentTemplate::factory()->make(['body' => '{{is_student}}']);
        $this->expectException(ValidationException::class);
        TemplateFields::replacements($template, TemplateFields::values($record));
    }

    private function profileData(): array
    {
        $this->travelTo('2026-10-24 12:00:00');

        return ['first_name' => 'Anna', 'last_name' => 'Testowa', 'second_name' => 'Maria', 'pesel' => '00000000001', 'birth_date' => '2001-01-01', 'birth_place' => 'Warszawa', 'gender' => 'female', 'country' => 'Polska', 'nationality' => 'Polskie', 'voivodeship' => 'Mazowieckie', 'county' => 'Warszawa', 'community' => 'Warszawa', 'street' => 'Przykładowa', 'city' => 'Warszawa', 'house_number' => '1', 'postal_code' => '00-001', 'post_office' => 'Warszawa', 'same_registered_address' => '1', 'bank_account' => '00111122223333444455556666', 'email' => 'anna@example.test', 'phone' => '000000000', 'is_student' => '0', 'other_mandate' => '0', 'other_insurance' => '0', 'is_unemployed' => '0', 'is_employed' => '0', 'on_leave' => '0', 'runs_business' => '0', 'pension' => 'none', 'has_disability' => '0', 'privacy_consent' => '1'];
    }
}
