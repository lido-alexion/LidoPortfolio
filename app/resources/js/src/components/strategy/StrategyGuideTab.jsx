import React from 'react';
import { Link } from 'react-router-dom';

const PIPELINE_STEPS = [
    {
        title: '1. Screeners pick candidates',
        body: 'Screeners filter stocks with OHLCV conditions (e.g. Minervini Trend Template). Strategy never rewrites those rules — it only references Screeners you assign under Eligibility Sources.',
    },
    {
        title: '2. Strategy scores eligible stocks',
        body: 'Each enabled scoring factor (Relative Strength, Momentum, Trend, …) contributes a 0–100 score. Weights of enabled factors must sum to exactly 100. The overall score is a weighted blend of those factors.',
    },
    {
        title: '3. Thresholds choose the action',
        body: 'The overall score is compared to recommendation thresholds to suggest Open, Increase, Reduce, Exit, or Watch. Portfolio rules and behaviour flags can still block or reshape that action.',
    },
    {
        title: '4. Market gates (optional)',
        body: 'If enabled, new buys / increases are allowed only when Market Analysis sentiment, phase, and risk look acceptable. Strategy does not recalculate market metrics — it only declares the gates.',
    },
    {
        title: '5. Capital allocation + cash',
        body: 'Available investable cash is split across funded recommendations by allocation method and score bands. Unfunded ideas become Watch. Approving a buy can reserve cash until you execute, cancel, or the idea expires.',
    },
    {
        title: '6. Exit rules on holdings',
        body: 'Separately, Exit Strategy watches existing positions (MA breakdown, max loss, ATR stop, …). A triggered exit can produce Reduce / Exit recommendations even when the stock is no longer a Screener hit.',
    },
];

