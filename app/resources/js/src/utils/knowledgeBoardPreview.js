import { marked } from 'marked';

marked.use({ breaks: true, gfm: true });

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

/**
 * @param {string} [html]
 */
export function htmlToMarkdownLite(html) {
    if (!html) {
        return '';
    }
    let text = html;
    text = text.replace(/<img\b[^>]*>/gi, (tag) => {
        const src = /src=["']([^"']+)["']/i.exec(tag)?.[1] || '';
        if (!src) {
            return '';
        }
        const alt = (/alt=["']([^"']*)["']/i.exec(tag)?.[1] || 'image').replace(/[[\]]/g, '');
        const full = /data-full-src=["']([^"']+)["']/i.exec(tag)?.[1]
            || /title=["']([^"']+)["']/i.exec(tag)?.[1]
            || '';
        if (full && full !== src) {
            return `\n\n![${alt}](${src} "${full}")\n\n`;
        }
        return `\n\n![${alt}](${src})\n\n`;
    });
    text = text.replace(/<h1[^>]*>(.*?)<\/h1>/gi, '# $1\n\n');
    text = text.replace(/<h2[^>]*>(.*?)<\/h2>/gi, '## $1\n\n');
    text = text.replace(/<h3[^>]*>(.*?)<\/h3>/gi, '### $1\n\n');
    text = text.replace(/<strong[^>]*>(.*?)<\/strong>/gi, '**$1**');
    text = text.replace(/<b[^>]*>(.*?)<\/b>/gi, '**$1**');
    text = text.replace(/<em[^>]*>(.*?)<\/em>/gi, '*$1*');
    text = text.replace(/<i[^>]*>(.*?)<\/i>/gi, '*$1*');
    text = text.replace(/<u[^>]*>(.*?)<\/u>/gi, '$1');
    text = text.replace(/<s[^>]*>(.*?)<\/s>/gi, '~~$1~~');
    text = text.replace(/<li[^>]*>(.*?)<\/li>/gi, '- $1\n');
    text = text.replace(/<blockquote[^>]*>(.*?)<\/blockquote>/gi, '> $1\n\n');
    text = text.replace(/<pre[^>]*><code[^>]*>(.*?)<\/code><\/pre>/gis, '```\n$1\n```\n\n');
    text = text.replace(/<code[^>]*>(.*?)<\/code>/gi, '`$1`');
    text = text.replace(/<br\s*\/?>/gi, '\n');
    text = text.replace(/<\/p>/gi, '\n\n');
    text = text.replace(/<[^>]+>/g, '');
    return text.replace(/\n{3,}/g, '\n\n').trim();
}

/**
 * True when HTML has visible text or at least one image.
 * @param {string} [html]
 */
export function htmlHasContent(html) {
    if (!html) {
        return false;
    }
    if (/<img\b/i.test(html)) {
        return true;
    }
    return htmlToPlainText(html).trim().length > 0;
}

/**
 * @param {string} [markdown]
 */
export function markdownToHtml(markdown) {
    const trimmed = (markdown || '').trim();
    if (!trimmed) {
        return '';
    }
    return marked.parse(trimmed, { async: false });
}
