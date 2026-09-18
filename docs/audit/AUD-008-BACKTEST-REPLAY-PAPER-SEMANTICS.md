# AUD-008 Backtest / Replay / Paper Semantics

## 1. Finding Recap

Backtest, Portfolio Replay, and Paper Portfolio functionality is present in separate services, persistence models, routes, and tests. This audit verifies the semantic boundaries and records the remaining gaps without changing simulation code.

## 2. Accepted Three-Mode Contract

| Mode | Meaning | Temporal direction | Source of truth |
| --- | --- | --- | --- |
| Strategy Backtest | Historical strategy experiment | Historical | `BacktestRun` and simulated result tables |
| Portfolio Replay | Historical Portfolio/economic reconstruction | Historical, session by session | `PortfolioReplayRun` and checkpoints |
| Paper Portfolio | Forward-running independent simulated Portfolio | Current/future effective sessions | `PortfolioProfile` with `portfolio_type=paper`, paper events and normal paper-domain records |

The implementation preserves the core distinction: simulation records do not become live broker or live-accounting evidence.

## 3. Semantic Matrix

| Concern | Backtest | Replay | Paper |
| --- | --- | --- | --- |
| Purpose | Evaluate one Strategy/version | Reconstruct Portfolio mechanics and history | Run a simulated Portfolio prospectively |
| Input | Historical dates, capital, Strategy/artifact world | Portfolio starting state, dates, pinned world | Independent Paper Portfolio, cash, strategies and current sessions |
| Strategy source | Pinned strategy/artifact versions | Pinned binding revisions in `pinned_world` | Paper bindings/configuration |
| Market data | As-of historical reads | Per-session historical reads and market fingerprints | Effective session price service |
| Starting holdings | Virtual simulation context | New simulated empty state or reconstructable historical branch | Persisted Paper holdings/opening state |
| Transaction model | `BacktestTransaction` and virtual trades | In-memory checkpoint state and replay transactions | Normal Paper-profile transactions plus `PaperExecutionEvent` |
| Execution | Simulated engine | `ReplayTradeTransition` | `PaperSimulationProcessor` / simulated executor |
| Broker | Never | Never | Structurally unavailable |
| Live ledger mutation | None | None | Only the Paper profile’s simulated domain state |
| Recommendations | Simulated/evidence state where engine produces them | Checkpoint recommendations | Normal Paper-profile recommendations and simulated execution |
| Output | Run, trades, snapshots, statistics, report | Run, checkpoints, state, statistics, limitations | Paper transactions, holdings, cash, events, performance |
| Resume/cancel | Checkpoint lifecycle and cancellation | Checkpoint processing and cancellation | Durable effective-session checkpoint; pause/resume |
| UI | `/backtests` and detail | Portfolio Replay panel | Portfolio navigation with persistent PAPER identity |
| Comparison | Backtest comparison | Replay comparison | Paper-only performance; no implied live equivalence |

## 4. Architecture / Persistence

Backtest uses `BacktestRun`, `BacktestTransaction`, `BacktestTrade`, `BacktestSnapshot`, hits, `BacktestSimulationEngine`, and `BacktestPersistenceService`. It operates on a virtual `SimulationContext` and does not write the live `Transaction`, `Holding`, or cash-ledger tables.

Replay uses `PortfolioReplayRun`, `PortfolioReplayCheckpoint`, `PortfolioReplayService`, `PortfolioReplayProcessor`, `ReplayTradeTransition`, `ReplayEconomicStateCalculator`, and `ReplayStrategyEvaluator`. Checkpoints contain `state_before`, `state_after`, market evidence fingerprints, limitations, and effective session dates.

Paper uses a first-class `PortfolioProfile` type. `PaperSimulationProcessor`, `PaperExecutionEvent`, `PaperSimulationEvent`, `SimulationPriceService`, and normal portfolio accounting operate only against the Paper profile. Paper provenance is carried on profile, transaction, and simulated-event records.