const TAB_GUIDES = [
    {
        id: 'general',
        label: 'General',
        body: (
            <>
                <p className="small text-muted mb-2">
                    Name, description, and optional change notes for the next saved version.
                    The factory Momentum Strategy is protected: saving forks a custom copy instead of overwriting the shipped default.
                </p>
                <ul className="small text-muted mb-0">
                    <li><strong>Duplicate Strategy</strong> — create a full copy to experiment safely.</li>
                    <li><strong>Save</strong> — writes a new version; historical recommendations keep the version they were generated with.</li>
                </ul>
            </>
        ),
    },
    {
        id: 'eligibility',
        label: 'Eligibility Sources',
        body: (
            <>
                <p className="small text-muted mb-2">
                    Attach one or more Screeners. A stock is eligible if it passes <strong>any</strong> enabled Screener (union).
                    Priority is for ordering / explainability — it does not require all Screeners to pass.
                </p>
                <ul className="small text-muted mb-0">
                    <li>Edit conditions only in the <Link to="/screeners">Screener</Link> module (use <em>View conditions</em>).</li>
                    <li>Assign at least one enabled Screener for production use.</li>
                    <li>Factory default usually references the Minervini Trend Template Screener.</li>
                </ul>
            </>
        ),
    },
    {
        id: 'scoring',
        label: 'Scoring Model',
        body: (
            <>
                <p className="small text-muted mb-2">
                    Rank eligible candidates. Scoring factors are <strong>not</strong> eligibility filters — a stock that fails a factor minimum may still get a low contribution, but it was already admitted by a Screener.
                </p>
                <ul className="small text-muted mb-0">
                    <li><strong>On</strong> — include the factor in the overall score.</li>
                    <li><strong>Weight</strong> — share of the overall score; enabled weights must total <strong>100</strong> (save is blocked otherwise; no auto-normalisation).</li>
                    <li><strong>Min / Max</strong> — soft targets for that factor (Risk uses Max as a risk cap).</li>
                    <li><strong>Parameters</strong> — lookbacks, RSI period, SMA lengths, ATR period, etc.</li>
                </ul>
                <p className="small text-muted mt-2 mb-0">
                    Categories: Momentum · Trend · Volume · Market · Risk. Factory weights emphasise Relative Strength (35), Trend (20), and Momentum (15).
                </p>
            </>
        ),
    },
    {
        id: 'thresholds',
        label: 'Recommendation Thresholds',
        body: (
            <>
                <p className="small text-muted mb-2">
                    Map the overall score (0–100) to a suggested action. Factory defaults (illustrative):
                </p>
                <ul className="small text-muted mb-0">
                    <li><strong>Minimum overall score</strong> — below this, usually no actionable buy path (factory 80).</li>
                    <li><strong>Open position</strong> — score needed to suggest a new buy (factory 85).</li>
                    <li><strong>Increase position</strong> — score to add to an existing holding (factory 90).</li>
                    <li><strong>Watch</strong> — interesting but not strong enough to fund (factory 60).</li>
                    <li><strong>Reduce / Exit position</strong> — weak scores that lean toward selling (factory 40 / 20).</li>
                </ul>
            </>
        ),
    },
    {
        id: 'portfolio',
        label: 'Portfolio Rules',
        body: (
            <>
                <p className="small text-muted mb-2">
                    Hard limits and behaviour toggles applied when recommendations are generated.
                </p>
                <ul className="small text-muted mb-0">
                    <li><strong>Position size %</strong> — min / max share of portfolio per idea.</li>
                    <li><strong>Cash deployment / reserve %</strong> — how much cash may be put to work vs kept aside.</li>
                    <li><strong>Max new positions</strong> — cap on fresh opens per generation cycle.</li>
                    <li><strong>Max exposure per stock</strong> — total concentration limit.</li>
                    <li><strong>Behaviour</strong> — allow increase, partial exit, averaging up/down.</li>
                </ul>
            </>
        ),
    },
    {
        id: 'allocation',
        label: 'Capital Allocation',
        body: (
            <>
                <p className="small text-muted mb-2">
                    How available investable cash is split among OPEN / INCREASE ideas that cleared thresholds and gates.
                </p>
                <ul className="small text-muted mb-0">
                    <li><strong>Proportional by score</strong> — stronger scores get larger slices (factory default).</li>
                    <li><strong>Simple ranking / Equal weight</strong> — alternate split methods.</li>
                    <li><strong>Tie-breaking</strong> — which secondary metric wins when scores match.</li>
                    <li><strong>Score bands</strong> — map score ranges to target allocation % of portfolio (e.g. 95–100 → 10%). Ideas that cannot be funded become Watch with an unfunded flag.</li>
                </ul>
            </>
        ),
    },
    {
        id: 'exit',
        label: 'Exit Strategy',
        body: (
            <>
                <p className="small text-muted mb-2">
                    Declarative sell-side rules on <strong>existing holdings</strong>, evaluated from Evaluation facts (not Screener condition trees).
                    When enabled, any triggered rule can drive Reduce / Exit suggestions (factory mode: any rule fires).
                </p>
                <ul className="small text-muted mb-0">
                    <li>Moving average breakdown, RS / trend weakening, overall score exit.</li>
                    <li>Maximum loss %, ATR multiple stop, trailing-stop proxy.</li>
                    <li>Toggle rules on/off and set thresholds — eligibility Screeners stay in the Screener module.</li>
                </ul>
            </>
        ),
    },
    {
        id: 'market',
        label: 'Market Gates',
        body: (
            <>
                <p className="small text-muted mb-2">
                    Optional overlay on Market Analysis Engine outputs (Dashboard Market Analytics). Factory ships with gates <strong>off</strong>.
                </p>
                <ul className="small text-muted mb-0">
                    <li><strong>Min sentiment</strong> — block new entries below this 0–100 sentiment.</li>
                    <li><strong>Allowed phases</strong> — only Open/Increase when the market phase is checked (e.g. Bull, Strong Bull).</li>
                    <li><strong>Max risk (raw)</strong> — block or shrink when market risk exceeds the cap.</li>
                </ul>
            </>
        ),
    },
    {
        id: 'cash',
        label: 'Cash Management',
        body: (
            <>
                <p className="small text-muted mb-2">
                    Controls how recommendations interact with the <Link to="/cash">Cash</Link> ledger.
                    Available cash = balance − reserved for pending-execution buys.
                </p>
                <ul className="small text-muted mb-0">
                    <li><strong>Reserve on approval</strong> — hold cash when you Approve a buy.</li>
                    <li><strong>Release on execution / cancellation / expiry</strong> — free the reservation when the idea closes out.</li>
                </ul>
            </>
        ),
    },
    {
        id: 'summary',
        label: 'Summary',
        body: (
            <p className="small text-muted mb-0">
                Read-only snapshot of name, version, enabled eligibility Screeners, weight total validity, and whether exit / market gates are on.
                Use it as a quick sanity check before generating recommendations.
            </p>
        ),
    },
];

