import { readFile, writeFile, readdir, mkdir } from 'node:fs/promises';
import { basename } from 'node:path';

const root = new URL('../../docs/user-journeys/', import.meta.url);
const output = new URL('../resources/js/src/data/journeyMetadata.js', import.meta.url);
const publicJourneyRoot = new URL('../public/docs/journeys/', import.meta.url);
const categories = {
  '00-account-entry.md': 'Account access', '01-screeners.md': 'Screeners', '02-strategies.md': 'Strategies',
  '03-recommendations-review.md': 'Recommendations', '04-execution-transactions.md': 'Execution',
  '05-end-to-end.md': 'End-to-end journeys',
};
const routeByCategory = { 'Account access': '/login', Screeners: '/screeners', Strategies: '/strategy', Recommendations: '/recommendations', Execution: '/transactions/pending', 'End-to-end journeys': '/' };
const files = (await readdir(root)).filter((file) => /^\d{2}-.*\.md$/.test(file) || file === 'README.md').sort();
await mkdir(publicJourneyRoot, { recursive: true });
const topics = [];
for (const file of files) {
  const source = await readFile(new URL(file, root), 'utf8');
  const category = categories[file] || 'StoX';
  const headings = [...source.matchAll(/^##\s+((?:AUTH|SCR|STR|REC|EXE|E2E|AI)-\d+)\s+—\s+(.+)$/gm)];
  headings.forEach((heading, index) => {
    const id = heading[1]; const titleText = heading[2].trim();
    const body = source.slice(heading.index + heading[0].length, headings[index + 1]?.index || source.length);
    const steps = [...body.matchAll(/^\d+\.\s+(.+)$/gm)].map((match) => match[1].replace(/\*\*/g, '').trim());
    const prerequisites = [...body.matchAll(/^\s*[-*]\s*(?:Prerequisite|Requires?):\s*(.+)$/gim)].map((match) => match[1].trim());
    const warnings = [
      ...[...body.matchAll(/^\s*[-*]\s*Warning:\s*(.+)$/gim)].map((match) => match[1]),
      ...[...body.matchAll(/^\*\*Important:\*\*\s*(.+)$/gm)].map((match) => match[1]),
    ].map((warning) => warning.replace(/\*\*/g, '').trim());
    const synonyms = [...body.matchAll(/^\s*[-*]\s*Search synonyms:\s*(.+)$/gim)].flatMap((match) => match[1].split(',').map((value) => value.trim()).filter(Boolean));
    const route = body.match(/`(\/[^`]+)`/)?.[1] || routeByCategory[category] || '/';
    const slug = titleText.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
    const title = titleText.startsWith('How do I') ? titleText : `How do I ${titleText[0].toLowerCase()}${titleText.slice(1)}?`;
    const aliases = [titleText.replace(/^How do I\s+/i, '').replace(/[?!.]$/, ''), id.toLowerCase()];
    if (/screener/i.test(titleText) && /create/i.test(titleText)) aliases.push('new screener', 'create screener');
    if (/strategy/i.test(titleText) && /create/i.test(titleText)) aliases.push('new strategy', 'create strategy');
    const keywords = [...new Set(titleText.toLowerCase().replace(/[^a-z0-9 ]/g, ' ').split(/\s+/).filter((word) => word.length > 2))];
    topics.push({ id, title, aliases, keywords, synonyms, category, route, guide: `/docs/journeys/${basename(file, '.md')}.html#${id.toLowerCase()}-${slug}`, steps, prerequisites, warnings });
  });
}
for (const file of files) {
  const source = await readFile(new URL(file, root), 'utf8');
  const html = source.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').split('\n').map((line) => {
    if (line.startsWith('# ')) return `<h1>${line.slice(2)}</h1>`;
    if (line.startsWith('## ')) return `<h2 id="${line.slice(3).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '')}">${line.slice(3)}</h2>`;
    if (/^\d+\. /.test(line)) return `<p>${line}</p>`;
    return line ? `<p>${line}</p>` : '';
  }).join('\n');
  await writeFile(new URL(`${basename(file, '.md')}.html`, publicJourneyRoot), `<!doctype html><html lang="en"><head><meta charset="utf-8"><title>StoX user journeys</title><style>body{font:16px system-ui;max-width:900px;margin:2rem auto;padding:0 1rem;line-height:1.5}h1,h2{margin-top:2rem}</style></head><body>${html}</body></html>`);
}
topics.sort((a, b) => a.id.localeCompare(b.id));
const tail = `
const STOP_WORDS = new Set(['a', 'an', 'and', 'do', 'how', 'i', 'the', 'to']);
export function normalizeHelpQuery(value) { return String(value || '').toLowerCase().normalize('NFKD').replace(/\\p{M}/gu, '').replace(/[^a-z0-9]+/g, ' ').trim(); }
function tokens(value) { return normalizeHelpQuery(value).split(/\\s+/).filter((token) => token && !STOP_WORDS.has(token)); }
function editDistanceAtMostOne(left, right) { if (Math.abs(left.length - right.length) > 1) return false; let i = 0; let j = 0; let edits = 0; while (i < left.length && j < right.length) { if (left[i] === right[j]) { i++; j++; continue; } if (++edits > 1) return false; if (left.length > right.length) i++; else if (right.length > left.length) j++; else { i++; j++; } } return edits + (i < left.length || j < right.length ? 1 : 0) <= 1; }
function scoreTopic(topic, query, currentPath = '', history = []) { const normalized = normalizeHelpQuery(query); if (!normalized) return 0; const qTokens = new Set(tokens(normalized)); let score = 0; const titleAliases = [topic.title, ...(topic.aliases || [])]; if (titleAliases.map(normalizeHelpQuery).includes(normalized)) score += 100; for (const token of qTokens) { if (tokens(topic.title).includes(token)) score += 24; if ((topic.aliases || []).some((value) => tokens(value).includes(token))) score += 16; if ((topic.keywords || []).includes(token)) score += 10; if ((topic.synonyms || []).some((value) => tokens(value).includes(token))) score += 7; if (token.length >= 4 && titleAliases.some((value) => tokens(value).some((candidate) => editDistanceAtMostOne(token, candidate)))) score += 5; } if (currentPath && topic.route === currentPath) score += 6; if (history.includes(topic.id)) score += 2; return score; }
export function searchJourneyTopics(query, { currentPath = '', history = [], limit = 6, exclude = [] } = {}) { return JOURNEY_TOPICS.map((topic) => { const textScore = scoreTopic(topic, query); const score = textScore + (currentPath && topic.route === currentPath ? 6 : 0) + (history.includes(topic.id) ? 2 : 0); return { ...topic, score, textScore }; }).filter((topic) => topic.textScore > 0 && !exclude.includes(topic.id)).sort((a, b) => b.textScore - a.textScore || b.score - a.score || a.id.localeCompare(b.id)).slice(0, limit); }
export function explainJourneyMatch(topic, query) { const q = new Set(tokens(query)); const matched = [topic.title, ...(topic.aliases || []), ...(topic.keywords || []), ...(topic.synonyms || [])].flatMap(tokens).filter((token) => q.has(token)); return matched.length ? 'Matched ' + [...new Set(matched)].slice(0, 3).join(', ') : 'Related StoX journey'; }
`;
await writeFile(output, `/** Generated from docs/user-journeys/*.md; do not edit manually. */\nexport const JOURNEY_METADATA_VERSION = 'v9.ux001.generated';\nexport const JOURNEY_TOPICS = Object.freeze(${JSON.stringify(topics, null, 2)});\n${tail}`);
console.log(`Generated ${topics.length} journey topics.`);
