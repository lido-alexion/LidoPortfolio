<?php

namespace Tests\Unit;

use App\Support\StockValidationUserMessage;
use Tests\TestCase;

class StockValidationUserMessageTest extends TestCase
{
    public function test_deduplicates_and_summarizes_provider_errors(): void
    {
        $message = StockValidationUserMessage::fromErrors([
            'NSE: HTTP 403',
            'NSE: HTTP 403',
            'NSE: HTTP 403',
            'Yahoo: HTTP 404',
            'Alpha Vantage API key not configured.',
        ], 'BS', 'NSE');

        $this->assertStringContainsString('BS', $message);
        $this->assertStringContainsString('not found', strtolower($message));
        $this->assertStringNotContainsString('NSE: HTTP 403', $message);
    }

    public function test_invalid_symbol_message_when_yahoo_404(): void
    {
        $message = StockValidationUserMessage::fromErrors([
            'NSE: HTTP 403 (NSE blocked automated access — session or anti-bot)',
            'Yahoo: HTTP 404',
        ], 'ACTUAAS', 'NSE');

        $this->assertStringContainsString('ACTUAAS', $message);
        $this->assertStringContainsString('not found', strtolower($message));
    }
}
