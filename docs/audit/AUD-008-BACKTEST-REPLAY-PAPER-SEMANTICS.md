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

Historical consumers use date-bounded price reads, calendar-aware sessions, as-of evaluation, and per-run/session evidence. Replay checkpoints store a market-data fingerprint. Backtest and Replay pin dataset/version context where available, and missing data blocks, waits, skips, or records limitations rather than becoming valid output.

The remaining limitation is explicit in current documentation: `DatasetVersion` is immutable sync attribution, not a complete immutable OHLCV snapshot. Therefore static repository evidence cannot prove that a later physical resync can never alter a future re-read of an old period. This remains a bounded PIT/runtime verification item, not an assertion that current runs use future bars.

## 11. Strategy / Artifact Provenance

Backtest runs retain strategy/version, reusable artifact version, binding revision, screener version references, parameter overrides, assumptions, and context. Replay pins binding revisions, artifact versions, hashes, dependencies, and strategy projection details in `pinned_world`. Paper clone copies active artifact binding revisions; later live artifact publication does not change the cloned binding automatically.

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
| Replay | `V5PortfolioReplayFoundationTest` | Pinned world, checkpoints, economic transition, source isolation, cancellation, idempotency, readiness blocking | Historical branch once reconstruction is supported; deployed worker |
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

This is sufficient static assurance for the implemented paths, while the two bounded gaps below prevent overall closure.

## 20. Gap Register

| ID | Finding | Classification | Severity | Evidence |
| --- | --- | --- | --- | --- |
| SIM-001 | Historical Replay branch reconstruction and temporal capital state | `IMPLEMENTED` | High | `HistoricalReplayStateBuilder`; immutable reservation and bridge-return ledgers; as-of loan status/amount derivation; precise fail-closed recall/legacy-state blockers; historical branch and lifecycle regression suites |
| SIM-002 | Dataset version is attribution, not an immutable market-data snapshot, so post-resync historical reproducibility is not fully proven | `RUNTIME_VERIFICATION_REQUIRED` | High | Current analytics and market-data docs explicitly retain this limitation; market fingerprints exist per Replay checkpoint |
| SIM-003 | A single three-mode end-to-end fixture is not present; assurance is split across strong mode-specific suites | `RUNTIME_VERIFICATION_REQUIRED` | Medium | Existing tests cover each mode and key isolation invariants, but not one combined workflow |
| SIM-004 | Full browser proof of mode labels, partial results, recovery, and constrained-width controls is absent | `RUNTIME_VERIFICATION_REQUIRED` | Medium | Routes/components and source tests exist; browser geometry and interaction remain unverified |

No evidence was found that Paper can submit to a live broker, that Replay writes the source ledger, that Backtest writes live holdings/cash, that clone state synchronizes continuously, or that artifact rollout rewrites simulation provenance.

## 21. Remediation Groups

### A — Mode isolation

Static isolation is implemented. Retain the existing service-level tests and add only a composite gate if operational confidence requires one.

### B — PIT/provenance

Prioritize an immutable/fingerprint-backed historical data verification or deployed resync drill. Keep artifact/version pins and run evidence unchanged.

### C — Backtest semantics

Current bounded override, cancellation, provenance, and draft behavior is implemented. Remaining work is completed-run isolation coverage and browser verification.

### D — Replay semantics

Historical branch reconstruction is implemented for the persisted state elements currently supported by the model. Reservation lifecycle events and bridge repayment events are appended by their normal domain services; normal-loan amount/status is derived from returns effective at or before the boundary. Recall progression and legacy bridge/reservation rows without sufficient temporal evidence fail closed with precise blockers rather than using current mutable state. Counterfactual version substitution is not currently exposed by the Replay API and remains a bounded follow-up if required by FEAT-020.

### E — Paper/clone semantics

Current Paper identity, broker exclusion, clone independence, no-history-copy, and binding pinning are implemented. Verify full strategy projection and deployed processing as runtime follow-up.

### F — UI distinction/recovery

Perform a browser walkthrough of Backtest, Replay, and Paper loading, partial, failure, cancel, pause, and recovery states.

### G — Runtime verification

Run deployed worker/scheduler, production-like dataset, browser, and provider-independent simulation checks.

## 22. Recommended Order

1. Preserve the existing mode-specific feature assurance suites and add a composite isolation test only if a single release gate is operationally required.
2. Verify point-in-time behavior after a controlled market-data resync and confirm run fingerprints/provenance remain explainable.
3. Run browser and deployed-worker verification for all three modes, including cancellation, pause, missing-data, and partial-result states.

## 23. Runtime Verification Boundary

Remaining runtime checks are:

- deployed Backtest/Replay/Paper workers and scheduler cadence;
- large-run checkpoint/resume behavior;
- historical data resync and post-resync reproducibility;
- production-like market data and dataset availability;
- browser mode labels, responsive controls, partial/error states, and Paper/live distinction;
- role/profile route reachability and deployed configuration.

## 24. Final AUD-008 Assessment

**Disposition: `PARTIALLY_IMPLEMENTED`.**

Backtest, Replay, and Paper are materially distinct and server-side isolated. Existing executable suites plus the historical branch assurance prove the most important no-live-mutation, broker exclusion, clone independence, artifact/version provenance, checkpoint, cancellation, as-of reconstruction, temporal capital-state handling, and missing-data boundaries. SIM-001 is implemented with precise fail-closed blockers for legacy or incomplete temporal evidence. Full point-in-time reproducibility after physical dataset resync remains unproven, and browser and deployed-worker checks remain.

This is a bounded partial verdict, not evidence of a live-state contamination defect. AUD-007, AUD-011, AUD-012, and AUD-015 remain separate.

## 25. Open Questions

- Should FEAT-020 counterfactual strategy/artifact version substitution be exposed in the current Replay API, or remain an explicit future capability?
- What deployed data/version retention or fingerprint policy will provide proof after a physical market-data resync?
- Is one composite three-mode assurance test required as a release gate, given the existing mode-specific feature suites?
