<?php

use App\Models\UserInvite;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * F003 PD-004: invitation tokens are stored as SHA-256 hashes (same column width).
 * Pending plaintext invites cannot be re-hashed without the raw secret — purge them
 * so administrators re-issue. Accepted rows get a random hash so old URLs no longer match.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('portfolio_user_invites')) {
            return;
        }

        UserInvite::query()->whereNull('accepted_at')->delete();

        UserInvite::query()
            ->whereNotNull('accepted_at')
            ->orderBy('id')
            ->each(function (UserInvite $invite): void {
                $invite->token = hash('sha256', Str::random(64));
                $invite->save();
            });
    }

    public function down(): void
    {
        // Irreversible: pending invites were deleted; accepted token hashes cannot be restored.
    }
};
