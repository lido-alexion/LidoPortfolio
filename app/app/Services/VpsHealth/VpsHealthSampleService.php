<?php

namespace App\Services\VpsHealth;

use App\Models\VpsHealthSample;
use App\Models\VpsHealthDiskHourlyRollup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

class VpsHealthSampleService
{
    /** @param array<string, mixed> $payload */
    public function record(array $payload): VpsHealthSample
    {
        $validated = Validator::make($payload, [
            'time' => ['required', 'date'],
            'status' => ['required', 'in:ok,warning,critical'],
            'issues' => ['present', 'array', 'max:20'],
            'issues.*' => ['string', 'max:240'],
            'metrics' => ['required', 'array'],
        ])->validate();

        $metrics = $this->metrics($validated['metrics']);
        $sampledAt = CarbonImmutable::parse($validated['time'])->utc();
        $sample = VpsHealthSample::query()->create([
            'sampled_at' => $sampledAt,
            'status' => $validated['status'],
            'issues' => array_values($validated['issues']),
            'metrics' => $metrics,
        ]);

        $diskMetrics = array_intersect_key($metrics, array_flip([
            'root_total_bytes', 'root_used_bytes', 'root_available_bytes', 'root_used_percent',
            'root_available_percent', 'root_inode', 'disk_io', 'disk_iowait_percent', 'directory_breakdown',
        ]));
        if ($diskMetrics) {
            $hour = $sampledAt->startOfHour();
            VpsHealthDiskHourlyRollup::query()->updateOrCreate(
                ['hour_start' => $hour->toDateTimeString()],
                ['sampled_at' => $sampledAt, 'metrics' => $diskMetrics],
            );
        }

        if ($sampledAt->minute === 0) {
            VpsHealthSample::query()->where('sampled_at', '<', now()->subHours(96))->delete();
            VpsHealthDiskHourlyRollup::query()->where('hour_start', '<', now()->subDays(30)->startOfHour())->delete();
        }

        return $sample;
    }

    /** @param array<string, mixed> $metrics
     *  @return array<string, mixed>
     */
    private function metrics(array $metrics): array
    {
        $result = [];
        foreach ([
            'load1', 'load5', 'load15', 'cpus', 'load_per_core', 'ram_available_percent',
            'ram_available_bytes', 'swap_used_percent', 'root_total_bytes', 'root_used_bytes',
            'root_available_bytes', 'root_used_percent', 'root_available_percent', 'root_disk_critical_percent',
        ] as $key) {
            if (is_numeric($metrics[$key] ?? null)) {
                $result[$key] = $metrics[$key] + 0;
            }
        }

        $inode = is_array($metrics['root_inode'] ?? null) ? $metrics['root_inode'] : [];
        $result['root_inode'] = [];
        foreach (['total', 'used', 'available', 'used_percent'] as $key) {
            if (is_numeric($inode[$key] ?? null)) {
                $result['root_inode'][$key] = $inode[$key] + 0;
            }
        }
        $io = is_array($metrics['disk_io'] ?? null) ? $metrics['disk_io'] : [];
        $result['disk_io'] = [
            'status' => ($io['status'] ?? null) === 'available' ? 'available' : 'unavailable',
            'read_bytes_per_second' => is_numeric($io['read_bytes_per_second'] ?? null) ? $io['read_bytes_per_second'] + 0 : null,
            'write_bytes_per_second' => is_numeric($io['write_bytes_per_second'] ?? null) ? $io['write_bytes_per_second'] + 0 : null,
        ];
        $result['disk_iowait_percent'] = is_numeric($metrics['disk_iowait_percent'] ?? null) ? $metrics['disk_iowait_percent'] + 0 : null;
        $breakdown = is_array($metrics['directory_breakdown'] ?? null) ? $metrics['directory_breakdown'] : [];
        $paths = [];
        $knownPaths = ['/var/log', '/var/lib', '/var/cache', '/var/tmp', '/tmp', '/home', '/opt'];
        foreach (array_slice(is_array($breakdown['paths'] ?? null) ? $breakdown['paths'] : [], 0, 7) as $path) {
            if (!is_array($path) || !in_array($path['path'] ?? null, $knownPaths, true)) {
                continue;
            }
            $pathBytes = $path['shallow_file_bytes'] ?? $path['direct_file_bytes'] ?? null;
            if (is_numeric($pathBytes) || $pathBytes === null) {
                $pathStatus = in_array($path['status'] ?? null, ['available', 'partial', 'unavailable'], true) ? $path['status'] : 'unavailable';
                if ($pathBytes === null) $pathStatus = 'unavailable';
                $paths[] = [
                    'path' => $path['path'],
                    'shallow_file_bytes' => is_numeric($pathBytes) ? $pathBytes + 0 : null,
                    'status' => $pathStatus,
                ];
            }
        }
        $result['directory_breakdown'] = [
            'status' => in_array($breakdown['status'] ?? null, ['available', 'partial'], true) ? $breakdown['status'] : 'unavailable',
            'scanned_at' => is_string($breakdown['scanned_at'] ?? null) ? $breakdown['scanned_at'] : null,
            'entries_scanned' => is_numeric($breakdown['entries_scanned'] ?? null) ? (int) $breakdown['entries_scanned'] : 0,
            'paths' => $paths,
            'reclaimable_candidates' => array_values(array_map(static function ($candidate) {
                $bytes = is_numeric($candidate['bytes'] ?? null) ? $candidate['bytes'] + 0 : null;
                $status = in_array($candidate['status'] ?? null, ['available', 'partial', 'unavailable'], true) ? $candidate['status'] : 'unavailable';
                return [
                    'kind' => in_array($candidate['kind'] ?? null, ['cache_files', 'rotated_log_files'], true) ? $candidate['kind'] : 'unknown',
                    'bytes' => $bytes,
                    'status' => $bytes === null ? 'unavailable' : $status,
                    'guaranteed_safe_to_delete' => false,
                ];
            }, array_slice(array_filter(is_array($breakdown['reclaimable_candidates'] ?? null) ? $breakdown['reclaimable_candidates'] : [], 'is_array'), 0, 4))),
            'visibility' => [
                'home' => 'unavailable: ProtectHome=yes',
                'tmp' => 'unavailable: PrivateTmp=yes; service-private namespace',
            ],
        ];

        foreach (['fpm', 'nginx', 'nginx_minute'] as $section) {
            $values = is_array($metrics[$section] ?? null) ? $metrics[$section] : [];
            if ($section === 'fpm') {
                $result[$section] = array_intersect_key($values, array_fill_keys([
                    'listen queue', 'active processes', 'idle processes', 'max active processes',
                    'max children reached', 'max children',
                ], true));
                $result[$section] = array_filter($result[$section], 'is_numeric');
            } else {
                $result[$section] = [];
                foreach (['499', '502', '503', '504'] as $code) {
                    if (is_numeric($values[$code] ?? null)) {
                        $result[$section][$code] = (int) $values[$code];
                    }
                }
            }
        }

        return $result;
    }
}
