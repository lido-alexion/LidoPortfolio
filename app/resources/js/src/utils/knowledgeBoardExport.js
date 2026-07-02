import { htmlToPlainText, htmlToMarkdownLite } from './knowledgeBoardPreview';

const EXPORT_FORMAT_KEY = 'portfolio_knowledge_board_export_format';
const VALID_EXPORT_FORMATS = new Set(['text', 'markdown', 'ai']);

export function loadExportFormatPreference() {
    try {
        const stored = localStorage.getItem(EXPORT_FORMAT_KEY);
        if (stored && VALID_EXPORT_FORMATS.has(stored)) {
            return stored;
        }
    } catch {
        // private mode — ignore
    }
    return 'text';
}

export function saveExportFormatPreference(format) {
    if (!VALID_EXPORT_FORMATS.has(format)) {
        return;
    }
    try {
        localStorage.setItem(EXPORT_FORMAT_KEY, format);
    } catch {
        // Quota or private mode — ignore.
    }
}

/**
 * @param {Array<{ content_html?: string }>} notes
 * @param {{ format: 'text' | 'markdown' | 'ai' }} options
 */
export function buildKnowledgeExport(notes, { format }) {
    const blocks = notes.map((note) => {
        const contentPlain = htmlToPlainText(note.content_html);
        const contentMd = htmlToMarkdownLite(note.content_html);
        return format === 'markdown' ? contentMd : contentPlain;
    });

    const divider = format === 'markdown'
        ? '---'
        : '------------------------------';

    if (format === 'ai') {
        const aiDivider = '=================================================';
        return blocks
            .map((block) => `${aiDivider}\n\n${block}\n\n${aiDivider}`)
            .join('\n\n');
    }

    return blocks.join(`\n\n${divider}\n\n`);
}
