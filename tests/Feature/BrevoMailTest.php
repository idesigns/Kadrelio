<?php

namespace Tests\Feature;

use App\Mail\HrMessage;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Address;
use Tests\TestCase;

class BrevoMailTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app()['env'] = 'production';
        config([
            'mail.default' => 'brevo',
            'mail.from.address' => 'nadawca@example.test',
            'mail.from.name' => 'Kadrelio',
            'services.brevo.key' => 'fake-api-key',
            'hr.mail_mode' => 'test',
            'hr.mail_test_address' => 'testy@kadrelio.pl',
        ]);
        Http::preventStrayRequests();
    }

    public function test_test_mode_sends_only_to_test_mailbox_with_pdf_and_working_links(): void
    {
        Http::fake(['https://api.brevo.com/v3/smtp/email' => Http::response(['messageId' => '<test@brevo.test>'], 201)]);
        Storage::fake('local');
        Storage::disk('local')->put('documents/test.pdf', '%PDF-test');
        $message = (new HrMessage('Dokument do podpisu', 'Sprawdź dokument.', 'https://kadrelio.pl/podpis/test', 'Podpisz', ['documents/test.pdf']))->replyTo('kadry@example.test');

        Mail::to('pracownik@example.test')->cc('kadry@example.test')->bcc('manager@example.test')->send($message);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.brevo.com/v3/smtp/email'
            && $request->hasHeader('api-key', 'fake-api-key')
            && $request['sender'] === ['email' => 'nadawca@example.test', 'name' => 'Kadrelio']
            && $request['to'] === [['email' => 'testy@kadrelio.pl']]
            && ! isset($request['cc']) && ! isset($request['bcc'])
            && $request['replyTo'] === ['email' => 'testy@kadrelio.pl']
            && $request['subject'] === '[TEST] Dokument do podpisu'
            && str_contains($request['htmlContent'], 'https://kadrelio.pl/podpis/test')
            && $request['attachment'] === [['name' => 'dokument.pdf', 'content' => 'JVBERi10ZXN0']]);
    }

    public function test_live_mode_preserves_real_recipients_and_subject(): void
    {
        config(['hr.mail_mode' => 'live']);
        Http::fake(['https://api.brevo.com/v3/smtp/email' => Http::response(['messageId' => '<live@brevo.test>'], 201)]);

        Mail::to('pracownik@example.test')->cc('kadry@example.test')->bcc('manager@example.test')
            ->send((new HrMessage('Dokument do podpisu', 'Treść'))->replyTo('odpowiedzi@example.test'));

        Http::assertSent(fn (Request $request): bool => $request['to'] === [['email' => 'pracownik@example.test']]
            && $request['cc'] === [['email' => 'kadry@example.test']]
            && $request['bcc'] === [['email' => 'manager@example.test']]
            && $request['replyTo'] === ['email' => 'odpowiedzi@example.test']
            && $request['subject'] === 'Dokument do podpisu');
    }

    public function test_other_mailers_also_deliver_only_to_test_recipient(): void
    {
        $message = new HrMessage('Dokument', 'Treść');

        $sent = Mail::mailer('array')->to('pracownik@example.test')->cc('kadry@example.test')->bcc('manager@example.test')->send($message);

        $this->assertSame(['testy@kadrelio.pl'], array_map(
            fn (Address $address): string => $address->getAddress(),
            $sent->getSymfonySentMessage()->getEnvelope()->getRecipients(),
        ));
        Http::assertNothingSent();
    }

    #[DataProvider('modeChanges')]
    public function test_message_cannot_be_delivered_after_switching_mode(string $before, string $after): void
    {
        config(['hr.mail_mode' => $before]);
        $message = unserialize(serialize(new HrMessage('Dokument', 'Treść')));
        config(['hr.mail_mode' => $after]);

        Mail::to('pracownik@example.test')->send($message);

        Http::assertNothingSent();
    }

    public static function modeChanges(): array
    {
        return [['test', 'live'], ['live', 'test']];
    }

    public function test_legacy_approved_message_without_mode_cannot_use_api(): void
    {
        $message = new HrMessage('Dokument', 'Treść');
        $message->deliveryMode = null;

        Mail::to('pracownik@example.test')->send($message);

        Http::assertNothingSent();
    }

    public function test_disabled_message_is_not_replayed_after_enabling_api(): void
    {
        config(['hr.mail_enabled' => false]);
        $message = new HrMessage('Dokument', 'Treść');
        config(['hr.mail_enabled' => true]);

        Mail::to('pracownik@example.test')->send($message);

        Http::assertNothingSent();
    }

    #[DataProvider('invalidSettings')]
    public function test_invalid_mail_configuration_cannot_send_to_real_recipient(array $settings): void
    {
        config($settings);

        Mail::to('pracownik@example.test')->send(new HrMessage('Dokument', 'Treść'));

        Http::assertNothingSent();
    }

    public static function invalidSettings(): array
    {
        return [
            [['hr.mail_mode' => 'mistake']],
            [['hr.mail_test_address' => '']],
            [['hr.mail_test_address' => 'niepoprawny-adres']],
        ];
    }

    #[DataProvider('failedResponses')]
    public function test_api_failure_is_reported_without_exposing_private_response(int $status, array $body): void
    {
        Http::fake(['https://api.brevo.com/v3/smtp/email' => Http::response($body, $status)]);

        try {
            Mail::to('pracownik@example.test')->send(new HrMessage('Dokument', 'Treść'));
            $this->fail('Brak błędu transportu.');
        } catch (TransportException $exception) {
            $this->assertSame('Brevo nie potwierdziło przyjęcia wiadomości (HTTP '.$status.').', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    public static function failedResponses(): array
    {
        return [[503, ['message' => 'private-provider-response']], [201, []]];
    }

    public function test_connection_failure_is_reported_as_transport_error(): void
    {
        Http::fake(['https://api.brevo.com/v3/smtp/email' => Http::failedConnection()]);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Nie udało się połączyć z API Brevo.');
        Mail::to('pracownik@example.test')->send(new HrMessage('Dokument', 'Treść'));
    }

    public function test_missing_api_key_prevents_outbound_request(): void
    {
        config(['services.brevo.key' => '']);

        try {
            Mail::to('pracownik@example.test')->send(new HrMessage('Dokument', 'Treść'));
            $this->fail('Brak błędu konfiguracji.');
        } catch (TransportException $exception) {
            $this->assertSame('Brakuje BREVO_API_KEY. Uzupełnij konfigurację poczty.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }
}
