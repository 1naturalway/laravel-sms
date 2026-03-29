<?php

namespace OneNaturalWay\Sms\Exceptions;

class SmsException extends \RuntimeException {
  public function __construct(
      string $message,
      public readonly string $errorType,
      public readonly ?string $phoneNumber = null,
      public readonly ?string $providerCode = null,
      ?\Throwable $previous = null,
  ) {
    parent::__construct($message, 0, $previous);
  }

  public function isBlacklisted(): bool {
    return $this->errorType === 'blacklisted';
  }

  public function isInvalidNumber(): bool {
    return $this->errorType === 'invalid_number';
  }
}
