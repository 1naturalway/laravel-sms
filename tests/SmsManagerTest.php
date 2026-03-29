<?php

declare(strict_types=1);

namespace OneNaturalWay\Sms\Tests;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use OneNaturalWay\Sms\Contracts\SmsProvider;
use OneNaturalWay\Sms\Drivers\FakeSmsProvider;
use OneNaturalWay\Sms\Drivers\LogSmsProvider;
use OneNaturalWay\Sms\Drivers\NullSmsProvider;
use OneNaturalWay\Sms\Drivers\SmsTrapProvider;
use OneNaturalWay\Sms\Facades\Sms;
use OneNaturalWay\Sms\SmsManager;
use OneNaturalWay\Sms\SmsServiceProvider;
use Orchestra\Testbench\TestCase;

class SmsManagerTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [SmsServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['Sms' => Sms::class];
    }

    // -------------------------------------------------------
    // Default Driver Resolution
    // -------------------------------------------------------

    public function test_default_config_resolves_to_null_driver(): void
    {
        $driver = Sms::driver();

        $this->assertInstanceOf(NullSmsProvider::class, $driver);
    }

    public function test_null_driver_does_nothing(): void
    {
        $driver = new NullSmsProvider();

        // Should not throw
        $driver->send('+15551234567', 'Hello');

        $this->assertTrue(true);
    }

    // -------------------------------------------------------
    // Driver Resolution by Name
    // -------------------------------------------------------

    public function test_resolves_log_driver(): void
    {
        $driver = Sms::driver('log');

        $this->assertInstanceOf(LogSmsProvider::class, $driver);
    }

    public function test_resolves_smstrap_driver(): void
    {
        $this->app['config']->set('sms.drivers.smstrap', [
            'url'     => 'https://trap.test',
            'api_key' => 'test-key',
            'project' => 'test-project',
        ]);

        $driver = Sms::driver('smstrap');

        $this->assertInstanceOf(SmsTrapProvider::class, $driver);
    }

    public function test_unsupported_driver_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SMS driver [nonexistent] is not supported.');

        Sms::driver('nonexistent');
    }

    public function test_driver_instances_are_cached(): void
    {
        $first = Sms::driver('null');
        $second = Sms::driver('null');

        $this->assertSame($first, $second);
    }

    // -------------------------------------------------------
    // On-the-fly Driver Switching
    // -------------------------------------------------------

    public function test_on_the_fly_driver_switching(): void
    {
        $null = Sms::driver('null');
        $log = Sms::driver('log');

        $this->assertInstanceOf(NullSmsProvider::class, $null);
        $this->assertInstanceOf(LogSmsProvider::class, $log);
        $this->assertNotSame($null, $log);
    }

    // -------------------------------------------------------
    // Extend / Custom Drivers
    // -------------------------------------------------------

    public function test_extend_registers_custom_driver(): void
    {
        $customProvider = new class implements SmsProvider {
            public bool $sent = false;

            public function send(string $to, string $body, array $options = []): void
            {
                $this->sent = true;
            }
        };

        /** @var SmsManager $manager */
        $manager = $this->app->make('sms');
        $manager->extend('custom', function ($app, $config) use ($customProvider) {
            return $customProvider;
        });

        $resolved = $manager->driver('custom');
        $resolved->send('+15551234567', 'test');

        $this->assertSame($customProvider, $resolved);
        $this->assertTrue($customProvider->sent);
    }

    // -------------------------------------------------------
    // SmsTrap Driver
    // -------------------------------------------------------

    public function test_smstrap_sends_http_post(): void
    {
        Http::fake([
            'trap.test/*' => Http::response([], 200),
        ]);

        $this->app['config']->set('sms.drivers.smstrap', [
            'url'     => 'https://trap.test',
            'api_key' => 'secret-key',
            'project' => 'my-project',
        ]);
        $this->app['config']->set('sms.from', '+15550001111');

        // Purge cached drivers to pick up new config
        $this->app->make('sms')->purge();

        Sms::driver('smstrap')->send('+15559876543', 'UAT test message');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://trap.test/messages'
                && $request->hasHeader('Authorization', 'Bearer secret-key')
                && $request['to'] === '+15559876543'
                && $request['body'] === 'UAT test message'
                && $request['from'] === '+15550001111'
                && $request['project'] === 'my-project'
                && isset($request['sent_at']);
        });
    }

    // -------------------------------------------------------
    // Log Driver
    // -------------------------------------------------------

    public function test_log_driver_writes_log_entry(): void
    {
        Log::shouldReceive('channel')
            ->with('stack')
            ->once()
            ->andReturnSelf();

        Log::shouldReceive('info')
            ->with('SMS sent', \Mockery::on(function ($context) {
                return $context['to'] === '+15551234567'
                    && $context['body'] === 'Log test';
            }))
            ->once();

        $driver = new LogSmsProvider(
            channel: 'stack',
            from: '+15550000000',
        );

        $driver->send('+15551234567', 'Log test');
    }

    public function test_log_driver_respects_from_override(): void
    {
        Log::shouldReceive('channel')
            ->with('stack')
            ->once()
            ->andReturnSelf();

        Log::shouldReceive('info')
            ->with('SMS sent', \Mockery::on(function ($context) {
                return $context['from'] === '+15559999999';
            }))
            ->once();

        $driver = new LogSmsProvider(
            channel: 'stack',
            from: '+15550000000',
        );

        $driver->send('+15551234567', 'Override test', ['from' => '+15559999999']);
    }

    // -------------------------------------------------------
    // Twilio Driver (mocked)
    // -------------------------------------------------------

    public function test_twilio_driver_calls_messages_create(): void
    {
        $messagesMock = \Mockery::mock();
        $messagesMock->shouldReceive('create')
            ->once()
            ->with('+15551234567', \Mockery::on(function ($params) {
                return $params['from'] === '+15550001111'
                    && $params['body'] === 'Twilio test';
            }));

        $clientMock = \Mockery::mock(\Twilio\Rest\Client::class);
        $clientMock->messages = $messagesMock;

        $driver = \Mockery::mock(
            \OneNaturalWay\Sms\Drivers\TwilioSmsProvider::class,
            ['sid', 'token', '+15550001111']
        )->makePartial()->shouldAllowMockingProtectedMethods();

        $driver->shouldReceive('createClient')
            ->once()
            ->andReturn($clientMock);

        $driver->send('+15551234567', 'Twilio test');
    }

    public function test_twilio_driver_passes_media_url(): void
    {
        $messagesMock = \Mockery::mock();
        $messagesMock->shouldReceive('create')
            ->once()
            ->with('+15551234567', \Mockery::on(function ($params) {
                return isset($params['mediaUrl'])
                    && $params['mediaUrl'] === ['https://example.com/image.jpg'];
            }));

        $clientMock = \Mockery::mock(\Twilio\Rest\Client::class);
        $clientMock->messages = $messagesMock;

        $driver = \Mockery::mock(
            \OneNaturalWay\Sms\Drivers\TwilioSmsProvider::class,
            ['sid', 'token', '+15550001111']
        )->makePartial()->shouldAllowMockingProtectedMethods();

        $driver->shouldReceive('createClient')
            ->once()
            ->andReturn($clientMock);

        $driver->send('+15551234567', 'MMS test', [
            'mediaUrl' => 'https://example.com/image.jpg',
        ]);
    }

    // -------------------------------------------------------
    // Fake SMS Provider
    // -------------------------------------------------------

    public function test_fake_provider_captures_messages(): void
    {
        $fake = new FakeSmsProvider();

        $fake->send('+15551111111', 'Hello');
        $fake->send('+15552222222', 'World');

        $this->assertCount(2, $fake->getSent());
    }

    public function test_fake_assert_sent_to(): void
    {
        $fake = new FakeSmsProvider();
        $fake->send('+15551111111', 'Hello there');

        $fake->assertSentTo('+15551111111');
        $fake->assertSentTo('+15551111111', 'Hello');
    }

    public function test_fake_assert_nothing_sent(): void
    {
        $fake = new FakeSmsProvider();

        $fake->assertNothingSent();
    }

    public function test_fake_assert_sent_count(): void
    {
        $fake = new FakeSmsProvider();
        $fake->send('+15551111111', 'One');
        $fake->send('+15552222222', 'Two');
        $fake->send('+15553333333', 'Three');

        $fake->assertSentCount(3);
    }

    public function test_fake_assert_sent_to_with_body(): void
    {
        $fake = new FakeSmsProvider();
        $fake->send('+15551111111', 'Your code is 123456');

        $fake->assertSentToWithBody('+15551111111', function (string $body) {
            return str_contains($body, '123456');
        });
    }

    // -------------------------------------------------------
    // Sms::fake() Facade Method
    // -------------------------------------------------------

    public function test_facade_fake_swaps_to_fake_provider(): void
    {
        $fake = Sms::fake();

        $this->assertInstanceOf(FakeSmsProvider::class, $fake);

        Sms::send('+15551234567', 'Faked message');

        $fake->assertSentTo('+15551234567', 'Faked');
        $fake->assertSentCount(1);
    }

    public function test_facade_fake_replaces_default_driver(): void
    {
        $this->app['config']->set('sms.default', 'log');

        $fake = Sms::fake();

        Sms::send('+15551234567', 'Should be faked');

        $fake->assertSentTo('+15551234567');
    }

    // -------------------------------------------------------
    // Manager send() proxies to default driver
    // -------------------------------------------------------

    public function test_manager_send_proxies_to_default_driver(): void
    {
        $fake = Sms::fake();

        /** @var SmsManager $manager */
        $manager = $this->app->make('sms');
        $manager->send('+15551234567', 'Proxied');

        $fake->assertSentTo('+15551234567', 'Proxied');
    }
}
