<?php

namespace Tests\Unit\V7;

use PHPUnit\Framework\TestCase;

class StoxNamespaceValidationTest extends TestCase
{
    public function test_v7_migrations_create_only_stox_prefixed_tables(): void
    {
        $migration = file_get_contents(__DIR__.'/../../../database/migrations/2026_09_12_100001_v7_stox_fundamentals_and_ml.php');
        preg_match_all("/Schema::create\\('([^']+)'/", (string) $migration, $matches);

        $this->assertNotEmpty($matches[1]);
        foreach ($matches[1] as $table) {
            $this->assertStringStartsWith('stox_', $table);
        }
    }
}
