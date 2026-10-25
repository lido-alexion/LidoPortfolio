<?php

namespace Tests\Unit;

use App\Services\Bhavcopy\UdiffBhavcopyParser;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Concerns\BhavcopyFixtures;

class UdiffBhavcopyParserTest extends TestCase
{
    use BhavcopyFixtures;

    public function test_validates_equity_ohlcv_and_retains_series_isin_and_code(): void
    {
        $result = (new UdiffBhavcopyParser)->parse($this->bhavcopyCsv(rows: [
            ['SctySrs' => 'BE', 'TtlTradgVol' => '0'],
        ]), Carbon::parse('2026-10-26'), 'NSE');

        $this->assertSame(1, $result['source_rows']);
        $this->assertSame(0, $result['invalid_rows']);
        $this->assertSame('BE', $result['rows'][0]['series']);
        $this->assertSame('INE001A01010', $result['rows'][0]['isin']);
        $this->assertSame('1001', $result['rows'][0]['scrip_code']);
        $this->assertSame(102.0, $result['rows'][0]['close_price']);
        $this->assertSame(0, $result['rows'][0]['volume']);
    }

    public function test_rejects_wrong_dates_exchange_segment_and_incomplete_schema(): void
    {
        $parser = new UdiffBhavcopyParser;
        $date = Carbon::parse('2026-10-26');
        foreach ([
            $this->bhavcopyCsv(rows: [['TradDt' => '2026-10-23']]),
            $this->bhavcopyCsv(rows: [['Src' => 'BSE']]),
            $this->bhavcopyCsv(rows: [['Sgmt' => 'FO']]),
            "TradDt,TckrSymb,ClsPric\n2026-10-26,AAA,100\n",
            '<html>Access denied</html>',
        ] as $body) {
            try {
                $parser->parse($body, $date, 'NSE');
                $this->fail('An invalid daily exchange file was accepted.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('Bhavcopy', $e->getMessage());
            }
        }
    }

    public function test_a_wrong_date_late_in_the_file_rejects_the_whole_file(): void
    {
        $this->expectException(RuntimeException::class);
        (new UdiffBhavcopyParser)->parse($this->bhavcopyCsv(rows: [
            [], ['FinInstrmId' => '1002', 'TradDt' => '2026-10-23'],
        ]), Carbon::parse('2026-10-26'), 'NSE');
    }

    public function test_drops_invalid_bars_without_replacing_them_with_zeroes(): void
    {
        $rows = [[]];
        foreach ([
            ['OpnPric' => '0'], ['HghPric' => '99'], ['LwPric' => '103'],
            ['TtlTradgVol' => '-1'], ['TtlTradgVol' => '1.2'], ['ClsPric' => 'NaN'],
        ] as $i => $invalid) {
            $rows[] = $invalid + ['FinInstrmId' => (string) (2000 + $i)];
        }
        $result = (new UdiffBhavcopyParser)->parse($this->bhavcopyCsv(rows: $rows), Carbon::parse('2026-10-26'), 'NSE');

        $this->assertCount(1, $result['rows']);
        $this->assertSame(6, $result['invalid_rows']);
        $this->assertSame(100.0, $result['rows'][0]['open_price']);
    }

    public function test_keeps_bse_sme_groups_and_excludes_other_sessions_or_instrument_types(): void
    {
        $result = (new UdiffBhavcopyParser)->parse($this->bhavcopyCsv('BSE', rows: [
            ['SctySrs' => 'MT'],
            ['FinInstrmId' => '1002', 'FinInstrmTp' => 'FUT'],
            ['FinInstrmId' => '1003', 'SsnId' => 'F2'],
        ]), Carbon::parse('2026-10-26'), 'BSE');

        $this->assertCount(1, $result['rows']);
        $this->assertSame('MT', $result['rows'][0]['series']);
        $this->assertSame(2, $result['excluded_rows']);
    }

    public function test_identical_duplicates_are_idempotent_but_conflicting_rows_are_rejected(): void
    {
        $parser = new UdiffBhavcopyParser;
        $date = Carbon::parse('2026-10-26');
        $this->assertCount(1, $parser->parse($this->bhavcopyCsv(rows: [[], []]), $date, 'NSE')['rows']);
        $this->expectException(RuntimeException::class);
        $parser->parse($this->bhavcopyCsv(rows: [[], ['ClsPric' => '103']]), $date, 'NSE');
    }
}
