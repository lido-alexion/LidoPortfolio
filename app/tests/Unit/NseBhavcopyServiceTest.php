<?php

namespace Tests\Unit;

use App\Models\CalendarEvent;
use App\Services\BseBhavcopyService;
use App\Services\NseBhavcopyService;
use App\Support\TradingCalendar;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;
use Tests\Concerns\BhavcopyFixtures;
use Tests\TestCase;

class NseBhavcopyServiceTest extends TestCase
{
    use BhavcopyFixtures;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        TradingCalendar::clearHolidayCache();
        Mockery::close();
        parent::tearDown();
    }

    public function test_downloads_one_nse_zip_for_all_rows_without_cookie_warmups(): void
    {
        Http::fake(['nsearchives.nseindia.com/*' => Http::response($this->bhavcopyZip($this->bhavcopyCsv()))]);
        $result = app(NseBhavcopyService::class)->fetchDailyRows(Carbon::parse('2026-10-26'));

        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->url() === 'https://nsearchives.nseindia.com/content/cm/BhavCopy_NSE_CM_0_0_0_20261026_F_0000.csv.zip');
        $this->assertSame('AAA', $result['rows'][0]['symbol']);
    }

    public function test_weekends_and_marked_holidays_do_not_download_files(): void
    {
        CalendarEvent::query()->create([
            'profile_id' => null, 'category' => CalendarEvent::CATEGORY_TRADE_HOLIDAY,
            'title' => 'Closed Monday', 'color' => CalendarEvent::TRADE_HOLIDAY_DEFAULT_COLOR,
            'anchor_date' => '2026-10-26', 'recurrence_type' => CalendarEvent::RECURRENCE_NONE,
            'is_active' => true, 'reminder_enabled' => false,
        ]);
        TradingCalendar::clearHolidayCache();
        Http::fake();
        foreach (['2026-10-24', '2026-10-25', '2026-10-26'] as $day) {
            $this->assertSame([], app(NseBhavcopyService::class)->fetchDailyRows(Carbon::parse($day))['rows']);
            $this->assertSame([], app(BseBhavcopyService::class)->fetchDailyRows(Carbon::parse($day))['rows']);
        }
        Http::assertNothingSent();
    }

    public function test_rejects_http_errors_html_wrong_archive_names_and_wrong_csv_dates(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push('not ready', 404)->push('<html>Denied</html>')
            ->push($this->bhavcopyZip($this->bhavcopyCsv(), name: '../wrong.csv'))
            ->push($this->bhavcopyZip($this->bhavcopyCsv(date: '2026-10-23')))]);
        foreach (['HTTP 404', 'not a supported ZIP', 'ZIP entry', 'trading date'] as $expected) {
            try {
                app(NseBhavcopyService::class)->fetchDailyRows(Carbon::parse('2026-10-26'));
                $this->fail('Invalid daily NSE content was accepted.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        }
        Http::assertSentCount(4);
    }

    public function test_bse_daily_path_reuses_its_downloader_and_validates_the_exchange_and_date(): void
    {
        $service = Mockery::mock(BseBhavcopyService::class)->makePartial();
        $service->shouldReceive('downloadForDate')->once()->andReturn($this->bhavcopyCsv('BSE', rows: [['SctySrs' => 'M']]));
        $result = $service->fetchDailyRows(Carbon::parse('2026-10-26'));
        $this->assertSame('M', $result['rows'][0]['series']);

        $wrong = Mockery::mock(BseBhavcopyService::class)->makePartial();
        $wrong->shouldReceive('downloadForDate')->once()->andReturn($this->bhavcopyCsv('NSE'));
        $this->expectException(RuntimeException::class);
        $wrong->fetchDailyRows(Carbon::parse('2026-10-26'));
    }
}
