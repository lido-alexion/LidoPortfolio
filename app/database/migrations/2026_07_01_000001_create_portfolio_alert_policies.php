<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->ensurePoliciesTable();
        $this->ensureAlertColumns();
    }

    public function down(): void
    {
        if (Schema::hasTable('portfolio_alerts')) {
            Schema::table('portfolio_alerts', function (Blueprint $table) {
                foreach (['context_json', 'action_suggested', 'condition_display', 'instance_key', 'alert_policy_id'] as $column) {
                    if (! Schema::hasColumn('portfolio_alerts', $column)) {
                        continue;
                    }
                    if ($column === 'alert_policy_id') {
                        $this->dropForeignIfExists($table, 'alert_policy_id');
                    }
                    if ($column === 'instance_key') {
                        $this->dropIndexIfExists('portfolio_alerts', 'pa_profile_instance_idx');
                    }
                    $table->dropColumn($column);
                }
            });
        }

        Schema::dropIfExists('portfolio_alert_policies');
    }

    protected function ensurePoliciesTable(): void
    {
        if (Schema::hasTable('portfolio_alert_policies')) {
            return;
        }

        Schema::create('portfolio_alert_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('portfolio_profiles')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('stock_universe', 32)->default('holdings');
            $table->string('condition_column', 64);
            $table->string('condition_operator', 16);
            $table->string('compare_type', 16);
            $table->string('compare_column', 64)->nullable();
            $table->text('compare_formula')->nullable();
            $table->decimal('compare_constant', 18, 6)->nullable();
            $table->text('message_template');
            $table->string('action_type', 32);
            $table->string('action_custom', 255)->nullable();
            $table->json('context_columns')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            $table->unique(['profile_id', 'name'], 'pap_profile_name_uq');
        });
    }

    protected function ensureAlertColumns(): void
    {
        if (! Schema::hasTable('portfolio_alerts')) {
            return;
        }

        if (! Schema::hasColumn('portfolio_alerts', 'alert_policy_id')) {
            Schema::table('portfolio_alerts', function (Blueprint $table) {
                $table->foreignId('alert_policy_id')->nullable()->after('stock_id')
                    ->constrained('portfolio_alert_policies')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('portfolio_alerts', 'instance_key')) {
            Schema::table('portfolio_alerts', function (Blueprint $table) {
                $table->string('instance_key', 191)->nullable()->after('alert_type');
            });
        }

        $this->ensureIndex(
            'portfolio_alerts',
            'pa_profile_instance_idx',
            ['profile_id', 'instance_key'],
            unique: false,
        );

        if (! Schema::hasColumn('portfolio_alerts', 'condition_display')) {
            Schema::table('portfolio_alerts', function (Blueprint $table) {
                $table->text('condition_display')->nullable()->after('message');
            });
        }

        if (! Schema::hasColumn('portfolio_alerts', 'action_suggested')) {
            Schema::table('portfolio_alerts', function (Blueprint $table) {
                $table->string('action_suggested', 255)->nullable()->after('condition_display');
            });
        }

        if (! Schema::hasColumn('portfolio_alerts', 'context_json')) {
            Schema::table('portfolio_alerts', function (Blueprint $table) {
                $table->json('context_json')->nullable()->after('action_suggested');
            });
        }
    }

    protected function ensureIndex(
        string $table,
        string $indexName,
        array $columns,
        bool $unique = false,
    ): void {
        if ($this->indexExists($table, $indexName)) {
            return;
        }

        try {
            Schema::table($table, function (Blueprint $blueprint) use ($columns, $indexName, $unique) {
                if ($unique) {
                    $blueprint->unique($columns, $indexName);
                } else {
                    $blueprint->index($columns, $indexName);
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
            // ignore
        }
    }

    protected function dropIndexIfExists(string $table, string $indexName): void
    {
        try {
            Schema::table($table, function (Blueprint $blueprint) use ($indexName) {
                $blueprint->dropIndex($indexName);
            });
        } catch (\Throwable) {
            // ignore
        }
    }
};