## 5. Strategy Backtest

Current Backtest statuses are `preparing`, `running`, `completed`, `failed`, and `cancelled`; stages include preparing, simulating days, statistics, report, completed, failed, and cancelled. The engine freezes strategy/artifact/screener evidence, execution assumptions, range, capital, benchmark/context, and permitted parameter overrides.

The run-local override path is bounded by the immutable `configurable_parameters` schema. Invalid keys, types, bounds, and undeclared paths are rejected. A modified completed run can create an unpublished next Strategy draft with Backtest provenance; it does not mutate or deploy the source published version.

Backtest cancellation is checkpoint-aware and terminal. Existing lifecycle tests prove cancelled runs are not processed as completed and retain tombstone evidence on deletion.

## 6. Portfolio Replay

Replay is a Portfolio-scoped historical timeline, not an aggregate Strategy Backtest. Its `pinned_world` captures binding revisions, exact artifact versions and hashes, dependencies, strategy identity/allocation, charge model, calendar, and economic settings. Each processed session creates a separate economic checkpoint with market fingerprint and limitations. Historical branches now use `HistoricalReplayStateBuilder` to pin an as-of starting world before queueing.

Replay applies historical trade transitions, fees, capital/economic state, holdings, pending recommendations, and valuation into checkpoint state. It does not write source Portfolio holdings, transactions, or cash ledger rows. Replay can compare runs and retains tombstones after deletion.

`new_simulated` and `historical_branch` remain separate. A historical branch reconstructs ledger-derived holdings/ownership episodes, effective-date cash, binding revisions, reservation events, loans/returns, recalls, bridge loans/returns, and unsettled sale proceeds as of the branch boundary. The resulting state and evidence are stored on `PortfolioReplayRun` and are not re-read from the source Portfolio during processing. Ambiguous ownership, unavailable cash/history, missing strategy binding evidence, incomplete allocation evidence, missing legacy reservation lifecycle evidence, progressed legacy recall state, and progressed legacy bridge state block the branch; missing starting valuation is retained as an explicit limitation. The branch is processed by the same checkpoint processor without writing source holdings, transactions, or cash.

## 7. Paper Portfolio

Paper is persisted as `PortfolioProfile::TYPE_PAPER`, immutable at creation. It requires starting simulated cash, uses manual execution mode, and cannot be switched to semi-automatic or automatic broker authority. The processor advances effective sessions, waits when required prices are unavailable, and is idempotent across repeated processing.

Paper simulation can create normal Paper-profile transactions, holdings, cash changes, recommendations, and simulated execution events. These records remain attributable to Paper/simulation and are excluded from real account performance and tax aggregation. Pause blocks evaluation, new recommendations, simulated execution, and manual interventions; resume is prospective and skips the paused interval.

## 8. Clone-As-Paper

`POST /api/portfolios/{portfolio}/clone-as-paper` calls `PortfolioCloneService::cloneAsPaper`. It creates a new Paper profile, deposits independent starting simulated cash, records `clone_as_paper` provenance and source profile identity, optionally copies positive holdings as independent opening holdings, and copies active artifact binding revisions with `clone_as_paper` action.

The source’s transactions are not copied; the current feature test verifies the clone begins with zero transactions. Later source holding changes do not change the Paper holding. Paper profile state is independently mutable and the source route remains ownership-scoped.

## 9. Live/Broker Isolation

The server-side execution mode contract blocks Paper from enabling real broker authority. Paper routes use simulation services, and `LiveBrokerExecutionService` is not the Paper execution path. Existing tests prove:

- Paper execution mode remains manual;
- Paper cannot use live simulation controls incorrectly;
- simulated fills create Paper evidence and transactions;
- real account aggregation excludes Paper;
- no broker order is created by the tested Paper processor.

Backtest and Replay do not use broker gateways or live ledger services. This is distinct from AUD-015’s live final-gate audit.

## 10. PIT / Dataset Evidence

