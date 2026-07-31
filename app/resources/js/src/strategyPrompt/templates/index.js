/**
 * Prompt template registry for AI Strategy Designer.
 * Add future templates here (ChatGPT / Gemini / Claude / Grok optimized) without changing the UI.
 */

import {
    renderStoxDefaultPrompt,
    STOX_DEFAULT_PROMPT_TEMPLATE,
    STOX_DEFAULT_TEMPLATE_ID,
    STOX_DEFAULT_TEMPLATE_LABEL,
} from './stoxDefault.js';

/**
 * @typedef {{
 *   id: string,
 *   label: string,
 *   description?: string,
 *   render: (tokens: Record<string, string>) => string,
 *   rawTemplate?: string,
 * }} PromptTemplateDefinition
 */

/** @type {Record<string, PromptTemplateDefinition>} */
export const PROMPT_TEMPLATES = {
    [STOX_DEFAULT_TEMPLATE_ID]: {
        id: STOX_DEFAULT_TEMPLATE_ID,
        label: STOX_DEFAULT_TEMPLATE_LABEL,
        description: 'General-purpose prompt for ChatGPT, Gemini, Claude, Grok, and similar assistants.',
        render: renderStoxDefaultPrompt,
        rawTemplate: STOX_DEFAULT_PROMPT_TEMPLATE,
    },
    // Future (register only — do not enable in UI until implemented):
    // 'chatgpt-optimized': { id: 'chatgpt-optimized', label: 'ChatGPT Optimized', render: ... },
    // 'gemini-optimized': { id: 'gemini-optimized', label: 'Gemini Optimized', render: ... },
    // 'claude-optimized': { id: 'claude-optimized', label: 'Claude Optimized', render: ... },
    // 'grok-optimized': { id: 'grok-optimized', label: 'Grok Optimized', render: ... },
};

export const DEFAULT_PROMPT_TEMPLATE_ID = STOX_DEFAULT_TEMPLATE_ID;

/** Templates exposed in the UI selector (implemented only). */
export function listAvailablePromptTemplates() {
    return Object.values(PROMPT_TEMPLATES).map(({ id, label, description }) => ({
        id,
        label,
        description: description || '',
    }));
}

/**
 * @param {string} templateId
 * @returns {PromptTemplateDefinition}
 */
export function getPromptTemplate(templateId) {
    return PROMPT_TEMPLATES[templateId] || PROMPT_TEMPLATES[DEFAULT_PROMPT_TEMPLATE_ID];
}
