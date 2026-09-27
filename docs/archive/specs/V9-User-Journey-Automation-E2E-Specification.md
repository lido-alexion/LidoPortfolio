# StoX V9 User Journey Automation Readiness & E2E Automation Specification

| Field | Value |
|---|---|
| **Epic** | `V9-UX-001` — User Journey Automation Readiness & E2E Automation |
| **Version target** | V9 |
| **Status** | FROZEN — implementation-ready |
| **Owner** | Product / Architecture |
| **Parent register** | `docs/archive/specs/LidoPortfolio-V9-Wishlist.md` |
| **Primary source of truth** | `docs/user-journeys/` plus approved golden-journey documents |

## 1. Product intent

Turn StoX's human-readable user-journey corpus into the authoritative, automation-ready product contract for major investor workflows. The epic must first make the implemented UX conform to the approved journeys and only then automate those journeys. Automation must protect product intent rather than freeze accidental implementation behavior.

The epic is the highest-priority V9 item.

## 2. Scope

In scope:

- the core investor path: Screener -> Strategy -> Recommendation -> Review -> Pending Execution -> Transaction;
- all other major investor-facing workflows already documented or discovered during the audit;
- login/session, watchlist/stock-detail, screener lifecycle, strategy lifecycle, recommendation review, Kite connect/disconnect, manual/semi-automatic execution, cancellation/reconciliation, and materially relevant portfolio/account settings;
- key validation, recovery, cancellation/back-navigation, authorization and error states for those journeys;
- Admin workflows only where they directly enable an investor journey;
- desktop plus one representative mobile viewport;
- Chromium browser coverage;
- targeted visual-regression coverage for critical screens/states;
- lightweight automated accessibility checks on key journey pages;
- deterministic broker simulation in CI plus a separate controlled real-Kite smoke suite;
- non-destructive production smoke validation;
- scheduled nightly regression execution in addition to normal blocking CI.

Out of scope:

- performance gates, load/stress/endurance testing or general performance-engineering work;
- exhaustive role-matrix automation beyond the primary Investor role;
- broad Admin-console regression coverage unrelated to investor journeys;
- real money-moving or destructive broker actions from automation;
- full accessibility certification;
- broad screenshot coverage of every page/state;
- arbitrary test-only shortcuts that bypass the journey being tested.

## 3. Authoritative journey contract

The human-readable golden/user journey remains authoritative.

Rules:

1. Every automated scenario must reference a stable journey/scenario identifier.
2. Tests must not silently redefine expected product behavior.
3. If implementation and an approved journey differ, the implementation must be corrected before automation proceeds.
4. **Every documented difference, including minor UX differences, must be resolved before that journey is automated.**
5. Any behavior-changing update to an authoritative journey requires explicit PO approval.
6. Engineering may make non-behavioral wording/formatting/reference corrections without changing workflow semantics.
7. If a real user journey is discovered during audit and is not documented, it becomes mandatory V9-UX-001 scope: document it, obtain any necessary PO decisions, conform the UX, and automate it before epic closure.

## 4. Journey conformance gate

For each in-scope journey:

1. Compare current application behavior with the authoritative golden/user journey.
2. Record every deviation.
3. Resolve every deviation before automation, including minor wording/layout/interaction mismatches.
4. Material corrections become linked child work packages under V9-UX-001; minor implementation adjustments may be completed directly within the epic.
5. Re-review the corrected journey.
6. Only then implement E2E automation.

V9-UX-001 owns the acceptance gate even when implementation fixes are delivered through child work packages.

## 5. Coverage standard

Each major journey must cover:

- successful happy path;
- key validation failures;
- meaningful recovery/retry paths;
- cancellation/back-navigation where materially relevant;
- authorization/permission failure where relevant to the primary Investor path;
- important known edge states that can prevent task completion.

The epic closes only when **100% of the agreed in-scope major journeys** satisfy the complete conformance and automation criteria. There is no percentage-based waiver.

## 6. Delivery model

Deliver incrementally by logical journey group, while keeping the epic open until all scope is complete.

Recommended implementation sequence:

1. authentication and account-entry prerequisites;
2. screener journeys;
3. strategy journeys;
4. recommendation and review journeys;
5. execution, cancellation and reconciliation journeys;
6. supporting investor workflows;
7. production smoke coverage and final completeness audit.

Each completed group should begin protecting regressions immediately.

## 7. Browser and viewport policy

- Full functional E2E coverage targets **Chromium only** for V9-UX-001.
- Run the major investor journeys on a representative desktop viewport and one representative mobile viewport.
- Exact viewport dimensions are implementation-level choices and should use stable commonly used sizes.

## 8. Test data and isolation

Use deterministic seeded test data per run.

Requirements:

- each run starts from a known state;
- tests must not depend on execution order;
- state leakage between scenarios is prohibited;
- dedicated test-only hooks may be used only for setup, reset, prerequisite creation and teardown;
- the journey itself must still execute through the real user-facing UI/API behavior;
- test-only hooks must be unavailable or strongly protected outside test environments.

## 9. Broker/Kite policy

Normal CI:

- use deterministic mocks/fakes for broker responses;
- cover connect-state behavior, placement lifecycle, cancellation, reconciliation, partial/failure states and recovery semantics as applicable;
- never depend on live market/session state.

Controlled real-Kite smoke suite:

- verify the real integration boundary separately;
- never place, alter or cancel a real money-moving order;
- stop before destructive actions;
- keep this suite isolated from normal deterministic CI.

Automation must never execute real destructive or money-moving financial actions.

## 10. CI and build gating

