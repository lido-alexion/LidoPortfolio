# V5-FEAT-034 — Richer Evaluation History UX

Status: **FROZEN / IMPLEMENTED**  
Date: 2026-09-04

## Problem

StoX stores every Evaluation run and its ranked results, but the supported UI exposes only the latest Evaluation merged into Discovery. Operators cannot inspect an earlier run without querying the database or knowing its id.

## Frozen behaviour

- Evaluation remains part of **Discovery**; `/evaluations` continues to redirect to `/candidates`.
- Discovery shows the 20 most recent Evaluation runs, newest first.
- Selecting a run shows its immutable ranked results, including symbol, rank, score, confidence, and explanation.
- History is isolated to the active portfolio and includes completed and failed runs so failures remain auditable.
- Running Discovery/Evaluation refreshes both current candidates and run history.

## Architecture

- `GET /api/v1/evaluation/runs?limit=20` provides a bounded run index; the server clamps the limit to 1–50.
- Existing `GET /api/v1/evaluations?evaluation_run_id=…` supplies results for the selected run.
- The Evaluation repository owns both queries. The controller and presenter retain the existing API envelope and portfolio middleware.
- No schema migration is needed; existing run/result retention is authoritative.

## Algorithms

1. Query Evaluation runs for the active portfolio, descending by id, with result counts.
2. Clamp the requested limit and return run status, timestamps, statistics, error, and count.
3. On selection, load results by run id ordered by stored rank.
4. Render stored values without rescoring or rewriting historical evidence.

## UX

- A compact **Evaluation history** card sits on Discovery.
- The selector labels the newest run as Latest and shows status and result count.
- Empty-run and no-history states are explicit.
- Historical results are read-only; current candidate evidence/factor details remain unchanged.

## Acceptance criteria

- A user can select and inspect any of the 20 latest runs from Discovery.
- Results retain stored rank, score, confidence, and explanation.
- Runs from another portfolio are never returned.
- Failed/empty runs remain visible and do not break the page.
- Running Evaluation refreshes history.
- Backend route/contract tests and Discovery UI tests pass.

## Dependencies

- Existing EvaluationRun/EvaluationResult persistence and active-portfolio middleware.
- Existing merged Discovery/Evaluation UX and API envelope.

## Non-goals

- Restoring a dedicated Evaluation page or primary navigation item.
- Recomputing, deleting, editing, or comparing historical factor payloads.
- Unbounded history, export, retention-policy changes, or Recommendation changes.

## Older-rule reconciliation

Early V1/V3 inventory documents describe a dedicated `/evaluations` page. The later frozen merged-page decision remains authoritative: `/evaluations` redirects to Discovery. FEAT-034 enriches history inside that merged experience and does not revive the superseded page or navigation rule.
