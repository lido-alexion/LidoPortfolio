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

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context) {
                return $level === 'error'
                    && str_contains($message, 'NSE failed')
                    && ($context['request_id'] ?? null) === 'test-req-123'
                    && ($context['category'] ?? null) === 'Provider';
            });
        Log::shouldReceive('channel')->with('provider')->andReturn($channel);

        $logger = new PortfolioLoggerService($settings);
        $logger->provider('error', 'NSE failed', ['symbol' => 'INFY']);
    }

    public function test_log_frontend_payload_sanitizes_secrets(): void
    {
        $settings = Mockery::mock(SettingsService::class);
        $settings->shouldReceive('get')->with('backend_log_level', 'info')->andReturn('debug');

        $channel = Mockery::mock();
        $channel->shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context) {
                $encoded = json_encode($context);

                return $level === 'error'
                    && ! str_contains($encoded, 'super-secret')
                    && str_contains($encoded, '[REDACTED]');
            });
        Log::shouldReceive('channel')->with('frontend')->andReturn($channel);

        $logger = new PortfolioLoggerService($settings);
        $logger->logFrontendPayload([
            'level' => 'error',
            'message' => 'token=abc123 failed',
            'extra' => ['password' => 'super-secret'],
        ]);
    }
}
