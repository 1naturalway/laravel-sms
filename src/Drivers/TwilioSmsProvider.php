<?php

declare(strict_types=1);

namespace OneNaturalWay\Sms\Drivers;

use OneNaturalWay\Sms\Contracts\SmsProvider;
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
    public function send(string $to, string $body, array $options = []): void
    {
        $client = $this->createClient();

        $params = [
            'from' => $options['from'] ?? $this->from,
            'body' => $body,
        ];

        if (isset($options['mediaUrl'])) {
            $params['mediaUrl'] = (array) $options['mediaUrl'];
        }

        $client->messages->create($to, $params);
    }

    /**
     * Create a new Twilio REST client instance.
     */
    protected function createClient(): \Twilio\Rest\Client
    {
        return new \Twilio\Rest\Client($this->sid, $this->token);
    }
}
