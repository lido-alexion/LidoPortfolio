import { formatTransactionDateDisplay } from './transactionDate';
import { htmlToPlainText } from './knowledgeBoardPreview';

function htmlToMarkdownLite(html) {
    if (!html) {
        return '';
    }
    let text = html;
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
 * @param {Array<{
 *   title?: string,
 *   tags?: Array<{ name: string }>,
 *   content_html?: string,
 *   created_at?: string,
 *   updated_at?: string,
 * }>} notes
 * @param {{
 *   format: 'text' | 'markdown' | 'ai',
 *   includeTitle?: boolean,
 *   includeTags?: boolean,
 *   includeCreated?: boolean,
 *   includeUpdated?: boolean,
 *   includeDivider?: boolean,
 * }} options
 */
export function buildKnowledgeExport(notes, options) {
    const {
        format,
        includeTitle = true,
        includeTags = true,
        includeCreated = true,
        includeUpdated = true,
        includeDivider = true,
    } = options;

    const blocks = notes.map((note) => {
        const title = note.title || 'Untitled';
        const tags = (note.tags || []).map((tag) => tag.name).join(', ');
        const contentPlain = htmlToPlainText(note.content_html);
        const contentMd = htmlToMarkdownLite(note.content_html);
        const created = note.created_at ? formatTransactionDateDisplay(note.created_at) : '';
        const updated = note.updated_at ? formatTransactionDateDisplay(note.updated_at) : '';

        if (format === 'ai') {
            const lines = [];
            if (includeTitle) {
                lines.push('TITLE:', title, '');
            }
            if (includeTags && tags) {
                lines.push('TAGS:', tags, '');
            }
            if (includeCreated && created) {
                lines.push('CREATED:', created, '');
            }
            if (includeUpdated && updated) {
                lines.push('UPDATED:', updated, '');
            }
            lines.push('CONTENT:', '', contentPlain);
            return lines.join('\n');
        }

        const lines = [];
        if (includeTitle) {
            lines.push(format === 'markdown' ? `# ${title}` : title);
        }
        if (includeTags && tags) {
            lines.push(format === 'markdown' ? `**Tags:** ${tags}` : `Tags: ${tags}`);
        }
        if (includeCreated && created) {
            lines.push(format === 'markdown' ? `**Created:** ${created}` : `Created: ${created}`);
        }
        if (includeUpdated && updated) {
            lines.push(format === 'markdown' ? `**Updated:** ${updated}` : `Updated: ${updated}`);
        }
        if (lines.length && includeDivider) {
            lines.push(format === 'markdown' ? '---' : '------------------------------');
        }
        lines.push(format === 'markdown' ? contentMd : contentPlain);
        return lines.filter((line, index, arr) => !(line === '' && arr[index - 1] === '')).join('\n');
    });

    if (format === 'ai') {
        return blocks.map((block) => `=================================================\n\n${block}\n\n=================================================`).join('\n\n');
    }

    return blocks.join('\n\n');
}
