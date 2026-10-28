<?php

namespace App\Mail;

use App\Services\OutgoingMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

class HrMessage extends Mailable implements ShouldBeEncrypted
{
    use Queueable, SerializesModels;

    public bool $deliveryApproved = false;

    public function __construct(public string $title, public string $messageText, public ?string $actionUrl = null, public string $actionLabel = 'Otwórz formularz', public array $files = [])
    {
        $this->deliveryApproved = app(OutgoingMail::class)->enabled();
    }

    public function headers(): Headers
    {
        return new Headers(text: ['X-Kadrelio-Mail-Approved' => $this->deliveryApproved ? 'yes' : 'no']);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->title);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.message');
    }

    public function attachments(): array
    {
        return array_map(fn (string $path): Attachment => Attachment::fromStorageDisk('local', $path)->as('dokument.pdf')->withMime('application/pdf'), $this->files);
    }
}
