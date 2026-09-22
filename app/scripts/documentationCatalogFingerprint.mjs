import crypto from 'node:crypto';

function catalogShape(docs) {
    return docs.map((doc) => ({
        id: doc.id,
        keyword: doc.keyword,
        aliases: doc.aliases || [],
        title: doc.title,
        routeLabel: doc.routeLabel,
        summary: doc.summary,
        overview: doc.overview,
        controls: doc.controls || [],
        concepts: doc.concepts || [],
        related: doc.related || [],
    }));
}

export function documentationCatalogFingerprint(docs) {
    return crypto
        .createHash('sha256')
        .update(JSON.stringify(catalogShape(docs)))
        .digest('hex');
}
