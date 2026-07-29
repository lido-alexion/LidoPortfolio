import React from 'react';
import DocMermaid from './DocMermaid';
import DocComparisonTable from './DocComparisonTable';

/**
 * Parse space-aligned or markdown-pipe tables from a text fence.
 * @returns {{ headers: string[], rows: string[][] }|null}
 */
export function parseAsciiTable(text) {
    const lines = String(text || '')
        .split('\n')
        .map((line) => line.replace(/\s+$/, ''))
        .filter((line) => line.trim() !== '');
    if (lines.length < 2) {
        return null;
    }

    if (lines[0].includes('|')) {
        const splitPipe = (line) => line
            .replace(/^\|/, '')
            .replace(/\|$/, '')
            .split('|')
            .map((cell) => cell.trim());
        const headers = splitPipe(lines[0]);
        const bodyLines = lines.slice(1).filter((line) => !/^[\s|:-]+$/.test(line));
        const rows = bodyLines.map(splitPipe).filter((row) => row.some(Boolean));
        if (headers.length >= 2 && rows.length) {
            return { headers, rows };
        }
    }

    // Header + dashed separator + body (space-aligned columns)
    const sepIdx = lines.findIndex((line, idx) => idx > 0 && /^[\s\-|]+$/.test(line) && line.includes('-'));
    if (sepIdx < 1) {
        return null;
    }
    const headerLine = lines[0];
    const colStarts = [];
    const parts = headerLine.match(/\S+(?:\s{2,}\S+)*/g);
    // Split header on 2+ spaces
    const headers = headerLine.trim().split(/\s{2,}/).filter(Boolean);
    if (headers.length < 2) {
        return null;
    }

    // Find column start offsets from header tokens
    let searchFrom = 0;
    headers.forEach((header) => {
        const at = headerLine.indexOf(header, searchFrom);
        colStarts.push(at >= 0 ? at : searchFrom);
        searchFrom = (at >= 0 ? at : searchFrom) + header.length;
    });

    const rows = lines.slice(sepIdx + 1).map((line) => {
        const cells = [];
        for (let i = 0; i < colStarts.length; i += 1) {
            const start = colStarts[i];
            const end = i + 1 < colStarts.length ? colStarts[i + 1] : line.length;
            cells.push(line.slice(start, end).trim());
        }
        return cells;
    }).filter((row) => row.some((cell) => cell !== ''));

    if (!rows.length) {
        return null;
    }
    return { headers, rows };
}

function looksLikeFlowDiagram(text) {
    const t = String(text || '');
    return /(→|->|\|\s*\n\s*v|↓)/.test(t) && t.split('\n').length >= 3;
}

/**
 * Renders overview / description text with fenced mermaid, tables, and paragraphs.
 */
export default function DocRichText({ text }) {
    const raw = String(text || '').trim();
    if (!raw) {
        return <p className="mb-0 text-muted">No overview available.</p>;
    }

    const parts = [];
    const fenceRegex = /```([\w-]*)\n([\s\S]*?)```/g;
    let lastIndex = 0;
    let match = fenceRegex.exec(raw);
    while (match) {
        const before = raw.slice(lastIndex, match.index).trim();
        if (before) {
            parts.push({ type: 'text', value: before });
        }
        const lang = (match[1] || '').toLowerCase();
        const body = match[2].replace(/\n$/, '');
        if (lang === 'mermaid') {
            parts.push({ type: 'mermaid', value: body });
        } else {
            const table = parseAsciiTable(body);
            if (table) {
                parts.push({ type: 'table', value: table });
            } else if (looksLikeFlowDiagram(body)) {
                parts.push({ type: 'pre', value: body, flow: true });
            } else {
                parts.push({ type: 'pre', value: body });
            }
        }
        lastIndex = fenceRegex.lastIndex;
        match = fenceRegex.exec(raw);
    }
    const tail = raw.slice(lastIndex).trim();
    if (tail) {
        parts.push({ type: 'text', value: tail });
    }

    return (
        <>
            {parts.map((part, partIdx) => {
                if (part.type === 'mermaid') {
                    return <DocMermaid key={`m-${partIdx}`} chart={part.value} />;
                }
                if (part.type === 'table') {
                    return (
                        <DocComparisonTable
                            key={`t-${partIdx}`}
                            headers={part.value.headers}
                            rows={part.value.rows}
                        />
                    );
                }
                if (part.type === 'pre') {
                    return (
                        <pre
                            key={`pre-${partIdx}`}
                            className={`lido-docs-pre small mb-3${part.flow ? ' lido-docs-pre--flow' : ''}`}
                        >
                            {part.value}
                        </pre>
                    );
                }
                const paragraphs = part.value
                    .split(/\n{2,}/)
                    .map((line) => line.trim())
                    .filter(Boolean);
                return paragraphs.map((paragraph, idx) => (
                    <p
                        key={`p-${partIdx}-${idx}`}
                        className={`lido-docs-paragraph${partIdx === parts.length - 1 && idx === paragraphs.length - 1 ? ' mb-0' : ''}`}
                    >
                        {paragraph}
                    </p>
                ));
            })}
        </>
    );
}
