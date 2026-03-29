<?php

declare(strict_types=1);

namespace OneNaturalWay\Sms\Drivers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OneNaturalWay\Sms\Contracts\SmsProvider;

class LogSmsProvider implements SmsProvider
{
    public function __construct(
        protected string $channel,
        protected string $table,
        protected bool $database,
        protected string $from,
    ) {}

    /**
     * Log an SMS to the configured log channel and optionally to the database.
     *
     * @param  string  $to  The recipient phone number.
     * @param  string  $body  The message body.
     * @param  array<string, mixed>  $options  Optional parameters (e.g., 'from').
     */
    public function send(string $to, string $body, array $options = []): void
    {
        $from = $options['from'] ?? $this->from;

        Log::channel($this->channel)->info('SMS sent', [
            'to'   => $to,
            'from' => $from,
            'body' => $body,
        ]);

        if ($this->database) {
            DB::table($this->table)->insert([
                'to'         => $to,
                'from'       => $from,
                'body'       => $body,
                'options'    => ! empty($options) ? json_encode($options) : null,
                'logged_at'  => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
