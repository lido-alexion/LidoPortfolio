<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Broker\BrokerConnectionService;
use App\Services\Broker\KiteBrokerGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

class KiteReadOnlySmokeCommand extends Command
{
    protected $signature = 'stox:kite-read-only-smoke {user_id : Existing user owning the authorized Kite session}';

    protected $description = 'Verify only Kite holdings, positions and equity-margin reads without reconciliation or orders';

    private const PATHS = ['/portfolio/holdings', '/portfolio/positions', '/user/margins/equity'];

    public function handle(BrokerConnectionService $connections, KiteBrokerGateway $kite): int
    {
        $testing = app()->environment('testing');
        $report = [
            'passed' => false,
            'evidence_kind' => $testing ? 'deterministic_contract' : 'real_kite_read_only',
            'checked_at' => now()->toISOString(),
            'commit_sha' => null,
            'stage' => 'configuration',
            'read_requests' => 0,
        ];
        $paths = [];
        $responses = [];
        try {
            if ((! $testing && ! app()->environment('production'))
                || rtrim((string) config('broker.kite.api_base'), '/') !== 'https://api.kite.trade'
                || ! preg_match('/^[1-9][0-9]*$/', (string) $this->argument('user_id'))) {
                throw new RuntimeException();
            }
            $build = is_file(base_path('bootstrap/build-info.json'))
                ? json_decode(file_get_contents(base_path('bootstrap/build-info.json')), true) : [];
            $sha = $build['commit_sha'] ?? null;
            if (! $testing && (! is_string($sha) || ! preg_match('/^[a-f0-9]{40}$/', $sha))) {
                throw new RuntimeException();
            }
            $report['commit_sha'] = $sha;
            $report['stage'] = 'session';
            $user = User::query()->findOrFail((int) $this->argument('user_id'));
            $status = $connections->status($user);
            if (! $status['configured'] || ! $status['usable'] || ! $status['expires_at']) {
                throw new RuntimeException();
            }

            // Guard before transmission; redirects cannot escape the official boundary.
            Http::globalOptions(['allow_redirects' => false]);
            Http::globalRequestMiddleware(function (RequestInterface $request) use (&$paths): RequestInterface {
                $uri = $request->getUri();
                if ($request->getMethod() !== 'GET' || $uri->getScheme() !== 'https'
                    || $uri->getHost() !== 'api.kite.trade' || ! in_array($uri->getPort(), [null, 443], true)
                    || $uri->getUserInfo() !== '' || $uri->getQuery() !== ''
                    || ! in_array($uri->getPath(), self::PATHS, true)) {
                    throw new RuntimeException('Read-only request policy rejected a request.');
                }
                $paths[] = $uri->getPath();
                return $request;
            });
            Http::globalResponseMiddleware(function (ResponseInterface $response) use (&$responses): ResponseInterface {
                $body = json_decode((string) $response->getBody(), true);
                $data = $body['data'] ?? null;
                $shape = match (self::PATHS[count($responses)] ?? null) {
                    '/portfolio/holdings' => is_array($data),
                    '/portfolio/positions' => is_array($data) && is_array($data['net'] ?? null),
                    '/user/margins/equity' => is_array($data) && is_numeric(data_get($data, 'available.live_balance', $data['net'] ?? null)),
                    default => false,
                };
                $responses[] = $response->getStatusCode() === 200 && ($body['status'] ?? null) === 'success' && $shape;
                return $response;
            });
            $report['stage'] = 'provider_reads';
            $snapshot = $kite->portfolioSnapshot($user->id);
            // portfolioSnapshot treats failed positions as empty; the smoke must not.
            if ($paths !== self::PATHS || $responses !== [true, true, true] || $snapshot === null) {
                throw new RuntimeException();
            }
            $report['passed'] = true;
            $report['stage'] = 'complete';
        } catch (Throwable) {
            // Raw provider bodies, financial data, headers, identities and errors stay private.
        }
        $report['read_requests'] = count($paths);
        $this->line((string) json_encode($report, JSON_UNESCAPED_SLASHES));
        return $report['passed'] ? self::SUCCESS : self::FAILURE;
    }
}
