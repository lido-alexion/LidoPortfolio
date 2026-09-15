# LidoPortfolio / StoX V6 Wishlist

| Field | Value |
|---|---|
| **Document type** | Canonical V6 product/status register |
| **Created** | 2026-09-07 |
| **Frozen** | 2026-09-09 |
| **Implementation status** | **COMPLETE — 12/12** |
| **Last reconciled** | 2026-09-15 |
| **Canonical path** | `specs/LidoPortfolio-V6-Wishlist.md` |
| **Predecessor** | `specs/LidoPortfolio-V5-Wishlist.md` |

## 1. Purpose and authority

This is the canonical V6 register. V6 product architecture was frozen on 2026-09-09 and the complete 12-item backlog has since been implemented.

V1–V5 frozen specifications remain authoritative for inherited behavior unless a V6 feature explicitly supersedes an older rule.

## 2. Canonical V6 backlog

**Current count: 12 COMPLETE / 0 OPEN.**

| ID | Feature | Scope / inherited boundary | Planning group | Status |
|---|---|---|---|---|
| V4-FEAT-016 | Mobile / responsive client support | Responsive SPA across mobile, desktop, ultrawide and 4K, with adaptive interaction patterns rather than merely shrinking desktop UI. | UX / Platform | COMPLETE |
| V4-FEAT-035 | Remaining frontend stack migration | Incremental TypeScript/TanStack Query/AG Grid evolution without a big-bang rewrite or functional regression. | UX / Platform | COMPLETE |
| V4-FEAT-036 | Trusted non-SPA API tokens | Named, revocable, scoped personal/API tokens for trusted first-party/personal integrations; SPA remains Sanctum cookie auth. | Platform / API | COMPLETE |
| V4-FEAT-043 | Dashboard / UX reorganization and widget management | Responsive, hierarchical, visual-first dashboard and reusable component standardization while preserving existing useful data/features. | UX / Platform | COMPLETE |
| V4-FEAT-044 | Kite Disconnect Kill Switch | Account-level halt, outbound-order gate closure, Kite disconnect/revocation and local credential destruction. | Live Execution Safety | COMPLETE |
| V4-FEAT-045 | Emergency Cancel Open Orders + Disconnect | Halt first, cancel eligible StoX-managed primary open orders with bounded verification, then disconnect/revoke. | Live Execution Safety | COMPLETE |
| V4-FEAT-046 | Live Kite Quote-Based Execution Sizing | Recompute residual external orders from target/current ownership using live quote, with Investor-level fallback policy. | Live Execution Safety | COMPLETE |
| V4-FEAT-047 | Account-Level Execution State | Broker-independent Investor execution state `Normal` / `Emergency Halt`, separate from Portfolio mode. | Live Execution Safety | COMPLETE |
| V4-FEAT-048 | Persistent Emergency Controls | Global execution-state indicator and responsive emergency/recovery controls. | Live Execution Safety / UX | COMPLETE |
| V4-FEAT-049 | Clone Portfolio as Paper | Create an independent Paper Portfolio, optionally copying current holdings, with pinned Strategy/artifact versions and no ongoing synchronization. | Portfolio experimentation | COMPLETE |
| V4-FEAT-050 | Admin Audit Explorer | Admin-only read-only explorer/export over authoritative persisted audit traces. | Administration | COMPLETE |
| V4-FEAT-051 | Contextual Notes | Personal plain-text notes attached to stable page context + account/Portfolio scope via responsive contextual UI. | UX / Productivity | COMPLETE |

## 3. V6 epic groups

| Epic | Features | Status |
|---|---|---|
| E1 — Live Execution Safety & Emergency Controls | FEAT-044 through FEAT-048 | COMPLETE |
| E2 — Paper Experimentation | FEAT-049 | COMPLETE |
| E3 — Administrative Auditability | FEAT-050 | COMPLETE |
| E4 — Investor UX & Client Evolution | FEAT-043, FEAT-016, FEAT-035 | COMPLETE |
| E5 — External/API Access | FEAT-036 | COMPLETE |
| E6 — Contextual Notes | FEAT-051 | COMPLETE |

## 4. Subsequent disposition of work once associated with V6/V7

The following items were deliberately kept outside V6 and have since moved further in the roadmap:

| ID | Feature | Current disposition |
|---|---|---|
| V4-FEAT-018 | ML Scoring Models | **V7 — implemented and deployed.** |
| V4-FEAT-017 | AI Assistant | **V9 — open.** |
| V4-FEAT-019 | ETF / Options / Crypto expansion | **V9 — open.** |
| V4-FEAT-052 | Standalone Telemetry Platform | **V8 — separate product/application; core architecture decided, implementation future.** |
| V4-FEAT-053 | Fundamental Data Integration & Support | **V7 — implemented and deployed.** |
| V4-FEAT-054 | Historical Fundamental Data Bootstrap | **V8 — open/deferred.** |
| V4-FEAT-055 | StoX DB namespace | **V7 — closed at current state after move to a dedicated StoX database removed the shared-DB requirement.** |
| TBD | StoX Telemetry Platform Integration | **V9 — open.** |

Use the V7/V8/V9 registers for the current scope of those features.

## 5. Inherited V6-wide principles

- Recommendation target amount remains authoritative; displayed/stored quantity is derived.
- Execution remains target-seeking and revalidates before incremental attempts.
- Submitted broker orders remain Order Lifecycle responsibility.
- Completed internal transfers/fills remain economically real even if later residual broker work fails.
- Reconciliation is diagnostic; confirmed holdings mismatch blocks new Semi/Automatic execution while cash mismatch alone does not.
- Portfolio execution mode remains configuration; Emergency Halt is a separate Investor/account operational state.
- Paper Portfolios remain outside Kite/reconciliation/live Emergency Halt execution authority.
- Admin observation does not imply Investor trading authority.
- Existing StoX domain/component foundations are preserved unless a later specification explicitly supersedes them.

## 6. Closure

V6 has no remaining implementation or product-planning backlog. Any later defect/regression should be handled as maintenance or in the version that introduces the affected new behavior; it must not be treated as unfinished V6 scope.
