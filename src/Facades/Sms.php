<?php

declare(strict_types=1);

namespace OneNaturalWay\Sms\Facades;

use Illuminate\Support\Facades\Facade;
use OneNaturalWay\Sms\Drivers\FakeSmsProvider;
use OneNaturalWay\Sms\SmsManager;
use OneNaturalWay\Sms\SmsResult;

/**
 * @method static SmsResult send(string $to, string $body, array<string, mixed> $options = [])
 * @method static \OneNaturalWay\Sms\Contracts\SmsProvider driver(?string $name = null)
 * @method static SmsManager extend(string $name, \Closure $creator)
 * @method static FakeSmsProvider fake()
 *
 * @see \OneNaturalWay\Sms\SmsManager
 */
class Sms extends Facade {
  /**
   * Get the registered name of the component.
   */
  protected static function getFacadeAccessor(): string {
    return 'sms';
  }
}
