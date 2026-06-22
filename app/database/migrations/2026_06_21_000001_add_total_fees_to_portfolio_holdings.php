<?php

use App\Models\User;
use App\Services\HoldingsCalculationService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_holdings', function (Blueprint $table) {
            $table->decimal('total_fees', 18, 4)->default(0)->after('invested_amount');
        });

        $calculator = app(HoldingsCalculationService::class);

        User::query()->orderBy('id')->chunkById(100, function ($users) use ($calculator) {
            foreach ($users as $user) {
                $calculator->recalculateForUser($user);
            }
        });
    }

    public function down(): void
    {
        Schema::table('portfolio_holdings', function (Blueprint $table) {
            $table->dropColumn('total_fees');
        });
    }
};
