<?php

return new class extends \Illuminate\Database\Migrations\Migration
{
    /**
     * Move tables created before the V7 namespace boundary to the governed
     * StoX namespace. Fresh installs already use the new names.
     */
    public function up(): void
    {
        $renames = [
            'portfolio_access_requests' => 'stox_access_requests',
            'portfolio_access_request_verifications' => 'stox_access_request_verifications',
            'portfolio_access_request_bans' => 'stox_access_request_bans',
            'portfolio_access_request_audit_events' => 'stox_access_request_audit_events',
            'portfolio_microstructure_collector_state' => 'stox_microstructure_collector_state',
            'portfolio_user_onboarding_state' => 'stox_user_onboarding_state',
        ];

        foreach ($renames as $from => $to) {
            if (\Illuminate\Support\Facades\Schema::hasTable($from) && ! \Illuminate\Support\Facades\Schema::hasTable($to)) {
                \Illuminate\Support\Facades\Schema::rename($from, $to);
            }
        }
    }

    public function down(): void
    {
        $renames = [
            'stox_user_onboarding_state' => 'portfolio_user_onboarding_state',
            'stox_microstructure_collector_state' => 'portfolio_microstructure_collector_state',
            'stox_access_request_audit_events' => 'portfolio_access_request_audit_events',
            'stox_access_request_bans' => 'portfolio_access_request_bans',
            'stox_access_request_verifications' => 'portfolio_access_request_verifications',
            'stox_access_requests' => 'portfolio_access_requests',
        ];

        foreach ($renames as $from => $to) {
            if (\Illuminate\Support\Facades\Schema::hasTable($from) && ! \Illuminate\Support\Facades\Schema::hasTable($to)) {
                \Illuminate\Support\Facades\Schema::rename($from, $to);
            }
        }
    }
};
