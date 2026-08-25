import test from 'node:test';
import assert from 'node:assert/strict';
import {
    addVolumeColors,
    buildPriceVolumeChartData,
    filterByTimeRange,
    normalizeOhlcvRows,
    sampleOhlcvBuckets,
    subtractCalendarRange,
} from '../../resources/js/src/utils/ohlcvChartData.js';
import {
    VOLUME_COLOR_DOWN,
    VOLUME_COLOR_NEUTRAL,
    VOLUME_COLOR_UP,
} from '../../resources/js/src/components/charts/priceVolumeChartTypes.js';

function makeRow(date, close, volume = 1000) {
    return {
        price_date: date,
        close_price: close,
        volume,
    };
}

test('normalizeOhlcvRows sorts ascending and skips invalid rows', () => {
    const rows = normalizeOhlcvRows([
        makeRow('2026-03-01', 110),
        { price_date: 'bad', close_price: 50 },
        makeRow('2026-01-01', 100),
    ]);

    assert.equal(rows.length, 2);
    assert.equal(rows[0].date, '2026-01-01');
    assert.equal(rows[1].date, '2026-03-01');
    assert.equal(rows[1].close, 110);
});

test('filterByTimeRange clamps when history is shorter than selected window', () => {
    const rows = normalizeOhlcvRows([
        makeRow('2026-05-01', 100),
        makeRow('2026-06-01', 105),
        makeRow('2026-07-01', 110),
    ]);

    const result = filterByTimeRange(rows, '1y');

    assert.equal(result.filtered.length, 3);
    assert.equal(result.isClamped, true);
});

test('filterByTimeRange applies calendar cutoff from latest date', () => {
    const rows = normalizeOhlcvRows([
        makeRow('2024-01-01', 90),
        makeRow('2025-06-01', 100),
        makeRow('2026-01-01', 110),
    ]);

    const result = filterByTimeRange(rows, '1y');

    assert.equal(result.filtered.length, 2);
    assert.equal(result.filtered[0].date, '2025-06-01');
    assert.equal(result.isClamped, false);
});

test('sampleOhlcvBuckets uses last close and sums volume', () => {
    const rows = normalizeOhlcvRows([
        makeRow('2026-01-01', 100, 10),
        makeRow('2026-01-02', 101, 20),
        makeRow('2026-01-03', 102, 30),
        makeRow('2026-01-04', 103, 40),
    ]);

    const buckets = sampleOhlcvBuckets(rows, 2);

    assert.equal(buckets.length, 2);
    assert.equal(buckets[0].close, 101);
    assert.equal(buckets[0].volume, 30);
    assert.equal(buckets[0].date, '2026-01-02');
    assert.equal(buckets[1].close, 103);
    assert.equal(buckets[1].volume, 70);
});

test('sampleOhlcvBuckets step 30 yields about twelve points from 360 rows', () => {
    const rows = [];
    for (let index = 0; index < 360; index += 1) {
        const day = String((index % 28) + 1).padStart(2, '0');
        const month = String(Math.floor(index / 28) + 1).padStart(2, '0');
        rows.push(makeRow(`2025-${month}-${day}`, 100 + index, 10));
    }

    const normalized = normalizeOhlcvRows(rows);
    const buckets = sampleOhlcvBuckets(normalized, 30);

    assert.equal(buckets.length, 12);
    assert.equal(buckets[0].volume, 300);
});

test('addVolumeColors compares bucket close to previous bucket', () => {
    const buckets = sampleOhlcvBuckets(normalizeOhlcvRows([
        makeRow('2026-01-01', 100),
        makeRow('2026-01-02', 105),
        makeRow('2026-01-03', 103),
    ]), 1);

    const colored = addVolumeColors(buckets);

    assert.equal(colored[0].volumeColor, VOLUME_COLOR_NEUTRAL);
    assert.equal(colored[1].volumeColor, VOLUME_COLOR_UP);
    assert.equal(colored[2].volumeColor, VOLUME_COLOR_DOWN);
});

test('buildPriceVolumeChartData handles empty and single row input', () => {
    const empty = buildPriceVolumeChartData([]);
    assert.deepEqual(empty.series, []);
    assert.equal(empty.meta.pointCount, 0);

    const single = buildPriceVolumeChartData([makeRow('2026-01-01', 100)]);
    assert.equal(single.series.length, 1);
    assert.equal(single.series[0].volumeColor, VOLUME_COLOR_NEUTRAL);
});

test('subtractCalendarRange moves back one year', () => {
    assert.equal(subtractCalendarRange('2026-06-15', '1y'), '2025-06-15');
});

test('subtractCalendarRange moves back five years', () => {
    assert.equal(subtractCalendarRange('2026-06-15', '5y'), '2021-06-15');
});
