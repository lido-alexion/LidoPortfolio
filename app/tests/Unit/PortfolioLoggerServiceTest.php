<?php

namespace Tests\Unit;

use App\Services\PortfolioLoggerService;
use App\Services\SettingsService;
use App\Support\RequestContext;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class PortfolioLoggerServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        RequestContext::clear();
        parent::tearDown();
    }

    public function test_should_log_respects_backend_log_level_from_settings(): void
    {
        $settings = Mockery::mock(SettingsService::class);
        $settings->shouldReceive('get')->with('backend_log_level', 'info')->andReturn('warning');

        $logger = new PortfolioLoggerService($settings);

        $this->assertFalse($logger->shouldLog('info'));
        $this->assertTrue($logger->shouldLog('warning'));
        $this->assertTrue($logger->shouldLog('error'));
        $this->assertTrue($logger->shouldLog('warn'));
    }

    public function test_provider_logs_include_request_id_context(): void
    {
        RequestContext::setRequestId('test-req-123');

        $settings = Mockery::mock(SettingsService::class);
        $settings->shouldReceive('get')->with('backend_log_level', 'info')->andReturn('debug');

        $logged = false;
        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context) use (&$logged) {
                $logged = $level === 'error'
                    && str_contains($message, 'NSE failed')
                    && ($context['request_id'] ?? null) === 'test-req-123'
                    && ($context['category'] ?? null) === 'Provider';

                return $logged;
            });
        Log::shouldReceive('channel')->with('provider')->andReturn($channel);

        $logger = new PortfolioLoggerService($settings);
        $logger->provider('error', 'NSE failed', ['symbol' => 'INFY']);

        $this->assertTrue($logged);
    }

    public function test_log_frontend_payload_sanitizes_secrets(): void
    {
        $settings = Mockery::mock(SettingsService::class);
        $settings->shouldReceive('get')->with('backend_log_level', 'info')->andReturn('debug');

        $sanitized = false;
        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context) use (&$sanitized) {
                $encoded = json_encode($context);

                $sanitized = $level === 'error'
                    && ! str_contains($encoded, 'super-secret')
                    && str_contains($encoded, '[REDACTED]');

                return $sanitized;
            });
        Log::shouldReceive('channel')->with('frontend')->andReturn($channel);

        $logger = new PortfolioLoggerService($settings);
        $logger->logFrontendPayload([
            'level' => 'error',
            'message' => 'token=abc123 failed',
            'extra' => ['password' => 'super-secret'],
        ]);

        $this->assertTrue($sanitized);
    }

    public function test_event_includes_stable_event_and_engine_context(): void
    {
        $settings = Mockery::mock(SettingsService::class);
        $settings->shouldReceive('get')->with('backend_log_level', 'info')->andReturn('debug');

        $logged = false;
        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context) use (&$logged) {
                $logged = $level === 'info'
                    && $message === 'Pipeline completed'
                    && ($context['event'] ?? null) === 'pipeline.completed'
                    && ($context['engine'] ?? null) === 'DailyDecisionPipeline'
                    && ($context['category'] ?? null) === 'DailyDecisionPipeline'
                    && ($context['profile_id'] ?? null) === 9
                    && ($context['pipeline_run_id'] ?? null) === 41
                    && ($context['dataset_version'] ?? null) === 'ds-test';

                return $logged;
            });
        Log::shouldReceive('channel')->with('daily')->andReturn($channel);

        $logger = new PortfolioLoggerService($settings);
        $logger->event('DailyDecisionPipeline', 'pipeline.completed', 'info', 'Pipeline completed', [
            'profile_id' => 9,
            'pipeline_run_id' => 41,
            'dataset_version' => 'ds-test',
        ]);

        $this->assertTrue($logged);
    }

    public function test_event_redacts_nested_tokens_and_does_not_emit_secrets(): void
    {
        $settings = Mockery::mock(SettingsService::class);
        $settings->shouldReceive('get')->with('backend_log_level', 'info')->andReturn('debug');

        $sanitized = false;
        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context) use (&$sanitized) {
                $encoded = json_encode($context);
                $sanitized = $level === 'error'
                    && $message === 'Delivery exception'
                    && ($context['event'] ?? null) === 'notification.delivery_failed'
                    && ($context['bot_token'] ?? null) === '[REDACTED]'
                    && ($context['nested']['access_token'] ?? null) === '[REDACTED]'
                    && ($context['nested']['totp_code'] ?? null) === '[REDACTED]'
                    && ($context['nested']['recovery_codes'] ?? null) === '[REDACTED]'
                    && ($context['nested']['recommendation_id'] ?? null) === 12
                    && is_string($context['detail'] ?? null)
                    && str_contains($context['detail'], 'token=[REDACTED]')
                    && ! str_contains($encoded, 'super-secret-bot')
                    && ! str_contains($encoded, 'atk-live')
                    && ! str_contains($encoded, 'Bearer xyz')
                    && ! str_contains($encoded, 'abc123-live')
                    && ! str_contains($encoded, '123456')
                    && ! str_contains($encoded, 'ABCD');

                return $sanitized;
            });
        Log::shouldReceive('channel')->with('daily')->andReturn($channel);

        $logger = new PortfolioLoggerService($settings);
        $logger->event('NotificationEngine', 'notification.delivery_failed', 'error', 'Delivery exception', [
            'profile_id' => 1,
            'bot_token' => 'super-secret-bot',
            'detail' => 'token=abc123-live failed',
            'nested' => [
                'access_token' => 'atk-live',
                'authorization' => 'Bearer xyz',
                'recommendation_id' => 12,
                'totp_code' => '123456',
                'recovery_codes' => ['ABCD'],
            ],
        ]);

        $this->assertTrue($sanitized);
    }
}
