<?php

namespace App\Services\VpsHealth;

use App\Models\VpsHealthSample;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

class VpsHealthSampleService
{
    /** @param array<string, mixed> $payload */
    public function record(array $payload): VpsHealthSample
    {
        $validated = Validator::make($payload, [
            'time' => ['required', 'date'],
            'status' => ['required', 'in:ok,critical'],
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

        if ($sampledAt->minute === 0) {
            VpsHealthSample::query()->where('sampled_at', '<', now()->subHours(96))->delete();
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
            'ram_available_bytes', 'swap_used_percent', 'root_used_percent',
        ] as $key) {
            if (is_numeric($metrics[$key] ?? null)) {
                $result[$key] = $metrics[$key] + 0;
            }
        }

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
