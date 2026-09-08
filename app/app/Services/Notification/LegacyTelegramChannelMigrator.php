<?php

namespace App\Services\Notification;

use App\Models\NotificationChannelSetting;
use App\Models\PortfolioProfile;
use App\Models\ProfileSetting;
use App\Models\User;
use Illuminate\Support\Collection;

class LegacyTelegramChannelMigrator
{
    public function migrateIfUnambiguous(User $user): ?NotificationChannelSetting
    {
        $existing = NotificationChannelSetting::query()
            ->where('user_id', $user->id)
            ->where('channel', 'telegram')
            ->first();
        if ($existing) {
            return $existing;
        }

        $profileIds = PortfolioProfile::query()
            ->where('user_id', $user->id)
            ->pluck('id');
        if ($profileIds->isEmpty()) {
            return null;
        }

        $settings = ProfileSetting::query()
            ->whereIn('profile_id', $profileIds)
            ->whereIn('setting_key', ['telegram_bot_token', 'telegram_chat_id', 'notifications_enabled'])
            ->get()
            ->groupBy('profile_id');

        $candidateGroups = $profileIds
            ->map(fn (int $profileId) => $this->candidate($settings->get($profileId, collect())))
            ->filter()
            ->groupBy(fn (array $candidate) => hash('sha256', $candidate['bot_token']."\0".$candidate['chat_id']));
        if ($candidateGroups->count() !== 1) {
            return null;
        }

        $matching = $candidateGroups->first();
        $candidate = $matching->first();
        $candidate['enabled'] = $matching->contains(fn (array $item) => $item['enabled']);

        return NotificationChannelSetting::query()->firstOrCreate(
            ['user_id' => $user->id, 'channel' => 'telegram'],
            [
                'enabled' => $candidate['enabled'],
                'configuration' => ['bot_token' => $candidate['bot_token'], 'chat_id' => $candidate['chat_id']],
                'health_status' => 'healthy',
                'verified_at' => now(),
                'last_test_status' => 'migrated',
            ],
        );
    }

    /** @return array{bot_token:string,chat_id:string,enabled:bool}|null */
    private function candidate(Collection $settings): ?array
    {
        $values = $settings->pluck('setting_value', 'setting_key');
        $token = trim((string) $values->get('telegram_bot_token'));
        $chatId = trim((string) $values->get('telegram_chat_id'));
        if ($token === '' || $chatId === '') {
            return null;
        }

        return [
            'bot_token' => $token,
            'chat_id' => $chatId,
            'enabled' => $values->get('notifications_enabled', 'true') === 'true',
        ];
    }
}
