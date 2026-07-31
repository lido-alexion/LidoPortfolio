/**
 * StoX Default Prompt template for AI Strategy Designer.
 * Placeholders use {{KEY}} tokens — edit this file to change prompt wording.
 */

export const STOX_DEFAULT_TEMPLATE_ID = 'stox-default';

export const STOX_DEFAULT_TEMPLATE_LABEL = 'StoX Default Prompt';

/**
 * Raw template body. Tokens:
 * INVESTMENT_STYLE, RISK_PROFILE, HOLDING_PERIOD, TARGET_MARKET, UNIVERSE,
 * MAXIMUM_POSITIONS, CAPITAL_ALLOCATION, PREFERRED_EXIT_STYLE, MARKET_PREFERENCE,
 * OPTIMIZATION_PRIORITIES, STRATEGY_COMPLEXITY, EXPLAINABILITY_LEVEL, ADDITIONAL_CONSTRAINTS
 */
export const STOX_DEFAULT_PROMPT_TEMPLATE = `You are an expert quantitative trading system designer.

Your only source of truth is the attached StoX Trading Artifacts AI Authoring Guide.

Treat that guide as the complete specification.

Do not invent functionality.

Do not invent indicators.

Do not invent operators.

Do not invent undocumented JSON.

If an investing methodology cannot be implemented exactly, generate the closest valid approximation and explain the limitations.

INVESTING STYLE

{{INVESTMENT_STYLE}}

RISK PROFILE

{{RISK_PROFILE}}

HOLDING PERIOD

{{HOLDING_PERIOD}}

TARGET MARKET

{{TARGET_MARKET}}

UNIVERSE

{{UNIVERSE}}

MAXIMUM POSITIONS

{{MAXIMUM_POSITIONS}}

CAPITAL ALLOCATION

{{CAPITAL_ALLOCATION}}

PREFERRED EXIT STYLE

{{PREFERRED_EXIT_STYLE}}

MARKET PREFERENCE

{{MARKET_PREFERENCE}}

OPTIMIZATION PRIORITIES

{{OPTIMIZATION_PRIORITIES}}

STRATEGY COMPLEXITY

{{STRATEGY_COMPLEXITY}}

EXPLAINABILITY LEVEL

{{EXPLAINABILITY_LEVEL}}

ADDITIONAL CONSTRAINTS

{{ADDITIONAL_CONSTRAINTS}}

Design a complete trading system consisting of:

1. Entry Screener
2. Exit Screener (if appropriate; otherwise use Strategy exit rules and explain why)
3. Strategy

Before generating JSON, briefly explain:

- Why this investing style works
- Ideal market conditions
- Poor market conditions
- Expected holding period
- Key risks

Then generate:

1. Strategy Overview
2. Trading Philosophy
3. Entry Screener Explanation
4. Entry Screener JSON
5. Exit Logic Explanation
6. Exit Screener JSON (if applicable)
7. Strategy Explanation
8. Strategy JSON
9. Limitations
10. Suggested Improvements

The generated artifacts must:

- fully comply with the StoX Trading Artifacts AI Authoring Guide
- follow the AI Authoring Contract
- validate successfully
- be immediately importable
- use only documented functionality
- use canonical identifiers
- remain portable

If any requested feature cannot be implemented exactly using the documented capabilities, explicitly explain why and provide the closest supported implementation instead.
`;

/**
 * @param {Record<string, string>} tokens
 * @returns {string}
 */
export function renderStoxDefaultPrompt(tokens) {
    return STOX_DEFAULT_PROMPT_TEMPLATE.replace(/\{\{(\w+)\}\}/g, (_m, key) => {
        const value = tokens[key];
        if (value == null || String(value).trim() === '') {
            return 'None';
        }
        return String(value);
    });
}
