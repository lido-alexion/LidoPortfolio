import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { documentationCatalogFingerprint } from './documentationCatalogFingerprint.mjs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const appRoot = path.resolve(__dirname, '..');
const docsDir = path.join(appRoot, 'public', 'docs');
const docsModuleUrl = pathToFileURL(
    path.join(appRoot, 'resources', 'js', 'src', 'data', 'appDocumentation.js'),
).href;

function slugify(keyword) {
    return String(keyword || 'overview')
        .trim()
        .toLowerCase()
        .replace(/[^a-z0-9_-]+/g, '-')
        .replace(/^-+|-+$/g, '') || 'overview';
}

const { APP_DOCUMENTATION } = await import(docsModuleUrl);
if (!fs.existsSync(docsDir)) {
    throw new Error(`Static documentation directory is missing: ${docsDir}`);
}

const topics = new Set();
const aliases = new Map();
for (const doc of APP_DOCUMENTATION) {
    if (topics.has(doc.keyword)) throw new Error(`Duplicate documentation keyword: ${doc.keyword}`);
    topics.add(doc.keyword);
    for (const alias of doc.aliases || []) {
        const aliasSlug = slugify(alias);
        // The generator intentionally keeps the first file when legacy aliases
        // overlap; every alias still has to resolve to an existing topic file.
        if (!aliases.has(aliasSlug)) aliases.set(aliasSlug, doc.keyword);
    }
}

const index = fs.readFileSync(path.join(docsDir, 'index.html'), 'utf8');
for (const doc of APP_DOCUMENTATION) {
    const keyword = slugify(doc.keyword);
    const file = path.join(docsDir, `${keyword}.html`);
    if (!fs.existsSync(file)) throw new Error(`Missing generated topic: ${keyword}.html`);
    if (!index.includes(`href="${keyword}.html"`)) throw new Error(`Topic missing from generated index: ${keyword}`);
    for (const alias of doc.aliases || []) {
        const aliasSlug = slugify(alias);
        if (!fs.existsSync(path.join(docsDir, `${aliasSlug}.html`))) {
            throw new Error(`Missing generated alias: ${aliasSlug}.html`);
        }
    }
}

const readme = fs.readFileSync(path.join(docsDir, 'README.txt'), 'utf8');
const expectedFingerprint = documentationCatalogFingerprint(APP_DOCUMENTATION);
const fingerprint = readme.match(/^Catalog fingerprint: ([a-f0-9]{64})$/m)?.[1];
if (fingerprint !== expectedFingerprint) {
    throw new Error(`Static documentation is stale: expected catalog fingerprint ${expectedFingerprint}`);
}

const generatedHtml = new Set(fs.readdirSync(docsDir).filter((name) => name.endsWith('.html')));
const expectedHtml = new Set(['index.html']);
for (const doc of APP_DOCUMENTATION) {
    expectedHtml.add(`${slugify(doc.keyword)}.html`);
    for (const alias of doc.aliases || []) expectedHtml.add(`${slugify(alias)}.html`);
}
for (const file of generatedHtml) {
    if (!expectedHtml.has(file)) throw new Error(`Orphan generated documentation topic: ${file}`);
}
console.log(`Static documentation contract is current (${APP_DOCUMENTATION.length} topics).`);
