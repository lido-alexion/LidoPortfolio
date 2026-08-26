import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const src = join(dirname(fileURLToPath(import.meta.url)), '../../resources/js/src');
const page = readFileSync(join(src, 'pages/StrategyPage.jsx'), 'utf8');
const registry = readFileSync(join(src, 'pages/StrategyRegistryPage.jsx'), 'utf8');
const nav = readFileSync(join(src, 'config/navigation.js'), 'utf8');
const docs = readFileSync(join(src, 'data/appDocumentation.js'), 'utf8');
const settings = readFileSync(join(src, 'pages/SettingsPage.jsx'), 'utf8');
const evaluator = readFileSync(
    join(dirname(fileURLToPath(import.meta.url)), '../../app/Engines/Strategy/ExitStrategyEvaluator.php'),
    'utf8',
);

test('Strategy editor no longer claims one strategy per portfolio', () => {
    assert.doesNotMatch(page, /Each portfolio has one strategy/);
    assert.match(page, /may enable multiple strategies/i);
});

test('Strategy editor exposes a visible strategy selector using strategy_id', () => {
    assert.match(page, /id="strategy-editor-select"/);
    assert.match(page, /Editing strategy/);
    assert.match(page, /\/v1\/strategy-registry/);
    assert.match(page, /navigate\(`\/strategy\?strategy_id=/);
});

test('Strategy Registry is discoverable in sidebar and editor', () => {
    assert.match(nav, /id: 'strategy-registry'/);
    assert.match(nav, /showInSidebar: true/);
    assert.match(page, /to="\/strategy\/registry"/);
    assert.doesNotMatch(registry, /Select it to make it active/);
    assert.doesNotMatch(registry, /use Select to activate/);
    assert.match(registry, /Use Enable to turn it on/);
    assert.match(registry, /Multiple strategies may be enabled/);
});

test('Cash Management reservation controls are absent from Strategy page', () => {
    assert.doesNotMatch(page, /Cash Management/);
    assert.doesNotMatch(page, /Cash reservations enabled/);
    assert.doesNotMatch(page, /Reserve on approval/);
    assert.doesNotMatch(page, /id: 'cash'/);
});

test('Strategy minimum cash reserve and max cash deployment controls are absent', () => {
    assert.doesNotMatch(page, /Minimum Cash Reserve %/);
    assert.doesNotMatch(page, /Maximum Cash Deployment %/);
    assert.doesNotMatch(page, /min_cash_reserve_pct/);
    assert.doesNotMatch(page, /max_cash_deployment_pct/);
});

test('Strategy Maximum Loss and V1 Trailing Stop controls are absent; Market Gates / horizon / first entry remain', () => {
    assert.doesNotMatch(page, /Maximum Loss \(Value/);
    assert.doesNotMatch(page, /key === 'max_loss'/);
    assert.doesNotMatch(page, /key === 'trailing_stop'/);
    assert.doesNotMatch(page, /Trailing Stop \(Value/);
    assert.match(page, /Market Gates/);
    assert.match(page, /Enable market gates/);
    assert.match(page, /id="horizon-calendar-days"/);
    assert.match(page, /id="first-entry-pct"/);
    assert.doesNotMatch(page, /buy_cooldown/);
    assert.match(page, /BUY cooldown: 1 calendar day \(OD-11; not configurable\)/);
});

test('Portfolio Settings exposes OD-12 / opportunity-cost / max position / lending limits and registry Archive', () => {
    assert.match(settings, /Minimum actionable BUY \/ INCREASE/);
    assert.match(settings, /minimum_actionable_buy_amount/);
    assert.match(settings, /Opportunity-cost rate %/);
    assert.match(settings, /opportunity_cost_rate/);
    assert.match(settings, /Portfolio max position size %/);
    assert.match(settings, /portfolio_max_position_pct/);
    assert.match(settings, /max_lending_pct_of_unused/);
    assert.match(settings, /max_lending_absolute/);
    assert.match(settings, /Atomic block ₹5,000 · Execution margin 1% \(OD-06\)/);
    assert.match(registry, /Archive this strategy\?/);
    assert.match(registry, /\/archive/);
});

test('Portfolio Settings still exposes stop-loss and trailing', () => {
    assert.match(settings, /Portfolio Stop-Loss %/);
    assert.match(settings, /Portfolio Trailing Stop %/);
    assert.match(settings, /default_stoploss_percent/);
    assert.match(settings, /portfolio_trailing_percent/);
});

test('ExitStrategyEvaluator ignores legacy max_loss and trailing_stop', () => {
    assert.match(evaluator, /IGNORED_ACCOUNT_EXIT_KEYS/);
    assert.match(evaluator, /'max_loss',\s*'trailing_stop'/);
    assert.doesNotMatch(evaluator, /case 'max_loss':/);
    assert.doesNotMatch(evaluator, /case 'trailing_stop':/);
});

test('Strategy Portfolio Rules expose hard maximum holdings', () => {
    assert.match(page, /id="max-holdings"/);
    assert.match(page, /Hard maximum holdings/);
    assert.match(page, /max_holdings/);
});

test('Strategy Portfolio Rules expose OD-16 weakest-position evaluation window', () => {
    assert.match(page, /id="weakest-position-window-days"/);
    assert.match(page, /Weakest-position evaluation window \(calendar days\)/);
    assert.match(page, /weakest_position_window_days/);
    assert.match(page, /weakestWindowCheck\.persist/);
    assert.match(docs, /weakest_position_window_days/);
    assert.match(docs, /OD-16/);
});

test('Strategy Registry exposes allocation % editor and sum-100 save', () => {
    assert.match(registry, /id="strategy-registry-allocation"/);
    assert.match(registry, /\/v1\/capital\/allocations/);
    assert.match(registry, /Save allocations/);
    assert.match(registry, /Allocation %/);
    assert.match(registry, /allocSumIs100/);
});

test('help docs no longer describe Strategy Cash Management or V1 max_loss/trailing as Strategy exits', () => {
    assert.doesNotMatch(docs, /name: 'Cash Management tab'/);
    assert.doesNotMatch(docs, /strategy-specific V1 proxy inside Exit Strategy/);
    assert.match(docs, /Editing strategy dropdown/);
    assert.match(docs, /Legacy V1 strategy JSON max_loss \/ trailing_stop are ignored/);
});