Historical consumers use date-bounded price reads, calendar-aware sessions, as-of evaluation, and per-run/session evidence. Replay checkpoints store a market-data fingerprint. Backtest and Replay retain dataset/version attribution where available, and missing data blocks, waits, skips, or records limitations rather than becoming valid output.

### SIM-002 controlled resync experiment

`HistoricalSimulationDatasetResyncTest` exercised the current Replay path with an isolated deterministic bar:

1. Dataset A was created through `DailyMarketSyncService::recordSuccessfulSyncAt()` with the historical bar at 100.
2. A historical Replay processed that bar and persisted a checkpoint valuation of 100 plus its market fingerprint.
3. The same physical bar was corrected through an isolated test upsert to 120, representing the normal sync/write effect, and Dataset B was created through the same dataset attribution path. Dataset A remained immutable and Dataset B received a new key.
4. Reloading the completed original Replay left its stored valuation and fingerprint unchanged. Reading the old run did not recompute its checkpoint from current OHLCV.
5. An equivalent new Replay after the correction read the current bar, produced valuation 120, and produced a different fingerprint.
6. Correcting a bar after the Replay period did not change the earlier checkpoint, confirming the date boundary did not leak future-period data backward.

This establishes the following guarantee split:

| Property | Result |
| --- | --- |
| Original result immutability | Confirmed: stored checkpoints/results remain unchanged after resync. |
| Original evidence integrity | Confirmed at evidence level: Dataset A attribution and checkpoint fingerprints remain retained; raw OHLCV inputs are not retained as a queryable snapshot. |
| Exact rerun reproducibility | Not available after physical correction: an equivalent rerun reads current physical `StockPrice` rows and may differ. |
| Silent reinterpretation | Not observed: loading the old Replay does not recompute its persisted result. |

The current `DatasetVersion` is an immutable successful-sync attribution record, not a query pin or immutable OHLCV membership snapshot. The Replay checkpoint fingerprint records the values used for evidence/change investigation, but there is no current service that resolves Dataset A back to the old physical bar set or automatically compares a later physical dataset to the old fingerprint. This is **Level 1 - immutable result evidence**, not Level 2 change-detectable or Level 3 fully reproducible.

This Level 1 result matches the accepted FEAT-020/current-doc contract: completed results and run evidence remain immutable and explainable, while rerunning with current data is a new run that may differ after corrections. Exact recomputation is not promised unless the original source data remains retained. No OHLCV snapshot redesign is required by the current contract.

## 11. Strategy / Artifact Provenance

Backtest runs retain strategy/version, reusable artifact version, binding revision, screener version references, parameter overrides, assumptions, and context. Replay pins binding revisions, artifact versions, hashes, dependencies, and strategy projection details in `pinned_world`. Historical Replay defaults to the binding revision effective at the branch boundary. It also accepts an explicit `strategy_version_overrides` map keyed by historical `binding_id` and valued by a published `artifact_version_id`; the selected version is pinned without changing historical economic state or source bindings. Each counterfactual row retains both historical and selected version identity, and `pinned_world.counterfactual` discloses the substitution. Paper clone copies active artifact binding revisions; later live artifact publication does not change the cloned binding automatically.

AUD-007 separately verifies artifact v1/v2 rollout and rollback. AUD-008 verifies that simulation consumers retain their own pins rather than resolving historical output through current mutable bindings.

## 12. Recommendation / Execution Semantics

| Mode | Creates recommendations? | Persistent where? | Executable? |
| --- | --- | --- | --- |
| Backtest | Simulated decisions/evidence depending on engine | Backtest context/trades/results | Never live-executable |
| Replay | Yes, as checkpoint state and pinned-world evaluation | Replay checkpoint state | Simulated replay transition only |
| Paper | Yes, in the Paper profile | Normal Paper recommendation/transaction domain plus paper event evidence | Simulated only |

Paper’s simulated fills update Paper state through the normal domain model. Replay keeps economics inside serialized checkpoint state. Backtest keeps virtual transactions/trades/snapshots under the run ID.

