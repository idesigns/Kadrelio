<?php

namespace App\Services;

use App\Mail\HrMessage;
use Illuminate\Support\Facades\Mail;

class OutgoingMail
{
    public function enabled(): bool
    {
        return (bool) config('hr.mail_enabled') && (app()->isProduction() || app()->runningUnitTests());
    }

    public function queue(string $recipient, HrMessage $message): bool
    {
        if (! $this->enabled() || ! $message->deliveryApproved) {
            return false;
        }
        Mail::to($recipient)->queue($message->afterCommit());

        return true;
    }
}
