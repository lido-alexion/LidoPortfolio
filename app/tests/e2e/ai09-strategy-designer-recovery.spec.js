import { test, expect } from '@playwright/test';
import { journeyId, seedDeterministicJourney } from './journeyTestUtils.js';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';
import { handleApiFailureDiagnosticForPage, isApiFailureDiagnosticRequest, registerApiFailureDiagnosticFixtureHooks } from './apiFailureDiagnosticBoundary.js';

registerApiFailureDiagnosticFixtureHooks(test);

const VIEWPORTS = [
    { name: 'mobile', width: 390, height: 844 },
    { name: 'desktop', width: 1440, height: 900 },
];
const STRATEGY_PATH = '/api/ai/insights/strategy';
const STRATEGY_GLOB = `**${STRATEGY_PATH}`;
const AI_ERROR = {
    success: false,
    error: { code: 'AI_UNAVAILABLE', message: 'The managed strategy service is unavailable.' },
};
const CACHED_A = {
    status: 'ready',
    fingerprint: 'strategy-cache-a'.padEnd(64, 'a'),
    response: {
        strategy_summary: 'Cached advisory design for configuration A.',
        target_market_universe: 'NSE shares in the configured universe.',
        entry_logic: 'Review a confirmed range expansion.',
        exit_logic: 'Use the configured score-based exit.',
        allocation_position_sizing: 'Respect the configured maximum positions.',
        risk_controls: 'Review exposure before any separate action.',
        assumptions: 'Daily price history is available.',
        caveats: 'This fixture is not investment advice.',
        rationale: 'The design matches the selected research profile.',
        artifact_compatibility_notes: 'Advisory result only; no strategy artifact was created.',
    },
    data_as_of: { ohlcv_through: '2026-10-16', fundamentals_period: '2026-06-30' },
};

function configuredInputs({ investmentStyle = 'Custom', maximumPositions = 7, additionalConstraints = 'Exclude stocks without 90 daily bars.' } = {}) {
    return {
        investmentStyle,
        customInvestmentStyle: 'Range expansion after liquidity review',
        riskProfile: 'Medium',
        holdingPeriod: '1–4 Weeks',
        customHoldingPeriod: '',
        targetMarket: 'NSE',
        customTargetMarket: '',
        universe: 'All Equities',
        customUniverse: '',
        maximumPositions,
        capitalAllocation: 'Conviction Based',
        customCapitalAllocation: '',
        preferredExitStyle: 'Score Based',
        customPreferredExitStyle: '',
        marketPreferences: ['Bull Markets'],
        optimizationPriorities: ['Higher Win Rate', 'Lower Drawdown'],
        strategyComplexity: 'Balanced',
        explainabilityLevel: 'Standard',
        additionalConstraints,
    };
}

const INPUT_KEYS = Object.keys(configuredInputs());
const INPUT_A = configuredInputs();
const INPUT_B = configuredInputs({
    investmentStyle: 'Value Investing',
    maximumPositions: 6,
    additionalConstraints: 'Require two consecutive audited annual reports.',
});
const DEFAULT_INPUTS = {
    investmentStyle: 'Momentum Investing',
    customInvestmentStyle: '',
    riskProfile: 'Medium',
    holdingPeriod: '1–4 Weeks',
    customHoldingPeriod: '',
    targetMarket: 'NSE',
    customTargetMarket: '',
    universe: 'All Equities',
    customUniverse: '',
    maximumPositions: 5,
    capitalAllocation: 'Conviction Based',
    customCapitalAllocation: '',
    preferredExitStyle: 'Score Based',
    customPreferredExitStyle: '',
    marketPreferences: ['Bull Markets'],
    optimizationPriorities: ['Higher Win Rate', 'Lower Drawdown'],
    strategyComplexity: 'Balanced',
    explainabilityLevel: 'Standard',
    additionalConstraints: '',
};

function sameJson(left, right) {
    return JSON.stringify(left) === JSON.stringify(right);
}

function strategyResponse(response, allowedOrigin, predicate = () => true) {
    try {
        const url = new URL(response.url());
        const request = response.request();
        return url.origin === allowedOrigin
            && url.pathname === STRATEGY_PATH
            && request.method() === 'POST'
            && predicate(request.postDataJSON());
    } catch {
        return false;
    }
}

