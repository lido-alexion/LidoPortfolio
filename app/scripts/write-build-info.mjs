import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const outputPath = resolve(__dirname, '../bootstrap/build-info.json');
const commitSha = process.env.GITHUB_SHA || '';
const runNumber = process.env.GITHUB_RUN_NUMBER || '';
const runAttempt = process.env.GITHUB_RUN_ATTEMPT || '';
const fallbackId = [
  runNumber && `build-${runNumber}`,
  runAttempt && `attempt-${runAttempt}`,
  commitSha && commitSha.slice(0, 12),
].filter(Boolean).join('-');

const buildInfo = {
  build_id: process.env.BUILD_ID || fallbackId || 'local',
  commit_sha: commitSha || null,
  short_sha: commitSha ? commitSha.slice(0, 12) : null,
  ref: process.env.GITHUB_REF_NAME || process.env.GITHUB_REF || null,
  workflow: process.env.GITHUB_WORKFLOW || null,
  run_id: process.env.GITHUB_RUN_ID || null,
  run_number: runNumber || null,
  run_attempt: runAttempt || null,
  built_at: new Date().toISOString(),
};

mkdirSync(dirname(outputPath), { recursive: true });
writeFileSync(outputPath, `${JSON.stringify(buildInfo, null, 2)}\n`);
console.log(`Wrote build metadata to ${outputPath}`);