- Any blocking E2E failure fails the build.
- Allow one automatic retry for a failed E2E test.
- If the retry also fails, the build fails.
- A test that frequently succeeds only on retry is still considered flaky and must be fixed.
- Important journey tests must not be permanently quarantined to make CI green.

Run:

- blocking E2E checks in normal CI on relevant code changes;
- a broader scheduled nightly regression run against the designated test environment.

Nightly execution supplements but never replaces PR/build-time checks.

## 11. Production smoke suite

After deployment, run a small non-destructive production smoke suite covering safe essentials such as:

- application availability;
- login using a dedicated production smoke-test identity where safely supported;
- critical route/page availability;
- important read-only API health;
- essential navigation.

Production smoke automation must not mutate portfolio/trading state or perform destructive actions.

If the production smoke suite fails:

- mark the deployment unhealthy;
- stop further rollout where applicable;
- roll back when evidence indicates the new release caused the failure;
- preserve diagnostic evidence and require fix/revalidation before considering the deployment healthy.

## 12. Visual regression

Use targeted screenshot-based visual regression for a small set of high-value states, including representative examples such as:

- login/account entry;
- screener results;
- strategy configuration;
- recommendation review;
- pending execution;
- selected critical mobile views.

Normalize or mask intentionally dynamic content. Do not snapshot the whole application indiscriminately.

## 13. Accessibility checks

Add lightweight automated accessibility checks to key journey pages, including where practical:

- semantic form labeling;
- keyboard reachability of key actions;
- ARIA/role misuse detectable by tooling;
- obvious automated contrast/accessibility violations.

This is a regression guard, not a full WCAG certification programme.

## 14. Role coverage

The automated functional journey suite targets the **primary Investor role**.

Admin actions are included only when they are prerequisites for an investor journey. Do not multiply the full suite across every role/persona.

## 15. Selector and automation contract

- Prefer user-visible semantic selectors such as accessible role/name where stable.
- Add stable semantic hooks such as `data-testid` only where needed for robustness.
- Test selectors must not leak implementation mechanics into user-facing documentation.
- Keep journey IDs/scenario IDs stable enough to map documentation to automated cases.
- Avoid brittle CSS/XPath selectors tied to incidental DOM structure.

Exact automation framework/library selection is implementation-level; prefer the current stable Playwright ecosystem unless repository constraints provide a stronger reason otherwise.

## 16. Failure evidence

For failed E2E runs, retain actionable evidence:

- screenshot(s);
- browser trace where available;
- relevant console errors;
- relevant network/request failure information;
- test/journey/scenario ID;
- concise logs sufficient to reproduce the problem.

For passing runs, retain only lightweight historical result data needed by CI.

Do not build a separate test-analytics product as part of this epic.

## 17. No performance gate

V9-UX-001 intentionally carries **no performance acceptance gate**. Performance, load, stress and endurance testing are separate concerns and must not block completion of this epic unless a functional timeout/failure makes a journey unusable.

## 18. Child work packages

When conformance review finds product/UX changes:

- material changes must be tracked as linked V9-UX-001 child work packages/tasks;
- each child task must reference the affected journey IDs and acceptance expectations;
- the parent journey remains blocked until its corrective task is complete;
- minor implementation corrections may be handled directly within the parent epic.

## 19. Completion criteria

V9-UX-001 is complete only when all of the following are true:

- 100% of all in-scope major journeys, including newly discovered journeys, are documented;
- each journey has been reviewed against the implemented product;
- every documented difference has been resolved;
- behavior-changing journey updates have received required PO approval;
- happy paths and key recovery/error paths are automated;
- Chromium desktop coverage exists for all required scenarios;
- representative mobile coverage exists for major journeys;
- targeted visual-regression coverage is in place;
- lightweight accessibility checks are in place for key journey pages;
- deterministic seeded data and safe setup/teardown are implemented;
- broker behavior is deterministic in CI and real-Kite smoke validation is non-destructive;
- blocking CI, one-retry flaky-test policy and nightly regression execution are operational;
- production smoke tests are non-destructive and act as deployment health gates;
- failed runs preserve useful diagnostic evidence;
- no real money-moving automation exists;
- documentation-to-test traceability is complete.

## 20. Frozen PO decisions

The following product decisions are frozen for this epic:

- Scope: core path plus all major investor workflows.
- Validate/correct workflows before automating them.
- Every documented UX/product difference must be resolved before automation.
- Chromium only.
- Any E2E failure blocks the build.
- Deterministic seeded test data per run.
- Mock broker in normal CI plus separate controlled real-Kite smoke tests.
- Desktop plus one representative mobile viewport.
- Include Admin workflows only where they directly enable investor journeys.
- Cover happy path plus key recovery/error paths.
- Add non-destructive post-deployment production smoke tests.
- Parent epic owns the acceptance gate; material fixes are linked child work packages.
- Human-readable golden/user journeys remain authoritative.
- Deliver in phases by journey group; close only after full scope is complete.
- Include lightweight accessibility checks.
- Include targeted visual regression.
- No performance gates.
- Primary Investor role only.
- Test-only hooks allowed only for setup/teardown.
- Retain useful failure evidence.
- Allow one automatic retry; repeated flakiness remains blocking.
- Require 100% of agreed in-scope major journeys for epic closure.
- Failed production smoke means unhealthy deployment and rollback/fix as appropriate.
- PO approval required for behavior-changing authoritative journey updates.
- Every newly discovered real user journey becomes mandatory scope.
- Never execute real destructive/money-moving actions in automation.
- Run blocking CI plus scheduled nightly regression.
