<?php

namespace App\Services;

use App\Mail\HrMessage;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;

class OutgoingMail
{
    public function enabled(): bool
    {
        return (bool) config('hr.mail_enabled')
            && (app()->isProduction() || app()->runningUnitTests())
            && in_array($this->mode(), ['test', 'live'], true)
            && ($this->mode() !== 'test' || filter_var(config('hr.mail_test_address'), FILTER_VALIDATE_EMAIL) !== false);
    }

    public function mode(): string
    {
        return (string) config('hr.mail_mode', 'test');
    }

    public function approved(bool $approved, ?string $mode): bool
    {
        return $approved && $this->enabled() && $mode === $this->mode();
    }

    public function prepare(Email $message): bool
    {
        if (! $this->approved(
            $message->getHeaders()->get('X-Kadrelio-Mail-Approved')?->getBodyAsString() === 'yes',
            $message->getHeaders()->get('X-Kadrelio-Mail-Mode')?->getBodyAsString(),
        )) {
            return false;
        }
        if ($this->mode() === 'test') {
            $address = config('hr.mail_test_address');
            $message->to($address)->cc()->bcc()->replyTo($address);
            if (! str_starts_with($message->getSubject() ?? '', '[TEST] ')) {
                $message->subject('[TEST] '.$message->getSubject());
            }
        }

        return true;
    }

    public function queue(string $recipient, HrMessage $message): bool
    {
        if (! $this->approved($message->deliveryApproved, $message->deliveryMode)) {
            return false;
        }
        Mail::to($recipient)->queue($message->afterCommit());

        return true;
    }
}
