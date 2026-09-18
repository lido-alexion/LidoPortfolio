<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guard against MySQL's 64-character identifier limit on production migrate.
 */
class HistoricalReplayLifecycleMigrationConstraintNamesTest extends TestCase
{
    #[Test]
    public function historical_replay_foreign_keys_use_short_explicit_names(): void
    {
        $path = database_path('migrations/2026_09_18_000001_historical_replay_lifecycle_evidence.php');
        $this->assertFileExists($path);

        $source = file_get_contents($path);
        $this->assertIsString($source);

        $names = [
            'prre_profile_fk',
            'prre_recommendation_fk',
            'ptblr_bridge_loan_fk',
        ];

        foreach ($names as $name) {
            $this->assertStringContainsString("'{$name}'", $source);
            $this->assertLessThanOrEqual(64, strlen($name), "Foreign-key name {$name} must fit MySQL identifier limit");
        }

        $this->assertStringNotContainsString(
            "->foreignId('recommendation_id')->constrained(",
            $source,
            'Recommendation foreign key must not rely on Laravel generated names'
        );
        $this->assertStringNotContainsString(
            "->foreignId('bridge_loan_id')->constrained(",
            $source,
            'Bridge-loan foreign key must not rely on Laravel generated names'
        );
    }
}
