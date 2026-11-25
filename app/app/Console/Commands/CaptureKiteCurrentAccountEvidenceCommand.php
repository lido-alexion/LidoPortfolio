<?php

namespace App\Console\Commands;

use App\Models\BrokerConnection;
use App\Services\Broker\KiteCurrentAccountEvidence;
use Illuminate\Console\Command;
use Throwable;

final class CaptureKiteCurrentAccountEvidenceCommand extends Command
{
    protected $signature = 'stox:kite-current-account-evidence {user_id : Existing user owning the authorized Kite session}';
    protected $description = 'Read current account-bound Kite holdings and positions evidence without persistence';

    public function handle(KiteCurrentAccountEvidence $capture): int
    {
        $report = ['passed' => false, 'stage' => 'session'];
        try {
            $id = (string) $this->argument('user_id');
            if (! preg_match('/^[1-9][0-9]*$/D', $id) || strlen($id) > strlen((string) PHP_INT_MAX)
                || (strlen($id) === strlen((string) PHP_INT_MAX) && strcmp($id, (string) PHP_INT_MAX) > 0)) throw new \RuntimeException();
            $connections = BrokerConnection::query()->where('user_id', (int) $id)
                ->where('provider', BrokerConnection::PROVIDER_KITE)->limit(2)->get();
            if ($connections->count() !== 1 || (string) $connections->first()->user_id !== $id) throw new \RuntimeException();
            $connection = $connections->first();
            $report['stage'] = 'provider_reads';
            $evidence = $capture->capture($connection);
            $report = ['passed' => true, 'stage' => 'complete', 'read_requests' => 4, ...$evidence];
        } catch (Throwable) {
            // Exceptions may include provider bodies or credentials; emit only bounded stage metadata.
        }
        $this->line((string) json_encode($report, JSON_UNESCAPED_SLASHES));
        return $report['passed'] ? self::SUCCESS : self::FAILURE;
    }
}
