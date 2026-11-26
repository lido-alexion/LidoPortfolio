<?php

// Creates deterministic, disposable browser-test identities in the isolated E2E DB.
// Refuse to bootstrap if called outside the dedicated harness configuration.
$envPath = getenv('LIDO_ENV_PATH') ?: '';
$database = getenv('DB_DATABASE') ?: '';
$tempRoot = realpath(dirname($envPath));
if (($tempRoot === false)
    || ! str_contains(basename($tempRoot), 'stox-auth-e2e.')
    || ! str_starts_with($database, $tempRoot.DIRECTORY_SEPARATOR)
    || ! str_ends_with($database, '.sqlite')
    || (getenv('DB_CONNECTION') ?: '') !== 'sqlite'
    || (getenv('APP_ENV') ?: '') !== 'testing'
    || (getenv('APP_DEBUG') ?: '') !== 'false') {
    fwrite(STDERR, "Refusing auth fixture outside its disposable SQLite environment.\n");
    exit(64);
}
require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\PortfolioProfile;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

if (($argv[1] ?? null) === 'expire-session') {
    $sessionId = $argv[2] ?? '';
    if (! preg_match('/^[A-Za-z0-9,-]{20,128}$/', $sessionId)) {
        fwrite(STDERR, "Invalid disposable session identifier.\n");
        exit(64);
    }

    $updated = Illuminate\Support\Facades\DB::table(config('session.table', 'sessions'))
        ->where('id', $sessionId)
        ->update(['last_activity' => now()->subMinutes(config('session.lifetime') + 1)->timestamp]);
    exit($updated === 1 ? 0 : 1);
}

foreach (['investor-a', 'investor-b', 'admin'] as $name) {
    $admin = $name === 'admin';
    $displayName = match ($name) {
        'investor-a' => 'Investor A',
        'investor-b' => 'Investor B',
        default => 'Admin',
    };
    $user = User::query()->create([
        'name' => $name,
        'email' => $name.'@auth-e2e.example.test',
        'password' => Hash::make('Disposable-E2E-Password-418!'),
    ]);
    $user->forceFill(['is_admin' => $admin])->save();

    if (! $admin) {
        PortfolioProfile::query()->create([
            'user_id' => $user->id,
            'name' => $displayName.' Default',
            'is_default' => true,
            'portfolio_type' => PortfolioProfile::TYPE_PAPER,
            'simulation_state' => PortfolioProfile::SIMULATION_ACTIVE,
            'simulation_price_method' => 'next_open',
            'simulation_evidence' => ['provenance' => 'disposable_auth_e2e', 'starting_cash' => 10000],
        ]);
        PortfolioProfile::query()->create([
            'user_id' => $user->id,
            'name' => $displayName.' Secondary',
            'is_default' => false,
            'portfolio_type' => PortfolioProfile::TYPE_PAPER,
            'simulation_state' => PortfolioProfile::SIMULATION_ACTIVE,
            'simulation_price_method' => 'next_open',
            'simulation_evidence' => ['provenance' => 'disposable_auth_e2e', 'starting_cash' => 10000],
        ]);
    }
}
