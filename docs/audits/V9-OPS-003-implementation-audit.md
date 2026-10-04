# V9-OPS-003 implementation and acceptance audit

Starting local revision for final reconciliation: `480ed417cdd7658e456fa5f3ae33f873531e8121`. Fetched and fast-forwarded normally to `origin/master` at `5b274128a5b571ac8b42d34cd27f6068c9b14411`, then restored the complete staged/unstaged OPS-003 worktree and untracked prompt files. The implementation is preserved in a local commit; push is withheld pending post-reconciliation acceptance.

## Frozen acceptance map and initial gaps

| Acceptance criterion | Baseline gap | Implementation and evidence |
|---|---|---|
| 1. Central ERROR observation, bounded asynchronous triage | Exception callback only; no central plain-log observation; sync queue could execute inference inline | `MessageLogged` listener, ERROR/critical/alert/emergency gate, dedicated durable queue; `V9LogErrorTriageTest` observation and guard tests |
| 2. Deterministic filtering and pre-AI privacy | Free-form messages and caller-supplied context retained after incomplete regex redaction | `LogErrorSanitizer` code-derived allowlist, ignored classes/exact messages/registered endpoints, context-free exclusion; privacy boundary tests |
| 3. Shared governed capability only | Correct runtime client existed, but placeholder prompt and no declared schema | `ops.log_error_triage` schema, versioned shipped prompt, background class, concurrency 1, shared routing/budgets/admission; Python runtime contract tests |
| 4. All seven classifications | Enum normalized locally, but incomplete structured validation | Strict required fields, enum/type/range/length validation in shared runtime and Laravel; malformed/unknown output fails closed |
| 5. Confidence, evidence, actionability and security gates | Only confidence/classification checked | Default 0.85 (invalid configuration falls back safely; stored scores round down), actionable, exact input evidence including an application frame, stable component/bug identity, independent security gate; parameterized negative tests |
| 6. Bounded duplicate cost | Last-seen debounce only, no decision TTL; counters reset/lost outside debounce | Atomic signature upsert, delayed representative, durable lease, six-hour reuse, deploy-sensitive signature; burst/cache and multiprocess MySQL tests |
| 7. Fingerprints and exact-marker dedupe | Fingerprint included changing message; search accepted first fuzzy match | Code-derived bug identity excludes values/deploy; exact body marker verification; direct known-issue lookup for indexing lag; shared lock and durable ambiguous-POST state |
| 8. Shared OPS-002 GitHub controls | Reused transport/token, but bypassed rate logic; no shared circuit | Both jobs use `GitHubIssueReporter`, shared credential/client, database lock, global creation admission, failure circuit and closure-based cooldown; shared reporter regression tests |
| 9. Safe useful issue evidence | Limited component/exception/evidence body; unconstrained model output | Source frames, diagnostic, exception, registered route, deploy SHA, counts/times and grounded evidence; model prose discarded; HTTP payload privacy assertions |
| 10. Fail-open original behavior | Model lookup and some error writes outside catches; raw exception text in failures | Observer/worker/reporter/maintenance guards and bounded categorical failure reasons; queue/persistence/AI/GitHub failure tests |
| 11. No recursion | Message-substring heuristic only | Nestable guard, explicit flags/capability/job exclusions and reporter exception-source exclusion; recursive AI log test |
| 12. FEAT-052 ownership | No telemetry replacement | Existing telemetry listeners and domain/financial/execution contracts untouched; no new telemetry datastore |
| 13. Auditable decision and linkage | No actionability, prompt/path, failure reason or history | Sanitized triage fields, immutable per-inference decision records, binding generation history, protected Admin list/details APIs |
| 14. Focused and regression green | No OPS-003 acceptance suite | Verification results recorded below; register closure requires all required gates |

## Privacy boundary

No request/response bodies, headers, caller-provided component/route/trace identifiers, user/account IDs, SQL bindings, portfolio values, environment dumps or uploaded content enter triage storage. Arbitrary exception/log prose is discarded, including secrets that a regex might miss. A fixed diagnostic category replaces it. A local HMAC discriminator over normalized original prose distinguishes different events without retaining or exporting that prose. It is never an AI/GitHub input.

