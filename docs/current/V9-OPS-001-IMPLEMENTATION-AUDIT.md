# V9-OPS-001 implementation and acceptance audit

Status: integration candidate; **not implemented/verified closure**. Updated 22 October 2026. The frozen [operations specification](../archive/specs/V9-Historical-Fundamentals-Bootstrap-Admin-Operations-Specification.md), especially §17, governs acceptance. Root owns review, protected integration, normal release and closure.

The implementation reuses the V8 FEAT-054 engine, jobs, fact store, source priority, quality and provenance. It adds durable Admin request, gap/warning review, preset/schedule, notification receipt and bounded projection records around that engine. It creates no second ingestion engine. Deployment alone must not adopt legacy queued/running requests or enable schedules. Only explicit managed request origin/version and explicitly enabled Admin schedules enter automatic execution; legacy running ownership still blocks the slot.

## Finite acceptance ledger

Every row requires combined-head hosted verification and release evidence before final acceptance. The source and test references below identify the producer of that evidence; an authored test is not a passing test.

| §17 criterion | Implementation and focused evidence source | Remaining qualification |
|---|---|---|
| 1. Admin full/targeted launch | Admin routes, `BootstrapOperationsService`, existing `FundamentalBootstrapService`; API/engine feature tests; browser OPS001-01 | Combined hosted backend/browser and released read-only smoke |
| 2. Single active run and deterministic queue | Control-first durable slot, process fence, FIFO explicit managed runs; `FundamentalBootstrapQueueConcurrencyTest`; producer overlap tests | Combined MySQL race/queue suite |
| 3. Supported pause and graceful cancel | Existing global pause checked before execution and between claims; cancellation fences preserve governed in-flight writes; real-engine integration and process-race tests | Combined pause/cancel suite |
| 4. Cancel terminal and new targeted recovery | Terminal cancellation API, no resume action; new compatible-gap or stock request; OPS001-01/02 | Combined backend/browser |
| 5. Failed retry and forced rerun with provenance | New retry run; explicit selected-stock force/reason; existing quality/fact provenance; API, concurrency and engine tests, OPS001-01 | Combined backend/browser |
| 6. Aggregate and drill-down progress | Safe run presenter, job pagination, annual/quarterly results, normalized reasons/provenance/period counts and progress timestamps; component/API tests | Combined frontend/backend |
| 7. Immutable history | Request/actor/scope/force/origin and gap/schedule/preset receipt saved in canonical creation transaction; typed JSON comparison, replay/rollback tests | Combined MySQL persistence/recovery |
| 8. Shared presets and recurring scheduler | Preset revisions, disabled-first daily/weekly/monthly local-time schedules, bounds, clone, enable/disable, future-only edits, independent Run now; C service/concurrency tests, OPS001-03 | Combined backend/browser |
| 9. Scheduled overlap queues | Durable occurrence outbox, canonical A creation adapter, immutable receipt/link acknowledgement and crash recovery; real adapter/producer tests | Combined MySQL/restart tests |
| 10. Metric/cadence coverage | Existing fact-store aggregation, eligible-stock denominator, bounded period projection and explicit unknown applicability; B feature/component tests | Combined backend/frontend |
| 11. Deterministic gaps and audited accepted-missing/reopening | Natural stock/metric/cadence/period identity, per-run observations, reason registry, acknowledgement history and evidence-based reopening; B tests, OPS001-02 | Combined query/ack/reopening tests |
| 12. Separate quality warnings and acknowledgement | Separate durable occurrences for rejected facts/anomaly review/missing provenance; Admin bulk audit records; B tests and component controls | Combined backend/frontend |
| 13. Coverage/failure targeted launch | Compatible actionable/reopened gap IDs resolve exact stocks; immutable selection, replay after resolution, incompatible-selection rollback; root integration tests, OPS001-02 | Combined backend/browser |
| 14. Meaningful Admin notifications | COMM001 canonical in-app publisher, transactional unique acceptance receipts, terminal failure/completed-with-failures, durable cannot-start/material delay and stable repeated-warning windows; D/producer tests | Combined real-facade/mock-publisher rollback/dedupe tests |
| 15. Operational CSV | Admin streamed bounded job export, selected-run gap-observation join, normalized categories, multiline escaping and formula neutralization; D feature tests and OPS001-03 link scope | Combined export/backend/browser |
| 16. No historical-value editing | Panel exposes operational controls only; no historical-value mutation route/form; API allowlists and browser assertions | Combined source/UI acceptance |
| 17. Governed provider selection | Existing deterministic engine/provider/quality path unchanged; no provider override in panel; forced rerun retains governed provenance | Combined existing engine regression suite |
| 18. Safe UI diagnostics | Safe presenters and normalized reason/category allowlists; raw checkpoint/provider payload omitted from run/gap/warning/CSV UI contracts | Combined API/component/browser and root source review |

## Scope and operational boundaries

Whole eligible-universe and explicit eligible-stock targets are supported. Compatible coverage selections preconfigure stocks and preserve the selected metric/cadence/period as provenance. They do not promise unsupported engine metric/date/index execution bounds or fabricate a complete historical expectation grid. Unknown applicability remains unknown. Global pause is reused; no new per-run resume semantics are invented. Cancelled runs remain terminal.

The same existing minute command advances explicitly managed work, materializes enabled schedules and performs bounded projection/outcome reconciliation. It does not adopt legacy work. Projection cursors commit atomically with exact B job pages of at most 50; restarts retry the same uncommitted work. Scheduled outcome scans rotate past invalid older receipts and stop revisiting accepted outcomes. Notification calls in tests use the real canonical facade with a mock publisher; this audit does not authorize a live notification send, ingestion or training action.

## Evidence reviewed before combined publication

Control-plane PR [257](https://github.com/lido-alexion/LidoPortfolio/pull/257), exact head `b34df98cfbc52535fba1f2bb858da82518460a93`: canonical [CI 1532](https://github.com/lido-alexion/LidoPortfolio/actions/runs/39784292443) passed on 22 October 2026. Full backend log: 2,399 tests, 16,978 assertions, five notices, two governed skips, no failures or risky tests. Python: 56 passed. OpenAPI: 231 operations. This is evidence for the control-plane slice, not qualification of the additional integration files.

Root reviewed the B/C/D/E/producer normal finals and complete owned source before copying into a fresh integration checkout based on that head. The prior dirty integration checkout and all worker source were preserved. Static PHP, migration portability, route-only OpenAPI and whitespace checks provide source checks only. Combined hosted tests, browser receipt review, protected merge, exact-master normal deployment, public/authenticated smoke, final audit and register closure remain required. No heavy verification runs on the production VPS.
