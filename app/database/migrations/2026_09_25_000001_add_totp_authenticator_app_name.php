<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('portfolio_users') && ! Schema::hasColumn('portfolio_users', 'totp_authenticator_app_name')) {
            Schema::table('portfolio_users', function (Blueprint $table): void {
                $table->string('totp_authenticator_app_name', 80)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('portfolio_users') && Schema::hasColumn('portfolio_users', 'totp_authenticator_app_name')) {
            Schema::table('portfolio_users', function (Blueprint $table): void {
                $table->dropColumn('totp_authenticator_app_name');
            });
        }
    }
};