async function installBoundaries(page, mode) {
    await page.addInitScript(() => {
        window.localStorage.removeItem('lido.strategy.aiPromptBuilder.v1');
        window.__stoxStrategyClipboardWrites = [];
        Object.defineProperty(window.navigator, 'clipboard', {
            configurable: true,
            value: {
                writeText: async (text) => { window.__stoxStrategyClipboardWrites.push(String(text)); },
            },
        });
    });
    await installInvestorWorkflowApiMocks(page);

    const allowedOrigin = new URL(process.env.STOX_E2E_BASE_URL || 'http://127.0.0.1:4177').origin;
    const observedRequests = [];
    const strategyEntries = [];
    const denied = [];
    const invalidPayloads = [];
    page.on('request', (request) => {
        const url = new URL(request.url());
        observedRequests.push({ request, protocol: url.protocol, origin: url.origin, pathname: url.pathname });
    });

    await page.route(STRATEGY_GLOB, async (route) => {
        const request = route.request();
        const payload = request.postDataJSON();
        const entry = { request, payload, expected: null };
        strategyEntries.push(entry);

        const keysMatch = payload && Object.keys(payload).sort().join('|') === ['inputs', 'lookup_only', 'refresh'].sort().join('|');
        const inputKeysMatch = payload?.inputs && Object.keys(payload.inputs).sort().join('|') === [...INPUT_KEYS].sort().join('|');
        if (request.method() !== 'POST' || !keysMatch || !inputKeysMatch
            || typeof payload.refresh !== 'boolean' || typeof payload.lookup_only !== 'boolean'
            || (payload.refresh && payload.lookup_only)) {
            invalidPayloads.push(payload);
            entry.expected = { status: 400, body: { error: { code: 'INVALID_FIXTURE_REQUEST' } } };
            await route.fulfill({ status: 400, contentType: 'application/json', body: JSON.stringify(entry.expected.body) });
            return;
        }

        if (payload.lookup_only) {
            const matchingCache = mode === 'cached-a-then-b' && sameJson(payload.inputs, INPUT_A);
            entry.expected = {
                status: 200,
                // The HTTP route returns the API's single `data` envelope; Axios
                // supplies its own response.data wrapper to the component.
                body: { data: matchingCache ? CACHED_A : { status: 'missing' } },
            };
        } else if (payload.refresh === true) {
            if (mode !== 'cached-a-then-b' || !sameJson(payload.inputs, INPUT_A)) invalidPayloads.push(payload);
            entry.expected = { status: 503, body: AI_ERROR };
        } else {
            const expectedInputs = mode === 'cached-a-then-b' ? INPUT_B : DEFAULT_INPUTS;
            if (!sameJson(payload.inputs, expectedInputs)) invalidPayloads.push(payload);
            entry.expected = { status: 503, body: AI_ERROR };
        }

        await route.fulfill({
            status: entry.expected.status,
            contentType: 'application/json',
            body: JSON.stringify(entry.expected.body),
        });
    });

    // This catch-all is registered last, so it is the outer guard. route.fallback()
    // continues to the specific synthetic handlers above and the shared read fixtures.
    await page.route('**/*', async (route) => {
        const request = route.request();
        const url = new URL(request.url());
        if (await handleApiFailureDiagnosticForPage(route, page, allowedOrigin)) return;
        if ((url.protocol === 'http:' || url.protocol === 'https:') && url.origin !== allowedOrigin) {
            denied.push({ method: request.method(), origin: url.origin, pathname: url.pathname });
            return route.abort();
        }
        if (/^(POST|PUT|PATCH|DELETE)$/.test(request.method())
            && !(request.method() === 'POST' && url.pathname === STRATEGY_PATH)) {
            denied.push({ method: request.method(), origin: url.origin, pathname: url.pathname });
            return route.abort();
        }
        return route.fallback();
    });

    return { allowedOrigin, observedRequests, strategyEntries, denied, invalidPayloads };
}

async function settleStrategyEntries(entries, start, allowedOrigin) {
    const settled = new Set();
    const outcomes = [];
    let previousLength = entries.length;
    while (true) {
        const pending = entries.slice(start).filter((entry) => !settled.has(entry));
        if (pending.length) {
            const batch = await Promise.all(pending.map(async (entry) => {
                expect(entry.request.method()).toBe('POST');
                expect(new URL(entry.request.url()).origin).toBe(allowedOrigin);
                expect(new URL(entry.request.url()).pathname).toBe(STRATEGY_PATH);
                const response = await entry.request.response();
                if (!response) {
                    expect(entry.payload.lookup_only).toBe(true);
                    expect(entry.request.failure()?.errorText).toBe('net::ERR_ABORTED');
                    return { entry, kind: 'expected-abort' };
                }
                expect(response.status()).toBe(entry.expected.status);
                expect(await response.finished()).toBeNull();
                const body = await response.json();
                expect(body).toEqual(entry.expected.body);
                return { entry, kind: 'response', status: response.status(), body };
            }));
            for (const outcome of batch) {
                settled.add(outcome.entry);
                outcomes.push(outcome);
            }
        }
        await Promise.resolve();
        if (entries.length === previousLength && entries.slice(start).every((entry) => settled.has(entry))) break;
        previousLength = entries.length;
    }
    expect(outcomes.some((outcome) => outcome.kind === 'response')).toBe(true);
    return outcomes;
}

