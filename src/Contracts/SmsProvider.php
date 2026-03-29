<?php

declare(strict_types=1);

namespace OneNaturalWay\Sms\Contracts;

use OneNaturalWay\Sms\SmsResult;

interface SmsProvider
{
    /**
     * Send an SMS message.
     *
     * @param  string  $to  The recipient phone number.
     * @param  string  $body  The message body.
     * @param  array<string, mixed>  $options  Optional parameters (e.g., 'from', 'mediaUrl').
     */
    public function send(string $to, string $body, array $options = []): SmsResult;
}
