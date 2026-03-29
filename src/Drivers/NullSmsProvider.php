<?php

declare(strict_types=1);

namespace OneNaturalWay\Sms\Drivers;

use OneNaturalWay\Sms\Contracts\SmsProvider;
use OneNaturalWay\Sms\SmsResult;

class NullSmsProvider implements SmsProvider
{
    /**
     * Discard the SMS silently.
     *
     * @param  string  $to  The recipient phone number.
     * @param  string  $body  The message body.
     * @param  array<string, mixed>  $options  Optional parameters.
     */
    public function send(string $to, string $body, array $options = []): SmsResult
    {
        return new SmsResult();
    }
}
