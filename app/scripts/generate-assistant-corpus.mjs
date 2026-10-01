import { readFile, writeFile } from 'node:fs/promises';
import { APP_DOCUMENTATION } from '../resources/js/src/data/appDocumentation.js';
const chunks = APP_DOCUMENTATION.flatMap(doc => [
    { kind: 'overview', section: 'Overview', snippet: [doc.summary, doc.overview].filter(Boolean).join(' ') },
    ...(doc.concepts || []).map(item => ({ kind: 'concept', section: item.name, snippet: item.description })),
    ...(doc.controls || []).map(item => ({ kind: 'control', section: item.name, snippet: item.description })),
].filter(item => item.snippet).map(item => ({
    source_id: `product:${doc.keyword}:${item.kind}:${item.section.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`, title: doc.title,
    path: 'app/resources/js/src/data/appDocumentation.js', section: item.section,
    snippet: item.snippet.slice(0, 900), url: `/docs/${doc.keyword}.html`,
})));
if (new Set(chunks.map(chunk => chunk.source_id)).size !== chunks.length) throw new Error('Duplicate assistant source IDs');
const destination = new URL('../../docs/assistant-product-corpus.json', import.meta.url);
const serialized = JSON.stringify(chunks, null, 2) + '\n';
if (process.argv.includes('--check')) {
    if (await readFile(destination, 'utf8') !== serialized) throw new Error('Assistant documentation corpus is stale');
} else {
    await writeFile(destination, serialized);
}

