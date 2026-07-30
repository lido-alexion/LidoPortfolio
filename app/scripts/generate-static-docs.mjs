/**
 * Generate crawlable static HTML documentation from APP_DOCUMENTATION.
 * Output: app/public/docs/{keyword}.html + index.html
 *         + stox-trading-artifacts-ai-guide.md (AI download pack)
 *
 * Usage: node scripts/generate-static-docs.mjs
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { marked } from 'marked';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const appRoot = path.resolve(__dirname, '..');
const repoRoot = path.resolve(appRoot, '..');
const outDir = path.join(appRoot, 'public', 'docs');
const specsAiGuidePath = path.join(repoRoot, 'specs', 'engines', 'StoX-Trading-Artifacts-AI-Guide.md');
const AI_GUIDE_BASENAME = 'stox-trading-artifacts-ai-guide.md';
const docsModuleUrl = pathToFileURL(
    path.join(appRoot, 'resources', 'js', 'src', 'data', 'appDocumentation.js'),
).href;
const contractModuleUrl = pathToFileURL(
    path.join(appRoot, 'resources', 'js', 'src', 'data', 'tradingArtifactAuthoringContract.js'),
).href;
const runtimeModuleUrl = pathToFileURL(
    path.join(appRoot, 'resources', 'js', 'src', 'data', 'tradingArtifactRuntimeSemantics.js'),
).href;

/** Topic keywords included in the AI download pack (order matters). Reference material after Contract. */
const AI_GUIDE_REFERENCE_KEYWORDS = [
    'authoring-trading-artifacts',
    'indicator-registry',
    'screener-registry',
    'strategy-registry',
    'trading-cookbook',
];

/** Runtime semantics placed in Appendix (detailed reference). */
const AI_GUIDE_APPENDIX_KEYWORDS = [
    'trading-artifact-runtime',
];

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function slugifyKeyword(keyword) {
    return String(keyword || 'overview')
        .trim()
        .toLowerCase()
        .replace(/[^a-z0-9_-]+/g, '-')
        .replace(/^-+|-+$/g, '') || 'overview';
}

function renderOverviewHtml(overview) {
    const raw = String(overview || '').trim();
    if (!raw) return '<p class="muted">No overview.</p>';
    // Keep mermaid fences readable for crawlers; humans can still read the text.
    const withMermaidHint = raw.replace(
        /```mermaid\n([\s\S]*?)```/g,
        (_m, body) => `\`\`\`\n(mermaid diagram)\n${body.trim()}\n\`\`\``,
    );
    return marked.parse(withMermaidHint, { async: false });
}

function renderItemList(items, emptyLabel) {
    if (!Array.isArray(items) || items.length === 0) {
        return `<p class="muted">${escapeHtml(emptyLabel)}</p>`;
    }
    return `<dl class="doc-list">${items.map((item) => `
        <dt>${escapeHtml(item.name)}</dt>
        <dd>${marked.parseInline(String(item.description || ''), { async: false })}</dd>
    `).join('')}</dl>`;
}

