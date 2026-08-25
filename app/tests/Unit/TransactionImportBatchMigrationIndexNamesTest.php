<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guard against MySQL 64-char identifier failures on production migrate.
 */
class TransactionImportBatchMigrationIndexNamesTest extends TestCase
{
    #[Test]
    public function import_batch_migration_uses_short_explicit_index_names(): void
    {
        $path = database_path('migrations/2026_08_09_180001_create_portfolio_transaction_import_batches_tables.php');
        $this->assertFileExists($path);

        $source = file_get_contents($path);
        $this->assertIsString($source);

        $this->assertStringContainsString("'ptib_profile_batch_idx'", $source);
        $this->assertStringContainsString("'ptibi_batch_row_uq'", $source);
        $this->assertStringContainsString("'ptibi_batch_sort_idx'", $source);

        foreach (['ptib_profile_batch_idx', 'ptibi_batch_row_uq', 'ptibi_batch_sort_idx'] as $name) {
            $this->assertLessThanOrEqual(64, strlen($name), "Index name {$name} must fit MySQL identifier limit");
        }

        $this->assertStringNotContainsString(
            "->index(['batch_id', 'sort_order'])",
            $source,
            'Unnamed composite index regenerates an overlong MySQL identifier'
        );
    }
}