Retained context consists of up to five relative application PHP paths/line numbers (no arguments or absolute paths), exception class, code-derived component, registered Laravel route template/method, deployment SHA, severity and a boolean security gate. Deployment identity comes from the existing `bootstrap/build-info.json` contract or explicit runtime override. Correlation identifiers are intentionally omitted.

The model receives only this envelope, occurrence count and an exact evidence candidate list. It must return all structured fields, but only exact candidate evidence is accepted. Free-form model title/summary are not persisted or posted; deterministic summaries are generated from the safe diagnostic and code component. Prompt/response audit on the shared AI platform therefore receives no original sensitive log content.

Security-keyword/context signals remain local even if a model claims `code_bug`. Unknown classifications, schema violations, ungrounded evidence, missing app-frame evidence, unknown bug kind, low confidence and non-actionable decisions cannot create issues.

## Cost, identity and GitHub behavior

Default debounce is 300 seconds, measured from scheduling rather than extended by every occurrence. Every accepted occurrence atomically updates the durable count/timestamp. A worker lease prevents concurrent inference for one signature. A classified decision is reused for 21,600 seconds; changed deployment identity or materially different message/location produces a different pre-triage signature. Real concurrent MySQL testing found and corrected a duplicate-insert shared-lock upgrade race: aggregation uses a write-locking atomic upsert.

Bug identity hashes environment, code-derived component, exception class, first application frame, registered route and normalized bug kind. It excludes changing prose, IDs, timestamps and deploy SHA. GitHub reconciliation uses exactly `<!-- stox-log-bug:<fingerprint> -->`. Multiple classified records can share a binding and one open issue.

The existing OPS-002 client owns the sole GitHub credential/transport. Search results must contain the complete exact marker and incomplete/truncated searches fail safely. Known issue numbers are read directly if search indexing lags. Missing labels retry without labels. Both reporters share the default 10 creation attempts/hour ceiling; ambiguous/failed creation attempts conservatively consume admission. Three consecutive failures open a default 300-second circuit.

Closed issues are never reopened. A recurrence links locally within 24 hours of closure (or a fixed last-observed fallback when closure is unavailable). A later recurrence creates a new generation and references the prior issue. The global database cache lock serializes workers; a durable `creation_unknown` state precedes the external POST. An ambiguous creation result permits later reconciliation but never a blind repeat POST. Recovering an ambiguous recurrence advances generation history exactly once and retains its prior-issue reference. Operator investigation is needed if GitHub cannot resolve it.

## Admin and retention

Authenticated Admin APIs reuse existing middleware and response conventions:

- `GET /api/admin/log-error-triages`: 30-row pagination, classification/minimum-confidence filters, enabled/capability configuration status, seven-day classification/status counts, occurrence totals, threshold, debounce/cache configuration and shared reporter state.
- `GET /api/admin/log-error-triages/{id}`: safe record, paginated decision history and GitHub binding/generation history.

No new frontend page or labeling workflow is introduced; the frozen minimal operational view is provided as an Admin API. `app/openapi/ops-log-triage.json` documents this legacy `/api` surface separately from generated `/api/v1` documentation.

`ops:maintain-log-triages` runs every ten minutes with overlap protection, recovers up to 50 recently failed/pending records, refreshes up to ten stale open bindings and prunes up to 500 expired records. Retention selects and deletes under row locks and skips active worker leases, preventing fresh-occurrence races. Default retention is 90 days after last occurrence; open/unknown linked bindings are retained conservatively, and known closed issues require closure plus retention. Decision history is pruned with its triage. Bindings retain dedupe/generation identity.

## Runtime prerequisites and boundaries

No production settings, provider paths, credentials or deployment were changed. The feature remains disabled unless explicitly enabled in an allowed environment. Admin must configure a priced eligible route for `ops.log_error_triage` through the existing AI platform. A customized governed prompt is preserved; the migration versions only the shipped placeholder.

Both OPS-002 GitHub jobs and OPS-003 triage jobs default to the dedicated `log-triage` database connection/queue. Workers must consume it with the job timeout of 120 seconds and visibility timeout of 180 seconds; existing workers that consume only `default` will not process these jobs. Existing queue overrides may select another durable asynchronous connection. No production worker configuration was changed automatically. The shared GitHub lock uses the existing database cache lock table and is held for at most 180 seconds. Scheduler execution is required for recovery/retention. Existing GitHub configuration and production environment policy remain authoritative. The new schema is packaged at `app/config/ai-schemas/ops.log_error_triage.v1.json`; it does not depend on parent documentation files missing from the existing application release archive. The migration clears unsafe legacy feature-specific message/context/evidence fields rather than allowing historical baseline records to bypass the new boundary.