function pageShell({ title, description, canonical, body, navLinks }) {
    return `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>${escapeHtml(title)} — StoX docs</title>
  <meta name="description" content="${escapeHtml(description)}">
  <link rel="canonical" href="${escapeHtml(canonical)}">
  <style>
    :root {
      --bg: #0f1419;
      --panel: #1a222c;
      --text: #e7ecf1;
      --muted: #9aa7b5;
      --accent: #6cb6ff;
      --border: #2a3542;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: "Segoe UI", system-ui, -apple-system, sans-serif;
      line-height: 1.55;
      background: var(--bg);
      color: var(--text);
    }
    a { color: var(--accent); }
    .wrap { max-width: 920px; margin: 0 auto; padding: 1.25rem 1rem 3rem; }
    header.app {
      border-bottom: 1px solid var(--border);
      padding: 0.85rem 0 1rem;
      margin-bottom: 1.25rem;
    }
    header.app h1 { font-size: 1.35rem; margin: 0 0 0.35rem; }
    .muted { color: var(--muted); }
    .panel {
      background: var(--panel);
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 1rem 1.1rem;
      margin: 1rem 0;
    }
    h2 { font-size: 1.1rem; margin: 0 0 0.65rem; }
    .doc-list { margin: 0; }
    .doc-list dt { font-weight: 600; margin-top: 0.75rem; }
    .doc-list dd { margin: 0.2rem 0 0; color: var(--muted); }
    .toc a { display: block; padding: 0.25rem 0; text-decoration: none; }
    .toc a:hover { text-decoration: underline; }
    .related { display: flex; flex-wrap: wrap; gap: 0.5rem; }
    .related a {
      border: 1px solid var(--border);
      border-radius: 999px;
      padding: 0.2rem 0.7rem;
      text-decoration: none;
      font-size: 0.92rem;
    }
    pre, code {
      font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
      font-size: 0.88rem;
    }
    pre {
      background: #0b1015;
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 0.85rem;
      overflow-x: hidden;
      white-space: pre-wrap;
      overflow-wrap: anywhere;
      word-break: break-word;
      max-width: 100%;
    }
    table {
      width: 100%;
      max-width: 100%;
      border-collapse: collapse;
      margin: 0.75rem 0;
      font-size: 0.88rem;
      table-layout: fixed;
    }
    th, td {
      border: 1px solid var(--border);
      padding: 0.35rem 0.45rem;
      text-align: left;
      vertical-align: top;
      overflow-wrap: anywhere;
      word-break: break-word;
    }
    th { background: #121820; }
    code { overflow-wrap: anywhere; word-break: break-word; }
    @media print {
      body { background: #fff; color: #111; }
      .panel { break-inside: avoid; }
      pre, table { overflow: visible !important; }
      a { color: inherit; }
    }
    .nav { display: flex; flex-wrap: wrap; gap: 0.75rem; margin-top: 0.5rem; font-size: 0.92rem; }
    footer { margin-top: 2rem; padding-top: 1rem; border-top: 1px solid var(--border); font-size: 0.85rem; }
  </style>
</head>
<body>
  <div class="wrap">
    <header class="app">
      <h1>StoX documentation</h1>
      <p class="muted" style="margin:0">Static HTML — readable without JavaScript or login.</p>
      <div class="nav">
        <a href="index.html">All topics</a>
        ${navLinks || ''}
      </div>
    </header>
    ${body}
    <footer class="muted">
      Generated from the StoX in-app documentation source. Product screens still require sign-in.
    </footer>
  </div>
</body>
</html>
`;
}

function topicPage(doc, allDocs) {
    const keyword = slugifyKeyword(doc.keyword);
    const related = (doc.related || [])
        .map((rel) => allDocs.find((d) => d.keyword === rel || d.id === rel))
        .filter(Boolean);

    const body = `
    <article>
      <h1 style="font-size:1.6rem;margin:0 0 0.4rem">${escapeHtml(doc.title)}</h1>
      <p class="muted"><code>${escapeHtml(keyword)}</code>${doc.routeLabel ? ` · screen: <code>${escapeHtml(doc.routeLabel)}</code>` : ''}</p>
      <p>${escapeHtml(doc.summary)}</p>

      <section class="panel">
        <h2>Overview</h2>
        ${renderOverviewHtml(doc.overview)}
      </section>

      <section class="panel">
        <h2>Controls</h2>
        ${renderItemList(doc.controls, 'No controls listed.')}
      </section>

      <section class="panel">
        <h2>Concepts</h2>
        ${renderItemList(doc.concepts, 'No concepts listed.')}
      </section>

      ${related.length ? `
      <section class="panel">
        <h2>Related topics</h2>
        <div class="related">
          ${related.map((r) => `<a href="${escapeHtml(slugifyKeyword(r.keyword))}.html">${escapeHtml(r.title)}</a>`).join('')}
        </div>
      </section>` : ''}
    </article>
    `;

    return pageShell({
        title: doc.title,
        description: doc.summary,
        canonical: `${keyword}.html`,
        body,
        navLinks: `<a href="${escapeHtml(keyword)}.html">This topic</a>`,
    });
}

function indexPage(docs) {
    const items = docs
        .slice()
        .sort((a, b) => a.title.localeCompare(b.title))
        .map((doc) => {
            const kw = slugifyKeyword(doc.keyword);
            return `<a href="${escapeHtml(kw)}.html"><strong>${escapeHtml(doc.title)}</strong> — <span class="muted">${escapeHtml(doc.summary)}</span></a>`;
        })
        .join('\n');

    const body = `
    <section class="panel">
      <h2>Topics</h2>
      <p class="muted">Share links like <code>docs/strategy.html</code>. These pages are plain HTML for browsers and AI crawlers.</p>
      <div class="toc">${items}</div>
    </section>
    `;

    return pageShell({
        title: 'Documentation index',
        description: 'StoX / Lido Portfolio static documentation index',
        canonical: 'index.html',
        body,
    });
}

