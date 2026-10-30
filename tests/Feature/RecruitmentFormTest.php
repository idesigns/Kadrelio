<?php

namespace Tests\Feature;

use App\Mail\HrMessage;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RecruitmentFormTest extends TestCase
{
    public function test_guest_sees_password_gate_until_form_is_unlocked(): void
    {
        $company = $this->company();
        $employee = Employee::factory()->for($company)->create();

        $this->get(route('recruitment.show', $company->public_token))->assertOk()
            ->assertSee('Hasło formularza')->assertDontSee('name="pesel"', false)
            ->assertDontSee($employee->fullName());
        $this->post(route('recruitment.unlock', $company->public_token), ['password' => 'recruitment-test-only'])
            ->assertRedirect(route('recruitment.show', $company->public_token));
        $this->get(route('recruitment.show', $company->public_token))->assertOk()
            ->assertSee('name="pesel"', false)->assertDontSee('name="employee_id"', false)
            ->assertDontSee($employee->fullName());
        $this->assertGuest();
        $this->get(route('companies.board', [$company, 'contracts']))->assertRedirect(route('login'));
    }

    public function test_wrong_password_does_not_unlock_or_remain_in_old_input(): void
    {
        $company = $this->company();

        $this->from(route('recruitment.show', $company->public_token))
            ->post(route('recruitment.unlock', $company->public_token), ['password' => 'incorrect-password'])
            ->assertSessionHasErrors('password')->assertSessionMissing('_old_input.password');
        $this->get(route('recruitment.show', $company->public_token))->assertSee('Hasło formularza')->assertDontSee('name="pesel"', false);
        $this->assertGuest();
    }

    public function test_password_is_required_and_company_must_have_form_settings(): void
    {
        $company = Company::factory()->create();

        $this->get(route('recruitment.show', $company->public_token))->assertNotFound();
        $this->post(route('recruitment.unlock', $company->public_token), ['password' => 'recruitment-test-only'])->assertNotFound();
        $company->forceFill(['recruitment_password' => 'recruitment-test-only', 'privacy_url' => ''])->save();
        $this->get(route('recruitment.show', $company->public_token))->assertNotFound();
    }

    public function test_one_company_password_session_does_not_authorize_another_company(): void
    {
        $first = $this->company();
        $second = $this->company();
        Mail::fake();
        $this->unlock($first);

        $this->get(route('recruitment.show', $second->public_token))->assertSee('Hasło formularza')->assertDontSee('name="pesel"', false);
        $this->post(route('recruitment.store', $second->public_token), $this->data())->assertForbidden();

        $this->assertDatabaseCount('workflow_records', 0);
        Mail::assertNothingOutgoing();
    }

    public function test_changing_password_revokes_existing_access(): void
    {
        $company = $this->company();
        $this->unlock($company);

        $company->forceFill(['recruitment_password' => 'changed-test-password'])->save();

        $this->get(route('recruitment.show', $company->public_token))->assertSee('Hasło formularza')->assertDontSee('name="pesel"', false);
        $this->post(route('recruitment.store', $company->public_token), $this->data())->assertForbidden();
        $this->post(route('recruitment.unlock', $company->public_token), ['password' => 'recruitment-test-only'])->assertSessionHasErrors('password');
        $this->post(route('recruitment.unlock', $company->public_token), ['password' => 'changed-test-password'])->assertRedirect();
        $this->get(route('recruitment.show', $company->public_token))->assertSee('name="pesel"', false);
        $this->assertDatabaseCount('workflow_records', 0);
    }

    public function test_unlocked_access_expires_after_two_hours(): void
    {
        $this->freezeTime();
        $company = $this->company();
        $this->unlock($company);

        $this->travel(121)->minutes();

        $this->get(route('recruitment.show', $company->public_token))->assertSee('Hasło formularza')->assertDontSee('name="pesel"', false);
        $this->post(route('recruitment.store', $company->public_token), $this->data())->assertForbidden();
        $this->assertDatabaseCount('workflow_records', 0);
    }

    public function test_submission_without_unlock_does_not_create_data_or_send_mail(): void
    {
        $company = $this->company();
        Mail::fake();

        $this->post(route('recruitment.store', $company->public_token), $this->data())->assertForbidden();

        $this->assertDatabaseCount('employees', 0);
        $this->assertDatabaseCount('workflow_records', 0);
        Mail::assertNothingOutgoing();
    }

    public function test_guest_can_submit_contract_and_queue_individual_employee_form(): void
    {
        $company = $this->company();
        $other = Company::factory()->create();
        Mail::fake();
        $this->unlock($company);

        $this->post(route('recruitment.store', $company->public_token), $this->data() + [
            'company_id' => $other->id, 'created_by' => $company->owner_id, 'status' => 'signed', 'kind' => 'termination',
        ])->assertRedirect(route('recruitment.show', $company->public_token))
            ->assertSessionHasNoErrors()->assertSessionMissing('_old_input.pesel');

        $employee = $company->employees()->firstOrFail();
        $record = $company->records()->firstOrFail();
        $this->assertSame('Anna Testowa', $employee->fullName());
        $this->assertSame('awaiting_data', $record->status);
        $this->assertSame('contract', $record->kind);
        $this->assertSame($employee->id, $record->employee_id);
        $this->assertNull($record->created_by);
        $this->assertSame('Rekruter Testowy', $record->data['reporter_name']);
        $this->assertSame(0, $other->records()->count());
        $this->assertDatabaseCount('users', 2);
        $this->assertGuest();
        Mail::assertQueued(HrMessage::class, fn (HrMessage $mail): bool => $mail->hasTo('anna@example.test')
            && $mail->deliveryMode === 'test' && str_contains($mail->actionUrl, $employee->form_token));
        $this->get(route('recruitment.show', $company->public_token))->assertSee('Zgłoszenie zostało przyjęte.')
            ->assertDontSee($employee->form_token)->assertDontSee($record->signing_token);
    }

    public function test_public_form_cannot_select_employee_by_id(): void
    {
        $company = $this->company();
        $employee = Employee::factory()->create();
        Mail::fake();
        $this->unlock($company);

        $this->post(route('recruitment.store', $company->public_token), $this->data() + ['employee_id' => $employee->id])
            ->assertSessionHasErrors('employee_id');

        $this->assertDatabaseCount('employees', 1);
        $this->assertDatabaseCount('workflow_records', 0);
        Mail::assertNothingOutgoing();
    }

    public function test_invalid_data_is_rejected_before_saving_or_queuing(): void
    {
        $company = $this->company();
        Mail::fake();
        $this->unlock($company);

        $this->post(route('recruitment.store', $company->public_token), array_replace($this->data(), [
            'email' => 'invalid', 'pesel' => '123', 'ends_at' => '2020-01-01',
        ]))->assertSessionHasErrors(['email', 'pesel', 'ends_at']);

        $this->assertDatabaseCount('employees', 0);
        $this->assertDatabaseCount('workflow_records', 0);
        Mail::assertNothingOutgoing();
    }

    public function test_disabled_mail_still_accepts_request_without_queuing_messages(): void
    {
        $company = $this->company();
        config(['hr.mail_enabled' => false]);
        Mail::fake();
        $this->unlock($company);

        $this->post(route('recruitment.store', $company->public_token), $this->data())->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseCount('workflow_records', 1);
        $this->assertDatabaseCount('employees', 1);
        Mail::assertNothingOutgoing();
    }

    public function test_password_attempts_are_rate_limited(): void
    {
        $company = $this->company();
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('recruitment.unlock', $company->public_token), ['password' => 'incorrect'])->assertSessionHasErrors('password');
        }

        $this->post(route('recruitment.unlock', $company->public_token), ['password' => 'incorrect'])->assertTooManyRequests();

        $this->get(route('recruitment.show', $company->public_token))->assertDontSee('name="pesel"', false);
        $this->assertDatabaseCount('workflow_records', 0);
    }

    public function test_manager_sets_hashed_password_and_blank_input_preserves_it(): void
    {
        $company = Company::factory()->create();
        $data = ['name' => $company->name, 'hr_email' => $company->hr_email, 'privacy_url' => $company->privacy_url, 'color' => $company->color];

        $this->actingAs($company->owner)->patch(route('companies.update', $company), $data + ['password' => 'configured-test-password'])->assertSessionHasNoErrors();
        $company->refresh();
        $hash = $company->getRawOriginal('recruitment_password');
        $this->assertTrue(Hash::check('configured-test-password', $hash));
        $this->assertArrayNotHasKey('recruitment_password', $company->toArray());
        $this->get(route('companies.edit', $company))->assertSee('Kopiuj link dla rekrutera')
            ->assertDontSee('configured-test-password')->assertDontSee($hash);
        $this->get(route('companies.board', [$company, 'links']))->assertSee(route('recruitment.show', $company->public_token), false);
        $this->patch(route('companies.update', $company), $data + ['password' => ''])->assertSessionHasNoErrors();
        $this->assertSame($hash, $company->fresh()->getRawOriginal('recruitment_password'));
    }

    public function test_regular_user_cannot_change_recruitment_password(): void
    {
        $company = $this->company();
        $user = User::factory()->create(['role' => 'user']);
        $company->users()->attach($user);
        $hash = $company->getRawOriginal('recruitment_password');

        $this->actingAs($user)->patch(route('companies.update', $company), [
            'name' => $company->name, 'hr_email' => $company->hr_email,
            'privacy_url' => $company->privacy_url, 'color' => $company->color, 'password' => 'unauthorized-password',
        ])->assertForbidden();

        $this->assertSame($hash, $company->fresh()->getRawOriginal('recruitment_password'));
    }

    public function test_too_long_unicode_password_is_rejected_without_flashing_it(): void
    {
        $company = Company::factory()->create();

        $this->actingAs($company->owner)->patch(route('companies.update', $company), [
            'name' => $company->name, 'hr_email' => $company->hr_email,
            'privacy_url' => $company->privacy_url, 'color' => $company->color, 'password' => str_repeat('ą', 40),
        ])->assertSessionHasErrors('password')->assertSessionMissing('_old_input.password');

        $this->assertNull($company->fresh()->recruitment_password);
    }

    private function company(): Company
    {
        $company = Company::factory()->create();
        $company->forceFill(['recruitment_password' => 'recruitment-test-only'])->save();

        return $company;
    }

    private function unlock(Company $company): void
    {
        $this->post(route('recruitment.unlock', $company->public_token), ['password' => 'recruitment-test-only'])
            ->assertRedirect(route('recruitment.show', $company->public_token));
    }

    private function data(): array
    {
        return [
            'first_name' => 'Anna', 'last_name' => 'Testowa', 'email' => 'anna@example.test', 'pesel' => '00000000001',
            'reporter_name' => 'Rekruter Testowy', 'reporter_email' => 'rekruter@example.test',
            'agreement_type' => 'mandate', 'language' => 'PL', 'department' => 'p1',
            'concluded_at' => '2027-01-01', 'starts_at' => '2027-01-02', 'ends_at' => '2027-12-31',
        ];
    }
}
