/**
 * Pure prompt generation for AI Strategy Designer.
 * UI must not embed template text — call generatePrompt(inputs, templateId).
 */

import { getPromptTemplate, DEFAULT_PROMPT_TEMPLATE_ID } from './templates/index.js';

/**
 * @typedef {{
 *   investmentStyle: string,
 *   customInvestmentStyle?: string,
 *   riskProfile: string,
 *   holdingPeriod: string,
 *   customHoldingPeriod?: string,
 *   targetMarket: string,
 *   customTargetMarket?: string,
 *   universe: string,
 *   customUniverse?: string,
 *   maximumPositions: number | string | null,
 *   capitalAllocation: string,
 *   customCapitalAllocation?: string,
 *   preferredExitStyle: string,
 *   customPreferredExitStyle?: string,
 *   marketPreferences: string[],
 *   optimizationPriorities: string[],
 *   strategyComplexity: string,
 *   explainabilityLevel: string,
 *   additionalConstraints?: string,
 * }} StrategyPromptInputs
 */

/**
 * @param {unknown} value
 * @returns {string}
 */
export function formatPromptField(value) {
    if (value == null) return 'None';
    if (Array.isArray(value)) {
        const items = value.map((v) => String(v ?? '').trim()).filter(Boolean);
        return items.length ? items.join(', ') : 'None';
    }
    const text = String(value).trim();
    return text || 'None';
}

/**
 * Resolve a dropdown that may be "Custom" with companion free text.
 * @param {string} selected
 * @param {string} [customText]
 * @returns {string}
 */
export function resolveChoice(selected, customText = '') {
    if (selected === 'Custom') {
        return formatPromptField(customText);
    }
    return formatPromptField(selected);
}

/**
 * Map form inputs to template tokens.
 * @param {StrategyPromptInputs} inputs
 * @returns {Record<string, string>}
 */
export function buildPromptTokens(inputs) {
    const complexity = formatPromptField(inputs.strategyComplexity);
    const explain = formatPromptField(inputs.explainabilityLevel);

    return {
        INVESTMENT_STYLE: resolveChoice(inputs.investmentStyle, inputs.customInvestmentStyle),
        RISK_PROFILE: formatPromptField(inputs.riskProfile),
        HOLDING_PERIOD: resolveChoice(inputs.holdingPeriod, inputs.customHoldingPeriod),
        TARGET_MARKET: resolveChoice(inputs.targetMarket, inputs.customTargetMarket),
        UNIVERSE: resolveChoice(inputs.universe, inputs.customUniverse),
        MAXIMUM_POSITIONS: formatPromptField(
            inputs.maximumPositions === '' || inputs.maximumPositions == null
                ? null
                : inputs.maximumPositions,
        ),
        CAPITAL_ALLOCATION: resolveChoice(inputs.capitalAllocation, inputs.customCapitalAllocation),
        PREFERRED_EXIT_STYLE: resolveChoice(inputs.preferredExitStyle, inputs.customPreferredExitStyle),
        MARKET_PREFERENCE: formatPromptField(inputs.marketPreferences),
        OPTIMIZATION_PRIORITIES: formatPromptField(inputs.optimizationPriorities),
        STRATEGY_COMPLEXITY: complexity,
        EXPLAINABILITY_LEVEL: explain,
        ADDITIONAL_CONSTRAINTS: formatPromptField(inputs.additionalConstraints),
    };
}

/**
 * Generate a complete external-AI prompt from designer inputs.
 * @param {StrategyPromptInputs} inputs
 * @param {string} [templateId]
 * @returns {string}
 */
export function generatePrompt(inputs, templateId = DEFAULT_PROMPT_TEMPLATE_ID) {
    const template = getPromptTemplate(templateId);
    const tokens = buildPromptTokens(inputs || {});
    return template.render(tokens).trim() + '\n';
}