function aliasRedirectPage(targetKeyword) {
    const target = `${slugifyKeyword(targetKeyword)}.html`;
    return `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta http-equiv="refresh" content="0; url=${escapeHtml(target)}">
  <link rel="canonical" href="${escapeHtml(target)}">
  <title>Redirecting…</title>
</head>
<body>
  <p>Moved to <a href="${escapeHtml(target)}">${escapeHtml(target)}</a>.</p>
</body>
</html>
`;
}

function findDoc(docs, keyword) {
    return docs.find((d) => d.keyword === keyword || d.id === keyword) || null;
}

function formatDocItems(title, items) {
    if (!Array.isArray(items) || items.length === 0) return '';
    const lines = [`### ${title}`, ''];
    for (const item of items) {
        lines.push(`- **${item.name}** — ${item.description || ''}`);
    }
    lines.push('');
    return `${lines.join('\n')}\n`;
}

/**
 * Build a single Markdown pack for AI / offline authoring.
 * Architecture: Introduction → Contract (normative) → Hard Rules → Workflow → Registries → Cookbook → Examples → Appendix.
 * @param {Array<Record<string, unknown>>} docs
 * @param {{ contractMarkdown: string, completeExamplesMarkdown: string }} extras
 */
function buildAiGuideMarkdown(docs, extras) {
    const generatedAt = new Date().toISOString();
    const parts = [];
    const AI_AUTHORING_CONTRACT_MARKDOWN = extras?.contractMarkdown || '';
    const COMPLETE_E2E_WALKTHROUGH_MARKDOWN = extras?.completeExamplesMarkdown || '';

    // ── 1. Introduction ──────────────────────────────────────────────
    parts.push('# StoX Trading Artifacts - AI Authoring Guide');
    parts.push('');
    parts.push('## 1. Introduction');
    parts.push('');
    parts.push('> **Audience:** AI agents and developers authoring portable Indicator / Screener / Strategy JSON **without** reading application source code.');
    parts.push('>');
    parts.push(`> **Generated:** ${generatedAt}`);
    parts.push(`> **Deploy download:** \`/docs/${AI_GUIDE_BASENAME}\` (also linked from Screener Registry and Strategy Registry).`);
    parts.push('> **Repo copy:** `specs/engines/StoX-Trading-Artifacts-AI-Guide.md`');
    parts.push('');
    parts.push('This guide is the **single authoritative specification** for authoring production-ready Trading Artifacts.');
    parts.push('');
    parts.push('| Section | Role |');
    parts.push('|---------|------|');
    parts.push('| **AI Authoring Contract** | Normative constitution (MUST / SHOULD). Read first. |');
    parts.push('| Hard Rules | Compact reminder table derived from the Contract. |');
    parts.push('| Authoring Workflow | Recommended Validate -> Import sequence. |');
    parts.push('| Indicator / Screener / Strategy Registry | Detailed reference catalogues and schemas. |');
    parts.push('| Trading Cookbook | Philosophy + paired JSON recipes. |');
    parts.push('| Complete Examples | Canonical end-to-end lifecycle. |');
    parts.push('| Appendix | Runtime semantics and other detailed behaviour. |');
    parts.push('');
    parts.push('**Document maintenance:** New authoring or behavioural constraints MUST be added to the AI Authoring Contract before detailed reference sections.');
    parts.push('');
    parts.push('HTML mirrors: `/docs/ai-authoring-contract.html`, `/docs/authoring-trading-artifacts.html`, `/docs/indicator-registry.html`, `/docs/screener-registry.html`, `/docs/strategy-registry.html`, `/docs/trading-cookbook.html`, `/docs/trading-artifact-runtime.html`.');
    parts.push('');
    parts.push('---');
    parts.push('');

    // ── 2. AI Authoring Contract ──────────────────────────────────────
    parts.push('## 2. AI Authoring Contract (Normative Rules)');
    parts.push('');
    // Strip the duplicate H1 from the contract module body
    const contractBody = String(AI_AUTHORING_CONTRACT_MARKDOWN || '')
        .replace(/^# AI Authoring Contract \(Normative Rules\)\s*/m, '')
        .trim();
    parts.push(contractBody);
    parts.push('');
    parts.push('---');
    parts.push('');

    // ── 3. Hard Rules ────────────────────────────────────────────────
    parts.push('## 3. Hard Rules');
    parts.push('');
    parts.push('Compact reminder. The **AI Authoring Contract** is authoritative if anything conflicts.');
    parts.push('');
    parts.push('| Topic | Requirement |');
    parts.push('|-------|-------------|');
    parts.push('| Schema | `schema_version` MUST be `"1.0"`; body key MUST be `definition`. |');
    parts.push('| Operators | Condition: `gt` `gte` `lt` `lte` `eq` only. Group: `AND` `OR` only. |');
    parts.push('| Forbidden | `neq`, `NOT`, `crosses_*`, `between`, `outside`, string/boolean/null/date operands. |');
    parts.push('| Operands | Indicator `{ indicator, params }` or constant `{ type:"constant", value:<number> }`. |');
    parts.push('| Limits | Nesting depth ≤ 4; ≤ 40 conditions. |');
    parts.push('| Dual-use | Screenable ≠ strategy-scorable. |');
    parts.push('| Eligibility | `screener_slug` / `screener_factory_key` only — MUST NOT embed Screener trees. |');
    parts.push('| Weights | Enabled `scoring_model` weights MUST total exactly 100. |');
    parts.push('| Import | Validate MUST succeed before Import; Strategy Import is draft until Select. |');
    parts.push('| Params | Present params MUST be in catalogue range; AI SHOULD set Screener params explicitly. |');
    parts.push('| Eligibility set | Multiple sources = UNION. |');
    parts.push('| Scoring gates | Min/max fail → contribution 0 (dilutes); stock is not rejected. |');
    parts.push('');
    parts.push('---');
    parts.push('');

    // ── 4–8. Reference topics ────────────────────────────────────────
    const sectionTitles = {
        'authoring-trading-artifacts': '## 4. Authoring Workflow',
        'indicator-registry': '## 5. Indicator Registry',
        'screener-registry': '## 6. Screener Registry',
        'strategy-registry': '## 7. Strategy Registry',
        'trading-cookbook': '## 8. Trading Cookbook',
    };

    for (const keyword of AI_GUIDE_REFERENCE_KEYWORDS) {
        const doc = findDoc(docs, keyword);
        parts.push(sectionTitles[keyword] || `# ${keyword}`);
        parts.push('');
        if (!doc) {
            parts.push(`_Missing topic: ${keyword}_`);
            parts.push('');
            parts.push('---');
            parts.push('');
            continue;
        }
        parts.push(`**Keyword:** \`${doc.keyword}\``);
        if (doc.aliases?.length) {
            parts.push(`**Aliases:** ${doc.aliases.map((a) => `\`${a}\``).join(', ')}`);
        }
        parts.push('');
        parts.push(`**Summary:** ${doc.summary || ''}`);
        parts.push('');
        if (doc.routeLabel) {
            parts.push(`**UI / docs route label:** \`${doc.routeLabel}\``);
            parts.push('');
        }
        parts.push(String(doc.overview || '').trim());
        parts.push('');
        parts.push(formatDocItems('Controls', doc.controls));
        parts.push(formatDocItems('Concepts', doc.concepts));
        if (Array.isArray(doc.related) && doc.related.length > 0) {
            parts.push('### Related topics');
            parts.push('');
            parts.push(doc.related.map((r) => `- \`${r}\``).join('\n'));
            parts.push('');
        }
        parts.push('---');
        parts.push('');
    }

    // ── 9. Complete Examples ─────────────────────────────────────────
    parts.push('## 9. Complete Examples');
    parts.push('');
    const examplesBody = String(COMPLETE_E2E_WALKTHROUGH_MARKDOWN || '')
        .replace(/^# Complete Examples\s*/m, '')
        .trim();
    parts.push(examplesBody);
    parts.push('');
    parts.push('---');
    parts.push('');

    // ── 10. Appendix ─────────────────────────────────────────────────
    parts.push('## 10. Appendix');
    parts.push('');
    parts.push('### A. Runtime Semantics (detailed reference)');
    parts.push('');
    parts.push('Normative authoring implications appear in the Contract (Runtime Behaviour Rules). This appendix documents engine evaluation detail.');
    parts.push('');

    for (const keyword of AI_GUIDE_APPENDIX_KEYWORDS) {
        const doc = findDoc(docs, keyword);
        if (!doc) {
            parts.push(`_Missing appendix topic: ${keyword}_`);
            parts.push('');
            continue;
        }
        parts.push(`#### ${doc.title}`);
        parts.push('');
        parts.push(`**Keyword:** \`${doc.keyword}\``);
        parts.push('');
        parts.push(String(doc.overview || '').trim());
        parts.push('');
        parts.push(formatDocItems('Controls', doc.controls));
        parts.push(formatDocItems('Concepts', doc.concepts));
    }

    parts.push('### B. Contract-first maintenance');
    parts.push('');
    parts.push('1. Add or change a MUST/SHOULD rule in the AI Authoring Contract.');
    parts.push('2. Update the relevant Registry / Runtime reference section.');
    parts.push('3. Regenerate static docs (`npm run docs:static`).');
    parts.push('4. Do not leave reference prose that contradicts the Contract.');
    parts.push('');
    parts.push('_End of StoX Trading Artifacts AI Authoring Guide._');
    parts.push('');

    return parts.filter((p, i, arr) => !(p === '' && arr[i - 1] === '')).join('\n');
}

