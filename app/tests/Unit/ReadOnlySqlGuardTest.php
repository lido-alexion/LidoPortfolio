<?php

namespace Tests\Unit;

use App\Support\ReadOnlySqlGuard;
use PHPUnit\Framework\TestCase;

class ReadOnlySqlGuardTest extends TestCase
{
    public function test_allows_select_show_describe_explain(): void
    {
        $this->assertTrue(ReadOnlySqlGuard::isAllowed('SELECT 1'));
        $this->assertTrue(ReadOnlySqlGuard::isAllowed('select * from portfolio_settings limit 5'));
        $this->assertTrue(ReadOnlySqlGuard::isAllowed('SHOW TABLES'));
        $this->assertTrue(ReadOnlySqlGuard::isAllowed('DESCRIBE portfolio_stocks'));
        $this->assertTrue(ReadOnlySqlGuard::isAllowed('EXPLAIN SELECT id FROM portfolio_stocks'));
    }

    public function test_rejects_writes_and_multiple_statements(): void
    {
        $this->assertFalse(ReadOnlySqlGuard::isAllowed('UPDATE portfolio_settings SET setting_value = 1'));
        $this->assertFalse(ReadOnlySqlGuard::isAllowed('DELETE FROM portfolio_settings'));
        $this->assertFalse(ReadOnlySqlGuard::isAllowed('SELECT 1; DROP TABLE portfolio_stocks'));
        $this->assertFalse(ReadOnlySqlGuard::isAllowed('SELECT * FROM portfolio_stocks FOR UPDATE'));
    }
}
