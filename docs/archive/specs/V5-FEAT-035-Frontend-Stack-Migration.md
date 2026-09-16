# V5-FEAT-035 — TypeScript / TanStack Query / AG Grid migration

Status: **IN PROGRESS**  
Started: 2026-09-04

## Migration policy

This is an incremental compatibility migration, not a big-bang rewrite. Existing working JavaScript and tables remain supported while touched, high-value data surfaces move behind typed boundaries. Product behaviour and HTTP contracts do not change merely to adopt the libraries.

## Implemented foundation

- Strict TypeScript configuration with JavaScript coexistence and a `typecheck` command.
- CI rejects type errors in migrated `.ts`/`.tsx` modules.
- One application-level TanStack Query client with conservative retries, a 30-second stale window, and no focus-triggered refetch.
- Query keys include active portfolio identity for portfolio-owned server state.
- Evaluation run/results history is the first migrated server-state vertical slice.
- The Evaluation-history result table is a typed AG Grid, lazy-loaded so its library does not inflate the initial application chunk.

## Remaining migration

- Convert shared API/envelope primitives and domain payloads to TypeScript.
- Move legacy `useApiGet` server state to portfolio-scoped TanStack Query keys screen by screen.
- Adopt AG Grid only where filtering, sorting, column state, or large datasets justify it; keep simple semantic tables simple.
- Convert components as they are materially changed, expanding strict coverage without blocking unrelated work.

## Guardrails

- No cache may cross portfolio/user identity.
- Mutations must invalidate only affected keys and preserve existing toast/error semantics.
- Grid adoption must retain loading, empty, keyboard, and narrow-screen behaviour.
- Each slice must pass frontend tests, TypeScript checking, and the production build.