async function main() {
    const { APP_DOCUMENTATION } = await import(docsModuleUrl);
    const { AI_AUTHORING_CONTRACT_MARKDOWN } = await import(contractModuleUrl);
    const { COMPLETE_E2E_WALKTHROUGH_MARKDOWN } = await import(runtimeModuleUrl);
    if (!Array.isArray(APP_DOCUMENTATION) || APP_DOCUMENTATION.length === 0) {
        throw new Error('APP_DOCUMENTATION is empty or missing');
    }

    fs.rmSync(outDir, { recursive: true, force: true });
    fs.mkdirSync(outDir, { recursive: true });

    const written = new Set();
    for (const doc of APP_DOCUMENTATION) {
        const keyword = slugifyKeyword(doc.keyword);
        const file = path.join(outDir, `${keyword}.html`);
        fs.writeFileSync(file, topicPage(doc, APP_DOCUMENTATION), 'utf8');
        written.add(keyword);

        for (const alias of doc.aliases || []) {
            const aliasSlug = slugifyKeyword(alias);
            if (!aliasSlug || written.has(aliasSlug)) continue;
            fs.writeFileSync(path.join(outDir, `${aliasSlug}.html`), aliasRedirectPage(keyword), 'utf8');
            written.add(aliasSlug);
        }
    }

    fs.writeFileSync(path.join(outDir, 'index.html'), indexPage(APP_DOCUMENTATION), 'utf8');

    const aiGuide = buildAiGuideMarkdown(APP_DOCUMENTATION, {
        contractMarkdown: AI_AUTHORING_CONTRACT_MARKDOWN,
        completeExamplesMarkdown: COMPLETE_E2E_WALKTHROUGH_MARKDOWN,
    });
    const aiGuidePublic = path.join(outDir, AI_GUIDE_BASENAME);
    fs.writeFileSync(aiGuidePublic, aiGuide, 'utf8');
    fs.mkdirSync(path.dirname(specsAiGuidePath), { recursive: true });
    fs.writeFileSync(specsAiGuidePath, aiGuide, 'utf8');

    fs.writeFileSync(
        path.join(outDir, 'README.txt'),
        [
            'StoX static documentation',
            `Generated: ${new Date().toISOString()}`,
            `Topics: ${APP_DOCUMENTATION.length}`,
            'Open index.html or any {keyword}.html — no JavaScript required.',
            `AI download pack: ${AI_GUIDE_BASENAME}`,
            '',
        ].join('\n'),
        'utf8',
    );

    console.log(
        `Static docs written to ${outDir} (${APP_DOCUMENTATION.length} topics, ${written.size} html files + index + ${AI_GUIDE_BASENAME})`,
    );
    console.log(`AI guide also written to ${specsAiGuidePath}`);
}

main().catch((err) => {
    console.error(err);
    process.exit(1);
});
