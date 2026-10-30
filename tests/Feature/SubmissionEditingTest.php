<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\MondayBoard;
use App\Models\MondayItem;
use App\Models\User;
use App\Models\WorkflowRecord;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SubmissionEditingTest extends TestCase
{
    #[TestWith(['admin'])]
    #[TestWith(['manager'])]
    #[TestWith(['user'])]
    public function test_assigned_users_can_edit_recruiter_submission_without_sending_mail(string $role): void
    {
        $record = WorkflowRecord::factory()->create(['created_by' => null]);
        $user = User::factory()->create(['role' => $role]);
        $record->company->users()->attach($user);
        $oldLink = $record->employee->form_token;
        $data = array_replace($this->data($record), [
            'first_name' => 'Anna', 'last_name' => 'Poprawiona', 'email' => 'ANNA@EXAMPLE.TEST', 'pesel' => '00000000002',
            'department' => 'p2', 'language' => 'BG', 'starts_at' => '2026-11-01', 'ends_at' => '2027-02-01',
            'reporter_name' => 'Rekruterka', 'reporter_email' => 'REKRUTACJA@EXAMPLE.TEST',
            'company_id' => 9999, 'created_by' => $user->id, 'status' => 'signed', 'data' => ['submission_edits' => ['forged']],
        ]);
        Mail::fake();
        Queue::fake();

        $this->actingAs($user)->get(route('companies.records.show', [$record->company, $record]))->assertOk()->assertSee('Edytuj zgłoszenie');
        $this->get(route('companies.records.edit', [$record->company, $record, 'panel' => 1]))->assertOk()
            ->assertSee('Zapisz zmiany')->assertSee('value="'.$record->data['reporter_email'].'"', false)
            ->assertSee('name="panel" value="1"', false)->assertDontSee('name="employee_id"', false);
        $this->patch(route('companies.records.submission.update', [$record->company, $record]), $data + ['panel' => 1])
            ->assertSessionHasNoErrors()->assertRedirect(route('companies.records.show', [$record->company, $record, 'panel' => 1]));

        $record->refresh()->load('employee');
        $this->assertSame('Anna Poprawiona', $record->employee->fullName());
        $this->assertSame('anna@example.test', $record->employee->email);
        $this->assertSame('00000000002', $record->employee->pesel);
        $this->assertSame(Employee::identityHash('00000000002'), $record->employee->pesel_hash);
        $this->assertSame('p2', $record->department);
        $this->assertSame('BG', $record->language);
        $this->assertSame('2027-02-01', $record->ends_at->format('Y-m-d'));
        $this->assertSame('rekrutacja@example.test', $record->data['reporter_email']);
        $this->assertSame($user->id, $record->data['submission_edits'][0]['user_id']);
        $this->assertNull($record->created_by);
        $this->assertSame('awaiting_data', $record->status);
        $this->assertNotSame($oldLink, $record->employee->form_token);
        $this->get(route('onboarding.show', $oldLink))->assertNotFound();
        $this->assertDatabaseCount('employees', 1);
        $this->assertDatabaseCount('workflow_records', 1);
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    public function test_guests_and_outsiders_cannot_edit_company_submission(): void
    {
        $record = WorkflowRecord::factory()->create();
        $url = route('companies.records.submission.update', [$record->company, $record]);
        $original = $record->fresh()->getRawOriginal();

        $this->get(route('companies.records.edit', [$record->company, $record]))->assertRedirect(route('login'));
        $this->patch($url, $this->data($record))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('companies.records.edit', [$record->company, $record]))->assertNotFound();
        $this->patch($url, $this->data($record))->assertNotFound();

        $this->assertSame($original, $record->fresh()->getRawOriginal());
    }

    public function test_company_scoped_binding_rejects_foreign_record(): void
    {
        $record = WorkflowRecord::factory()->create();
        $other = WorkflowRecord::factory()->create();

        $this->actingAs($record->company->owner)->get(route('companies.records.edit', [$record->company, $other]))->assertNotFound();
        $this->patch(route('companies.records.submission.update', [$record->company, $other]), $this->data($other))->assertNotFound();
    }

    #[TestWith(['sent', false, false])]
    #[TestWith(['signed', true, false])]
    #[TestWith(['not_concluded', false, false])]
    #[TestWith(['draft', true, false])]
    #[TestWith(['draft', false, true])]
    #[TestWith(['draft', false, false, 'amendment'])]
    public function test_sent_signed_or_imported_submissions_are_immutable(string $status, bool $signed, bool $imported, string $kind = 'contract'): void
    {
        $record = WorkflowRecord::factory()->create([
            'kind' => $kind, 'status' => $status, 'signed_at' => $signed ? now() : null,
            'data' => ['reporter_name' => 'Rekruterka', 'reporter_email' => 'hr@example.test'] + ($imported ? ['monday_source' => 'original'] : []),
        ]);
        $original = $record->fresh()->getRawOriginal();

        $this->actingAs($record->company->owner)->get(route('companies.records.edit', [$record->company, $record]))->assertConflict();
        $this->patch(route('companies.records.submission.update', [$record->company, $record]), $this->data($record))->assertConflict();

        $this->assertSame($original, $record->fresh()->getRawOriginal());
    }

    public function test_editing_generated_draft_preserves_old_pdf_and_requires_regeneration(): void
    {
        $employee = Employee::factory()->completed()->create();
        $record = WorkflowRecord::factory()->ready()->for($employee->company)->for($employee)->create([
            'pdf_path' => 'documents/before.pdf',
            'data' => ['reporter_name' => 'HR', 'reporter_email' => 'hr@example.test', 'agreement_type' => 'b2b', 'contractor_tax_id' => '1234567890', 'document_parts' => [['path' => 'documents/before.pdf']]],
        ]);
        $oldToken = $record->signing_token;
        Storage::fake('local');
        Storage::disk('local')->put('documents/before.pdf', '%PDF-original');
        Mail::fake();
        Queue::fake();

        $this->actingAs($record->company->owner)->patch(route('companies.records.submission.update', [$record->company, $record]), array_replace($this->data($record), ['agreement_type' => 'mandate', 'ends_at' => '2028-01-01']))
            ->assertSessionHasNoErrors()->assertRedirect();

        $record->refresh();
        $this->assertSame('draft', $record->status);
        $this->assertNull($record->generated_at);
        $this->assertNull($record->document_html);
        $this->assertNull($record->document_hash);
        $this->assertNull($record->pdf_path);
        $this->assertNotSame($oldToken, $record->signing_token);
        $this->assertArrayNotHasKey('document_parts', $record->data);
        $this->assertArrayNotHasKey('contractor_tax_id', $record->data);
        $this->assertSame('documents/before.pdf', $record->data['submission_edits'][0]['previous_document']['pdf_path']);
        $this->assertSame('1234567890', $record->data['submission_edits'][0]['before']['contractor_tax_id']);
        $this->assertSame('%PDF-original', Storage::disk('local')->get('documents/before.pdf'));
        $this->get(route('companies.records.document', [$record->company, $record, 'preview' => 1]))->assertNotFound();
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    public function test_saving_unchanged_draft_does_not_invalidate_document(): void
    {
        $employee = Employee::factory()->completed()->create();
        $record = WorkflowRecord::factory()->ready()->for($employee->company)->for($employee)->create();
        $original = $record->fresh()->getRawOriginal();

        $this->actingAs($record->company->owner)->patch(route('companies.records.submission.update', [$record->company, $record]), $this->data($record))
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame($original, $record->fresh()->getRawOriginal());
    }

    #[TestWith(['completed'])]
    #[TestWith(['shared'])]
    #[TestWith(['imported_profile'])]
    public function test_completed_or_shared_profile_cannot_be_overwritten_from_submission(string $source): void
    {
        $record = WorkflowRecord::factory()->create();
        if ($source === 'shared') {
            WorkflowRecord::factory()->for($record->company)->for($record->employee)->create();
        } elseif ($source === 'imported_profile') {
            $board = MondayBoard::factory()->for($record->company)->create(['kind' => 'profiles']);
            MondayItem::factory()->for($board, 'board')->create(['employee_id' => $record->employee_id]);
        } else {
            $record->employee->forceFill(['profile_completed_at' => now()])->save();
        }
        $original = $record->employee->fresh()->getRawOriginal();

        $this->actingAs($record->company->owner)->get(route('companies.records.edit', [$record->company, $record]))->assertOk()->assertSee('readonly', false);
        $this->patch(route('companies.records.submission.update', [$record->company, $record]), array_replace($this->data($record), ['first_name' => 'Podmieniona', 'pesel' => '99999999999', 'email' => 'changed@example.test', 'language' => 'RO']))
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame($original, $record->employee->fresh()->getRawOriginal());
        $this->assertSame('RO', $record->fresh()->language);
    }

    public function test_stale_form_cannot_overwrite_newer_changes(): void
    {
        $record = WorkflowRecord::factory()->create();
        $data = $this->data($record);

        $this->actingAs($record->company->owner)->patch(route('companies.records.submission.update', [$record->company, $record]), array_replace($data, ['reporter_name' => 'Pierwsza zmiana']))->assertSessionHasNoErrors();
        $this->patch(route('companies.records.submission.update', [$record->company, $record]), array_replace($data, ['reporter_name' => 'Nieaktualna zmiana']))->assertConflict();

        $this->assertSame('Pierwsza zmiana', $record->fresh()->data['reporter_name']);
        $this->assertCount(1, $record->fresh()->data['submission_edits']);
    }

    public function test_duplicate_identity_cannot_merge_or_overwrite_another_employee(): void
    {
        $record = WorkflowRecord::factory()->create();
        $other = Employee::factory()->for($record->company)->create(['pesel' => '00000000009', 'pesel_hash' => Employee::identityHash('00000000009')]);
        $original = $record->employee->getRawOriginal();

        $this->actingAs($record->company->owner)->patch(route('companies.records.submission.update', [$record->company, $record]), array_replace($this->data($record), ['pesel' => $other->pesel]))->assertSessionHasErrors('pesel');

        $this->assertSame($original, $record->employee->fresh()->getRawOriginal());
        $this->assertSame($original['id'], $record->fresh()->employee_id);
        $this->assertDatabaseCount('employees', 2);
    }

    public function test_invalid_identity_cannot_change_submission_or_employee(): void
    {
        $record = WorkflowRecord::factory()->create();
        $original = $record->fresh()->getRawOriginal();
        $employee = $record->employee->getRawOriginal();

        $this->actingAs($record->company->owner)->patch(route('companies.records.submission.update', [$record->company, $record]), array_replace($this->data($record), ['pesel' => '123', 'email' => 'invalid', 'starts_at' => 'invalid']))
            ->assertSessionHasErrors(['pesel', 'email', 'starts_at']);

        $this->assertSame($original, $record->fresh()->getRawOriginal());
        $this->assertSame($employee, $record->employee->fresh()->getRawOriginal());
    }

    public function test_profile_completed_while_editing_is_not_overwritten(): void
    {
        $record = WorkflowRecord::factory()->create();
        $data = $this->data($record);
        $record->employee->forceFill(['profile_completed_at' => now()])->save();
        $original = $record->employee->fresh()->getRawOriginal();

        $this->actingAs($record->company->owner)->patch(route('companies.records.submission.update', [$record->company, $record]), array_replace($data, ['first_name' => 'Stare dane', 'language' => 'RO']))->assertConflict();

        $this->assertSame($original, $record->employee->fresh()->getRawOriginal());
        $this->assertSame('PL', $record->fresh()->language);
    }

    public function test_another_submission_created_while_editing_blocks_the_old_form(): void
    {
        $record = WorkflowRecord::factory()->create();
        $data = $this->data($record);
        $original = $record->employee->getRawOriginal();
        WorkflowRecord::factory()->for($record->company)->for($record->employee)->create();

        $this->actingAs($record->company->owner)->patch(route('companies.records.submission.update', [$record->company, $record]), array_replace($data, ['first_name' => 'Stare dane', 'language' => 'RO']))->assertConflict();

        $this->assertSame($original, $record->employee->fresh()->getRawOriginal());
        $this->assertSame('PL', $record->fresh()->language);
    }

    public function test_invalid_submission_fields_or_employee_reassignment_are_rejected(): void
    {
        $record = WorkflowRecord::factory()->create();
        $original = $record->fresh()->getRawOriginal();

        $this->actingAs($record->company->owner)->patch(route('companies.records.submission.update', [$record->company, $record]), array_replace($this->data($record), [
            'employee_id' => 999, 'pesel' => '123', 'email' => 'invalid', 'ends_at' => '2000-01-01', 'agreement_type' => 'b2b', 'contractor_tax_id' => '123',
        ]))->assertSessionHasErrors(['employee_id', 'ends_at', 'contractor_tax_id']);

        $this->assertSame($original, $record->fresh()->getRawOriginal());
    }

    private function data(WorkflowRecord $record): array
    {
        $record = $record->fresh();

        return $record->submissionValues() + ['fingerprint' => $record->submissionFingerprint()];
    }
}