A missing deployment SHA is disclosed as `unknown`; operators should supply the normal build-info artifact. The narrow privacy boundary deliberately favors uncertain decisions when safe technical evidence cannot establish a code bug. It does not send arbitrary exception prose to improve model confidence.

## Upstream reconciliation

The fetched `origin/master` contained ten commits beyond the starting local SHA: `f0ecf5cd` (recoverable production AI migration), `2f89e574` (AI schemas packaged in production runtime), `9203632f`, `c6aeca4f`, `6d2963cc` (FEAT-057 historical identity chain), `136d8159`, `06e122cd` (FEAT-057 apply lifecycle), `aada94f5`, `bac545c3` (acceptance PIT journal), and `5b274128` (bounded fundamentals bootstrap). The worktree was stashed with untracked files, fast-forwarded to `5b274128`, and restored without conflict or loss.

Overlap review: upstream adds `app/resources/ai-schemas/{stock_analysis_insight,strategy_designer}.v1.json`; `EmbeddedAiContract` loads those through Laravel `resource_path`. OPS-003's distinct `ops.log_error_triage` schema stays in `app/config/ai-schemas`, which its migration and service load through `config_path`. These are separate capabilities and packaging roots; no duplicate schema loader was added. Upstream's `EmbeddedAiService` normalization in `480ed417` remains untouched and is not duplicated. Shared OPS-003 AI projection/runtime changes remain limited to declaring background `service_class` and sending triage schema/provider settings.

## Shared AI regression context

The order-sensitive `EmbeddedAi` cache normalization and regression assertion repair are already present upstream in `480ed417`. OPS-003 does not modify `EmbeddedAiService` or duplicate that repair. The shared AI suite could not be completed in this final environment because its Laravel feature tests require a database service; see the post-reconciliation results below.

## Verification results

- Previously reported pre-reconciliation focused OPS-003 acceptance: **55 tests, 235 assertions passed on MySQL 8.4.11**; shared Laravel AI regression: **50 tests, 299 assertions passed**; Python runtime: **56 passed**; backend gate: **2,187 passed, 2 skipped, 13,901 assertions**. These remain historical evidence, not final post-reconciliation acceptance.
- Post-reconciliation migration portability: **passed**, 169 migrations.
- Post-reconciliation static documentation and assistant corpus: **passed**, 53 topics (`npm run docs:static:check`).
- Post-reconciliation OpenAPI `/api/v1`: **passed**, current at 219 operations. Dedicated Admin contract remains `app/openapi/ops-log-triage.json`.
- Post-reconciliation Python triage runtime tests: **4 passed** from a fresh `/tmp/ops003-ai-venv` installed from `ai-runtime/requirements.lock`. The requested full 56-test runtime suite did not complete in this environment; its run stopped making progress during an unrelated agent endpoint test and produced no final result.
- Post-reconciliation focused Laravel tests could not start: this container's PHP 8.4.26 lacks `pdo_sqlite`, required by the backend verifier and PHPUnit defaults. Explicit MySQL execution also could not connect to `127.0.0.1:3306`. `./scripts/verify-ci.sh --backend` stopped at its platform check for missing `pdo_sqlite`; installing it was unavailable because container package management is not privileged. Thus focused OPS-003 and shared Laravel AI regressions were not re-run here.
- `php artisan openapi:v1 --check` passed. Final `git diff --check` and syntax checks are pending commit preparation.
- Full backend gate was not rerun to completion. It is required because upstream reconciliation materially changes the backend base; completion is blocked by absent `pdo_sqlite` and MySQL service.
- V9 register row: **IMPLEMENTED / VERIFICATION PENDING**, not VERIFIED, until post-reconciliation CI-parity acceptance is green.

No frontend code was changed for OPS-003; Node/Vitest/typecheck/build/browser gates are outside this change's scope. No deployed/live-provider acceptance is claimed; automated tests fake AI/GitHub and never create real issues.

## Publication

Local commit `a160c4e5` was created. No push was made because post-reconciliation backend acceptance is not green. A later authorized push may start the repository's standard CI/CD automatically; no manual workflow or production deployment is performed as part of this audit.
