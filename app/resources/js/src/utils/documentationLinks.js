import { appUrl } from '../appBase';
import {
    APP_DOCUMENTATION,
    APP_DOCUMENTATION_BY_SPECIFICITY,
} from '../data/appDocumentation';

/**
 * Map a React Router pathname to the documentation keyword for that screen.
 */
export function resolveDocKeywordFromPath(pathname) {
    const path = String(pathname || '/').replace(/\/$/, '') || '/';
    for (const doc of APP_DOCUMENTATION_BY_SPECIFICITY) {
        if (doc.match(path)) {
            return doc.keyword;
        }
    }
    return 'overview';
}

/**
 * Absolute in-app URL for Documentation with optional keyword query.
 */
export function buildDocumentationUrl(keyword) {
    const q = String(keyword || 'overview').trim() || 'overview';
    return appUrl(`/documentation?q=${encodeURIComponent(q)}`);
}

/**
 * Open contextual Documentation in a new tab for the given router pathname.
 */
export function openDocumentationForPath(pathname) {
    const keyword = resolveDocKeywordFromPath(pathname);
    const url = buildDocumentationUrl(keyword);
    window.open(url, '_blank', 'noopener,noreferrer');
    return keyword;
}

function normalizeQuery(value) {
    return String(value || '')
        .trim()
        .toLowerCase()
        .replace(/[_/]+/g, '-')
        .replace(/\s+/g, '-');
}

/**
 * Resolve a ?q= value to a documentation entry (exact keyword / id / alias, then fuzzy).
 */
export function findDocumentationByKeyword(rawKeyword) {
    const needle = normalizeQuery(rawKeyword);
    if (!needle) {
        return APP_DOCUMENTATION.find((d) => d.keyword === 'overview') || APP_DOCUMENTATION[0];
    }

    const exact = APP_DOCUMENTATION.find((doc) => {
        if (normalizeQuery(doc.keyword) === needle || normalizeQuery(doc.id) === needle) {
            return true;
        }
        return (doc.aliases || []).some((alias) => normalizeQuery(alias) === needle);
    });
    if (exact) {
        return exact;
    }

    const scored = APP_DOCUMENTATION.map((doc) => {
        const haystack = [
            doc.keyword,
            doc.id,
            doc.title,
            doc.summary,
            ...(doc.aliases || []),
        ]
            .join(' ')
            .toLowerCase();
        let score = 0;
        if (haystack.includes(needle)) score += 5;
        needle.split('-').filter(Boolean).forEach((part) => {
            if (haystack.includes(part)) score += 1;
        });
        return { doc, score };
    })
        .filter((row) => row.score > 0)
        .sort((a, b) => b.score - a.score);

    return scored[0]?.doc
        || APP_DOCUMENTATION.find((d) => d.keyword === 'overview')
        || APP_DOCUMENTATION[0];
}

/**
 * Filter documentation index by free-text search.
 */
export function searchDocumentationIndex(query) {
    const needle = String(query || '').trim().toLowerCase();
    if (!needle) {
        return APP_DOCUMENTATION;
    }
    return APP_DOCUMENTATION.filter((doc) => {
        const blob = [
            doc.keyword,
            doc.id,
            doc.title,
            doc.summary,
            doc.routeLabel,
            ...(doc.aliases || []),
        ]
            .join(' ')
            .toLowerCase();
        return blob.includes(needle);
    });
}

export function getDocumentationByKeyword(keyword) {
    return APP_DOCUMENTATION.find((d) => d.keyword === keyword) || null;
}
