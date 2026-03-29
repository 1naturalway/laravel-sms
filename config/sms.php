<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default SMS Driver
    |--------------------------------------------------------------------------
    |
    | The default driver used for sending SMS messages. Set to 'null' by
    | default so no messages are sent unless explicitly configured.
    |
    */

    'default' => env('SMS_DRIVER', 'null'),

    /*
    |--------------------------------------------------------------------------
    | Default "From" Number
    |--------------------------------------------------------------------------
    |
    | The default phone number that SMS messages are sent from. Individual
    | drivers may override this with their own 'from' configuration.
    |
    */

    'from' => env('SMS_FROM'),

    /*
    |--------------------------------------------------------------------------
    | SMS Drivers
    |--------------------------------------------------------------------------
    |
    | Configuration for each available SMS driver. Add your credentials
    | in the .env file — never commit secrets to version control.
    |
    */

    'drivers' => [
        'twilio' => [
            'sid'   => env('TWILIO_SID'),
            'token' => env('TWILIO_TOKEN'),
            'from'  => env('TWILIO_FROM'),
        ],

        'smstrap' => [
            'url'     => env('SMSTRAP_URL'),
            'api_key' => env('SMSTRAP_API_KEY'),
            'project' => env('SMSTRAP_PROJECT'),
        ],

        'log' => [
            'channel'  => env('SMS_LOG_CHANNEL', 'stack'),
            'table'    => env('SMS_LOG_TABLE', 'sms_log'),
            'database' => true,
        ],

        'null' => [],
    ],
];
