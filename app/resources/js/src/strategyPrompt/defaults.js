/**
 * AI Strategy Designer — form option catalogues and defaults.
 */

export const INVESTMENT_STYLES = [
    'Swing Trading',
    'Momentum Investing',
    'Breakout',
    'Trend Following',
    'Position Trading',
    'Mean Reversion',
    'Value Investing',
    'Growth Investing',
    'GARP',
    'CANSLIM',
    'Minervini Trend Template',
    'Darvas Box',
    'Volatility Breakout',
    'Sector Rotation',
    'Dividend Investing',
    'Custom',
];

export const RISK_PROFILES = ['Low', 'Medium', 'High', 'Aggressive'];

export const HOLDING_PERIODS = [
    'Intraday',
    '2–5 Days',
    '1–4 Weeks',
    '1–3 Months',
    '3–12 Months',
    'Long Term',
    'Custom',
];

export const TARGET_MARKETS = ['NSE', 'BSE', 'NSE + BSE', 'US Equities', 'Custom'];

export const UNIVERSES = [
    'All Equities',
    'Watchlist',
    'Current Holdings',
    'Portfolio Candidates',
    'Index Constituents',
    'Custom',
];

export const CAPITAL_ALLOCATIONS = [
    'Equal Weight',
    'Risk Weighted',
    'Conviction Based',
    'Volatility Adjusted',
    'Custom',
];

export const EXIT_STYLES = [
    'Trailing Stop',
    'ATR Stop',
    'Moving Average Breakdown',
    'Score Based',
    'Trend Reversal',
    'Momentum Weakening',
    'Time Based',
    'Screener Based',
    'Custom',
];

export const MARKET_PREFERENCES = [
    'Bull Markets',
    'Bear Markets',
    'Sideways Markets',
    'High Volatility',
    'Low Volatility',
    'No Preference',
];

export const OPTIMIZATION_PRIORITIES = [
    'Higher Win Rate',
    'Higher CAGR',
    'Lower Drawdown',
    'Lower Volatility',
    'Earlier Entries',
    'Fewer Trades',
    'Higher Liquidity',
    'Large Caps',
    'Mid Caps',
    'Small Caps',
];

export const STRATEGY_COMPLEXITIES = [
    {
        id: 'Simple',
        label: 'Simple',
        guidance: 'Easy to understand. Few screener conditions. Few scoring factors.',
    },
    {
        id: 'Balanced',
        label: 'Balanced (Default)',
        guidance: 'Suitable for most users. Moderate complexity. Good trade-off between performance and maintainability.',
    },
    {
        id: 'Advanced',
        label: 'Advanced',
        guidance: 'More sophisticated filtering. Richer scoring model. More nuanced strategy.',
    },
    {
        id: 'Expert',
        label: 'Expert',
        guidance: 'Maximize sophistication within StoX capabilities. Use advanced combinations where appropriate. Optimize for quality over simplicity.',
    },
];

export const EXPLAINABILITY_LEVELS = [
    {
        id: 'Minimal',
        label: 'Minimal',
        guidance: 'Focus primarily on JSON output.',
    },
    {
        id: 'Standard',
        label: 'Standard (Default)',
        guidance: 'Explain major design decisions.',
    },
    {
        id: 'Detailed',
        label: 'Detailed',
        guidance: 'Explain every condition, scoring factor, threshold, and trade-off.',
    },
];

/** @typedef {import('./generatePrompt.js').StrategyPromptInputs} StrategyPromptInputs */

/** @type {StrategyPromptInputs} */
export const DEFAULT_STRATEGY_PROMPT_INPUTS = {
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

export const STORAGE_KEY = 'lido.strategy.aiPromptBuilder.v1';
