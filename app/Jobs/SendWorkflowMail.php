<?php

namespace App\Jobs;

use App\Mail\HrMessage;
use App\Models\WorkflowRecord;
use App\Services\OutgoingMail;
use App\Services\PdfGenerator;
use App\Support\HrForms;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendWorkflowMail implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 70;

    public int $uniqueFor = 300;

    public array $backoff = [10, 60];

    public bool $deliveryApproved = false;

    public ?string $deliveryMode = null;

    public function __construct(public int $recordId, public bool $statement = false)
    {
        $this->deliveryApproved = app(OutgoingMail::class)->enabled();
        $this->deliveryMode = $this->deliveryApproved ? app(OutgoingMail::class)->mode() : null;
    }

    public function uniqueId(): string
    {
        return $this->recordId.'-'.($this->statement ? 'statement' : 'signing');
    }

    public function handle(PdfGenerator $pdf): void
    {
        $record = WorkflowRecord::with(['company', 'employee'])->findOrFail($this->recordId);
        if (! app(OutgoingMail::class)->approved($this->deliveryApproved, $this->deliveryMode)) {
            if ($record->mail_status === 'queued') {
                $record->forceFill(['mail_status' => 'suppressed'])->save();
            }

            return;
        }
        if ($record->isImported()) {
            return;
        }
        if ($this->statement ? ! $record->signed_at : ! $record->canBeSigned()) {
            return;
        }
        $path = $pdf->save($record, $this->statement);
        $record->refresh();
        if (! $this->statement && ! $record->canBeSigned()) {
            return;
        }
        if ($this->statement) {
            $recipient = $record->company->hr_email;
            $title = 'Złożono oświadczenie: '.HrForms::kinds()[$record->kind].' - '.$record->employee->fullName();
            $text = 'W załączniku znajduje się oświadczenie z danymi i podpisem. Dokument jest również dostępny w panelu firmy.';
            $url = null;
        } else {
            $recipient = $record->employee->email;
            $title = HrForms::kinds()[$record->kind].' do podpisu - '.$record->company->name;
            $text = 'Zapoznaj się z dokumentami w załączniku. Następnie uzupełnij oświadczenie i złóż podpis, korzystając z poniższego przycisku. Link jest ważny przez 30 dni.';
            $url = route('signing.show', $record->signing_token);
        }
        Mail::to($recipient)->send(new HrMessage($title, $text, $url, 'Przeczytaj i podpisz', [$path]));
        $record->mail_status = 'sent';
        $record->mail_sent_at = now();
        $record->save();
    }

    public function failed(?Throwable $exception): void
    {
        WorkflowRecord::whereKey($this->recordId)->where('mail_status', 'queued')->update(['mail_status' => 'failed']);
    }
}
