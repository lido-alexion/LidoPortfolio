#!/usr/bin/env node

import { readFile } from 'node:fs/promises';
import { calculateGroupedTransactionFees } from '../resources/js/src/utils/feeCalculator.js';

function paise(value) {
    const rounded = Math.round(Number(value || 0) * 100);
    return rounded === 0 ? 0 : rounded;
}

/** Compare fee components at the evidence granularity supplied by the source note. */
export function compareReconciliation(input) {
    const calculatedByDay = new Map();
    const fillsByDay = new Map();
    const fees = calculateGroupedTransactionFees(input.fills || [], input.fee_components);
    (input.fills || []).forEach((fill, index) => {
        const date = String(fill.transaction_date || 'unknown');
        fillsByDay.set(date, (fillsByDay.get(date) || 0) + 1);
        if (!calculatedByDay.has(date)) calculatedByDay.set(date, {});
        for (const line of fees[index].breakdown) {
            const row = calculatedByDay.get(date);
            row[line.id] = (row[line.id] || 0) + Number(line.total || 0);
        }
    });

    const notes = (input.invoice_days || []).map((invoice) => {
        const calculated = calculatedByDay.get(invoice.date) || {};
        const components = Object.keys(invoice.components || {}).sort().map((component) => {
            const calculatedAmount = Number(calculated[component] || 0);
            const sourceAmount = Math.abs(Number(invoice.components[component] || 0));
            return {
                component,
                calculated: Number(calculatedAmount.toFixed(2)),
                source: Number(sourceAmount.toFixed(2)),
                variance_paise: paise(calculatedAmount - sourceAmount),
                evidence: fillsByDay.get(invoice.date) === 1 ? 'single_fill_invoice' : 'daily_aggregate_allocation',
            };
        });
        return { date: invoice.date, components };
    });

    const cashEvents = (input.cash_event_pairs || []).map((pair) => {
        const variance = Number(pair.stox_amount) - Number(pair.source_amount);
        return {
            date: pair.date,
            event_type: pair.event_type,
            stox_amount: Number(Number(pair.stox_amount).toFixed(4)),
            source_amount: Number(Number(pair.source_amount).toFixed(6)),
            variance_paise: Number((variance * 100).toFixed(4)),
            evidence: 'matched_source_event',
        };
    });

    return {
        notes,
        cash_events: cashEvents,
        limitations: [
            'Daily invoice component totals do not uniquely prove individual fill allocations.',
            'Cash comparisons include only explicitly paired source events; aggregate net settlement is not a trade-level cash event.',
            'StoX stores four decimal places; source precision below one paise may not be representable.',
        ],
    };
}

async function main() {
    const inputPath = process.argv[2];
    if (!inputPath) {
        process.stderr.write('Usage: node scripts/zerodha-fee-comparator.mjs <sanitized-input.json>\n');
        process.exitCode = 64;
        return;
    }
    const input = JSON.parse(await readFile(inputPath, 'utf8'));
    process.stdout.write(`${JSON.stringify(compareReconciliation(input), null, 2)}\n`);
}

if (process.argv[1] && import.meta.url === new URL(`file://${process.argv[1]}`).href) {
    main().catch((error) => {
        process.stderr.write(`${error.message}\n`);
        process.exitCode = 1;
    });
}
