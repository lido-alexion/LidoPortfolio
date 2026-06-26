<?php

namespace Tests\Feature;

use App\Support\ApiErrorMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AlertPolicySchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_assert_schema_ready_passes_after_migrations(): void
    {
        ApiErrorMessage::assertAlertPolicySchemaReady();
        $this->assertTrue(Schema::hasTable('portfolio_alert_policies'));
        $this->assertTrue(Schema::hasColumn('portfolio_alerts', 'instance_key'));
    }
}
