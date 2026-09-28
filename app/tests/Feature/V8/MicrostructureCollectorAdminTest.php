<?php

namespace Tests\Feature\V8;

use App\Models\MicrostructureCollectorState;
use App\Models\User;
use App\Services\Microstructure\MicrostructureCollectorControlService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class MicrostructureCollectorAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->withHeaders([
            'Origin' => 'http://localhost',
            'Referer' => 'http://localhost',
        ]);
        config([
            'microstructure_collector.enabled' => true,
            'microstructure_collector.command_file' => storage_path('framework/testing/microstructure-command.json'),
            'microstructure_collector.heartbeat_file' => storage_path('framework/testing/microstructure-heartbeat.json'),
            'microstructure_collector.data_root' => storage_path('framework/testing/microstructure-data'),
        ]);
        @mkdir(storage_path('framework/testing/microstructure-data'), 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink(storage_path('framework/testing/microstructure-command.json'));
        @unlink(storage_path('framework/testing/microstructure-heartbeat.json'));
        parent::tearDown();
    }

    protected function makeAdmin(): User
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-'.Str::random(8).'@example.com',
            'password' => Hash::make('password123'),
        ]);
        $user->is_admin = true;
        $user->save();
        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertOk();

        return $user->fresh();
    }

    public function test_admin_status_and_stop_hold(): void
    {
        $this->makeAdmin();

        $this->getJson('/api/microstructure-collector/status')
            ->assertOk()
            ->assertJsonPath('data.enabled', true);

        $this->postJson('/api/microstructure-collector/command', ['command' => 'stop'])
            ->assertOk()
            ->assertJsonPath('data.manual_hold', true);

        $this->assertTrue(MicrostructureCollectorState::current()->manual_hold);
        $this->assertFileExists(config('microstructure_collector.command_file'));
        $payload = json_decode(File::get(config('microstructure_collector.command_file')), true);
        $this->assertSame('stop', $payload['command']);
    }

    public function test_kite_login_signals_start_when_not_on_hold(): void
    {
        $user = User::query()->create([
            'name' => 'Operator',
            'email' => 'ops-'.Str::random(8).'@example.com',
            'password' => Hash::make('password123'),
        ]);
        config(['microstructure_collector.kite_user_id' => $user->id]);

        app(MicrostructureCollectorControlService::class)->signalAutoStartAfterKiteLogin($user);

        $payload = json_decode(File::get(config('microstructure_collector.command_file')), true);
        $this->assertSame('start', $payload['command']);
        $this->assertSame('kite_auth', $payload['source']);
    }

    public function test_kite_login_does_not_start_when_manual_hold(): void
    {
        $state = MicrostructureCollectorState::current();
        $state->manual_hold = true;
        $state->save();

        $user = User::query()->create([
            'name' => 'Operator',
            'email' => 'ops-'.Str::random(8).'@example.com',
            'password' => Hash::make('password123'),
        ]);
        config(['microstructure_collector.kite_user_id' => $user->id]);

        app(MicrostructureCollectorControlService::class)->signalAutoStartAfterKiteLogin($user);

        $this->assertFileDoesNotExist(config('microstructure_collector.command_file'));
    }

    public function test_non_admin_cannot_issue_collector_command(): void
    {
        $user = User::query()->create([
            'name' => 'Investor',
            'email' => 'inv-'.Str::random(8).'@example.com',
            'password' => Hash::make('password123'),
        ]);
        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertOk();

        $this->postJson('/api/microstructure-collector/command', ['command' => 'start'])
            ->assertForbidden();
    }
}