## 13. Lifecycle / Resume / Cancellation

Backtest has durable status/stage/progress and checkpoint-aware cancellation. Replay has queued/running/completed/cancelled states, checkpoint idempotency, bounded session processing, and cancellation that stops later processing. Paper has active/paused/waiting/catching-up behavior, durable checkpoint dates, missing-price waits, bounded processing, and idempotent repeated calls.

The production scheduler/worker commands are present for all three (`ProcessBacktestsCommand`, `ProcessPortfolioReplaysCommand`, and `ProcessPaperSimulationsCommand`). Deployed worker recovery, queue timing, and large-run performance remain runtime checks.

## 14. UI Reachability

| User goal | Route/component | API | Status |
| --- | --- | --- | --- |
| Create/list Backtests | `/backtests`, `BacktestHistoryPage` | `/v1/backtests` | `UI_REACHABLE` |
| Inspect Backtest result | `/backtests/:id`, `BacktestDetailPage` | `/v1/backtests/{id}` and timeline | `UI_REACHABLE` |
| Continue/cancel/duplicate/compare Backtest | History/detail controls | Backtest lifecycle routes | `UI_REACHABLE` |
| Create Strategy draft from modified Backtest | Backtest detail | `/v1/backtests/{id}/strategy-draft` | `UI_REACHABLE` |
| Create/list Replay | Portfolio Replay panel | `/replays`, readiness, compare | `UI_REACHABLE` |
| Select published counterfactual Strategy versions | Historical branch version selector after readiness check | `/replays/readiness`, `/replays` with `strategy_version_overrides` | `UI_REACHABLE` |
| Inspect/cancel/delete Replay | Replay panel | `/replays/{id}` routes | `UI_REACHABLE` |
| Create Paper Portfolio | Portfolios page | `POST /api/portfolios` | `UI_REACHABLE` |
| Clone Live as Paper | Portfolios page | `POST /api/portfolios/{id}/clone-as-paper` | `UI_REACHABLE` |
| Pause/resume Paper | Paper simulation surface | portfolio simulation routes | `UI_REACHABLE` |
| Run Paper simulation | scheduler/processor and Paper workflow | paper simulation processor | `AUTOMATION_ONLY` for processing; controls are reachable |

## 15. UI Semantic Distinction

Backtest pages use Strategy Backtest terminology and expose historical run assumptions/results. The Replay panel is Portfolio-contextual and displays replay periods, readiness, checkpoints, and comparison. Paper is a first-class portfolio type with persistent `PAPER` disclosure and separate execution-mode blockers.

No generic route was found that silently switches between Backtest, Replay, and Paper based only on a client hint. The remaining browser check is whether the distinctions remain visually obvious at constrained widths and during loading/failure states.

## 16. Error / Empty / Recovery States

Backtest and Replay readiness reject invalid inputs and block missing/incompatible worlds. Replay explicitly blocks missing strategy/capital reconstruction. Paper waits on missing session prices without advancing its checkpoint. Backtest cancellation/failure remains terminal rather than completed.

The current source and tests cover domain failure semantics, but a complete cross-mode browser review of failed, partial, unavailable, and incomplete-result rendering remains runtime verification. This is especially relevant to long-running progress and partial checkpoint/result disclosure.

## 17. Authorization

Portfolio route binding is user-scoped, active profile middleware scopes requests, and Replay/Backtest controllers resolve profile-owned resources. Clone uses the source Portfolio route ownership boundary. Existing tests cover foreign profile rejection in related portfolio middleware and simulation paths; a complete production route matrix remains AUD-012 scope.

## 18. Existing Test Coverage

