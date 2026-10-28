<?php

namespace Tests\Feature;

use App\Jobs\SendWorkflowMail;
use App\Mail\HrMessage;
use App\Models\Company;
use App\Models\Employee;
use App\Models\WorkflowRecord;
use App\Services\HrWorkflow;
use App\Services\OutgoingMail;
use App\Services\PdfGenerator;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use ReflectionClass;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class OutgoingMailSafetyTest extends TestCase
{
    public function test_disabled_mail_does_not_queue_invites_or_reminders(): void
    {
        config(['hr.mail_enabled' => false]);
        Mail::fake();
        $employee = Employee::factory()->create();
        app(HrWorkflow::class)->inviteEmployee($employee);
        $this->actingAs($employee->company->owner)->post(route('companies.members.store', $employee->company), ['name' => 'Kadry', 'email' => 'kadry@example.test'])->assertSessionHasNoErrors();
        Mail::assertNothingOutgoing();
        $this->assertDatabaseCount('company_invitations', 1);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_sending_document_is_blocked_before_changing_status_when_mail_is_disabled(): void
    {
        config(['hr.mail_enabled' => false]);
        Queue::fake([SendWorkflowMail::class]);
        $record = WorkflowRecord::factory()->ready()->create();
        $this->actingAs($record->company->owner)->post(route('companies.records.send', [$record->company, $record]))->assertConflict();
        $this->assertSame('ready', $record->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_confirmation_form_does_not_save_or_queue_when_mail_is_disabled(): void
    {
        config(['hr.mail_enabled' => false]);
        Mail::fake();
        $company = Company::factory()->create();
        $this->post(route('forms.additional', [$company->public_token, 'data_change']), [])->assertConflict();
        $this->assertDatabaseCount('workflow_records', 0);
        Mail::assertNothingOutgoing();
    }

    public function test_disabled_worker_marks_existing_mail_as_suppressed_without_rendering_pdf(): void
    {
        $record = WorkflowRecord::factory()->sent()->create(['mail_status' => 'queued']);
        $job = new SendWorkflowMail($record->id);
        config(['hr.mail_enabled' => false]);
        Mail::fake();
        $pdf = $this->mock(PdfGenerator::class);
        $pdf->shouldNotReceive('save');
        $job->handle($pdf);
        $this->assertSame('suppressed', $record->fresh()->mail_status);
        $this->assertNull($record->fresh()->mail_sent_at);
        Mail::assertNothingOutgoing();
    }

    public function test_legacy_job_without_delivery_approval_is_not_replayed_after_enabling_mail(): void
    {
        $record = WorkflowRecord::factory()->sent()->create(['mail_status' => 'queued']);
        $job = (new ReflectionClass(SendWorkflowMail::class))->newInstanceWithoutConstructor();
        $job->recordId = $record->id;
        $job->statement = false;
        Mail::fake();
        $pdf = $this->mock(PdfGenerator::class);
        $pdf->shouldNotReceive('save');
        $job->handle($pdf);
        $this->assertSame('suppressed', $record->fresh()->mail_status);
        Mail::assertNothingOutgoing();
    }

    public function test_message_created_while_disabled_cannot_be_queued_after_enabling_mail(): void
    {
        Mail::fake();
        config(['hr.mail_enabled' => false]);
        $message = new HrMessage('Test', 'Treść');
        config(['hr.mail_enabled' => true]);
        $this->assertFalse(app(OutgoingMail::class)->queue('test@example.test', $message));
        Mail::assertNothingOutgoing();
    }

    public function test_real_transport_is_blocked_during_tests_even_if_mail_is_enabled(): void
    {
        $message = (new Email)->from('from@example.test')->to('to@example.test')->subject('Test');
        $message->getHeaders()->addTextHeader('X-Kadrelio-Mail-Approved', 'yes');
        $this->assertFalse(Event::until(new MessageSending($message)));
    }

    public function test_nonproduction_environment_cannot_enable_outgoing_mail(): void
    {
        app()['env'] = 'local';
        $this->assertFalse(app(OutgoingMail::class)->enabled());
        $message = (new Email)->from('from@example.test')->to('to@example.test')->subject('Test');
        $message->getHeaders()->addTextHeader('X-Kadrelio-Mail-Approved', 'yes');
        $this->assertFalse(Event::until(new MessageSending($message)));
    }

    public function test_production_transport_rejects_unapproved_legacy_mail(): void
    {
        app()['env'] = 'production';
        $message = (new Email)->from('from@example.test')->to('to@example.test')->subject('Test');
        $this->assertFalse(Event::until(new MessageSending($message)));
        $message->getHeaders()->addTextHeader('X-Kadrelio-Mail-Approved', 'yes');
        $this->assertNull(Event::until(new MessageSending($message)));
    }
}