async function assertOnlyStrategyMutations(boundaries) {
    const httpRequests = boundaries.observedRequests.filter(({ protocol }) => protocol === 'http:' || protocol === 'https:');
    expect(httpRequests.length).toBeGreaterThan(0);
    expect(httpRequests.every(({ origin }) => origin === boundaries.allowedOrigin)).toBe(true);
    const mutations = httpRequests.filter(({ request }) => /^(POST|PUT|PATCH|DELETE)$/.test(request.method())
        && !isApiFailureDiagnosticRequest(request, boundaries.allowedOrigin));
    expect(mutations.length).toBeGreaterThan(0);
    expect(mutations.every(({ request, pathname }) => request.method() === 'POST' && pathname === STRATEGY_PATH)).toBe(true);
    expect(boundaries.denied).toEqual([]);
    expect(boundaries.invalidPayloads).toEqual([]);
    expect(boundaries.strategyEntries.every(({ payload }) => payload.refresh === false || payload.refresh === true)).toBe(true);
    expect(boundaries.strategyEntries.every(({ payload }) => payload.lookup_only === false || payload.lookup_only === true)).toBe(true);
}

async function openDesigner(page) {
    await page.goto('/strategy');
    await page.getByRole('button', { name: 'AI Strategy Designer', exact: true }).click();
    const panel = page.locator('#ai-strategy-designer-panel');
    await expect(panel).toBeVisible();
    return panel;
}

async function setInputsA(panel, page, allowedOrigin) {
    await panel.getByLabel('Investment Style').selectOption('Custom');
    await panel.getByLabel('Describe Investing Style').fill(INPUT_A.customInvestmentStyle);
    await panel.getByLabel('Maximum Positions').fill(String(INPUT_A.maximumPositions));
    const cachedLookup = page.waitForResponse((response) => strategyResponse(
        response,
        allowedOrigin,
        (payload) => payload.lookup_only === true && sameJson(payload.inputs, INPUT_A) && response.status() === 200,
    ));
    await panel.getByLabel('Additional Constraints').fill(INPUT_A.additionalConstraints);
    return cachedLookup;
}

function assertPromptValues(prompt, inputs) {
    const values = [
        ['INVESTING STYLE', inputs.investmentStyle === 'Custom' ? inputs.customInvestmentStyle : inputs.investmentStyle],
        ['RISK PROFILE', inputs.riskProfile],
        ['HOLDING PERIOD', inputs.holdingPeriod],
        ['TARGET MARKET', inputs.targetMarket],
        ['UNIVERSE', inputs.universe],
        ['MAXIMUM POSITIONS', String(inputs.maximumPositions)],
        ['CAPITAL ALLOCATION', inputs.capitalAllocation],
        ['PREFERRED EXIT STYLE', inputs.preferredExitStyle],
        ['MARKET PREFERENCE', inputs.marketPreferences.join(', ')],
        ['OPTIMIZATION PRIORITIES', inputs.optimizationPriorities.join(', ')],
        ['STRATEGY COMPLEXITY', inputs.strategyComplexity],
        ['EXPLAINABILITY LEVEL', inputs.explainabilityLevel],
        ['ADDITIONAL CONSTRAINTS', inputs.additionalConstraints],
    ];
    expect(prompt).toContain('You are an expert quantitative trading system designer.');
    for (const [heading, value] of values) expect(prompt).toContain(`${heading}\n\n${value}`);
    expect(prompt).not.toMatch(/\{\{\w+\}\}/);
}

