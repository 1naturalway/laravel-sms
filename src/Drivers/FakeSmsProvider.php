<?php

declare(strict_types=1);

namespace OneNaturalWay\Sms\Drivers;

use Closure;
use OneNaturalWay\Sms\Contracts\SmsProvider;
use OneNaturalWay\Sms\SmsResult;
use PHPUnit\Framework\Assert;

class FakeSmsProvider implements SmsProvider {
  /**
   * All captured messages.
   *
   * @var list<SmsResult>
   */
  protected array $sent = [];

  /**
   * Capture the SMS in memory instead of sending it.
   *
   * @param  string  $to  The recipient phone number.
   * @param  string  $body  The message body.
   * @param  array<string, mixed>  $options  Optional parameters.
   */
  public function send(string $to, string $body, array $options = []): SmsResult {
    $result = new SmsResult(
        messageId: 'fake_' . uniqid(),
        status: 'sent',
        to: $to,
        from: $options['from'] ?? null,
        body: $body,
    );

    $this->sent[] = $result;

    return $result;
  }

    /**
     * Assert that a message was sent to the given number, optionally containing the given text.
     */
  public function assertSentTo(string $number, ?string $bodyContains = null): void {
    $found = array_filter($this->sent, function (SmsResult $result) use ($number, $bodyContains) {
      if ($result->to !== $number) {
            return false;
      }

      if ($bodyContains !== null && !str_contains((string) $result->body, $bodyContains)) {
              return false;
      }

              return true;
    });

    Assert::assertNotEmpty(
        $found,
        $bodyContains ? "No SMS was sent to [{$number}] containing [{$bodyContains}]." : "No SMS was sent to [{$number}]."
    );
  }

    /**
     * Assert that no messages were sent.
     */
  public function assertNothingSent(): void {
    Assert::assertEmpty($this->sent, 'SMS messages were sent unexpectedly.');
  }

    /**
     * Assert the total number of sent messages.
     */
  public function assertSentCount(int $expected): void {
    Assert::assertCount($expected, $this->sent, "Expected {$expected} SMS messages, got " . count($this->sent) . '.');
  }

    /**
     * Assert that a message was sent to the given number matching the callback.
     *
     * @param  string  $number  The recipient phone number.
     * @param  Closure(SmsResult): bool  $callback  Receives the SmsResult, returns true if it matches.
     */
  public function assertSentToWithBody(string $number, Closure $callback): void {
    $found = array_filter($this->sent, function (SmsResult $result) use ($number, $callback) {
        return $result->to === $number && $callback($result);
    });

    Assert::assertNotEmpty(
        $found,
        "No SMS sent to [{$number}] matched the given callback."
    );
  }

    /**
     * Get all captured messages.
     *
     * @return list<SmsResult>
     */
  public function getSent(): array {
    return $this->sent;
  }
}