/**
 * In-page help for Strategy configuration tabs.
 * @param {{ onOpenSection?: (sectionId: string) => void }} props
 */
export default function StrategyGuideTab({ onOpenSection }) {
    return (
        <div className="card">
            <div className="card-body">
                <h2 className="h5 mb-3">Strategy guide</h2>
                <p className="text-muted">
                    Strategy encodes your investment philosophy: which Screener hits to score, how to weight factors,
                    when to open or exit, how much capital to deploy, and optional market / cash gates.
                    It does <strong>not</strong> redefine Screener conditions — eligibility lives in the{' '}
                    <Link to="/screeners">Screener</Link> module.
                </p>

                <h3 className="h6 mt-4">How a recommendation is built</h3>
                <ol className="list-unstyled mb-0">
                    {PIPELINE_STEPS.map((step) => (
                        <li key={step.title} className="mb-3">
                            <div className="fw-semibold">{step.title}</div>
                            <p className="small text-muted mb-0">{step.body}</p>
                        </li>
                    ))}
                </ol>

                <pre className="small bg-body-tertiary border rounded p-3 mt-2 mb-4 text-muted" style={{ whiteSpace: 'pre-wrap' }}>
{`Screeners → eligible stocks
    → Strategy scoring (weights = 100)
    → Thresholds + portfolio rules + market gates
    → Capital allocation (available cash)
    → Recommendations (Approve → reserve cash → Execute)`}
                </pre>

                <h3 className="h6 mt-2">What each tab configures</h3>
                <p className="small text-muted mb-3">
                    Work left-to-right conceptually: Eligibility → Scoring → Thresholds → Portfolio / Allocation → Exit → optional Market &amp; Cash.
                </p>
                {TAB_GUIDES.map((tab) => (
                    <div key={tab.id} className="mb-4" id={`strategy-guide-${tab.id}`}>
                        <div className="d-flex flex-wrap align-items-baseline gap-2 mb-1">
                            <h4 className="h6 mb-0">{tab.label}</h4>
                            {typeof onOpenSection === 'function' && (
                                <button
                                    type="button"
                                    className="btn btn-link btn-sm p-0 align-baseline"
                                    onClick={() => onOpenSection(tab.id)}
                                >
                                    Open tab
                                </button>
                            )}
                        </div>
                        {tab.body}
                    </div>
                ))}

                <h3 className="h6 mt-2">Factory Momentum Strategy (defaults)</h3>
                <p className="small text-muted mb-2">
                    The shipped baseline is protected. Typical defaults you will see after a fresh install:
                </p>
                <ul className="small text-muted mb-0">
                    <li>Eligibility: Minervini Trend Template Screener.</li>
                    <li>Scoring weights sum to 100 (Relative Strength 35, Trend 20, Momentum 15, Breakout 10, Volume 8, Market Regime 5, Sector 4, Risk 3).</li>
                    <li>Open ≥ 85, Increase ≥ 90, Watch ≥ 60; max position ~10%; cash reserve ~20%.</li>
                    <li>Exit strategy on; market gates off.</li>
                </ul>
                <p className="small text-muted mt-3 mb-0">
                    After configuring, generate and review ideas on <Link to="/recommendations">Recommendations</Link>,
                    manage cash on <Link to="/cash">Cash</Link>, and refine filters in <Link to="/screeners">Screeners</Link>.
                </p>
            </div>
        </div>
    );
}
