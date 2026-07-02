/**
 * Strip HTML to plain text for card previews and exports.
 * @param {string} [html]
 */
export function htmlToPlainText(html) {
    if (!html) {
        return '';
    }
    const doc = new DOMParser().parseFromString(html, 'text/html');
    return (doc.body.textContent || '').replace(/\s+/g, ' ').trim();
}

/**
 * Full note body for card display (no length limit, preserves line breaks).
 * @param {string} [html]
 */
export function noteCardText(html) {
    if (!html) {
        return '';
    }
    const normalized = html
        .replace(/<br\s*\/?>/gi, '\n')
        .replace(/<\/p>/gi, '\n\n')
        .replace(/<\/li>/gi, '\n')
        .replace(/<\/h[1-6]>/gi, '\n\n');
    const doc = new DOMParser().parseFromString(normalized, 'text/html');
    return (doc.body.textContent || '')
        .replace(/[ \t]+\n/g, '\n')
        .replace(/\n{3,}/g, '\n\n')
        .trim();
}

/**
 * @param {string} [html]
 * @param {number} [maxLength]
 */
export function notePreviewText(html, maxLength = 220) {
    const text = htmlToPlainText(html);
    if (text.length <= maxLength) {
        return text;
    }
    return `${text.slice(0, maxLength).trim()}…`;
}

/**
 * Derive a storage title from note body when no title field is shown in the UI.
 * @param {string} [html]
 * @param {number} [maxLength]
 */
export function deriveNoteTitle(html, maxLength = 120) {
    const text = htmlToPlainText(html);
    if (!text) {
        return '';
    }
    const firstLine = text.split(/\n/).map((line) => line.trim()).find(Boolean) || text;
    if (firstLine.length <= maxLength) {
        return firstLine;
    }
    return `${firstLine.slice(0, maxLength).trim()}…`;
}

/**
 * @param {string} plain
 */
export function plainTextToHtml(plain) {
    const trimmed = plain.trim();
    if (!trimmed) {
        return '';
    }
    return trimmed
        .split(/\n{2,}/)
        .map((block) => `<p>${block.replace(/\n/g, '<br>')}</p>`)
        .join('');
}

/**
 * @param {string} plain
 */
export function plainTextToJson(plain) {
    const trimmed = plain.trim();
    if (!trimmed) {
        return { type: 'doc', content: [{ type: 'paragraph' }] };
    }
    return {
        type: 'doc',
        content: trimmed.split(/\n{2,}/).map((block) => ({
            type: 'paragraph',
            content: [{ type: 'text', text: block }],
        })),
    };
}
