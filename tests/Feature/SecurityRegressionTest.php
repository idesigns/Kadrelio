<?php

namespace Tests\Feature;

use App\Jobs\SendWorkflowMail;
use App\Mail\HrMessage;
use App\Models\Company;
use App\Models\DocumentTemplate;
use App\Models\WorkflowRecord;
use App\Services\PdfGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SecurityRegressionTest extends TestCase
{
    public function test_pdf_changed_during_render_cannot_replace_the_current_document(): void
    {
        Storage::fake('local');
        $record = WorkflowRecord::factory()->ready()->create();
        $this->partialMock(PdfGenerator::class, function (MockInterface $mock) use ($record): void {
            $mock->shouldReceive('render')->once()->andReturnUsing(function () use ($record): string {
                $current = $record->fresh();
                $current->document_html = '<html>Nowa wersja</html>';
                $current->document_hash = hash('sha256', $current->document_html);
                $current->pdf_path = 'documents/current.pdf';
                $current->save();
                Storage::disk('local')->put($current->pdf_path, '%PDF-current');

                return '%PDF-old';
            });
        });

        $this->actingAs($record->company->owner)->get(route('companies.records.document', [$record->company, $record]))->assertSessionHasErrors('document');

        $this->assertSame('documents/current.pdf', $record->fresh()->pdf_path);
        $this->assertSame('%PDF-current', Storage::disk('local')->get('documents/current.pdf'));
        $this->assertSame(['documents/current.pdf'], Storage::disk('local')->allFiles());
    }

    #[TestWith(['not_concluded', false])]
    #[TestWith(['sent', true])]
    public function test_revoked_or_expired_documents_are_not_mailed(string $status, bool $expired): void
    {
        $this->freezeTime();
        $record = WorkflowRecord::factory()->sent()->create(['status' => $status, 'signing_expires_at' => $expired ? now()->subMinute() : now()->addDay(), 'mail_status' => 'queued']);
        Mail::fake();
        $this->mock(PdfGenerator::class, fn (MockInterface $mock) => $mock->shouldNotReceive('save'));

        (new SendWorkflowMail($record->id))->handle(app(PdfGenerator::class));

        Mail::assertNothingSent();
        $this->assertNull($record->fresh()->mail_sent_at);
    }

    public function test_terminated_contract_revokes_amendment_signing_pdf_and_delivery(): void
    {
        $contract = WorkflowRecord::factory()->signed()->create(['status' => 'terminated']);
        $amendment = WorkflowRecord::factory()->sent()->for($contract->company)->for($contract->employee)->create(['kind' => 'amendment', 'contract_id' => $contract->id]);
        Mail::fake();
        $this->mock(PdfGenerator::class, fn (MockInterface $mock) => $mock->shouldNotReceive('save'));

        $this->get(route('signing.show', $amendment->signing_token))->assertGone();
        $this->get(route('signing.document', $amendment->signing_token))->assertGone();
        $this->post(route('signing.show', $amendment->signing_token), [])->assertGone();
        (new SendWorkflowMail($amendment->id))->handle(app(PdfGenerator::class));

        $this->assertNull($amendment->fresh()->signed_at);
        Mail::assertNothingSent();
    }

    public function test_unconfirmed_submission_cannot_be_processed_by_hr_or_delivery_job(): void
    {
        $record = WorkflowRecord::factory()->create(['kind' => 'data_change', 'status' => 'awaiting_confirmation']);
        Mail::fake();
        $this->mock(PdfGenerator::class, fn (MockInterface $mock) => $mock->shouldNotReceive('save'));

        $this->actingAs($record->company->owner)->patch(route('companies.records.update', [$record->company, $record]), ['status' => 'reviewed'])->assertConflict();
        $this->get(route('companies.board', [$record->company, 'changes']))->assertOk()->assertSee('Elementy: 0')->assertDontSee($record->employee->fullName());
        $this->get(route('companies.records.document', [$record->company, $record, 'statement' => 1]))->assertNotFound();
        (new SendWorkflowMail($record->id, true))->handle(app(PdfGenerator::class));

        $this->assertSame('awaiting_confirmation', $record->fresh()->status);
        $this->assertNull($record->fresh()->signed_at);
        Mail::assertNothingSent();
    }

    public function test_confirmation_requires_consent_and_the_original_employee_email(): void
    {
        $this->freezeTime();
        $record = WorkflowRecord::factory()->create(['kind' => 'data_change', 'status' => 'awaiting_confirmation', 'signing_expires_at' => now()->addMinutes(30)]);
        $record->data = ['email' => $record->employee->email];
        $record->save();
        Queue::fake([SendWorkflowMail::class]);

        $this->post(route('forms.confirmation', $record->signing_token), [])->assertSessionHasErrors('accepted');
        $record->employee->update(['email' => 'changed@example.test']);
        $this->post(route('forms.confirmation', $record->signing_token), ['accepted' => '1'])->assertGone();

        $this->assertNull($record->fresh()->signed_at);
        Queue::assertNothingPushed();
    }

    public function test_expired_confirmation_link_cannot_be_opened_or_submitted(): void
    {
        $this->freezeTime();
        $record = WorkflowRecord::factory()->create(['kind' => 'multisport', 'status' => 'awaiting_confirmation', 'signing_expires_at' => now()->subSecond()]);
        Queue::fake([SendWorkflowMail::class]);

        $this->get(route('forms.confirmation', $record->signing_token))->assertGone();
        $this->post(route('forms.confirmation', $record->signing_token), ['accepted' => '1'])->assertGone();

        $this->assertNull($record->fresh()->signed_at);
        Queue::assertNothingPushed();
    }

    public function test_host_header_cannot_change_invitation_email_link(): void
    {
        $company = Company::factory()->create();
        Mail::fake();

        $this->actingAs($company->owner)->post('https://attacker.invalid/companies/'.$company->id.'/members', ['name' => 'Zaproszona Osoba', 'email' => 'invited@example.test'])->assertRedirect();

        $invitation = $company->invitations()->firstOrFail();
        Mail::assertQueued(HrMessage::class, fn (HrMessage $mail): bool => $mail->actionUrl === rtrim(config('app.url'), '/').'/invite/'.$invitation->token);
    }

    public function test_production_rejects_untrusted_hosts(): void
    {
        $this->app['env'] = 'production';
        try {
            $this->get('http://attacker.invalid/login')->assertBadRequest();
        } finally {
            $this->app['env'] = 'testing';
            Request::setTrustedHosts([]);
        }
    }

    public function test_company_members_cannot_mutate_foreign_nested_resources(): void
    {
        $company = Company::factory()->create();
        $record = WorkflowRecord::factory()->sent()->create();
        $template = DocumentTemplate::factory()->for($record->company)->create();
        Queue::fake();

        $this->actingAs($company->owner)->patch(route('companies.records.update', [$company, $record]), ['status' => 'not_concluded'])->assertNotFound();
        $this->post(route('companies.records.generate', [$company, $record]))->assertNotFound();
        $this->post(route('companies.records.send', [$company, $record]))->assertNotFound();
        $this->patch(route('companies.employees.update', [$company, $record->employee]), ['first_name' => 'Intruz'])->assertNotFound();
        $this->post(route('companies.employees.invite', [$company, $record->employee]))->assertNotFound();
        $this->patch(route('companies.templates.update', [$company, $template]), ['body' => 'Podmieniona umowa'])->assertNotFound();

        $this->assertSame('sent', $record->fresh()->status);
        $this->assertSame($template->body, $template->fresh()->body);
        $this->assertSame($record->employee->first_name, $record->employee->fresh()->first_name);
        Queue::assertNothingPushed();
    }
}
