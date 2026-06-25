<?php

use App\Models\Setting;
use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MIGRATED_KEYS = [
        'default_stoploss_percent',
        'telegram_bot_token',
        'telegram_chat_id',
        'notifications_enabled',
        'notification_schedules',
    ];

    private const DEFAULTS = [
        'default_stoploss_percent' => '10',
        'telegram_bot_token' => '',
        'telegram_chat_id' => '',
        'notifications_enabled' => 'true',
        'notification_schedules' => '[]',
    ];

    public function up(): void
    {
        Schema::create('portfolio_user_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('portfolio_users')->cascadeOnDelete();
            $table->string('setting_key', 64);
            $table->text('setting_value')->nullable();
            $table->timestamp('updated_at')->useCurrent();

            $table->unique(['user_id', 'setting_key']);
        });

        if (! Schema::hasTable('portfolio_users')) {
            return;
        }

        foreach (User::query()->orderBy('id')->get() as $user) {
            foreach (self::MIGRATED_KEYS as $key) {
                $global = Setting::query()->where('setting_key', $key)->value('setting_value');
                $value = $global ?? self::DEFAULTS[$key];
                UserSetting::setValue($user->id, $key, $value);
            }
        }

        Setting::query()->whereIn('setting_key', self::MIGRATED_KEYS)->delete();
    }

    public function down(): void
    {
        if (Schema::hasTable('portfolio_user_settings')) {
            $firstUser = User::query()->orderBy('id')->first();
            if ($firstUser) {
                foreach (self::MIGRATED_KEYS as $key) {
                    $value = UserSetting::getValue($firstUser->id, $key, self::DEFAULTS[$key]);
                    Setting::setValue($key, $value);
                }
            }
        }

        Schema::dropIfExists('portfolio_user_settings');
    }
};
