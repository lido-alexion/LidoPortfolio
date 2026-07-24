/** Fixed contrasting background + text palettes for Knowledge Board notes. */

export const KNOWLEDGE_NOTE_DEFAULT_PALETTE = 'default';

/**
 * @typedef {{ id: string, label: string, background: string, text: string }} KnowledgeNotePalette
 */

/** @type {KnowledgeNotePalette[]} */
export const KNOWLEDGE_NOTE_PALETTES = [
    { id: 'default', label: 'Theme default', background: '', text: '' },
    { id: 'slate', label: 'Slate', background: '#1e293b', text: '#f1f5f9' },
    { id: 'paper', label: 'Paper', background: '#f7f4ef', text: '#1c1917' },
    { id: 'ocean', label: 'Ocean', background: '#0c4a6e', text: '#e0f2fe' },
    { id: 'forest', label: 'Forest', background: '#14532d', text: '#dcfce7' },
    { id: 'ink', label: 'Ink', background: '#111111', text: '#f5f5f5' },
    { id: 'sky', label: 'Sky', background: '#e0f2fe', text: '#0c4a6e' },
    { id: 'moss', label: 'Moss', background: '#ecfccb', text: '#365314' },
    { id: 'navy', label: 'Navy', background: '#172554', text: '#dbeafe' },
    { id: 'mint', label: 'Mint', background: '#ccfbf1', text: '#134e4a' },
    { id: 'ember', label: 'Ember', background: '#7c2d12', text: '#ffedd5' },
    { id: 'charcoal', label: 'Charcoal', background: '#374151', text: '#f9fafb' },
];

/**
 * @param {string} [id]
 * @returns {KnowledgeNotePalette}
 */
export function getKnowledgeNotePalette(id) {
    const found = KNOWLEDGE_NOTE_PALETTES.find((p) => p.id === id);
    return found || KNOWLEDGE_NOTE_PALETTES[0];
}

/**
 * Inline styles for a note card / editor chrome.
 * @param {string} [id]
 * @returns {React.CSSProperties | undefined}
 */
export function knowledgeNotePaletteStyle(id) {
    const palette = getKnowledgeNotePalette(id);
    if (!palette.background || palette.id === KNOWLEDGE_NOTE_DEFAULT_PALETTE) {
        return undefined;
    }
    return {
        backgroundColor: palette.background,
        color: palette.text,
        '--lido-knowledge-note-text': palette.text,
        '--lido-knowledge-palette-bg': palette.background,
        '--lido-knowledge-palette-fg': palette.text,
    };
}
