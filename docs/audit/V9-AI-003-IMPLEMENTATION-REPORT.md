# V9-AI-003 implementation and verification

## Scope and baseline

Started at `68eaf153` on master. Preserved the pre-existing untracked
`.codex-ai003-prompt.txt`. Reconciled nine remote advances by fast-forward to
`d12eed70`: `164b1a52`, `de1af849`, `94a5f17b`, `e4af7d07`, `87a24a64`, `dd6cfb0b`,
`4303d1cc`, `943299a7`, `d12eed70`. No shared history rewrite.

The placement inventory is in `docs/architecture/ai003-surface-audit.md`.
All seven stock placements use one `AnalyseStockButton` and one backend
`stock_analysis_insight` capability. Holdings, both Dashboard contexts and Watchlist
rows use a 35vw pane at >=1600px and a near-full-page modal otherwise. Selected
Watchlist and both Explorer result paths use expandable inline sections. Explorer
sections span the result column. Closing or switching portfolios clears the surface.

## Implementation

- Registered `stock_analysis_insight` and `strategy_designer` with governed prompts
  and versioned structured schemas. Provider paths remain explicitly Admin-configured;
  no new provider, key, paid route or parallel routing implementation is selected.
- Shared runtime handles budgets, admission, failover, provider streaming, final
  schema validation and audit delivery. Embedded inference is bounded to 45 seconds;
  Laravel waits 55 seconds and browser requests 60 seconds. Streaming is buffered
  until validation, so partial output cannot become a canonical insight.
- Stock evidence uses canonical OHLCV, existing RS and pattern services,
  FundamentalSignalsService and the fundamental investor snapshot. Optional missing
  evidence is disclosed. Holding rows come from AI-002's deterministic projection,
  filtered to the stock in the owned active portfolio. No watchlist data is read.
- FEAT-062 now retains successful interpretations by deterministic-evidence hash;
  AI-003 only reads an exact matching interpretation. It never regenerates FEAT-062.
- Persistent `stox_ai_insight_cache` stores structured results, provenance, scope,
  stock/account/profile identity as applicable, versions, timestamps and the shared
  inference request ID. Fingerprints include normalized evidence, context and
  prompt/schema/capability versions/content. Strategy authoring evidence excludes the
  generated guide timestamp, so rebuilds alone do not invalidate. Provider/model
  changes do not invalidate.
  Global entries have null account/profile IDs. Holding entries include both account
  and portfolio. Strategy entries are account-only. Failed refreshes preserve a
  matching valid entry; schema-invalid/partial results are never persisted.
- Shared UX supports refresh, copying results, stock navigation, concise provenance,
  holding personalization and degraded-only original Copy AI Prompt fallback.
  Normal success hides raw prompts and routing/provider/model metadata.
- Strategy Designer retains structured inputs, normalizes set-valued choices and
  irrelevant hidden custom fields, restores matching account results, hides results
  for changed inputs and never generates proactively. Explicit generation cancels
  any pending cache lookup so lookup cannot interrupt a user request.
- Create draft strategy is enabled through the existing AI-002 `strategy.create`
  catalog path. It stages a run/preview and reuses AssistantRun approval UX. Existing
  approval hashes, expiry, stale checks, validation, audit, idempotency, Library draft
  lifecycle and post-action verification remain authoritative. No second mutation
  implementation exists; advisory generation itself does not mutate artifacts.

## Verification record

Implementation verification is complete subject to the frontend OS-installer exception below; this report is not a production release approval.

- Python AI: 52 tests passed, including both embedded structured contracts.
- Final focused Laravel AI regressions on isolated MariaDB: 36 tests / 246 assertions
  passed, including valid draft creation and the existing oversized-preview refusal.
- Node: 201 tests passed on reconciled master.
- Vitest: all 142 tests in 35 files passed. After the latest remote reconciliation,
  the five upstream classification tests also passed unchanged; the overlapping
  local fixture correction was removed in favor of the upstream fix.
- Typecheck and hosted-path production build passed.
- Focused Chromium: 10 journeys passed (AI-001, AI-002, AI-003; mobile, desktop, wide).
- Static documentation/corpus checks and OpenAPI v1 check passed.
- MySQL migration portability: all 166 migrations passed.
- Full Chromium/responsive suite: 53 passed, 50 expected project-specific skips.
- The existing shell theme contrast test intermittently scanned Bootstrap transitions:
  untouched origin/master reproduced the same failure in 2/5 runs. The test now waits
  for active CSS transitions before the unchanged accessibility checks. Three repeated
  4K runs without retries and the entire browser suite passed afterward.
- Initial broad backend run: 2,100 passed, 2 skipped, 1 failure from the original
  oversized draft fixture. Final focused tests prove both bounded creation and the
  preserved rejection. Final complete `verify-ci.sh --backend` passed: 2,104 passed,
  one skipped, 13,461 assertions (2,105 tests), including the migration/seed gate
  on MariaDB and current OpenAPI. The subsequent material-guide hash regression
  is covered by the final focused 36-test run.
- `verify-ci.sh --frontend` passed JS/typecheck/build, but its OS dependency installer
  failed because `playwright install --with-deps` requires unavailable sudo. Exact
  pinned Chromium and headless-shell installation succeeded without that OS installer;
  the full browser suite passed with those binaries and existing system libraries.
  Exact executable: `/home/nitty/.cache/ms-playwright/chromium-1234/chrome-linux64/chrome`.

No paid external inference was used. Tests use deterministic/fake provider results.
No production deployment or production database modification was performed. The
MariaDB database and supplemental PHP SQLite extension configuration are isolated
under `/tmp/stox-ai003-*`; the required backend gate still uses MariaDB, not SQLite.

## Completion state

Ending repository HEAD is `d12eed70`, equal to `origin/master` after the final fetch.
AI-003 is staged in 43 files and has not been committed or pushed. The original
untracked `.codex-ai003-prompt.txt` remains untouched. Push is pending explicit
Product Owner authorization for the OS-installer-only exception required by
AGENTS.md, Commit and push discipline item 3. All test execution described above
is complete; no browser coverage is being waived. No production deployment occurred.

New legacy `/api/ai/insights`
contracts are documented in `app/openapi/embedded-ai.json`; the existing generated
`/api/v1` document remains current. Maintained product docs and assistant journeys
AI-07 through AI-09 have been regenerated. No DATA-002, OPS, broker trading,
autonomous execution or external MCP scope is included.

Operational limits: new capability provider paths require normal Admin configuration.
Draft conversion retains AI-002's existing 100-field preview limit; oversized designs
return `preview_too_large` without creating an artifact. No AI-003 capability or
placement is deferred. Provider streaming is consumed internally and only validated
final results reach the browser or persistent cache. `git diff --check` and the
staged equivalent passed.
