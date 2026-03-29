<?php

declare(strict_types=1);

namespace OneNaturalWay\Sms;

use Illuminate\Support\ServiceProvider;

class SmsServiceProvider extends ServiceProvider
{
    /**
     * Register the SMS manager as a singleton.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/sms.php', 'sms');

        $this->app->singleton('sms', function ($app) {
            return new SmsManager($app);
        });

        $this->app->alias('sms', SmsManager::class);
    }

    /**
     * Boot the package: publish config.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/sms.php' => config_path('sms.php'),
            ], 'sms-config');
        }
    }
}
