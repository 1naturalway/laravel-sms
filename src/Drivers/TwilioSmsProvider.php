<?php

declare(strict_types=1);

namespace OneNaturalWay\Sms\Drivers;

use OneNaturalWay\Sms\Contracts\SmsProvider;
use OneNaturalWay\Sms\SmsResult;
use RuntimeException;

class TwilioSmsProvider implements SmsProvider
{
    public function __construct(
        protected string $sid,
        protected string $token,
        protected string $from,
    ) {
        if (! class_exists(\Twilio\Rest\Client::class)) {
            throw new RuntimeException(
                'The Twilio SDK is required to use the Twilio SMS driver. '
                . 'Install it with: composer require twilio/sdk'
            );
        }
    }

    /**
     * Send an SMS via the Twilio API.
     *
     * @param  string  $to  The recipient phone number.
     * @param  string  $body  The message body.
     * @param  array<string, mixed>  $options  Optional parameters (e.g., 'from', 'mediaUrl').
     */
    public function send(string $to, string $body, array $options = []): SmsResult
    {
        $client = $this->createClient();

        $from = $options['from'] ?? $this->from;

        $params = [
            'from' => $from,
            'body' => $body,
        ];

        if (isset($options['mediaUrl'])) {
            $params['mediaUrl'] = (array) $options['mediaUrl'];
        }

        $message = $client->messages->create($to, $params);

        return new SmsResult(
            messageId: $message->sid,
            status: $message->status,
            to: $to,
            from: $from,
            body: $body,
            mediaCount: (int) $message->numMedia,
            raw: $message->toArray(),
        );
    }

    /**
     * Create a new Twilio REST client instance.
     */
    protected function createClient(): \Twilio\Rest\Client
    {
        return new \Twilio\Rest\Client($this->sid, $this->token);
    }
}
