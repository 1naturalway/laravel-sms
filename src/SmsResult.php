<?php

declare(strict_types=1);

namespace OneNaturalWay\Sms;

class SmsResult {
  /**
   * @param  array<string>  $mediaUrls  List of media URLs.
   * @param  array<string, mixed>  $raw  The raw response data from the provider.
   */
  public function __construct(
      public readonly ?string $messageId = null,
      public readonly ?string $status = null,
      public readonly ?string $to = null,
      public readonly ?string $from = null,
      public readonly ?string $body = null,
      public readonly ?int $mediaCount = null,
      public readonly array $mediaUrls = [],
      public readonly array $raw = [],
  ) {
  }

  /**
   * Check if the result represents a successfully queued/sent message.
   */
  public function successful(): bool {
    return $this->messageId !== null;
  }

  public function hasMedia(): bool {
    return $this->mediaCount > 0;
  }
}
