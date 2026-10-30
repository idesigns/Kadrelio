<?php

namespace App\Mail;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Component\Mime\Part\DataPart;

class BrevoTransport extends AbstractTransport
{
    public function __construct(private readonly string $key)
    {
        parent::__construct();
    }

    public function __toString(): string
    {
        return 'brevo+api';
    }

    protected function doSend(SentMessage $message): void
    {
        if ($this->key === '') {
            throw new TransportException('Brakuje BREVO_API_KEY. Uzupełnij konfigurację poczty.');
        }
        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $payload = [
            'sender' => $this->address($email->getFrom()[0]),
            'subject' => $email->getSubject(),
        ];
        foreach (['to' => $email->getTo(), 'cc' => $email->getCc(), 'bcc' => $email->getBcc()] as $key => $addresses) {
            if ($addresses !== []) {
                $payload[$key] = array_map($this->address(...), $addresses);
            }
        }
        if ($email->getHtmlBody() !== null) {
            $payload['htmlContent'] = $email->getHtmlBody();
        } else {
            $payload['textContent'] = $email->getTextBody();
        }
        if ($email->getReplyTo() !== []) {
            $payload['replyTo'] = $this->address($email->getReplyTo()[0]);
        }
        if ($email->getAttachments() !== []) {
            $payload['attachment'] = array_map(fn (DataPart $part): array => [
                'name' => $part->getFilename() ?? 'dokument.pdf',
                'content' => base64_encode($part->getBody()),
            ], $email->getAttachments());
        }
        try {
            $response = Http::acceptJson()->withHeaders(['api-key' => $this->key])
                ->connectTimeout(5)->timeout(20)
                ->post('https://api.brevo.com/v3/smtp/email', $payload);
        } catch (ConnectionException $exception) {
            throw new TransportException('Nie udało się połączyć z API Brevo.', previous: $exception);
        }
        $id = $response->json('messageId');
        if (! $response->successful() || ! is_string($id) || $id === '') {
            throw new TransportException('Brevo nie potwierdziło przyjęcia wiadomości (HTTP '.$response->status().').');
        }
        $message->setMessageId(trim($id, '<>'));
    }

    private function address(Address $address): array
    {
        $data = ['email' => $address->getAddress()];
        if ($address->getName() !== '') {
            $data['name'] = $address->getName();
        }

        return $data;
    }
}
