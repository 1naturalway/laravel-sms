<?php

declare(strict_types=1);

namespace OneNaturalWay\Sms;

use Closure;
use InvalidArgumentException;
use OneNaturalWay\Sms\Contracts\SmsProvider;
use OneNaturalWay\Sms\Drivers\FakeSmsProvider;
use OneNaturalWay\Sms\Drivers\LogSmsProvider;
use OneNaturalWay\Sms\Drivers\NullSmsProvider;
use OneNaturalWay\Sms\Drivers\TwilioSmsProvider;

class SmsManager implements SmsProvider {
  /**
   * Resolved driver instances, keyed by driver name.
   *
   * @var array<string, SmsProvider>
   */
  protected array $drivers = [];

  /**
   * Custom driver creators registered via extend().
   *
   * @var array<string, Closure>
   */
  protected array $customCreators = [];

  public function __construct(protected \Illuminate\Contracts\Foundation\Application $app) {
  }

  /**
   * Get an SMS driver instance by name.
   *
   * @param  string|null  $name  The driver name, or null for the default.
   */
  public function driver(?string $name = null): SmsProvider {
    $name ??= $this->getDefaultDriver();

    return $this->drivers[$name] ??= $this->resolve($name);
  }

  /**
   * Get the default driver name from config.
   */
  public function getDefaultDriver(): string {
    return $this->app['config']['sms.default'] ?? 'null';
  }

  /**
   * Resolve a driver instance by name.
   */
  protected function resolve(string $name): SmsProvider {
    $config = $this->getDriverConfig($name);

    if (isset($this->customCreators[$name])) {
      return ($this->customCreators[$name])($this->app, $config);
    }

    return match ($name) {
      'twilio'  => $this->createTwilioDriver($config),
      'log'     => $this->createLogDriver($config),
      'null'    => new NullSmsProvider(),
      default   => throw new InvalidArgumentException("SMS driver [{$name}] is not supported."),
    };
  }

  /**
   * Get the configuration for a specific driver.
   *
   * @return array<string, mixed>
   */
  protected function getDriverConfig(string $name): array {
    return $this->app['config']["sms.drivers.{$name}"] ?? [];
  }

  /**
   * Create an instance of the Twilio driver.
   *
   * @param  array<string, mixed>  $config
   */
  protected function createTwilioDriver(array $config): TwilioSmsProvider {
    return new TwilioSmsProvider(
        sid: $config['sid'] ?? '',
        token: $config['token'] ?? '',
        from: $config['from'] ?? $this->app['config']['sms.from'] ?? '',
    );
  }

  /**
   * Create an instance of the Log driver.
   *
   * @param  array<string, mixed>  $config
   */
  protected function createLogDriver(array $config): LogSmsProvider {
    return new LogSmsProvider(
        channel: $config['channel'] ?? 'stack',
        from: $this->app['config']['sms.from'] ?? '',
    );
  }

  /**
   * Register a custom driver creator.
   *
   * @param  string  $name  The driver name.
   * @param  Closure  $creator  A closure that receives ($app, $config) and returns an SmsProvider.
   */
  public function extend(string $name, Closure $creator): static {
    $this->customCreators[$name] = $creator;

    return $this;
  }

  /**
   * Send an SMS using the default driver.
   *
   * @param  string  $to  The recipient phone number.
   * @param  string  $body  The message body.
   * @param  array<string, mixed>  $options  Optional parameters.
   */
  public function send(string $to, string $body, array $options = []): SmsResult {
    return $this->driver()->send($to, $body, $options);
  }

  /**
   * Swap the manager's resolved instance to a FakeSmsProvider for testing.
   */
  public function fake(): FakeSmsProvider {
    $fake = new FakeSmsProvider();

    $this->app->instance('sms', $this);

    // Replace the default driver with the fake
    $this->drivers[$this->getDefaultDriver()] = $fake;

    return $fake;
  }

  /**
   * Forget all resolved driver instances.
   */
  public function purge(): void {
    $this->drivers = [];
  }
}
