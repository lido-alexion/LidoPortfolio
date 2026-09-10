import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const portfolios = readFileSync(new URL('../../resources/js/src/pages/PortfoliosPage.jsx', import.meta.url), 'utf8');
const switcher = readFileSync(new URL('../../resources/js/src/components/PortfolioSwitcher.jsx', import.meta.url), 'utf8');
const execution = readFileSync(new URL('../../resources/js/src/components/ExecutionModePanel.jsx', import.meta.url), 'utf8');
const dashboard = readFileSync(new URL('../../resources/js/src/pages/DashboardPage.jsx', import.meta.url), 'utf8');

test('Paper is selected only at creation with simulated cash and pinned fill method', () => {
    assert.match(portfolios, /portfolio_type: newType/);
    assert.match(portfolios, /starting_cash: Number\(startingCash\)/);
    assert.match(portfolios, /simulation_price_method: priceMethod/);
    assert.match(portfolios, /Next open/);
    assert.match(portfolios, /OHLC average/);
});

test('Paper identity and pause lifecycle remain visible while broker authority is unavailable', () => {
    assert.match(portfolios, /Pause simulation/);
    assert.match(portfolios, /Resume simulation/);
    assert.match(switcher, /· PAPER/);
    assert.match(execution, /Paper portfolios never connect or submit to Kite/);
    assert.match(dashboard, /Kite submission and reconciliation are unavailable/);
});