| Requirement | Existing tests | What they prove | Missing coverage |
| --- | --- | --- | --- |
| Backtest lifecycle | `V5BacktestLifecycleTest` | Durable cancellation, terminal state, processor exclusion, tombstone | Full real completed run with before/after live ledger snapshot |
| Backtest overrides/drafts | `V5BacktestParameterOverrideTest` | Bounded overrides, immutable source, unpublished provenance-linked draft | Browser disclosure of all assumptions |
| Replay | `V5PortfolioReplayFoundationTest`, `HistoricalReplayTemporalStateTest`, `HistoricalSimulationDatasetResyncTest`, `CounterfactualHistoricalReplayTest` | Pinned world, checkpoints, economic transition, source isolation, cancellation, idempotency, readiness blocking, temporal reconstruction, controlled Dataset A/B resync behavior, default historical and counterfactual version pins, invalid override rejection | Deployed worker and browser selection/disclosure |
| Paper creation/clone | `V5PaperPortfolioFoundationTest` | Immutable type, independent cash/holdings, binding pins, no history copy, broker-mode guard | Full strategy projection clone and browser walkthrough |
| Paper processing | `V5PaperSimulationProcessorTest` | Simulated fill, missing-price wait, partial affordability, idempotency | Production worker/catch-up timing |
| Paper price boundary | `V5SimulationPriceServiceTest` | Effective session price behavior | Provider/runtime data boundary |
| Artifact provenance | AUD-007 artifact suites | Exact artifact/binding pins through rollout and rollback | Deployed artifact storage |
| Authorization | portfolio/profile and simulation feature suites | User/profile ownership boundaries | Full route/object matrix, AUD-012 |

## 19. Representative Assurance Design / Results

The existing focused suites form the executable assurance set rather than a new composite test. They use production services and deterministic fixtures:

1. Backtest lifecycle and parameter tests prove run-local semantics, cancellation, source immutability, and draft provenance.
2. Replay foundation tests create a pinned world, process real checkpoints, assert deterministic replay state, and compare source holdings/transactions/cash-ledger counts before and after.
3. Paper foundation and processor tests create/clone Paper, process simulated fills, assert Paper cash/holding/event changes, preserve live/source independence, and block broker authority.
4. Clone tests assert no copied transaction history, copied opening holdings when requested, pinned binding identity, and no ongoing source holding synchronization.

This is sufficient static assurance for the implemented paths. SIM-002 retains its documented Level 1 limitation; the remaining browser/deployment checks do not represent a known static semantic defect.

## 20. Gap Register

| ID | Finding | Classification | Severity | Evidence |
| --- | --- | --- | --- | --- |
| SIM-001 | Historical Replay branch reconstruction and temporal capital state | `IMPLEMENTED` | High | `HistoricalReplayStateBuilder`; immutable reservation and bridge-return ledgers; as-of loan status/amount derivation; precise fail-closed recall/legacy-state blockers; historical branch and lifecycle regression suites |
| SIM-002 | Dataset version is attribution, not an immutable market-data snapshot; completed results are immutable but equivalent reruns after a physical correction may differ | `IMPLEMENTED_WITH_LIMITATION` | Medium | `HistoricalSimulationDatasetResyncTest`: Dataset A/B controlled correction, unchanged original checkpoint/evidence, changed equivalent rerun, and future-period boundary check; current docs explicitly permit current-data reruns to differ |
| SIM-003 | A single three-mode end-to-end fixture is not present; assurance is split across strong mode-specific suites | `OPTIONAL_ASSURANCE` | Low | Backtest, Replay, and Paper each have focused production-service isolation suites; a composite fixture is not required by a distinct product contract |
| SIM-004 | Full browser proof of mode labels, partial results, recovery, and constrained-width controls is absent | `RUNTIME_VERIFICATION_REQUIRED` | Medium | Routes/components and source tests exist; browser geometry and interaction remain unverified |
| SIM-005 | Historical Replay lacks explicit published Strategy-version substitution for counterfactual runs | `IMPLEMENTED` | Medium | `PortfolioReplayService` validates `binding_id -> artifact_version_id` overrides, preserves historical/selected evidence, pins the selected version, and `CounterfactualHistoricalReplayTest` covers default, partial, invalid, inaccessible, and non-historical inputs |

