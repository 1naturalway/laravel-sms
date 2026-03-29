# Laravel SMS

A driver-based SMS abstraction layer for Laravel, following the same architectural pattern as Laravel's built-in Mail system. Swap between Twilio, a UAT trap, logging, or a null driver with a single config change.

## Installation

```bash
composer require 1naturalway/laravel-sms
```

Publish the config file:

```bash
php artisan vendor:publish --tag=sms-config
```

## Configuration

Set the driver and credentials in your `.env` file:

```dotenv
# Choose your driver: twilio, smstrap, log, null
SMS_DRIVER=null

# Default "from" number (used by drivers that don't define their own)
SMS_FROM=+15551234567
```

### Twilio

```dotenv
SMS_DRIVER=twilio
TWILIO_SID=your-account-sid
TWILIO_TOKEN=your-auth-token
TWILIO_FROM=+15551234567
```

Requires the Twilio SDK:

```bash
composer require twilio/sdk
```

### SmsTrap (UAT / Testing)

```dotenv
SMS_DRIVER=smstrap
SMSTRAP_URL=https://your-smstrap-instance.com
SMSTRAP_API_KEY=your-api-key
SMSTRAP_PROJECT=your-project-name
```

SmsTrap sends all messages to your internal trap service via HTTP POST, authenticated with a Bearer token. Use this in UAT environments to verify SMS sends without hitting real carrier networks.

### Log

```dotenv
SMS_DRIVER=log
SMS_LOG_CHANNEL=stack
```

Writes every SMS to a Laravel log channel. Useful for debugging and verifying sends in staging or CI.

### Null

```dotenv
SMS_DRIVER=null
```

Silently discards all messages. This is the default — no accidental sends in development.

## Usage

### Basic Send

```php
use OneNaturalWay\Sms\Facades\Sms;

$result = Sms::send('+15559876543', 'Your verification code is 123456');

if ($result->successful()) {
    // Store $result->messageId for tracking
    // Check $result->status
}
```

Every `send()` call returns an `SmsResult` DTO with these properties:

| Property | Type | Description |
|----------|------|-------------|
| `messageId` | `?string` | Provider message ID (e.g., Twilio SID) |
| `status` | `?string` | Provider status (e.g., `queued`, `captured`, `logged`) |
| `to` | `?string` | Recipient number |
| `from` | `?string` | Sender number |
| `body` | `?string` | Message body |
| `mediaCount` | `?int` | Number of media attachments (Twilio) |
| `raw` | `array` | Full raw response from the provider |

Use `$result->successful()` to check if the message was accepted — returns `true` when `messageId` is present. The null driver always returns an unsuccessful result since nothing was sent.

### With Options

```php
$result = Sms::send('+15559876543', 'Hello!', [
    'from'     => '+15550001111',  // Override the default "from" number
    'mediaUrl' => 'https://example.com/image.jpg',  // Twilio MMS
]);
```

### Driver Switching

```php
// Use a specific driver for this call
$result = Sms::driver('log')->send('+15559876543', 'This goes to the log');

// Use the default driver
$result = Sms::send('+15559876543', 'This uses the configured default');
```

### Dependency Injection

```php
use OneNaturalWay\Sms\SmsManager;

class NotificationService
{
    public function __construct(
        protected SmsManager $sms,
    ) {}

    public function sendWelcome(string $phone): void
    {
        $result = $this->sms->send($phone, 'Welcome to our service!');

        if ($result->successful()) {
            logger()->info('Welcome SMS queued', ['sid' => $result->messageId]);
        }
    }
}
```

## Testing

The package ships with a `FakeSmsProvider` that captures all messages in memory, just like Laravel's `Mail::fake()`.

```php
use OneNaturalWay\Sms\Facades\Sms;

public function test_sends_verification_sms(): void
{
    $fake = Sms::fake();

    // ... trigger your code that sends SMS ...

    $fake->assertSentTo('+15559876543');
    $fake->assertSentTo('+15559876543', 'verification code');
    $fake->assertSentCount(1);
}

public function test_no_sms_sent_on_invalid_input(): void
{
    $fake = Sms::fake();

    // ... trigger code that should NOT send SMS ...

    $fake->assertNothingSent();
}

public function test_sms_body_matches_pattern(): void
{
    $fake = Sms::fake();

    // ... trigger your code ...

    $fake->assertSentToWithBody('+15559876543', function (SmsResult $result) {
        return preg_match('/\d{6}/', $result->body) === 1;
    });
}
```

### Available Assertions

| Method | Description |
|--------|-------------|
| `assertSentTo($number, $bodyContains?)` | A message was sent to this number, optionally containing text |
| `assertNothingSent()` | No messages were sent |
| `assertSentCount($n)` | Exactly N messages were sent |
| `assertSentToWithBody($number, $callback)` | A message to this number passes the callback (receives `SmsResult`) |
| `getSent()` | Returns an array of `SmsResult` objects |

## Custom Drivers

Register a custom driver using `extend()`, typically in a service provider's `boot()` method:

```php
use OneNaturalWay\Sms\Facades\Sms;
use OneNaturalWay\Sms\Contracts\SmsProvider;

Sms::extend('vonage', function ($app, $config) {
    return new VonageSmsProvider(
        apiKey: $config['api_key'],
        apiSecret: $config['api_secret'],
        from: $config['from'] ?? $app['config']['sms.from'],
    );
});
```

Add the driver config to `config/sms.php`:

```php
'drivers' => [
    // ... existing drivers ...
    'vonage' => [
        'api_key'    => env('VONAGE_API_KEY'),
        'api_secret' => env('VONAGE_API_SECRET'),
        'from'       => env('VONAGE_FROM'),
    ],
],
```

Your custom provider must implement the `SmsProvider` interface:

```php
use OneNaturalWay\Sms\Contracts\SmsProvider;

use OneNaturalWay\Sms\SmsResult;

class VonageSmsProvider implements SmsProvider
{
    public function send(string $to, string $body, array $options = []): SmsResult
    {
        // Your implementation — return an SmsResult with the provider's response data
    }
}
```

## UAT Strategy

For UAT environments, we recommend combining the **SmsTrap** and **Log** drivers:

- **SmsTrap** sends all messages to an internal HTTP service where QA can inspect them without hitting real phone numbers. Set `SMS_DRIVER=smstrap` in your UAT `.env`.
- **Log** writes messages to your Laravel log files for easy inspection. Use `SMS_DRIVER=log` when you need to verify sends in CI or staging.
- **Null** (the default) ensures no SMS is sent in development or CI unless explicitly configured.

This layered approach guarantees:
1. Production uses Twilio (or your chosen carrier) via `SMS_DRIVER=twilio`
2. UAT uses SmsTrap to capture and inspect messages
3. Development and CI use `null` or `log` — zero accidental sends

## License

MIT
