<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminGlobalSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        $this->withHeaders([
            'Origin' => 'http://localhost',
            'Referer' => 'http://localhost',
        ]);
    }

    protected function makeUser(bool $isAdmin = false): User
    {
        $user = User::query()->create([
            'name' => 'Settings User',
            'email' => 'settings-'.Str::random(8).'@example.com',
            'password' => Hash::make('password123'),
        ]);

        if ($isAdmin) {
            $user->is_admin = true;
            $user->save();
        }

        return $user->fresh();
    }

    protected function actingAsUser(User $user): self
    {
        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertOk();

        return $this;
    }

    public function test_non_admin_settings_response_omits_global_keys(): void
    {
        Setting::setValue('cron_time', '17:00');
        Setting::setValue('nse_retry_count', '5');

        $user = $this->makeUser(false);
        $this->actingAsUser($user)
            ->getJson('/api/settings')
            ->assertOk()
            ->assertJsonMissingPath('data.cron_time')
            ->assertJsonMissingPath('data.nse_retry_count')
            ->assertJsonMissingPath('data.fee_components')
            ->assertJsonPath('data.cron_timezone', 'Asia/Kolkata');
    }

    public function test_admin_settings_response_includes_global_keys(): void
    {
        Setting::setValue('cron_time', '17:00');

        $user = $this->makeUser(true);
        $this->actingAsUser($user)
            ->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('data.cron_time', '17:00')
            ->assertJsonStructure(['data' => ['fee_components', 'sync_log_latest_runs']]);
    }

    public function test_non_admin_cannot_update_global_settings(): void
    {
        $user = $this->makeUser(false);

        $this->actingAsUser($user)
            ->putJson('/api/settings', [
                'cron_time' => '06:00',
                'default_stoploss_percent' => '9',
            ])
            ->assertForbidden();

        $this->assertSame('18:30', Setting::getValue('cron_time', '18:30'));
    }

    public function test_admin_can_update_global_settings(): void
    {
        $user = $this->makeUser(true);

        $this->actingAsUser($user)
            ->putJson('/api/settings', [
                'cron_time' => '06:15',
                'nse_retry_count' => '4',
            ])
            ->assertOk()
            ->assertJsonPath('data.cron_time', '06:15')
            ->assertJsonPath('data.nse_retry_count', '4');
    }

    public function test_non_admin_cannot_run_daily_sync(): void
    {
        $user = $this->makeUser(false);

        $this->actingAsUser($user)
            ->postJson('/api/sync/daily', ['force' => true])
            ->assertForbidden();
    }

    public function test_admin_can_run_daily_sync(): void
    {
        $user = $this->makeUser(true);

        $this->actingAsUser($user)
            ->postJson('/api/sync/daily', ['force' => true])
            ->assertOk();
    }

    public function test_non_admin_cannot_run_backfill_sync(): void
    {
        $user = $this->makeUser(false);
        $stock = \App\Models\Stock::query()->create([
            'symbol' => 'BFTEST',
            'exchange' => 'NSE',
            'name' => 'Backfill Test',
            'is_active' => true,
        ]);

        $this->actingAsUser($user)
            ->postJson("/api/sync/backfill/{$stock->id}")
            ->assertForbidden();
    }
}
