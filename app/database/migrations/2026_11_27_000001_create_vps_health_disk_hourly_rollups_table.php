<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_vps_health_disk_hourly_rollups', function (Blueprint $table) {
            $table->timestamp('hour_start')->primary();
            $table->timestamp('sampled_at')->index();
            $table->json('metrics');
            $table->timestamps();
        });

        // Backfill only the existing four-day raw retention, capped at 6,000 rows.
        $samples = DB::table('portfolio_vps_health_samples')->select(['id', 'sampled_at', 'metrics'])
            ->where('sampled_at', '>=', now()->subHours(96))
            ->orderByDesc('sampled_at')->orderByDesc('id')->limit(6000)->get()->reverse();
        $rollups = [];
        foreach ($samples as $sample) {
            $metrics = json_decode($sample->metrics, true) ?: [];
            $disk = array_intersect_key($metrics, array_flip([
                'root_total_bytes', 'root_used_bytes', 'root_available_bytes', 'root_used_percent',
                'root_available_percent', 'root_inode', 'disk_io', 'disk_iowait_percent',
                'directory_breakdown',
            ]));
            if (!$disk) continue;
            $sampledAt = \Illuminate\Support\Carbon::parse($sample->sampled_at, 'UTC')->utc();
            $hour = $sampledAt->copy()->startOfHour();
            $rollups[$hour->toDateTimeString()] = [
                'hour_start' => $hour->toDateTimeString(),
                'sampled_at' => $sampledAt->toDateTimeString(),
                'metrics' => json_encode($disk),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        if ($rollups) {
            DB::table('portfolio_vps_health_disk_hourly_rollups')->insert(array_values($rollups));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_vps_health_disk_hourly_rollups');
    }
};
