<?php

namespace Tests\Feature;

use App\Jobs\SendWorkflowMail;
use App\Mail\HrMessage;
use App\Models\Company;
use App\Models\Employee;
use App\Models\WorkflowRecord;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PublicFormsTest extends TestCase
{
    public function test_public_profile_form_has_required_fields_and_company_privacy_link(): void
    {
        $company = Company::factory()->create();

        $this->get(route('forms.profile', $company->public_token))->assertOk()->assertSee('Dane do Twojej umowy')->assertSee($company->privacy_url)->assertSee('bank_account')->assertSee('university')->assertSee('disability_level');
    }

    public function test_public_profile_rejects_missing_required_fields_and_consent(): void
    {
        $company = Company::factory()->create();
        Mail::fake();

        $this->post(route('forms.profile', $company->public_token), [])->assertSessionHasErrors(['first_name', 'last_name', 'pesel', 'bank_account', 'privacy_consent']);

        $this->assertDatabaseCount('employees', 0);
        Mail::assertNothingQueued();
    }

    public function test_employee_completes_only_the_individual_profile_once(): void
    {
        $this->travelTo('2026-10-20 12:00:00');
        $employee = Employee::factory()->create(['first_name' => 'Anna', 'last_name' => 'Testowa', 'email' => 'anna@example.test', 'pesel' => '00000000001', 'pesel_hash' => Employee::identityHash('00000000001')]);
        $record = WorkflowRecord::factory()->for($employee->company)->for($employee)->create();
        Mail::fake();

        $this->post(route('onboarding.show', $employee->form_token), $this->profileData() + ['company_id' => 999, 'role' => 'admin'])->assertRedirect(route('forms.success'));

        $employee->refresh();
        $this->assertNotNull($employee->profile_completed_at);
        $this->assertSame('Warszawa', $employee->personal_data['city']);
        $this->assertStringNotContainsString('00111122223333444455556666', $employee->getRawOriginal('personal_data'));
        $this->assertSame('draft', $record->fresh()->status);
        $this->assertArrayNotHasKey('role', $employee->personal_data);
        Mail::assertQueued(HrMessage::class, fn (HrMessage $mail): bool => $mail->hasTo($employee->company->hr_email));
        $this->post(route('onboarding.show', $employee->form_token), $this->profileData())->assertGone();
    }

    public function test_profile_identity_must_match_the_recruitment(): void
    {
        $this->travelTo('2026-10-20 12:00:00');
        $employee = Employee::factory()->create();
        Mail::fake();

        $this->post(route('onboarding.show', $employee->form_token), $this->profileData())->assertSessionHasErrors('pesel');
        $this->assertNull($employee->fresh()->profile_completed_at);
        Mail::assertNothingQueued();
    }

    public function test_student_requires_university_and_unused_answers_are_excluded(): void
    {
        $this->travelTo('2026-10-20 12:00:00');
        $company = Company::factory()->create();
        Mail::fake();

        $this->post(route('forms.profile', $company->public_token), array_replace($this->profileData(), ['is_student' => '1']))->assertSessionHasErrors('university');
        $this->assertDatabaseCount('employees', 0);
        $this->post(route('forms.profile', $company->public_token), $this->profileData() + ['university' => 'Nie dotyczy'])->assertRedirect();
        $employee = $company->employees()->firstOrFail();
        $this->assertArrayNotHasKey('university', $employee->personal_data);
        Mail::assertQueued(HrMessage::class);
    }

    public function test_generic_profile_cannot_overwrite_an_existing_employee(): void
    {
        $this->travelTo('2026-10-20 12:00:00');
        $employee = Employee::factory()->completed()->create(['pesel' => '00000000001', 'pesel_hash' => Employee::identityHash('00000000001')]);
        Mail::fake();

        $this->post(route('forms.profile', $employee->company->public_token), $this->profileData())->assertSessionHasErrors('pesel');

        $this->assertSame($employee->first_name, $employee->fresh()->first_name);
        $this->assertDatabaseCount('employees', 1);
        Mail::assertNothingQueued();
    }

    public function test_expired_individual_links_are_not_usable(): void
    {
        $employee = Employee::factory()->create(['form_expires_at' => now()->subDay()]);
        $record = WorkflowRecord::factory()->sent()->create(['signing_expires_at' => now()->subDay()]);

        $this->get(route('onboarding.show', $employee->form_token))->assertGone();
        $this->get(route('signing.show', $record->signing_token))->assertGone();
        $this->get(route('signing.document', $record->signing_token))->assertGone();
    }

    public function test_signature_is_saved_and_hr_delivery_is_queued(): void
    {
        $record = WorkflowRecord::factory()->sent()->create();
        Queue::fake([SendWorkflowMail::class]);

        $this->post(route('signing.show', $record->signing_token), $this->signatureData($record))->assertRedirect(route('forms.success'));

        $record->refresh();
        $this->assertSame('signed', $record->status);
        $this->assertNotNull($record->signed_at);
        $this->assertSame($record->employee->fullName(), $record->data['signer_name']);
        $this->assertSame($record->document_hash, $record->data['signed_document_hash']);
        Queue::assertPushed(SendWorkflowMail::class, fn (SendWorkflowMail $job): bool => $job->recordId === $record->id && $job->statement);
        $this->get(route('signing.show', $record->signing_token))->assertGone();
    }

    public function test_signing_requires_identity_consent_image_and_current_document_hash(): void
    {
        $record = WorkflowRecord::factory()->sent()->create();
        Queue::fake([SendWorkflowMail::class]);

        $this->post(route('signing.show', $record->signing_token), [])->assertSessionHasErrors(['full_name', 'pesel', 'signature_name', 'signature_image', 'accepted', 'document_hash']);
        $this->post(route('signing.show', $record->signing_token), array_replace($this->signatureData($record), ['full_name' => 'Inna Osoba']))->assertSessionHasErrors('full_name');
        $this->post(route('signing.show', $record->signing_token), array_replace($this->signatureData($record), ['document_hash' => str_repeat('a', 64)]))->assertSessionHasErrors('accepted');
        $this->post(route('signing.show', $record->signing_token), array_replace($this->signatureData($record), ['signature_image' => 'data:image/svg+xml;base64,'.base64_encode('<svg><script>alert(1)</script></svg>')]))->assertSessionHasErrors('signature_image');
        $this->assertNull($record->fresh()->signed_at);
        Queue::assertNothingPushed();
    }

    public function test_signing_a_termination_updates_only_its_original_contract(): void
    {
        $contract = WorkflowRecord::factory()->signed()->create();
        $other = WorkflowRecord::factory()->signed()->for($contract->company)->for($contract->employee)->create();
        $termination = WorkflowRecord::factory()->sent()->for($contract->company)->for($contract->employee)->create(['kind' => 'termination', 'contract_id' => $contract->id, 'ends_at' => '2026-11-30']);
        Queue::fake([SendWorkflowMail::class]);

        $this->post(route('signing.show', $termination->signing_token), $this->signatureData($termination) + ['effective_date' => '2026-11-30'])->assertRedirect();

        $this->assertSame('terminated', $contract->fresh()->status);
        $this->assertSame('signed', $other->fresh()->status);
        Queue::assertPushed(SendWorkflowMail::class);
    }

    public function test_amendment_requires_matching_date_before_updating_department(): void
    {
        $contract = WorkflowRecord::factory()->signed()->create();
        $amendment = WorkflowRecord::factory()->sent()->for($contract->company)->for($contract->employee)->create(['kind' => 'amendment', 'contract_id' => $contract->id, 'concluded_at' => '2026-11-01', 'data' => ['change_type' => 'department', 'new_department' => 'p7']]);
        Queue::fake([SendWorkflowMail::class]);

        $this->post(route('signing.show', $amendment->signing_token), $this->signatureData($amendment) + ['effective_date' => '2026-11-02'])->assertSessionHasErrors('effective_date');
        $this->assertSame('p1', $contract->employee->fresh()->department);
        $this->post(route('signing.show', $amendment->signing_token), $this->signatureData($amendment) + ['effective_date' => '2026-11-01'])->assertRedirect();
        $this->assertSame('p7', $contract->employee->fresh()->department);
        Queue::assertPushed(SendWorkflowMail::class);
    }

    public function test_data_change_preserves_original_data_until_hr_verification(): void
    {
        $employee = Employee::factory()->completed()->create();
        $record = WorkflowRecord::factory()->for($employee->company)->for($employee)->make();
        Queue::fake([SendWorkflowMail::class]);
        Mail::fake();
        $data = $this->signatureData($record) + ['email' => $employee->email, 'changed_at' => '2026-10-20', 'changed_fields' => 'adres', 'new_values' => 'Nowy adres'];

        $this->post(route('forms.additional', [$employee->company->public_token, 'data_change']), $data)->assertRedirect();

        $this->assertSame($employee->personal_data, $employee->fresh()->personal_data);
        $change = WorkflowRecord::where('kind', 'data_change')->firstOrFail();
        $this->assertSame('awaiting_confirmation', $change->status);
        $this->assertNull($change->signed_at);
        Queue::assertNothingPushed();
        Mail::assertQueued(HrMessage::class, fn (HrMessage $mail): bool => $mail->hasTo($employee->email) && $mail->actionUrl === route('forms.confirmation', $change->signing_token));
        $this->get(route('forms.confirmation', $change->signing_token))->assertOk()->assertSee('Nowy adres');
        $this->assertNull($change->fresh()->signed_at);
        $this->post(route('forms.confirmation', $change->signing_token), ['accepted' => '1'])->assertRedirect(route('forms.success'));
        $change->refresh();
        $this->assertSame('submitted', $change->status);
        $this->assertNotNull($change->signed_at);
        $this->assertNotNull($change->data['email_confirmed_at']);
        $this->assertSame('Nowy adres', $change->data['new_values']);
        Queue::assertPushed(SendWorkflowMail::class, 1);
        $this->post(route('forms.confirmation', $change->signing_token), ['accepted' => '1'])->assertGone();
    }

    public function test_multisport_requires_consent_and_card_for_companion(): void
    {
        $employee = Employee::factory()->completed()->create();
        $record = WorkflowRecord::factory()->for($employee->company)->for($employee)->make();
        Queue::fake([SendWorkflowMail::class]);
        Mail::fake();
        $data = $this->signatureData($record) + ['email' => $employee->email, 'card_type' => 'classic', 'companion' => '1'];

        $this->post(route('forms.additional', [$employee->company->public_token, 'multisport']), $data)->assertSessionHasErrors(['companion_consent', 'companion_card']);
        $this->assertDatabaseCount('workflow_records', 0);
        $this->post(route('forms.additional', [$employee->company->public_token, 'multisport']), $data + ['companion_consent' => '1', 'companion_card' => 'plus'])->assertRedirect();
        $consent = WorkflowRecord::where('kind', 'multisport')->firstOrFail();
        $this->assertNull($consent->signed_at);
        Queue::assertNothingPushed();
        Mail::assertQueued(HrMessage::class, fn (HrMessage $mail): bool => $mail->hasTo($employee->email));
        $this->post(route('forms.confirmation', $consent->signing_token), ['accepted' => '1'])->assertRedirect();
        $this->assertDatabaseHas('workflow_records', ['kind' => 'multisport', 'status' => 'submitted']);
        Queue::assertPushed(SendWorkflowMail::class);
    }

    public function test_signature_uses_document_identity_after_employee_profile_changes(): void
    {
        $record = WorkflowRecord::factory()->sent()->create();
        $data = $this->signatureData($record);
        $record->data = ['document_identity' => ['full_name' => $record->employee->fullName(), 'pesel_hash' => $record->employee->pesel_hash], 'document_company_name' => 'Firma z dokumentu'];
        $record->save();
        $record->employee->update(['last_name' => 'Nowe Nazwisko']);
        Queue::fake([SendWorkflowMail::class]);

        $this->post(route('signing.show', $record->signing_token), $data)->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame($data['signature_name'], $record->fresh()->data['signer_name']);
        $this->assertSame('Firma z dokumentu', $record->fresh()->data['company_name']);
        Queue::assertPushed(SendWorkflowMail::class);
    }

    public function test_b2b_signing_uses_nip_instead_of_pesel(): void
    {
        $record = WorkflowRecord::factory()->sent()->create();
        $record->data = ['agreement_type' => 'b2b', 'contractor_tax_id' => '1234567890', 'document_identity' => ['full_name' => $record->employee->fullName(), 'pesel_hash' => Employee::identityHash('1234567890')]];
        $record->save();
        Queue::fake([SendWorkflowMail::class]);

        $this->get(route('signing.show', $record->signing_token))->assertOk()->assertSee('NIP działalności')->assertSee('umowę B2B');
        $this->post(route('signing.show', $record->signing_token), array_replace($this->signatureData($record), ['pesel' => '1234567890']))->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('NIP', $record->fresh()->data['identifier_label']);
        $this->assertSame('1234567890', $record->fresh()->data['signer_identifier']);
        Queue::assertPushed(SendWorkflowMail::class);
    }

    private function profileData(): array
    {
        return ['first_name' => 'Anna', 'last_name' => 'Testowa', 'pesel' => '00000000001', 'age' => '25', 'birth_date' => '2001-01-01', 'birth_place' => 'Warszawa', 'country' => 'Polska', 'nationality' => 'Polskie', 'voivodeship' => 'Mazowieckie', 'county' => 'Warszawa', 'community' => 'Warszawa', 'street' => 'Przykładowa', 'city' => 'Warszawa', 'house_number' => '1', 'postal_code' => '00-001', 'post_office' => 'Warszawa', 'bank_account' => '00111122223333444455556666', 'email' => 'anna@example.test', 'phone' => '000000000', 'gender' => 'female', 'same_registered_address' => '1', 'is_unemployed' => '0', 'is_student' => '0', 'other_mandate' => '0', 'other_insurance' => '0', 'is_employed' => '0', 'on_leave' => '0', 'runs_business' => '0', 'pension' => 'none', 'has_disability' => '0', 'privacy_consent' => '1'];
    }

    private function signatureData(WorkflowRecord $record): array
    {
        $image = imagecreatetruecolor(200, 80);
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefill($image, 0, 0, $white);
        imageline($image, 10, 20, 150, 55, imagecolorallocate($image, 20, 20, 20));
        ob_start();
        imagepng($image);
        $png = ob_get_clean();

        return ['full_name' => $record->employee->fullName(), 'pesel' => $record->employee->pesel, 'signature_name' => $record->employee->fullName(), 'signature_image' => 'data:image/png;base64,'.base64_encode($png), 'accepted' => '1', 'document_hash' => $record->document_hash ?? str_repeat('a', 64)];
    }
}
