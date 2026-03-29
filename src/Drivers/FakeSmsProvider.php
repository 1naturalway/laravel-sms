<?php

declare(strict_types=1);

namespace OneNaturalWay\Sms\Drivers;

use Closure;
use OneNaturalWay\Sms\Contracts\SmsProvider;
use PHPUnit\Framework\Assert;

class FakeSmsProvider implements SmsProvider
{
    /**
     * All captured messages.
     *
     * @var list<array{to: string, body: string, options: array<string, mixed>}>
     */
    protected array $sent = [];

    /**
     * Capture the SMS in memory instead of sending it.
     *
     * @param  string  $to  The recipient phone number.
     * @param  string  $body  The message body.
     * @param  array<string, mixed>  $options  Optional parameters.
     */
    public function send(string $to, string $body, array $options = []): void
    {
        $this->sent[] = [
            'to'      => $to,
            'body'    => $body,
            'options' => $options,
        ];
    }

    /**
     * Assert that a message was sent to the given number, optionally containing the given text.
     */
    public function assertSentTo(string $number, ?string $bodyContains = null): void
    {
        $found = array_filter($this->sent, function (array $message) use ($number, $bodyContains) {
            if ($message['to'] !== $number) {
                return false;
            }

            if ($bodyContains !== null && ! str_contains($message['body'], $bodyContains)) {
                return false;
            }

            return true;
        });

        Assert::assertNotEmpty(
            $found,
            $bodyContains
                ? "No SMS was sent to [{$number}] containing [{$bodyContains}]."
                : "No SMS was sent to [{$number}]."
        );
    }

    /**
     * Assert that no messages were sent.
     */
    public function assertNothingSent(): void
    {
        Assert::assertEmpty($this->sent, 'SMS messages were sent unexpectedly.');
    }

    /**
     * Assert the total number of sent messages.
     */
    public function assertSentCount(int $expected): void
    {
        Assert::assertCount($expected, $this->sent, "Expected {$expected} SMS messages, got " . count($this->sent) . '.');
    }

    /**
     * Assert that a message was sent to the given number with a body matching the callback.
     *
     * @param  string  $number  The recipient phone number.
     * @param  Closure(string): bool  $callback  Receives the body string, returns true if it matches.
     */
    public function assertSentToWithBody(string $number, Closure $callback): void
    {
        $found = array_filter($this->sent, function (array $message) use ($number, $callback) {
            return $message['to'] === $number && $callback($message['body']);
        });

        Assert::assertNotEmpty(
            $found,
            "No SMS sent to [{$number}] matched the given callback."
        );
    }

    /**
     * Get all captured messages.
     *
     * @return list<array{to: string, body: string, options: array<string, mixed>}>
     */
    public function getSent(): array
    {
        return $this->sent;
    }
}
