import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const candidates = readFileSync(new URL('../../resources/js/src/pages/CandidatesPage.jsx', import.meta.url), 'utf8');
const wiki = readFileSync(new URL('../../resources/js/src/pages/WikiPage.jsx', import.meta.url), 'utf8');

test('targeted accessibility fixes label Candidates filters', () => {
    assert.match(candidates, /aria-label="Search candidates by symbol or name"/);
    assert.match(candidates, /aria-label="Filter candidates by source"/);
});

test('targeted accessibility fixes the Wiki Markdown label association', () => {
    assert.match(wiki, /htmlFor="wiki-markdown-source"/);
    assert.match(wiki, /id="wiki-markdown-source"/);
});
