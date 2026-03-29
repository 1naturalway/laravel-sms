<?php

declare(strict_types=1);

namespace OneNaturalWay\Sms\Drivers;

use Illuminate\Support\Str;
use OneNaturalWay\Sms\Contracts\SmsProvider;
use OneNaturalWay\Sms\Exceptions\SmsException;
use OneNaturalWay\Sms\SmsResult;
use RuntimeException;

class TwilioSmsProvider implements SmsProvider {
  public function __construct(
      protected string $sid,
      protected string $token,
      protected string $from,
  ) {
    if (!class_exists(\Twilio\Rest\Client::class)) {
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
  public function send(string $to, string $body, array $options = []): SmsResult {
    $client = $this->createClient();

    $from = $options['from'] ?? $this->from;

    $params = [
      'from' => $from,
      'body' => $body,
    ];

    if (isset($options['mediaUrl'])) {
      $params['mediaUrl'] = (array) $options['mediaUrl'];
    }

    try {
      $message = $client->messages->create($to, $params);

      $mediaUrls = [];
      if ((int) $message->numMedia > 0) {
        $mediaResponse = $client->messages($message->sid)->media->read();
        foreach ($mediaResponse as $media) {
          $mediaUrls[] = "https://api.twilio.com" . Str::replaceLast(".json", "", $media->uri);
        }
      }
    } catch (\Twilio\Exceptions\RestException $e) {
      if (Str::contains($e->getMessage(), 'blacklist rule')) {
        throw new SmsException(
            message: $e->getMessage(),
            errorType: 'blacklisted',
            phoneNumber: $to,
            providerCode: (string) $e->getCode(),
            previous: $e,
        );
      }

      if (Str::contains($e->getMessage(), 'not a valid phone number')) {
        throw new SmsException(
            message: $e->getMessage(),
            errorType: 'invalid_number',
            phoneNumber: $to,
            providerCode: (string) $e->getCode(),
            previous: $e,
        );
      }

    // Anything unexpected — still wrap it
      throw new SmsException(
          message: $e->getMessage(),
          errorType: 'provider_error',
          phoneNumber: $to,
          providerCode: (string) $e->getCode(),
          previous: $e,
      );
    }

    return new SmsResult(
        messageId: $message->sid,
        status: $message->status,
        to: $to,
        from: $from,
        body: $body,
        mediaCount: (int) $message->numMedia,
        mediaUrls: $mediaUrls,
        raw: $message->toArray(),
    );
  }

    /**
     * Create a new Twilio REST client instance.
     */
  protected function createClient(): \Twilio\Rest\Client {
    return new \Twilio\Rest\Client($this->sid, $this->token);
  }
}
