<?php

namespace Tests\Feature;

use App\Mail\NotificationDeliveryMail;
use App\Models\NotificationChannelSetting;
use App\Models\User;
use App\Services\Notification\LegacyTelegramChannelMigrator;
use App\Services\VpsHealth\VpsHealthNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class VpsHealthNotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $temporaryStateDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporaryStateDirectory = sys_get_temp_dir().'/vps-health-test-'.bin2hex(random_bytes(8));
        mkdir($this->temporaryStateDirectory, 0700, true);
        config(['vps_health.state_dir' => $this->temporaryStateDirectory]);
        config(['mail.default' => 'smtp']);
        Mail::fake();
        Http::fake();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->temporaryStateDirectory.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->temporaryStateDirectory);
        parent::tearDown();
    }

    public function test_sends_to_stox_admin_emails_and_only_enabled_verified_admin_telegram_channels(): void
    {
        $firstAdmin = User::factory()->admin()->create(['email' => 'first-admin@example.com']);
        $secondAdmin = User::factory()->admin()->create(['email' => 'second-admin@example.com']);
        $member = User::factory()->create(['email' => 'member@example.com']);

        NotificationChannelSetting::query()->create([
            'user_id' => $firstAdmin->id,
            'channel' => 'telegram',
            'enabled' => true,
            'verified_at' => now(),
            'configuration' => ['bot_token' => 'admin-bot-secret', 'chat_id' => 'admin-chat'],
        ]);
        NotificationChannelSetting::query()->create([
            'user_id' => $secondAdmin->id,
            'channel' => 'telegram',
            'enabled' => false,
            'verified_at' => now(),
            'configuration' => ['bot_token' => 'disabled-bot', 'chat_id' => 'disabled-chat'],
        ]);
        NotificationChannelSetting::query()->create([
            'user_id' => $member->id,
            'channel' => 'telegram',
            'enabled' => true,
            'verified_at' => now(),
            'configuration' => ['bot_token' => 'member-bot', 'chat_id' => 'member-chat'],
        ]);

        $result = app(VpsHealthNotificationService::class)->send('Health alert', 'Queue is full.', true);

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['email_recipients']);
        $this->assertSame(1, $result['telegram_recipients']);
        $this->assertFalse($result['cache_used']);
        Mail::assertSentTimes(NotificationDeliveryMail::class, 2);
        Mail::assertSentTo('first-admin@example.com', NotificationDeliveryMail::class);
        Mail::assertSentTo('second-admin@example.com', NotificationDeliveryMail::class);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'admin-bot-secret')
            && $request['chat_id'] === 'admin-chat');
        Http::assertSentCount(1);
        $this->assertFileExists($this->temporaryStateDirectory.'/notification-targets.enc');
        $this->assertSame(0600, fileperms($this->temporaryStateDirectory.'/notification-targets.enc') & 0777);
        $encrypted = file_get_contents($this->temporaryStateDirectory.'/notification-targets.enc');
        $this->assertStringNotContainsString('first-admin@example.com', $encrypted);
        $this->assertStringNotContainsString('admin-bot-secret', $encrypted);
        $decoded = json_decode(Crypt::decryptString($encrypted), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['first-admin@example.com', 'second-admin@example.com'], $decoded['targets']['emails']);
    }

    public function test_uses_recent_encrypted_cache_when_database_target_refresh_fails(): void
    {
        User::factory()->admin()->create(['email' => 'cached-admin@example.com']);
        app(VpsHealthNotificationService::class)->send('Initial digest', 'Cache this address.');
        Mail::fake();

        $migrator = Mockery::mock(LegacyTelegramChannelMigrator::class);
        $migrator->shouldReceive('migrateIfUnambiguous')->once()->andThrow(new RuntimeException('database unavailable'));
        $result = (new VpsHealthNotificationService($migrator))->send('Alert', 'Use cached target.', true);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['cache_used']);
        $this->assertSame(1, $result['email_recipients']);
        Mail::assertSentTimes(NotificationDeliveryMail::class, 1);
        Mail::assertSent(NotificationDeliveryMail::class, fn ($mail) => $mail->hasTo('cached-admin@example.com'));
    }
}
