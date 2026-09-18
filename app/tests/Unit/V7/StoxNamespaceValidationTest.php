<?php

namespace Tests\Unit\V7;

use PHPUnit\Framework\TestCase;

class StoxNamespaceValidationTest extends TestCase
{
    private const GOVERNANCE_CUTOFF = '2026_09_12_100001';

    public function test_all_governed_migrations_create_only_stox_prefixed_tables(): void
    {
        $files = glob(__DIR__.'/../../../database/migrations/*.php');
        sort($files);

        $governed = array_filter($files, fn (string $file): bool =>
            basename($file) >= self::GOVERNANCE_CUTOFF
        );
        $createdTables = [];

        foreach ($governed as $file) {
            $source = file_get_contents($file);
            $this->assertIsString($source);
            preg_match_all("/Schema::create\\('([^']+)'/", $source, $matches);
            $createdTables = [...$createdTables, ...$matches[1]];
        }

        $this->assertContains('stox_recommendation_reservation_events', $createdTables);
        $this->assertContains('stox_tos_recall_bridge_loan_returns', $createdTables);

        foreach ($createdTables as $table) {
            $this->assertStringStartsWith('stox_', $table);
        }
    }
}
