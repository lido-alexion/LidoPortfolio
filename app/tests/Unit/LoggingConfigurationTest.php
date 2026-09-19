<?php

namespace Tests\Unit;

use Tests\TestCase;

class LoggingConfigurationTest extends TestCase
{
    public function test_file_backed_logging_channels_use_shared_group_write_permissions(): void
    {
        foreach (['single', 'daily', 'frontend', 'provider', 'scheduler', 'emergency'] as $channel) {
            $this->assertSame(0664, config("logging.channels.{$channel}.permission"), $channel);
        }
    }
}
