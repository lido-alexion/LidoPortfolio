<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminInvestmentOwnershipAuditService
{
    /** @var array<int, string> */
    protected array $directInvestorTables = [
        'portfolio_broker_connections',
        'portfolio_execution_batches',
    ];

    /**
     * Detect FEAT-042 ownership conflicts without changing any production data.
     *
     * @return array<string, mixed>
     */
    public function audit(): array
    {
        $admins = DB::table('portfolio_users')
            ->where('is_admin', true)
            ->orderBy('id')
            ->get(['id', 'name', 'email']);
        $tables = collect(Schema::getTables())
            ->pluck('name')
            ->filter(fn ($name): bool => is_string($name) && Schema::hasColumn($name, 'profile_id'))
            ->reject(fn (string $name): bool => $name === 'portfolio_profiles')
            ->values();

        $conflicts = [];
        foreach ($admins as $admin) {
            $profiles = DB::table('portfolio_profiles')
                ->where('user_id', $admin->id)
                ->orderBy('id')
                ->get(['id', 'name', 'deleted_at']);
            $profileIds = $profiles->pluck('id')->map(fn ($id): int => (int) $id)->all();

            $profileTableCounts = [];
            if ($profileIds !== []) {
                foreach ($tables as $table) {
                    $count = DB::table($table)->whereIn('profile_id', $profileIds)->count();
                    if ($count > 0) {
                        $profileTableCounts[$table] = $count;
                    }
                }
            }

            $directCounts = [];
            foreach ($this->directInvestorTables as $table) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'user_id')) {
                    continue;
                }
                $count = DB::table($table)->where('user_id', $admin->id)->count();
                if ($count > 0) {
                    $directCounts[$table] = $count;
                }
            }

            if ($profiles->isNotEmpty() || $directCounts !== []) {
                $conflicts[] = [
                    'admin_user_id' => (int) $admin->id,
                    'admin_name' => $admin->name,
                    'admin_email' => $admin->email,
                    'profiles' => $profiles->map(fn ($profile): array => [
                        'id' => (int) $profile->id,
                        'name' => $profile->name,
                        'deleted_at' => $profile->deleted_at,
                    ])->all(),
                    'profile_record_counts' => $profileTableCounts,
                    'direct_record_counts' => $directCounts,
                ];
            }
        }

        return [
            'safe_to_enforce' => $conflicts === [],
            'admin_accounts_checked' => $admins->count(),
            'conflicting_admin_accounts' => count($conflicts),
            'conflicts' => $conflicts,
            'action' => $conflicts === []
                ? 'No Admin-owned Investor data was found.'
                : 'Resolve each ownership conflict explicitly before deployment. No data was changed.',
        ];
    }
}
