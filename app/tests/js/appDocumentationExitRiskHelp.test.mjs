import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const root = join(dirname(fileURLToPath(import.meta.url)), '../../resources/js/src/data/appDocumentation.js');
const source = readFileSync(root, 'utf8');

test('help documents Portfolio Stop-Loss and Trailing Stop terminology', () => {
    assert.match(source, /Portfolio Stop-Loss/);
    assert.match(source, /Portfolio Trailing Stop/);
    assert.match(source, /weighted-average actual fill cost/i);
    assert.match(source, /maximum raw daily close/i);
    assert.match(source, /default\/seed 15%/i);
});

test('help documents exit precedence order', () => {
    assert.match(source, /Strategy exit → Portfolio Stop-Loss → Portfolio Trailing Stop → Strategy Horizon/);
});

test('help does not describe portfolio trailing as unrealized-% proxy', () => {
    // Strategy-specific V1 trailing proxy may still be documented; portfolio trailing must not be framed as unrealized %.
    const portfolioTrailingConcept = source.match(
        /name: 'Portfolio Trailing Stop'[\s\S]*?(?=\{\s*name:|'related':)/,
    );
    assert.ok(portfolioTrailingConcept, 'expected Portfolio Trailing Stop concept block');
    assert.doesNotMatch(portfolioTrailingConcept[0], /unrealized % ≤/i);
    assert.match(portfolioTrailingConcept[0], /Not an unrealized-% proxy/i);
});

test('help keeps strategy JSON trailing_stop as strategy-specific (not claimed removed)', () => {
    assert.match(source, /strategy-specific V1 proxy inside Exit Strategy/i);
    assert.match(source, /separate from the portfolio-level Portfolio Trailing Stop/i);
});

test('help documents primary exit attribution canonical values', () => {
    assert.match(source, /strategy_exit/);
    assert.match(source, /stop_loss/);
    assert.match(source, /trailing_stop/);
    assert.match(source, /horizon_expiry/);
    assert.match(source, /Primary exit reason/);
});
