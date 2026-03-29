<?php

declare(strict_types=1);

namespace OneNaturalWay\Sms\Drivers;

use Illuminate\Support\Facades\Log;
use OneNaturalWay\Sms\Contracts\SmsProvider;
use OneNaturalWay\Sms\SmsResult;

class LogSmsProvider implements SmsProvider
{
    public function __construct(
        protected string $channel,
        protected string $from,
    ) {}

    /**
     * Log an SMS to the configured log channel.
     *
     * @param  string  $to  The recipient phone number.
     * @param  string  $body  The message body.
     * @param  array<string, mixed>  $options  Optional parameters (e.g., 'from').
     */
    public function send(string $to, string $body, array $options = []): SmsResult
    {
        $from = $options['from'] ?? $this->from;

        Log::channel($this->channel)->info('SMS sent', [
            'to'   => $to,
            'from' => $from,
            'body' => $body,
        ]);

        return new SmsResult(
            messageId: 'log_' . uniqid(),
            status: 'logged',
            to: $to,
            from: $from,
            body: $body,
        );
    }
}
