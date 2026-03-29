<?php

declare(strict_types=1);

namespace OneNaturalWay\Sms\Drivers;

use Illuminate\Support\Facades\Http;
use OneNaturalWay\Sms\Contracts\SmsProvider;

class SmsTrapProvider implements SmsProvider
{
    public function __construct(
        protected string $url,
        protected string $apiKey,
        protected string $project,
        protected string $from,
    ) {}

    /**
     * Send an SMS via the SmsTrap HTTP API.
     *
     * @param  string  $to  The recipient phone number.
     * @param  string  $body  The message body.
     * @param  array<string, mixed>  $options  Optional parameters (e.g., 'from').
     */
    public function send(string $to, string $body, array $options = []): void
    {
        Http::withToken($this->apiKey)
            ->post(rtrim($this->url, '/') . '/messages', [
                'to'      => $to,
                'from'    => $options['from'] ?? $this->from,
                'body'    => $body,
                'project' => $this->project,
                'sent_at' => now()->toIso8601String(),
            ]);
    }
}