No evidence was found that Paper can submit to a live broker, that Replay writes the source ledger, that Backtest writes live holdings/cash, that clone state synchronizes continuously, or that artifact rollout rewrites simulation provenance.

## 21. Remediation Groups

### A — Mode isolation

Static isolation is implemented. Retain the existing service-level tests and add only a composite gate if operational confidence requires one.

### B — PIT/provenance

The repository-level resync experiment establishes the accepted Level 1 guarantee. Keep artifact/version pins and run evidence unchanged. A future Level 2/3 design would require a changed contract and a bounded immutable data-revision or run-input retention strategy; it is not part of this audit remediation.

### C — Backtest semantics

Current bounded override, cancellation, provenance, and draft behavior is implemented. Remaining work is completed-run isolation coverage and browser verification.

### D — Replay semantics

Historical branch reconstruction is implemented for the persisted state elements currently supported by the model. Reservation lifecycle events and bridge repayment events are appended by their normal domain services; normal-loan amount/status is derived from returns effective at or before the boundary. Recall progression and legacy bridge/reservation rows without sufficient temporal evidence fail closed with precise blockers rather than using current mutable state. Counterfactual substitution is implemented as an additive historical-binding override: published same-lineage versions are library-access and dependency validated, then pinned with historical-vs-selected evidence. The reconstructed economic starting state remains unchanged.

### E — Paper/clone semantics

Current Paper identity, broker exclusion, clone independence, no-history-copy, and binding pinning are implemented. Verify full strategy projection and deployed processing as runtime follow-up.

### F — UI distinction/recovery

Perform a browser walkthrough of Backtest, Replay, and Paper loading, partial, failure, cancel, pause, and recovery states.

### G — Runtime verification

Run deployed worker/scheduler, production-like dataset, browser, and provider-independent simulation checks.

## 22. Recommended Order

1. Preserve the existing mode-specific feature assurance suites and add a composite isolation test only if a single release gate is operationally required.
2. Retain the controlled resync test as the regression gate for immutable results, DatasetVersion attribution, and no future-period leakage.
3. Run browser and deployed-worker verification for all three modes, including counterfactual selector/disclosure, cancellation, pause, missing-data, and partial-result states.

## 23. Runtime Verification Boundary

Remaining runtime checks are:

- deployed Backtest/Replay/Paper workers and scheduler cadence;
- large-run checkpoint/resume behavior;
- deployed historical data resync behavior and operational visibility of source-data changes; exact rerun reproducibility is not promised by the current contract;
- production-like market data and dataset availability;
- browser mode labels, responsive controls, partial/error states, and Paper/live distinction;
- browser counterfactual version selection and historical-vs-selected disclosure;
- role/profile route reachability and deployed configuration.

## 24. Final AUD-008 Assessment

**Disposition: `IMPLEMENTED`.**

Backtest, Replay, and Paper are materially distinct and server-side isolated. Existing executable suites plus the historical branch assurance prove the most important no-live-mutation, broker exclusion, clone independence, artifact/version provenance, checkpoint, cancellation, as-of reconstruction, temporal capital-state handling, and missing-data boundaries. Historical Replay now supports explicit published same-lineage version substitution while preserving historical economic state and historical-vs-selected evidence. SIM-002 remains an accepted Level 1 limitation: completed results and evidence are immutable, while equivalent reruns after physical corrections use current data and may differ. SIM-003 is optional composite assurance; SIM-004 remains browser/deployment verification.

This is a bounded static closure, not evidence of a live-state contamination defect. Deployment, browser, and operational verification remain; AUD-007, AUD-011, AUD-012, and AUD-015 remain separate.

## 25. Open Questions

- If a future product contract requires Level 2 change detection or Level 3 exact reruns, which bounded market-data retention strategy should be adopted?
- Is one composite three-mode assurance test required as a release gate, given the existing mode-specific feature suites?
