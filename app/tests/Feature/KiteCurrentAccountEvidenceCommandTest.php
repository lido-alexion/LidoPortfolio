<?php

namespace Tests\Feature;

use App\Models\BrokerConnection;
use App\Models\User;
use App\Services\Broker\KiteCurrentAccountEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class KiteCurrentAccountEvidenceCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['broker.kite.api_key' => 'synthetic-key', 'broker.kite.api_base' => 'https://api.kite.trade']);
        Http::preventStrayRequests();
    }

    private function connected(): array
    {
        $user = User::factory()->create();
        $connection = new BrokerConnection(['user_id' => $user->id, 'provider' => 'kite', 'broker_user_id' => 'AB1234',
            'connected_at' => now(), 'expires_at' => now()->addHour()]);
        $connection->access_token = 'synthetic-token-never-print';
        $connection->save();
        return [$user, $connection];
    }

    private function response(string $path, ?string $identity = 'AB1234', bool $dayBucket = true, bool $nonempty = false): array
    {
        return match ($path) {
            '/user/profile' => ['status' => 'success', 'data' => ['user_id' => $identity]],
            '/portfolio/holdings' => ['status' => 'success', 'data' => $nonempty ? [['instrument_token' => 123, 'exchange' => 'NSE', 'tradingsymbol' => 'SYNTH', 'quantity' => 2, 'average_price' => 12.5, 't1_quantity' => 0, 'used_quantity' => 0]] : []],
            default => ['status' => 'success', 'data' => ['net' => $nonempty ? [['instrument_token' => 123, 'exchange' => 'NSE', 'tradingsymbol' => 'SYNTH', 'product' => 'CNC', 'quantity' => 2]] : [], ...($dayBucket ? ['day' => []] : [])]],
        };
    }

    private function fake(?string $identity = 'AB1234', bool $dayBucket = true, bool $nonempty = false): void
    {
        Http::fake(fn ($request) => Http::response($this->response(parse_url($request->url(), PHP_URL_PATH), $identity, $dayBucket, $nonempty)));
    }

    public function test_reads_profile_holdings_and_both_position_buckets_and_emits_metadata_only(): void
    {
        [$user] = $this->connected();
        $this->fake();
        $exit = Artisan::call('stox:kite-current-account-evidence', ['user_id' => $user->id]);
        $output = Artisan::output();
        $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, $exit);
        $this->assertTrue($report['account_bound']);
        $this->assertSame('empty', $report['holdings']['state']);
        $this->assertSame('empty', $report['positions']['day']['state']);
        $this->assertSame(4, $report['read_requests']);
        $this->assertArrayHasKey('observation', $report);
        $this->assertMatchesRegularExpression('/Z$/', $report['observation']['started_at_utc']);
        $this->assertGreaterThanOrEqual(0, $report['observation']['duration_ms']);
        $this->assertStringNotContainsString('synthetic-token-never-print', $output);
        $this->assertStringNotContainsString('AB1234', $output);
        Http::assertSentCount(4);
        $this->assertSame(['/user/profile', '/portfolio/holdings', '/portfolio/positions', '/user/profile'], Http::recorded()->map(fn ($pair) => parse_url($pair[0]->url(), PHP_URL_PATH))->all());
        Http::assertSent(fn ($request) => $request->method() === 'GET' && str_starts_with($request->url(), 'https://api.kite.trade/'));
        Http::assertSentCount(4);
        foreach (Http::recorded() as [$request]) {
            $this->assertSame('token synthetic-key:synthetic-token-never-print', $request->header('Authorization')[0]);
        }
    }

    public function test_nonempty_typed_rows_are_observed_without_disclosing_values(): void
    {
        [$user] = $this->connected(); $this->fake(nonempty: true);
        $this->assertSame(0, Artisan::call('stox:kite-current-account-evidence', ['user_id' => $user->id]));
        $output = Artisan::output(); $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['state' => 'observed', 'row_count' => 1], $report['holdings']);
        $this->assertStringNotContainsString('SYNTH', $output);
        $this->assertSame(['state' => 'observed', 'row_count' => 1], $report['positions']['net']);
        $this->assertArrayNotHasKey('average_price', $report['holdings']);
    }

    public static function malformedHoldingsBuckets(): array
    {
        return ['empty object' => ['{}'], 'numeric-key object' => ['{"0":{"instrument_token":1}}']];
    }

    #[DataProvider('malformedHoldingsBuckets')]
    public function test_object_holdings_buckets_are_rejected(string $bucket): void
    {
        [$user] = $this->connected();
        Http::fake(function ($request) use ($bucket) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            return Http::response($path === '/portfolio/holdings'
                ? '{"status":"success","data":'.$bucket.'}' : $this->response($path));
        });
        $this->assertSame(1, Artisan::call('stox:kite-current-account-evidence', ['user_id' => $user->id]));
    }

    public static function malformedPositionBuckets(): array
    {
        return ['net object' => ['net', '{}'], 'day object' => ['day', '{"0":{"quantity":1}}']];
    }

    #[DataProvider('malformedPositionBuckets')]
    public function test_positions_object_buckets_are_rejected_even_when_empty(string $bucket, string $jsonBucket): void
    {
        [$user] = $this->connected();
        Http::fake(function ($request) use ($bucket, $jsonBucket) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/portfolio/positions') {
                $other = $bucket === 'net' ? 'day' : 'net';
                return Http::response('{"status":"success","data":{"'.$bucket.'":'.$jsonBucket.', "'.$other.'":[]}}');
            }
            return Http::response($this->response($path));
        });
        $this->assertSame(1, Artisan::call('stox:kite-current-account-evidence', ['user_id' => $user->id]));
    }

    public function test_identity_mismatch_fails_closed(): void
    {
        [$user] = $this->connected(); $this->fake('OTHER');
        $this->assertSame(1, Artisan::call('stox:kite-current-account-evidence', ['user_id' => $user->id]));
        $this->assertStringNotContainsString('OTHER', Artisan::output());
    }

    public function test_missing_day_bucket_fails_closed_even_when_positions_request_succeeds(): void
    {
        [$user] = $this->connected(); $this->fake(dayBucket: false);
        $this->assertSame(1, Artisan::call('stox:kite-current-account-evidence', ['user_id' => $user->id]));
    }

    public function test_malformed_nonempty_holding_row_fails_closed(): void
    {
        [$user] = $this->connected();
        Http::fake(fn ($request) => Http::response(parse_url($request->url(), PHP_URL_PATH) === '/portfolio/holdings'
            ? ['status' => 'success', 'data' => [['unexpected' => 'shape']]]
            : $this->response(parse_url($request->url(), PHP_URL_PATH))));
        $this->assertSame(1, Artisan::call('stox:kite-current-account-evidence', ['user_id' => $user->id]));
    }

    public function test_reassigned_connection_before_first_read_is_rejected(): void
    {
        [, $connection] = $this->connected();
        $other = User::factory()->create();
        $changed = $connection->fresh();
        $changed->user_id = $other->id;
        $changed->save();
        $this->fake();
        try {
            app(KiteCurrentAccountEvidence::class)->capture($connection);
            $this->fail('Stale owner must not be accepted');
        } catch (\RuntimeException $e) {
            $this->assertSame('session_changed', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_missing_or_future_connection_timestamp_fails_before_provider_reads(): void
    {
        [$user, $connection] = $this->connected();
        $this->fake();
        foreach ([null, now()->addDay()] as $timestamp) {
            $connection->connected_at = $timestamp;
            $connection->save();
            $this->assertSame(1, Artisan::call('stox:kite-current-account-evidence', ['user_id' => $user->id]));
        }
        Http::assertNothingSent();
    }

    public function test_session_change_during_capture_fails_closed(): void
    {
        [$user, $connection] = $this->connected();
        Http::fake(function ($request) use ($connection) {
            if (parse_url($request->url(), PHP_URL_PATH) === '/portfolio/positions') {
                $changed = $connection->fresh(); $changed->access_token = 'rotated-token'; $changed->save();
            }
            return Http::response($this->response(parse_url($request->url(), PHP_URL_PATH)));
        });
        $this->assertSame(1, Artisan::call('stox:kite-current-account-evidence', ['user_id' => $user->id]));
        $this->assertStringNotContainsString('rotated-token', Artisan::output());
    }

    public function test_expiry_change_during_capture_fails_closed(): void
    {
        [$user, $connection] = $this->connected();
        Http::fake(function ($request) use ($connection) {
            if (parse_url($request->url(), PHP_URL_PATH) === '/portfolio/positions') { $changed = $connection->fresh(); $changed->expires_at = now()->addDays(2); $changed->save(); }
            return Http::response($this->response(parse_url($request->url(), PHP_URL_PATH)));
        });
        $this->assertSame(1, Artisan::call('stox:kite-current-account-evidence', ['user_id' => $user->id]));
    }

    public function test_final_profile_mismatch_fails_closed(): void
    {
        [$user] = $this->connected(); $profiles = 0;
        Http::fake(function ($request) use (&$profiles) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/user/profile' && ++$profiles === 2) return Http::response(['status' => 'success', 'data' => ['user_id' => 'OTHER']]);
            return Http::response($this->response($path));
        });
        $this->assertSame(1, Artisan::call('stox:kite-current-account-evidence', ['user_id' => $user->id]));
        $this->assertSame(4, Http::recorded()->count());
        $this->assertStringNotContainsString('OTHER', Artisan::output());
    }

    public function test_redirect_or_non_success_status_is_not_accepted(): void
    {
        [$user] = $this->connected();
        Http::fake(fn () => Http::response(['status' => 'success', 'data' => ['user_id' => 'AB1234']], 302));
        $this->assertSame(1, Artisan::call('stox:kite-current-account-evidence', ['user_id' => $user->id]));
    }
}
