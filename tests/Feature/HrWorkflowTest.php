<?php

namespace Tests\Feature;

use App\Jobs\SendWorkflowMail;
use App\Mail\HrMessage;
use App\Models\Company;
use App\Models\DocumentTemplate;
use App\Models\Employee;
use App\Models\WorkflowRecord;
use App\Services\PdfGenerator;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HrWorkflowTest extends TestCase
{
    public function test_recruitment_creates_employee_and_contract_and_queues_individual_form(): void
    {
        $company = Company::factory()->create();
        Mail::fake();

        $this->actingAs($company->owner)->post(route('companies.records.store', [$company, 'contract']), $this->contractData() + ['employee_id' => '', 'status' => 'signed', 'company_id' => 999])->assertRedirect();

        $employee = $company->employees()->firstOrFail();
        $record = $company->records()->firstOrFail();
        $this->assertSame('Anna Testowa', $employee->fullName());
        $this->assertSame($employee->id, $record->employee_id);
        $this->assertSame('awaiting_data', $record->status);
        $this->assertSame($company->owner_id, $record->created_by);
        $this->assertNotSame('00000000001', $employee->getRawOriginal('pesel'));
        Mail::assertQueued(HrMessage::class, fn (HrMessage $mail): bool => $mail->hasTo('anna@example.test') && str_contains($mail->actionUrl, $employee->form_token));
    }

    public function test_contract_dates_are_validated_before_saving(): void
    {
        $company = Company::factory()->create();
        Mail::fake();

        $this->actingAs($company->owner)->post(route('companies.records.store', [$company, 'contract']), array_replace($this->contractData(), ['ends_at' => '2026-09-01']))->assertSessionHasErrors('ends_at');

        $this->assertDatabaseCount('workflow_records', 0);
        $this->assertDatabaseCount('employees', 0);
        Mail::assertNothingQueued();
    }

    public function test_existing_completed_employee_is_reused_without_replacing_profile(): void
    {
        $company = Company::factory()->create();
        $employee = Employee::factory()->completed()->for($company)->create();
        Mail::fake();
        $data = collect($this->contractData())->except(['first_name', 'last_name', 'email', 'pesel'])->all() + ['employee_id' => $employee->id];

        $this->actingAs($company->owner)->post(route('companies.records.store', [$company, 'contract']), $data)->assertRedirect();

        $this->assertDatabaseCount('employees', 1);
        $this->assertDatabaseHas('workflow_records', ['employee_id' => $employee->id, 'status' => 'draft']);
        Mail::assertNothingQueued();
    }

    public function test_employee_from_another_company_cannot_be_used(): void
    {
        $company = Company::factory()->create();
        $employee = Employee::factory()->create();
        Mail::fake();

        $this->actingAs($company->owner)->post(route('companies.records.store', [$company, 'contract']), $this->contractData() + ['employee_id' => $employee->id])->assertSessionHasErrors('employee_id');

        $this->assertDatabaseCount('workflow_records', 0);
        Mail::assertNothingQueued();
    }

    public function test_generating_documents_requires_all_three_templates(): void
    {
        $company = Company::factory()->create();
        $employee = Employee::factory()->completed()->for($company)->create();
        $record = WorkflowRecord::factory()->for($company)->for($employee)->create(['status' => 'draft']);
        DocumentTemplate::factory()->for($company)->create();

        $this->actingAs($company->owner)->post(route('companies.records.generate', [$company, $record]))->assertSessionHasErrors('document');

        $this->assertNull($record->fresh()->generated_at);
    }

    public function test_generation_uses_company_templates_and_keeps_an_escaped_snapshot(): void
    {
        $company = Company::factory()->create(['name' => 'Firma Własna']);
        $employee = Employee::factory()->completed()->for($company)->create(['first_name' => '<script>alert(1)</script>', 'last_name' => 'Testowa']);
        $record = WorkflowRecord::factory()->for($company)->for($employee)->create(['status' => 'draft']);
        foreach (['contract', 'declaration', 'attachment'] as $kind) {
            DocumentTemplate::factory()->for($company)->create(['kind' => $kind, 'body' => 'Firma {{company_name}}. Pracownik {{full_name}}.']);
        }
        DocumentTemplate::factory()->create(['body' => 'Treść z innej firmy']);

        $this->actingAs($company->owner)->post(route('companies.records.generate', [$company, $record]))->assertRedirect();

        $record->refresh();
        $this->assertSame('ready', $record->status);
        $this->assertStringContainsString('Firma Własna', $record->document_html);
        $this->assertStringContainsString('&lt;script&gt;', $record->document_html);
        $this->assertStringNotContainsString('<script>', $record->document_html);
        $this->assertStringNotContainsString('Treść z innej firmy', $record->document_html);
        $snapshot = $record->document_html;
        $employee->update(['last_name' => 'Zmieniona']);
        $this->assertSame($snapshot, $record->fresh()->document_html);
    }

    public function test_unknown_template_variable_blocks_incomplete_documents(): void
    {
        $company = Company::factory()->create();
        $employee = Employee::factory()->completed()->for($company)->create();
        $record = WorkflowRecord::factory()->for($company)->for($employee)->create(['status' => 'draft']);
        foreach (['contract', 'declaration', 'attachment'] as $kind) {
            DocumentTemplate::factory()->for($company)->create(['kind' => $kind, 'body' => 'Brakujące dane {{unknown_field}}']);
        }

        $this->actingAs($company->owner)->post(route('companies.records.generate', [$company, $record]))->assertSessionHasErrors('document');
        $this->assertNull($record->fresh()->document_html);
    }

    public function test_sending_generated_contract_queues_delivery_and_activates_signing_link(): void
    {
        $record = WorkflowRecord::factory()->ready()->create();
        Queue::fake([SendWorkflowMail::class]);

        $this->actingAs($record->company->owner)->post(route('companies.records.send', [$record->company, $record]))->assertRedirect();

        $record->refresh();
        $this->assertSame('sent', $record->status);
        $this->assertSame('queued', $record->mail_status);
        $this->assertTrue($record->signing_expires_at->isFuture());
        Queue::assertPushed(SendWorkflowMail::class, fn (SendWorkflowMail $job): bool => $job->recordId === $record->id && ! $job->statement);
    }

    public function test_signed_documents_cannot_be_rewritten_or_manually_cancelled(): void
    {
        $record = WorkflowRecord::factory()->signed()->create();

        $this->actingAs($record->company->owner)->post(route('companies.records.generate', [$record->company, $record]))->assertConflict();
        $this->patch(route('companies.records.update', [$record->company, $record]), ['status' => 'not_concluded'])->assertConflict();
        $this->assertSame('signed', $record->fresh()->status);
    }

    public function test_amendment_cannot_reference_an_unsigned_or_foreign_contract(): void
    {
        $company = Company::factory()->create();
        $contract = WorkflowRecord::factory()->for($company)->create();
        $foreign = WorkflowRecord::factory()->signed()->create();
        $data = ['concluded_at' => '2026-10-20', 'reporter_name' => 'Anna HR', 'reporter_email' => 'hr@example.test', 'current_address' => 'Adres testowy', 'change_type' => 'hourly', 'hourly_rate' => '40.50'];

        $this->actingAs($company->owner)->post(route('companies.records.store', [$company, 'amendment']), $data + ['contract_id' => $contract->id])->assertSessionHasErrors('contract_id');
        $this->post(route('companies.records.store', [$company, 'amendment']), $data + ['contract_id' => $foreign->id])->assertSessionHasErrors('contract_id');
    }

    public function test_amendment_stores_only_fields_for_selected_change(): void
    {
        $contract = WorkflowRecord::factory()->signed()->create();
        $data = ['concluded_at' => '2026-10-20', 'reporter_name' => 'Anna HR', 'reporter_email' => 'hr@example.test', 'current_address' => 'Adres testowy', 'change_type' => 'hourly', 'hourly_rate' => '40.50', 'commission_rate' => 999, 'new_department' => 'p7', 'contract_id' => $contract->id];

        $this->actingAs($contract->company->owner)->post(route('companies.records.store', [$contract->company, 'amendment']), $data)->assertRedirect();

        $record = WorkflowRecord::where('kind', 'amendment')->firstOrFail();
        $this->assertSame('40.50', $record->data['hourly_rate']);
        $this->assertArrayNotHasKey('commission_rate', $record->data);
        $this->assertArrayNotHasKey('new_department', $record->data);
        $this->assertSame($contract->employee_id, $record->employee_id);
    }

    public function test_delivery_job_attaches_pdf_and_updates_delivery_status(): void
    {
        Storage::fake('local');
        $record = WorkflowRecord::factory()->sent()->create();
        Mail::fake();
        $this->mock(PdfGenerator::class, function ($mock): void {
            $mock->shouldReceive('render')->once()->andReturn('%PDF-1.4 test');
        })->makePartial();

        (new SendWorkflowMail($record->id))->handle(app(PdfGenerator::class));

        $record->refresh();
        $this->assertSame('sent', $record->mail_status);
        $this->assertNotNull($record->mail_sent_at);
        Storage::disk('local')->assertExists($record->pdf_path);
        Mail::assertSent(HrMessage::class, fn (HrMessage $mail): bool => $mail->hasTo($record->employee->email) && count($mail->files) === 1);
    }

    public function test_board_filters_and_sorting_do_not_expose_other_company_records(): void
    {
        $company = Company::factory()->create();
        $visible = Employee::factory()->for($company)->create(['last_name' => 'Widoczna']);
        WorkflowRecord::factory()->for($company)->for($visible)->create();
        $foreign = Employee::factory()->create(['last_name' => 'Ukryta']);
        WorkflowRecord::factory()->for($foreign->company)->for($foreign)->create();

        $this->actingAs($company->owner)->get(route('companies.board', [$company, 'contracts', 'q' => 'Widoczna', 'sort' => 'name']))->assertOk()->assertSee('Widoczna')->assertDontSee('Ukryta');
        $this->get(route('companies.board', [$company, 'contracts', 'sort' => 'id desc; DROP TABLE users']))->assertSessionHasErrors('sort');
        $this->assertDatabaseCount('users', 2);
    }

    public function test_signed_statement_can_be_requeued_after_delivery_failure(): void
    {
        $record = WorkflowRecord::factory()->signed()->create(['mail_status' => 'failed']);
        Queue::fake([SendWorkflowMail::class]);

        $this->actingAs($record->company->owner)->post(route('companies.records.send', [$record->company, $record]))->assertRedirect();

        $this->assertSame('queued', $record->fresh()->mail_status);
        Queue::assertPushed(SendWorkflowMail::class, fn (SendWorkflowMail $job): bool => $job->recordId === $record->id && $job->statement);
    }

    public function test_regeneration_clears_the_cached_unsigned_pdf(): void
    {
        $company = Company::factory()->create();
        $employee = Employee::factory()->completed()->for($company)->create();
        $record = WorkflowRecord::factory()->ready()->for($company)->for($employee)->create(['pdf_path' => 'old.pdf']);
        foreach (['contract', 'declaration', 'attachment'] as $kind) {
            DocumentTemplate::factory()->for($company)->create(['kind' => $kind]);
        }

        $this->actingAs($company->owner)->post(route('companies.records.generate', [$company, $record]))->assertSessionHasNoErrors()->assertRedirect();

        $record->refresh();
        $this->assertNull($record->pdf_path);
        $this->assertSame($employee->fullName(), $record->data['document_identity']['full_name']);
    }

    public function test_b2b_contract_requires_and_stores_contractor_tax_id(): void
    {
        $company = Company::factory()->create();
        $employee = Employee::factory()->completed()->for($company)->create();
        $data = collect($this->contractData())->except(['first_name', 'last_name', 'email', 'pesel'])->all() + ['employee_id' => $employee->id, 'agreement_type' => 'b2b'];
        Mail::fake();

        $this->actingAs($company->owner)->post(route('companies.records.store', [$company, 'contract']), $data)->assertSessionHasErrors('contractor_tax_id');
        $this->assertDatabaseCount('workflow_records', 0);
        $this->post(route('companies.records.store', [$company, 'contract']), $data + ['contractor_tax_id' => '1234567890'])->assertSessionHasNoErrors()->assertRedirect();

        $record = $company->records()->firstOrFail();
        $this->assertSame('Umowa B2B', $record->agreementLabel());
        $this->assertSame('1234567890', $record->data['contractor_tax_id']);
        Mail::assertNothingQueued();
    }

    private function contractData(): array
    {
        return ['first_name' => 'Anna', 'last_name' => 'Testowa', 'email' => 'anna@example.test', 'pesel' => '00000000001', 'reporter_name' => 'HR Test', 'reporter_email' => 'hr@example.test', 'language' => 'PL', 'department' => 'p1', 'concluded_at' => '2026-10-20', 'starts_at' => '2026-10-21', 'ends_at' => '2027-10-20'];
    }
}
