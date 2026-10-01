<?php

namespace App\Services\ML;

use App\Models\V8\MlAcceptanceCampaign;
use App\Models\V8\MlAcceptanceSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class NseAcceptanceSourceBootstrapService
{
    private const UDIFF_START_DATE = '2024-07-08';

    /** @return list<string> */
    public function referenceDates(MlAcceptanceCampaign $campaign): array
    {
        $dates = [];
        foreach ($campaign->horizons ?? [] as $evidence) {
            foreach ($evidence['reference_dates'] ?? [] as $date) {
                $dates[] = CarbonImmutable::parse((string) $date)->toDateString();
            }
        }
        $dates = array_values(array_unique($dates));
        sort($dates);

        return $dates;
    }

    /** @return array{source:string,date:string,filename:string,url:string} */
    public function descriptor(string $date): array
    {
        $date = CarbonImmutable::parse($date);
        $base = rtrim((string) config('ml.historical_universe.nse_archives_base_url'), '/');
        if ($date->toDateString() < self::UDIFF_START_DATE) {
            $month = strtoupper($date->format('M'));
            $filename = 'cm'.$date->format('d').$month.$date->format('Y').'bhav.csv.zip';
            $path = sprintf('/content/historical/EQUITIES/%s/%s/%s', $date->format('Y'), $month, $filename);
        } else {
            $filename = 'BhavCopy_NSE_CM_0_0_0_'.$date->format('Ymd').'_F_0000.csv.zip';
            $path = '/content/cm/'.$filename;
        }

        return [
            'source' => 'nse_cash_bhavcopy',
            'date' => $date->toDateString(),
            'filename' => $filename,
            'url' => $base.$path,
        ];
    }

    public function latestForDate(string $date): ?MlAcceptanceSource
    {
        $query = MlAcceptanceSource::query()
            ->where('manifest->source', 'nse_cash_bhavcopy')
            ->where('manifest->date', CarbonImmutable::parse($date)->toDateString());

        return (clone $query)->where('status', 'sealed')->latest('created_at')->first()
            ?? (clone $query)->whereIn('status', ['uploading', 'queued'])->latest('created_at')->first();
    }

    public function stage(string $date, int $actorId, ?MlAcceptanceSource $existing = null): MlAcceptanceSource
    {
        $descriptor = $this->descriptor($date);
        $payload = $this->download($descriptor['url']);
        $bytes = strlen($payload);
        $sha256 = hash('sha256', $payload);
        if ($bytes < 1 || $bytes > MlAcceptanceSourceService::MAX_BYTES || ! str_starts_with($payload, "PK\x03\x04")) {
            $this->fail('Official NSE response is not a supported ZIP source for '.$descriptor['date'].'.');
        }

        $service = app(MlAcceptanceSourceService::class);
        $source = $existing;
        if ($source !== null && $source->status === 'uploading') {
            $manifest = $source->manifest;
            if (($manifest['filename'] ?? null) !== $descriptor['filename']
                || (int) ($manifest['bytes'] ?? 0) !== $bytes
                || ! hash_equals((string) ($manifest['sha256'] ?? ''), $sha256)) {
                $this->fail('Existing partial source differs from the current official NSE payload for '.$descriptor['date'].'.');
            }
        } else {
            $source = $service->create([
                'version' => 1,
                'source' => $descriptor['source'],
                'date' => $descriptor['date'],
                'filename' => $descriptor['filename'],
                'bytes' => $bytes,
                'sha256' => $sha256,
            ], $actorId);
        }

        $offset = (int) $source->received;
        while ($offset < $bytes) {
            $chunk = substr($payload, $offset, min(1048576, $bytes - $offset));
            $source = $service->chunk($source, $offset, $chunk, $actorId)->refresh();
            $offset = (int) $source->received;
        }
        if ($source->status === 'uploading') {
            $source = $service->finalize($source, $actorId);
        }

        return $source->refresh();
    }

    private function download(string $url): string
    {
        $response = Http::withHeaders([
            'Accept' => 'application/zip,text/csv,*/*',
            'Referer' => 'https://www.nseindia.com/',
            'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/152 Safari/537.36 StoX/1.0',
        ])->connectTimeout(10)->timeout(45)->retry(3, 1000, throw: false)->get($url);

        if (! $response->successful()) {
            $this->fail('Official NSE download failed with HTTP '.$response->status().'.');
        }

        return $response->body();
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['source' => [$message]]);
    }
}
