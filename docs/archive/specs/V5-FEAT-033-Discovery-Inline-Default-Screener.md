# V5 FEAT-033 — Discovery inline default screener

| Field | Value |
|---|---|
| Status | **IMPLEMENTED** |
| Implemented | 2026-09-04 |
| Scope | Discovery UX only; existing Screener APIs and engine behavior |

## Problem

Discovery consumes recent screener hits but previously showed only candidates and
a generic link to Screeners. An operator inspecting the candidate funnel could
not see whether the portfolio's default factory screener was enabled, healthy,
or recently run without leaving the page.

## Frozen behaviour

- Discovery shows the factory Minervini Trend Template inline, selected by its
  stable `factory_key`; another factory screener is a compatibility fallback.
- The card shows enabled/off state, scope, description, latest run status, and
  matched/scanned totals using the existing `/api/screeners` representation.
- **Run default screener** uses the existing chunk-aware run endpoints and stays
  on Discovery. It reports completion and tells the user to run Discovery to
  rebuild candidates.
- Running a screener does not silently create a Discovery or Evaluation run.
- **View or edit** opens the existing full Screener editor. Missing factory data
  shows a clear recovery action instead of hiding the section or crashing.

## Architecture

`CandidatesPage` performs an independent screener-list read alongside its
existing candidate read. The card is a presentation component; run orchestration
reuses `/screeners/{id}/run` and `/screener-runs/{id}/continue`. No endpoint,
database table, engine, or strategy linkage changes.

## Algorithms

1. Load portfolio screeners.
2. Select `factory_key=minervini_trend_template`, else the first `is_factory`
   row, else render recovery state.
3. On Run, start the screener and continue chunked runs until complete or the
   existing safety guard is reached.
4. Report matched/scanned totals and refresh only the screener summary.
5. Candidate generation remains an explicit **Run discovery** action.

## UX

The compact card sits above Discovery metrics and the candidate table. Actions
and error states use existing Bootstrap controls and application toasts. Run and
Discovery/Evaluation controls share a busy state to avoid overlapping operator
actions.

## Acceptance criteria

- Default factory screener identity, state, scope, and latest totals are visible
  on Discovery.
- It can be run to completion without navigating away, including chunked runs.
- Candidate rows are not implicitly regenerated.
- Disabled/misconfigured screeners cannot be run and explain their issue.
- Missing default screener offers an Open Screeners recovery action.
- Existing Discovery loading, empty, filtering, evidence, and evaluation flows
  remain intact and covered by tests.

## Dependencies

- Existing Screener list/run APIs and factory screener seed.
- Existing Discovery consumption of recent screener hits.

## Non-goals

- No inline condition-tree editor, default-screener replacement setting,
  Strategy eligibility rewrite, automatic run scheduling, new API, or engine
  coupling.
- No FEAT-034 Evaluation-history changes.