async function assertCachedDesign(panel) {
    const result = panel.getByRole('region', { name: 'Strategy design result', exact: true });
    await expect(result).toBeVisible();
    for (const heading of [
        'Strategy summary', 'Target market / universe', 'Entry logic', 'Exit logic',
        'Allocation / position sizing', 'Risk controls', 'Assumptions', 'Caveats',
        'Rationale', 'StoX artifact compatibility',
    ]) {
        await expect(result.getByRole('heading', { name: heading, exact: true })).toBeVisible();
    }
    await expect(result.getByText('Cached advisory design for configuration A.', { exact: true })).toBeVisible();
    const provenance = result.getByLabel('Based on / Data used');
    await expect(provenance.getByText('OHLCV through 2026-10-16', { exact: true })).toBeVisible();
    await expect(provenance.getByText('Fundamentals through 2026-06-30', { exact: true })).toBeVisible();
    await expect(result.getByRole('button', { name: 'Copy result', exact: true })).toBeVisible();
    await expect(result.getByRole('button', { name: 'Create draft strategy', exact: true })).toBeVisible();
    await expect(result.getByRole('button', { name: 'Copy AI Prompt', exact: true })).toHaveCount(0);
}

for (const viewport of VIEWPORTS) {
    test(`AI-09 retains a matching cached design and uses current-input prompt recovery (${viewport.name})`, async ({ page }, testInfo) => {
        journeyId(testInfo, 'AI-09');
        await seedDeterministicJourney(page, `ai09-cache-and-input-recovery-${viewport.name}`);
        await page.setViewportSize({ width: viewport.width, height: viewport.height });
        const boundaries = await installBoundaries(page, 'cached-a-then-b');
        const { allowedOrigin, strategyEntries } = boundaries;
        const panel = await openDesigner(page);

        const cachedLookupResponse = await setInputsA(panel, page, allowedOrigin);
        expect(cachedLookupResponse.status()).toBe(200);
        await settleStrategyEntries(strategyEntries, 0, allowedOrigin);
        await assertCachedDesign(panel);
        expect(strategyEntries.filter(({ payload }) => payload.lookup_only === false)).toHaveLength(0);
        expect(await page.evaluate(() => window.__stoxStrategyClipboardWrites)).toEqual([]);

        let phaseStart = strategyEntries.length;
        const refreshResponsePromise = page.waitForResponse((response) => strategyResponse(
            response,
            allowedOrigin,
            (payload) => payload.refresh === true && payload.lookup_only === false && sameJson(payload.inputs, INPUT_A),
        ));
        await panel.getByRole('button', { name: 'Regenerate', exact: true }).click();
        const refreshResponse = await refreshResponsePromise;
        expect(refreshResponse.status()).toBe(503);
        await settleStrategyEntries(strategyEntries, phaseStart, allowedOrigin);
        await expect(panel.getByText('Fresh AI refresh unavailable; showing the latest cached insight.', { exact: true })).toBeVisible();
        await expect(panel.getByText('Cached advisory design for configuration A.', { exact: true })).toBeVisible();

        await panel.getByRole('button', { name: 'Copy AI Prompt', exact: true }).click();
        await expect.poll(() => page.evaluate(() => window.__stoxStrategyClipboardWrites.length)).toBe(1);
        const promptA = (await page.evaluate(() => window.__stoxStrategyClipboardWrites))[0];
        assertPromptValues(promptA, INPUT_A);

        phaseStart = strategyEntries.length;
        await panel.getByLabel('Investment Style').selectOption(INPUT_B.investmentStyle);
        await panel.getByLabel('Maximum Positions').fill(String(INPUT_B.maximumPositions));
        await expect(panel.getByText('Cached advisory design for configuration A.', { exact: true })).toHaveCount(0);
        const currentLookupPromise = page.waitForResponse((response) => strategyResponse(
            response,
            allowedOrigin,
            (payload) => payload.lookup_only === true && sameJson(payload.inputs, INPUT_B) && response.status() === 200,
        ));
        await panel.getByLabel('Additional Constraints').fill(INPUT_B.additionalConstraints);
        const currentLookupResponse = await currentLookupPromise;
        expect(currentLookupResponse.status()).toBe(200);
        await settleStrategyEntries(strategyEntries, phaseStart, allowedOrigin);
        await expect(panel.getByRole('button', { name: 'Generate strategy', exact: true })).toBeVisible();
        await expect(panel.getByText('Cached advisory design for configuration A.', { exact: true })).toHaveCount(0);
        await expect(panel.getByRole('button', { name: 'Copy result', exact: true })).toHaveCount(0);

        phaseStart = strategyEntries.length;
        const generationResponsePromise = page.waitForResponse((response) => strategyResponse(
            response,
            allowedOrigin,
            (payload) => payload.lookup_only === false && payload.refresh === false && sameJson(payload.inputs, INPUT_B),
        ));
        await panel.getByRole('button', { name: 'Generate strategy', exact: true }).click();
        const generationResponse = await generationResponsePromise;
        expect(generationResponse.status()).toBe(503);
        await settleStrategyEntries(strategyEntries, phaseStart, allowedOrigin);
        await expect(panel.getByText('Managed AI is unavailable. You can copy the AI prompt and try manually.', { exact: true })).toBeVisible();
        await expect(panel.getByRole('button', { name: 'Copy result', exact: true })).toHaveCount(0);
        await expect(panel.getByText('Cached advisory design for configuration A.', { exact: true })).toHaveCount(0);

        await panel.getByRole('button', { name: 'Copy AI Prompt', exact: true }).click();
        await expect.poll(() => page.evaluate(() => window.__stoxStrategyClipboardWrites.length)).toBe(2);
        const promptB = (await page.evaluate(() => window.__stoxStrategyClipboardWrites))[1];
        assertPromptValues(promptB, INPUT_B);
        await expect(page).toHaveURL(/\/strategy(?:[?#]|$)/);
        await expect(panel.getByLabel('Additional Constraints')).toBeEnabled();

        const explicitActions = strategyEntries
            .filter(({ payload }) => payload.lookup_only === false)
            .map(({ payload }) => ({ inputs: payload.inputs, refresh: payload.refresh, lookup_only: payload.lookup_only }));
        expect(explicitActions).toEqual([
            { inputs: INPUT_A, refresh: true, lookup_only: false },
            { inputs: INPUT_B, refresh: false, lookup_only: false },
        ]);
        await assertOnlyStrategyMutations(boundaries);
    });

    test(`AI-09 exposes manual prompt recovery when no matching cache or generated result exists (${viewport.name})`, async ({ page }, testInfo) => {
        journeyId(testInfo, 'AI-09');
        await seedDeterministicJourney(page, `ai09-no-cache-recovery-${viewport.name}`);
        await page.setViewportSize({ width: viewport.width, height: viewport.height });
        const boundaries = await installBoundaries(page, 'no-cache');
        const { allowedOrigin, strategyEntries } = boundaries;
        const initialLookup = page.waitForResponse((response) => strategyResponse(
            response,
            allowedOrigin,
            (payload) => payload.lookup_only === true && sameJson(payload.inputs, DEFAULT_INPUTS) && response.status() === 200,
        ));
        const panel = await openDesigner(page);
        const lookupResponse = await initialLookup;
        expect(lookupResponse.status()).toBe(200);
        await settleStrategyEntries(strategyEntries, 0, allowedOrigin);
        await expect(panel.getByRole('button', { name: 'Generate strategy', exact: true })).toBeVisible();
        await expect(panel.getByRole('button', { name: 'Copy AI Prompt', exact: true })).toHaveCount(0);

        const phaseStart = strategyEntries.length;
        const generationResponsePromise = page.waitForResponse((response) => strategyResponse(
            response,
            allowedOrigin,
            (payload) => payload.lookup_only === false && payload.refresh === false && sameJson(payload.inputs, DEFAULT_INPUTS),
        ));
        await panel.getByRole('button', { name: 'Generate strategy', exact: true }).click();
        const generationResponse = await generationResponsePromise;
        expect(generationResponse.status()).toBe(503);
        await settleStrategyEntries(strategyEntries, phaseStart, allowedOrigin);

        await expect(panel.getByText('Managed AI is unavailable. You can copy the AI prompt and try manually.', { exact: true })).toBeVisible();
        await expect(panel.getByRole('button', { name: 'Copy result', exact: true })).toHaveCount(0);
        await expect(panel.getByRole('button', { name: 'Copy AI Prompt', exact: true })).toBeVisible();
        await expect(panel.getByRole('button', { name: 'Create draft strategy', exact: true })).toHaveCount(0);
        await panel.getByRole('button', { name: 'Copy AI Prompt', exact: true }).click();
        await expect.poll(() => page.evaluate(() => window.__stoxStrategyClipboardWrites.length)).toBe(1);
        assertPromptValues((await page.evaluate(() => window.__stoxStrategyClipboardWrites))[0], DEFAULT_INPUTS);
        await expect(panel.getByLabel('Additional Constraints')).toBeEnabled();

        const explicitActions = strategyEntries
            .filter(({ payload }) => payload.lookup_only === false)
            .map(({ payload }) => ({ inputs: payload.inputs, refresh: payload.refresh, lookup_only: payload.lookup_only }));
        expect(explicitActions).toEqual([{ inputs: DEFAULT_INPUTS, refresh: false, lookup_only: false }]);
        await assertOnlyStrategyMutations(boundaries);
    });
}
