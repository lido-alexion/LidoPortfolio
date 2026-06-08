import test from 'node:test';
import assert from 'node:assert/strict';
import {
    calculateTransactionFees,
    DEFAULT_FEE_COMPONENTS,
    FEE_MODES,
    formatFeeBreakdownHtml,
    formatFeeBreakdownText,
} from '../../resources/js/src/utils/feeCalculator.js';

test('NSE buy applies stamp and txn NSE charges', () => {
    const result = calculateTransactionFees({
        quantity: 100,
        price: 1000,
        type: 'buy',
        exchange: 'NSE',
        feeComponents: DEFAULT_FEE_COMPONENTS,
    });

    assert.ok(result.total > 0);
    const labels = result.breakdown.map((line) => line.id);
    assert.ok(labels.includes('stamp'));
    assert.ok(labels.includes('txn_nse'));
    assert.ok(!labels.includes('txn_bse'));
});

test('BSE sell excludes stamp', () => {
    const result = calculateTransactionFees({
        quantity: 10,
        price: 500,
        type: 'sell',
        exchange: 'BSE',
        feeComponents: DEFAULT_FEE_COMPONENTS,
    });

    const labels = result.breakdown.map((line) => line.id);
    assert.ok(!labels.includes('stamp'));
    assert.ok(labels.includes('txn_bse'));
    assert.ok(!labels.includes('txn_nse'));
});

test('fixed fee mode ignores turnover', () => {
    const components = [
        {
            id: 'flat',
            label: 'Flat fee',
            value: '5',
            mode: FEE_MODES.FIXED,
            applies_buy: true,
            applies_sell: true,
            exchange: 'both',
            gst_percent: '0',
        },
    ];

    const small = calculateTransactionFees({
        quantity: 1,
        price: 100,
        type: 'buy',
        exchange: 'NSE',
        feeComponents: components,
    });
    const large = calculateTransactionFees({
        quantity: 100,
        price: 10000,
        type: 'buy',
        exchange: 'NSE',
        feeComponents: components,
    });

    assert.equal(small.total, 5);
    assert.equal(large.total, 5);
});

test('per-component GST is applied', () => {
    const components = [
        {
            id: 'txn',
            label: 'Txn',
            value: '1',
            mode: FEE_MODES.PERCENTAGE,
            applies_buy: true,
            applies_sell: false,
            exchange: 'both',
            gst_percent: '18',
        },
    ];

    const result = calculateTransactionFees({
        quantity: 10,
        price: 100,
        type: 'buy',
        exchange: 'NSE',
        feeComponents: components,
    });

    assert.equal(result.breakdown[0].base, 10);
    assert.equal(result.breakdown[0].gst, 1.8);
    assert.equal(result.total, 11.8);
});

test('formatFeeBreakdownText omits redundant totals and groups GST', () => {
    const noGst = formatFeeBreakdownText([
        { label: 'STT/CTT', base: 1, gst: 0, total: 1 },
    ], 1);
    assert.equal(noGst, 'STT/CTT: ₹1.00\nTotal fees: ₹1.00');

    const singleGst = formatFeeBreakdownText([
        { label: 'Txn', base: 10, gst: 1.8, total: 11.8 },
    ], 11.8);
    assert.equal(singleGst, 'Txn: ₹10.00\nGST: ₹1.80\nTotal fees: ₹11.80');

    const multiGst = formatFeeBreakdownText([
        { label: 'Brokerage', base: 10, gst: 1, total: 11 },
        { label: 'Txn', base: 20, gst: 2, total: 22 },
        { label: 'SEBI', base: 30, gst: 1, total: 31 },
        { label: 'STT/CTT', base: 5, gst: 0, total: 5 },
    ], 69);
    assert.equal(
        multiGst,
        'Brokerage: ₹10.00\nTxn: ₹20.00\nSEBI: ₹30.00\nSTT/CTT: ₹5.00\nGST: ₹1.00+₹2.00+₹1.00 = ₹4.00\nTotal fees: ₹69.00',
    );

    const multiGstHtml = formatFeeBreakdownHtml([
        { label: 'STT/CTT', base: 1, gst: 0, total: 1 },
        { label: 'Txn', base: 10, gst: 1.8, total: 11.8 },
    ], 11.8);
    assert.match(multiGstHtml, /lido-fee-breakdown-row/);
    assert.match(multiGstHtml, /lido-fee-breakdown-amount/);
    assert.match(multiGstHtml, /₹1\.00/);
    assert.match(multiGstHtml, /₹1\.80/);
});
