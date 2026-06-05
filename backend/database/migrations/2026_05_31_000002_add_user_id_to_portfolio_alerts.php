<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_alerts', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->nullable()
                ->after('id')
                ->constrained('portfolio_users')
                ->cascadeOnDelete();
        });

        $this->backfillUserIds();

        Schema::table('portfolio_alerts', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable(false)->change();
            $table->index(['user_id', 'stock_id']);
        });
    }

    public function down(): void
    {
        Schema::table('portfolio_alerts', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'stock_id']);
            $table->dropConstrainedForeignId('user_id');
        });
    }

    protected function backfillUserIds(): void
    {
        $alerts = DB::table('portfolio_alerts')->whereNull('user_id')->get();

        foreach ($alerts as $alert) {
            $userIds = DB::table('portfolio_holdings')
                ->where('stock_id', $alert->stock_id)
                ->where('quantity', '>', 0)
                ->pluck('user_id');

            if ($userIds->isEmpty()) {
                $userIds = DB::table('portfolio_transactions')
                    ->where('stock_id', $alert->stock_id)
                    ->distinct()
                    ->pluck('user_id');
            }

            if ($userIds->isEmpty()) {
                DB::table('portfolio_alerts')->where('id', $alert->id)->delete();

                continue;
            }

            DB::table('portfolio_alerts')
                ->where('id', $alert->id)
                ->update(['user_id' => $userIds->first()]);

            foreach ($userIds->skip(1) as $userId) {
                DB::table('portfolio_alerts')->insert([
                    'user_id' => $userId,
                    'stock_id' => $alert->stock_id,
                    'alert_type' => $alert->alert_type,
                    'message' => $alert->message,
                    'is_sent' => $alert->is_sent,
                    'created_at' => $alert->created_at,
                    'expired_at' => $alert->expired_at,
                    'expiration_reason' => $alert->expiration_reason,
                ]);
            }
        }
    }
};
