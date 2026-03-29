<?php

declare(strict_types=1);

namespace OneNaturalWay\Sms\Tests;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use OneNaturalWay\Sms\Contracts\SmsProvider;
use OneNaturalWay\Sms\Drivers\FakeSmsProvider;
use OneNaturalWay\Sms\Exceptions\SmsException;
use OneNaturalWay\Sms\Drivers\LogSmsProvider;
use OneNaturalWay\Sms\Drivers\NullSmsProvider;
use OneNaturalWay\Sms\Drivers\SmsTrapProvider;
use OneNaturalWay\Sms\Facades\Sms;
use OneNaturalWay\Sms\SmsManager;
use OneNaturalWay\Sms\SmsResult;
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
    // SmsResult DTO
    // -------------------------------------------------------

    public function test_sms_result_with_all_parameters(): void
    {
        $result = new SmsResult(
            messageId: 'msg_123',
            status: 'queued',
            to: '+15551234567',
            from: '+15550001111',
            body: 'Hello',
            mediaCount: 1,
            mediaUrls: ['https://api.twilio.com/media/123'],
            raw: ['sid' => 'msg_123'],
        );

        $this->assertSame('msg_123', $result->messageId);
        $this->assertSame('queued', $result->status);
        $this->assertSame('+15551234567', $result->to);
        $this->assertSame('+15550001111', $result->from);
        $this->assertSame('Hello', $result->body);
        $this->assertSame(1, $result->mediaCount);
        $this->assertSame(['https://api.twilio.com/media/123'], $result->mediaUrls);
        $this->assertSame(['sid' => 'msg_123'], $result->raw);
    }

    public function test_sms_result_with_defaults(): void
    {
        $result = new SmsResult();

        $this->assertNull($result->messageId);
        $this->assertNull($result->status);
        $this->assertNull($result->to);
        $this->assertNull($result->from);
        $this->assertNull($result->body);
        $this->assertNull($result->mediaCount);
        $this->assertSame([], $result->mediaUrls);
        $this->assertSame([], $result->raw);
    }

    public function test_sms_result_successful_with_message_id(): void
    {
        $result = new SmsResult(messageId: 'msg_123');

        $this->assertTrue($result->successful());
    }

    public function test_sms_result_not_successful_without_message_id(): void
    {
        $result = new SmsResult();

        $this->assertFalse($result->successful());
    }

    public function test_sms_result_has_media_with_count(): void
    {
        $result = new SmsResult(mediaCount: 2, mediaUrls: ['https://example.com/a', 'https://example.com/b']);

        $this->assertTrue($result->hasMedia());
    }

    public function test_sms_result_has_media_without_count(): void
    {
        $result = new SmsResult();

        $this->assertFalse($result->hasMedia());
    }

    public function test_sms_result_has_media_with_zero_count(): void
    {
        $result = new SmsResult(mediaCount: 0);

        $this->assertFalse($result->hasMedia());
    }

    // -------------------------------------------------------
    // Default Driver Resolution
    // -------------------------------------------------------

    public function test_default_config_resolves_to_null_driver(): void
    {
        $driver = Sms::driver();

        $this->assertInstanceOf(NullSmsProvider::class, $driver);
    }

    public function test_null_driver_returns_unsuccessful_result(): void
    {
        $driver = new NullSmsProvider();

        $result = $driver->send('+15551234567', 'Hello');

        $this->assertInstanceOf(SmsResult::class, $result);
        $this->assertFalse($result->successful());
        $this->assertNull($result->messageId);
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

            public function send(string $to, string $body, array $options = []): SmsResult
            {
                $this->sent = true;

                return new SmsResult(messageId: 'custom_1', status: 'sent', to: $to, body: $body);
            }
        };

        /** @var SmsManager $manager */
        $manager = $this->app->make('sms');
        $manager->extend('custom', function ($app, $config) use ($customProvider) {
            return $customProvider;
        });

        $resolved = $manager->driver('custom');
        $result = $resolved->send('+15551234567', 'test');

        $this->assertSame($customProvider, $resolved);
        $this->assertTrue($customProvider->sent);
        $this->assertInstanceOf(SmsResult::class, $result);
        $this->assertTrue($result->successful());
    }

    // -------------------------------------------------------
    // SmsTrap Driver
    // -------------------------------------------------------

    public function test_smstrap_sends_http_post_and_returns_result(): void
    {
        Http::fake([
            'trap.test/*' => Http::response(['id' => 'trap_abc123'], 200),
        ]);

        $this->app['config']->set('sms.drivers.smstrap', [
            'url'     => 'https://trap.test',
            'api_key' => 'secret-key',
            'project' => 'my-project',
        ]);
        $this->app['config']->set('sms.from', '+15550001111');

        // Purge cached drivers to pick up new config
        $this->app->make('sms')->purge();

        $result = Sms::driver('smstrap')->send('+15559876543', 'UAT test message');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://trap.test/messages'
                && $request->hasHeader('Authorization', 'Bearer secret-key')
                && $request['to'] === '+15559876543'
                && $request['body'] === 'UAT test message'
                && $request['from'] === '+15550001111'
                && $request['project'] === 'my-project'
                && isset($request['sent_at']);
        });

        $this->assertInstanceOf(SmsResult::class, $result);
        $this->assertTrue($result->successful());
        $this->assertSame('trap_abc123', $result->messageId);
        $this->assertSame('captured', $result->status);
        $this->assertSame('+15559876543', $result->to);
        $this->assertSame('+15550001111', $result->from);
        $this->assertSame('UAT test message', $result->body);
        $this->assertSame(['id' => 'trap_abc123'], $result->raw);
    }

    public function test_smstrap_generates_id_when_response_has_none(): void
    {
        Http::fake([
            'trap.test/*' => Http::response([], 200),
        ]);

        $this->app['config']->set('sms.drivers.smstrap', [
            'url'     => 'https://trap.test',
            'api_key' => 'key',
            'project' => 'proj',
        ]);
        $this->app->make('sms')->purge();

        $result = Sms::driver('smstrap')->send('+15551234567', 'test');

        $this->assertTrue($result->successful());
        $this->assertStringStartsWith('smstrap_', $result->messageId);
    }

    // -------------------------------------------------------
    // Log Driver
    // -------------------------------------------------------

    public function test_log_driver_writes_log_and_returns_result(): void
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

        $result = $driver->send('+15551234567', 'Log test');

        $this->assertInstanceOf(SmsResult::class, $result);
        $this->assertTrue($result->successful());
        $this->assertStringStartsWith('log_', $result->messageId);
        $this->assertSame('logged', $result->status);
        $this->assertSame('+15551234567', $result->to);
        $this->assertSame('+15550000000', $result->from);
        $this->assertSame('Log test', $result->body);
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

        $result = $driver->send('+15551234567', 'Override test', ['from' => '+15559999999']);

        $this->assertSame('+15559999999', $result->from);
    }

    // -------------------------------------------------------
    // Twilio Driver (mocked)
    // -------------------------------------------------------

    public function test_twilio_driver_calls_messages_create_and_returns_result(): void
    {
        $twilioMessage = \Mockery::mock();
        $twilioMessage->sid = 'SM_abc123';
        $twilioMessage->status = 'queued';
        $twilioMessage->numMedia = '0';
        $twilioMessage->shouldReceive('toArray')
            ->once()
            ->andReturn(['sid' => 'SM_abc123', 'status' => 'queued']);

        $messagesMock = \Mockery::mock();
        $messagesMock->shouldReceive('create')
            ->once()
            ->with('+15551234567', \Mockery::on(function ($params) {
                return $params['from'] === '+15550001111'
                    && $params['body'] === 'Twilio test';
            }))
            ->andReturn($twilioMessage);

        $clientMock = \Mockery::mock(\Twilio\Rest\Client::class);
        $clientMock->messages = $messagesMock;

        $driver = \Mockery::mock(
            \OneNaturalWay\Sms\Drivers\TwilioSmsProvider::class,
            ['sid', 'token', '+15550001111']
        )->makePartial()->shouldAllowMockingProtectedMethods();

        $driver->shouldReceive('createClient')
            ->once()
            ->andReturn($clientMock);

        $result = $driver->send('+15551234567', 'Twilio test');

        $this->assertInstanceOf(SmsResult::class, $result);
        $this->assertTrue($result->successful());
        $this->assertSame('SM_abc123', $result->messageId);
        $this->assertSame('queued', $result->status);
        $this->assertSame('+15551234567', $result->to);
        $this->assertSame('+15550001111', $result->from);
        $this->assertSame('Twilio test', $result->body);
        $this->assertSame(0, $result->mediaCount);
        $this->assertSame(['sid' => 'SM_abc123', 'status' => 'queued'], $result->raw);
    }

    public function test_twilio_driver_passes_media_url_and_fetches_media_urls(): void
    {
        $twilioMessage = \Mockery::mock();
        $twilioMessage->sid = 'SM_mms456';
        $twilioMessage->status = 'queued';
        $twilioMessage->numMedia = '1';
        $twilioMessage->shouldReceive('toArray')
            ->once()
            ->andReturn(['sid' => 'SM_mms456']);

        $mediaItem = \Mockery::mock();
        $mediaItem->uri = '/2010-04-01/Accounts/AC123/Messages/SM_mms456/Media/ME123.json';

        $messageContextMock = \Mockery::mock();
        $messageContextMock->media = \Mockery::mock();
        $messageContextMock->media->shouldReceive('read')
            ->once()
            ->andReturn([$mediaItem]);

        $messagesMock = \Mockery::mock();
        $messagesMock->shouldReceive('create')
            ->once()
            ->with('+15551234567', \Mockery::on(function ($params) {
                return isset($params['mediaUrl'])
                    && $params['mediaUrl'] === ['https://example.com/image.jpg'];
            }))
            ->andReturn($twilioMessage);

        $clientMock = \Mockery::mock(\Twilio\Rest\Client::class);
        $clientMock->messages = $messagesMock;
        $clientMock->shouldReceive('messages')
            ->with('SM_mms456')
            ->once()
            ->andReturn($messageContextMock);

        $driver = \Mockery::mock(
            \OneNaturalWay\Sms\Drivers\TwilioSmsProvider::class,
            ['sid', 'token', '+15550001111']
        )->makePartial()->shouldAllowMockingProtectedMethods();

        $driver->shouldReceive('createClient')
            ->once()
            ->andReturn($clientMock);

        $result = $driver->send('+15551234567', 'MMS test', [
            'mediaUrl' => 'https://example.com/image.jpg',
        ]);

        $this->assertSame(1, $result->mediaCount);
        $this->assertTrue($result->hasMedia());
        $this->assertCount(1, $result->mediaUrls);
        $this->assertSame(
            'https://api.twilio.com/2010-04-01/Accounts/AC123/Messages/SM_mms456/Media/ME123',
            $result->mediaUrls[0]
        );
    }

    public function test_twilio_driver_sms_without_media_has_empty_media_urls(): void
    {
        $twilioMessage = \Mockery::mock();
        $twilioMessage->sid = 'SM_sms789';
        $twilioMessage->status = 'queued';
        $twilioMessage->numMedia = '0';
        $twilioMessage->shouldReceive('toArray')
            ->once()
            ->andReturn(['sid' => 'SM_sms789']);

        $messagesMock = \Mockery::mock();
        $messagesMock->shouldReceive('create')
            ->once()
            ->andReturn($twilioMessage);

        $clientMock = \Mockery::mock(\Twilio\Rest\Client::class);
        $clientMock->messages = $messagesMock;

        $driver = \Mockery::mock(
            \OneNaturalWay\Sms\Drivers\TwilioSmsProvider::class,
            ['sid', 'token', '+15550001111']
        )->makePartial()->shouldAllowMockingProtectedMethods();

        $driver->shouldReceive('createClient')
            ->once()
            ->andReturn($clientMock);

        $result = $driver->send('+15551234567', 'Plain SMS');

        $this->assertSame(0, $result->mediaCount);
        $this->assertFalse($result->hasMedia());
        $this->assertSame([], $result->mediaUrls);
    }

    // -------------------------------------------------------
    // SmsException
    // -------------------------------------------------------

    public function test_sms_exception_properties(): void
    {
        $previous = new \Exception('original');
        $exception = new SmsException(
            message: 'The number is blacklisted',
            errorType: 'blacklisted',
            phoneNumber: '+15551234567',
            providerCode: '21610',
            previous: $previous,
        );

        $this->assertSame('The number is blacklisted', $exception->getMessage());
        $this->assertSame('blacklisted', $exception->errorType);
        $this->assertSame('+15551234567', $exception->phoneNumber);
        $this->assertSame('21610', $exception->providerCode);
        $this->assertSame($previous, $exception->getPrevious());
    }

    public function test_sms_exception_is_blacklisted(): void
    {
        $exception = new SmsException(message: 'blocked', errorType: 'blacklisted');

        $this->assertTrue($exception->isBlacklisted());
        $this->assertFalse($exception->isInvalidNumber());
    }

    public function test_sms_exception_is_invalid_number(): void
    {
        $exception = new SmsException(message: 'bad number', errorType: 'invalid_number');

        $this->assertFalse($exception->isBlacklisted());
        $this->assertTrue($exception->isInvalidNumber());
    }

    public function test_sms_exception_provider_error(): void
    {
        $exception = new SmsException(message: 'something broke', errorType: 'provider_error');

        $this->assertFalse($exception->isBlacklisted());
        $this->assertFalse($exception->isInvalidNumber());
        $this->assertSame('provider_error', $exception->errorType);
    }

    public function test_sms_exception_extends_runtime_exception(): void
    {
        $exception = new SmsException(message: 'test', errorType: 'provider_error');

        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }

    // -------------------------------------------------------
    // Twilio Error Handling
    // -------------------------------------------------------

    public function test_twilio_throws_sms_exception_for_blacklisted_number(): void
    {
        $restException = new \Twilio\Exceptions\RestException(
            'The message From/To pair violates a blacklist rule', 21610, 403
        );

        $messagesMock = \Mockery::mock();
        $messagesMock->shouldReceive('create')->andThrow($restException);

        $clientMock = \Mockery::mock(\Twilio\Rest\Client::class);
        $clientMock->messages = $messagesMock;

        $driver = \Mockery::mock(
            \OneNaturalWay\Sms\Drivers\TwilioSmsProvider::class,
            ['sid', 'token', '+15550001111']
        )->makePartial()->shouldAllowMockingProtectedMethods();
        $driver->shouldReceive('createClient')->andReturn($clientMock);

        try {
            $driver->send('+15551234567', 'Hello');
            $this->fail('Expected SmsException was not thrown');
        } catch (SmsException $e) {
            $this->assertTrue($e->isBlacklisted());
            $this->assertFalse($e->isInvalidNumber());
            $this->assertSame('+15551234567', $e->phoneNumber);
            $this->assertSame('21610', $e->providerCode);
            $this->assertSame($restException, $e->getPrevious());
        }
    }

    public function test_twilio_throws_sms_exception_for_invalid_number(): void
    {
        $restException = new \Twilio\Exceptions\RestException(
            'The "To" number +1555bad is not a valid phone number', 21211, 400
        );

        $messagesMock = \Mockery::mock();
        $messagesMock->shouldReceive('create')->andThrow($restException);

        $clientMock = \Mockery::mock(\Twilio\Rest\Client::class);
        $clientMock->messages = $messagesMock;

        $driver = \Mockery::mock(
            \OneNaturalWay\Sms\Drivers\TwilioSmsProvider::class,
            ['sid', 'token', '+15550001111']
        )->makePartial()->shouldAllowMockingProtectedMethods();
        $driver->shouldReceive('createClient')->andReturn($clientMock);

        try {
            $driver->send('+1555bad', 'Hello');
            $this->fail('Expected SmsException was not thrown');
        } catch (SmsException $e) {
            $this->assertTrue($e->isInvalidNumber());
            $this->assertFalse($e->isBlacklisted());
            $this->assertSame('+1555bad', $e->phoneNumber);
            $this->assertSame('21211', $e->providerCode);
        }
    }

    public function test_twilio_throws_sms_exception_for_generic_rest_error(): void
    {
        $restException = new \Twilio\Exceptions\RestException(
            'Account suspended', 20003, 403
        );

        $messagesMock = \Mockery::mock();
        $messagesMock->shouldReceive('create')->andThrow($restException);

        $clientMock = \Mockery::mock(\Twilio\Rest\Client::class);
        $clientMock->messages = $messagesMock;

        $driver = \Mockery::mock(
            \OneNaturalWay\Sms\Drivers\TwilioSmsProvider::class,
            ['sid', 'token', '+15550001111']
        )->makePartial()->shouldAllowMockingProtectedMethods();
        $driver->shouldReceive('createClient')->andReturn($clientMock);

        try {
            $driver->send('+15551234567', 'Hello');
            $this->fail('Expected SmsException was not thrown');
        } catch (SmsException $e) {
            $this->assertSame('provider_error', $e->errorType);
            $this->assertFalse($e->isBlacklisted());
            $this->assertFalse($e->isInvalidNumber());
            $this->assertSame('20003', $e->providerCode);
            $this->assertSame('+15551234567', $e->phoneNumber);
        }
    }

    // -------------------------------------------------------
    // Fake SMS Provider
    // -------------------------------------------------------

    public function test_fake_provider_captures_messages_as_sms_results(): void
    {
        $fake = new FakeSmsProvider();

        $result1 = $fake->send('+15551111111', 'Hello');
        $result2 = $fake->send('+15552222222', 'World');

        $this->assertCount(2, $fake->getSent());
        $this->assertInstanceOf(SmsResult::class, $result1);
        $this->assertInstanceOf(SmsResult::class, $result2);
        $this->assertTrue($result1->successful());
        $this->assertStringStartsWith('fake_', $result1->messageId);
        $this->assertSame('sent', $result1->status);
        $this->assertContainsOnlyInstancesOf(SmsResult::class, $fake->getSent());
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

    public function test_fake_assert_sent_to_with_body_receives_sms_result(): void
    {
        $fake = new FakeSmsProvider();
        $fake->send('+15551111111', 'Your code is 123456');

        $fake->assertSentToWithBody('+15551111111', function (SmsResult $result) {
            return str_contains((string) $result->body, '123456');
        });
    }

    // -------------------------------------------------------
    // Sms::fake() Facade Method
    // -------------------------------------------------------

    public function test_facade_fake_swaps_to_fake_provider(): void
    {
        $fake = Sms::fake();

        $this->assertInstanceOf(FakeSmsProvider::class, $fake);

        $result = Sms::send('+15551234567', 'Faked message');

        $this->assertInstanceOf(SmsResult::class, $result);
        $this->assertTrue($result->successful());
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

    public function test_manager_send_proxies_and_returns_result(): void
    {
        $fake = Sms::fake();

        /** @var SmsManager $manager */
        $manager = $this->app->make('sms');
        $result = $manager->send('+15551234567', 'Proxied');

        $this->assertInstanceOf(SmsResult::class, $result);
        $fake->assertSentTo('+15551234567', 'Proxied');
    }
}
