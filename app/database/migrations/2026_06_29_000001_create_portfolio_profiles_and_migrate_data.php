<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<int, int> */
    protected array $userToProfile = [];

    public function up(): void
    {
        $this->ensureProfilesTable();
        $this->ensureDefaultProfiles();
        $this->ensureProfileSettingsTable();
        $this->migrateUserSettings();

        $this->migrateTransactions();
        $this->migrateHoldings();
        $this->migrateSnapshots();
        $this->migrateAlerts();
    }

    public function down(): void
    {
        throw new \RuntimeException('This migration cannot be reversed automatically.');
    }

    protected function ensureProfilesTable(): void
    {
        if (Schema::hasTable('portfolio_profiles')) {
            return;
        }

        Schema::create('portfolio_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('portfolio_users')->cascadeOnDelete();
            $table->string('name', 120);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'is_default'], 'pp_user_default_idx');
        });
    }

    protected function ensureDefaultProfiles(): void
    {
        $this->loadUserToProfileMap();

        foreach (DB::table('portfolio_users')->orderBy('id')->get() as $user) {
            if (isset($this->userToProfile[$user->id])) {
                continue;
            }

            $this->userToProfile[$user->id] = DB::table('portfolio_profiles')->insertGetId([
                'user_id' => $user->id,
                'name' => 'Default',
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    protected function ensureProfileSettingsTable(): void
    {
        if (Schema::hasTable('portfolio_profile_settings')) {
            return;
        }

        Schema::create('portfolio_profile_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('portfolio_profiles')->cascadeOnDelete();
            $table->string('setting_key', 64);
            $table->text('setting_value')->nullable();
            $table->timestamp('updated_at')->useCurrent();

            $table->unique(['profile_id', 'setting_key'], 'pps_profile_key_uq');
        });
    }

    protected function migrateUserSettings(): void
    {
        if (! Schema::hasTable('portfolio_user_settings')) {
            return;
        }

        $this->loadUserToProfileMap();

        foreach (DB::table('portfolio_user_settings')->orderBy('id')->get() as $row) {
            $profileId = $this->userToProfile[$row->user_id] ?? null;
            if ($profileId === null) {
                continue;
            }

            $exists = DB::table('portfolio_profile_settings')
                ->where('profile_id', $profileId)
                ->where('setting_key', $row->setting_key)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('portfolio_profile_settings')->insert([
                'profile_id' => $profileId,
                'setting_key' => $row->setting_key,
                'setting_value' => $row->setting_value,
                'updated_at' => $row->updated_at ?? now(),
            ]);
        }

        Schema::drop('portfolio_user_settings');
    }

    protected function migrateTransactions(): void
    {
        if (! Schema::hasTable('portfolio_transactions')) {
            return;
        }

        $this->loadUserToProfileMap();

        if (! Schema::hasColumn('portfolio_transactions', 'profile_id')) {
            Schema::table('portfolio_transactions', function (Blueprint $table) {
                $table->foreignId('profile_id')->nullable()->after('id')->constrained('portfolio_profiles')->cascadeOnDelete();
            });
        }

        if (Schema::hasColumn('portfolio_transactions', 'user_id')) {
            $this->backfillProfileId('portfolio_transactions');

            Schema::table('portfolio_transactions', function (Blueprint $table) {
                $this->dropForeignIfExists($table, 'user_id');
                $this->dropIndexIfExists($table, ['user_id', 'stock_id', 'transaction_date']);
                $table->dropColumn('user_id');
            });
        }

        $this->ensureIndex(
            'portfolio_transactions',
            'ppt_prof_stock_date_idx',
            ['profile_id', 'stock_id', 'transaction_date'],
            unique: false,
        );
    }

    protected function migrateHoldings(): void
    {
        if (! Schema::hasTable('portfolio_holdings')) {
            return;
        }

        $this->loadUserToProfileMap();

        if (! Schema::hasColumn('portfolio_holdings', 'profile_id')) {
            Schema::table('portfolio_holdings', function (Blueprint $table) {
                $table->foreignId('profile_id')->nullable()->after('id')->constrained('portfolio_profiles')->cascadeOnDelete();
            });
        }

        if (Schema::hasColumn('portfolio_holdings', 'user_id')) {
            $this->backfillProfileId('portfolio_holdings');

            Schema::table('portfolio_holdings', function (Blueprint $table) {
                $this->dropForeignIfExists($table, 'user_id');
                $this->dropIndexIfExists($table, ['user_id', 'stock_id'], unique: true);
                $table->dropColumn('user_id');
            });
        }

        $this->ensureIndex(
            'portfolio_holdings',
            'pph_prof_stock_uq',
            ['profile_id', 'stock_id'],
            unique: true,
        );
    }

    protected function migrateSnapshots(): void
    {
        if (! Schema::hasTable('portfolio_portfolio_snapshots')) {
            return;
        }

        $this->loadUserToProfileMap();

        if (! Schema::hasColumn('portfolio_portfolio_snapshots', 'profile_id')) {
            Schema::table('portfolio_portfolio_snapshots', function (Blueprint $table) {
                $table->foreignId('profile_id')->nullable()->after('id')->constrained('portfolio_profiles')->cascadeOnDelete();
            });
        }

        if (Schema::hasColumn('portfolio_portfolio_snapshots', 'user_id')) {
            $this->backfillProfileId('portfolio_portfolio_snapshots');

            Schema::table('portfolio_portfolio_snapshots', function (Blueprint $table) {
                $this->dropForeignIfExists($table, 'user_id');
                $this->dropIndexIfExists($table, ['user_id', 'snapshot_date'], unique: true);
                $table->dropColumn('user_id');
            });
        }

        $this->ensureIndex(
            'portfolio_portfolio_snapshots',
            'pps_prof_snap_uq',
            ['profile_id', 'snapshot_date'],
            unique: true,
        );
    }

    protected function migrateAlerts(): void
    {
        if (! Schema::hasTable('portfolio_alerts')) {
            return;
        }

        $this->loadUserToProfileMap();

        if (! Schema::hasColumn('portfolio_alerts', 'profile_id')) {
            Schema::table('portfolio_alerts', function (Blueprint $table) {
                $table->foreignId('profile_id')->nullable()->after('id')->constrained('portfolio_profiles')->cascadeOnDelete();
            });
        }

        if (Schema::hasColumn('portfolio_alerts', 'user_id')) {
            $this->backfillProfileId('portfolio_alerts');

            Schema::table('portfolio_alerts', function (Blueprint $table) {
                $this->dropForeignIfExists($table, 'user_id');
                $this->dropIndexIfExists($table, ['user_id', 'stock_id'], unique: false);
                $table->dropColumn('user_id');
            });
        }

        $this->ensureIndex(
            'portfolio_alerts',
            'ppa_prof_stock_idx',
            ['profile_id', 'stock_id'],
            unique: false,
        );
    }

    protected function loadUserToProfileMap(): void
    {
        if ($this->userToProfile !== []) {
            return;
        }

        foreach (DB::table('portfolio_profiles')->where('is_default', true)->orderBy('id')->get() as $profile) {
            $this->userToProfile[(int) $profile->user_id] = (int) $profile->id;
        }

        foreach (DB::table('portfolio_profiles')->orderBy('id')->get() as $profile) {
            $userId = (int) $profile->user_id;
            if (! isset($this->userToProfile[$userId])) {
                $this->userToProfile[$userId] = (int) $profile->id;
            }
        }
    }

    protected function backfillProfileId(string $tableName): void
    {
        $this->loadUserToProfileMap();

        $query = DB::table($tableName)->orderBy('id');
        if (Schema::hasColumn($tableName, 'user_id')) {
            $query->whereNotNull('user_id');
        } else {
            $query->whereNull('profile_id');
        }

        foreach ($query->get() as $row) {
            if (isset($row->profile_id) && $row->profile_id !== null) {
                continue;
            }

            $userId = $row->user_id ?? null;
            if ($userId === null) {
                continue;
            }

            $profileId = $this->userToProfile[(int) $userId] ?? null;
            if ($profileId !== null) {
                DB::table($tableName)->where('id', $row->id)->update(['profile_id' => $profileId]);
            }
        }
    }

    protected function ensureIndex(string $table, string $name, array $columns, bool $unique): void
    {
        if ($this->indexExists($table, $name)) {
            return;
        }

        try {
            Schema::table($table, function (Blueprint $blueprint) use ($name, $columns, $unique) {
                if ($unique) {
                    $blueprint->unique($columns, $name);
                } else {
                    $blueprint->index($columns, $name);
                }
            });
        } catch (\Throwable $e) {
            if ($this->isDuplicateIndexError($e)) {
                return;
            }

            throw $e;
        }
    }

    protected function indexExists(string $table, string $name): bool
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'sqlite') {
            return false;
        }

        $database = $connection->getDatabaseName();

        $result = $connection->select(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
            [$database, $table, $name],
        );

        return $result !== [];
    }

    protected function isDuplicateIndexError(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'duplicate key name')
            || str_contains($message, 'already exists');
    }

    protected function dropForeignIfExists(Blueprint $table, string $column): void
    {
        try {
            $table->dropForeign([$column]);
        } catch (\Throwable) {
            // FK may already be dropped on a resumed migration.
        }
    }

    protected function dropIndexIfExists(Blueprint $table, array $columns, bool $unique = false): void
    {
        try {
            if ($unique) {
                $table->dropUnique($columns);
            } else {
                $table->dropIndex($columns);
            }
        } catch (\Throwable) {
            // Index may already be dropped on a resumed migration.
        }
    }
};
