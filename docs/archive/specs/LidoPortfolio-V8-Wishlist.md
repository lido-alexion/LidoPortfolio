# LidoPortfolio / StoX V8 Wishlist

| Field | Value |
|---|---|
| **Document type** | Canonical V8 planning register |
| **Created** | 2026-09-09 |
| **Status** | EARLY PLANNING |
| **Canonical path** | `specs/LidoPortfolio-V8-Wishlist.md` |
| **Predecessor** | `specs/LidoPortfolio-V7-Wishlist.md` |

## 1. Purpose

V8 contains the **Standalone Telemetry Platform**, deferred follow-on data-bootstrap work that is intentionally kept outside the V7 closure gate, and selected account/onboarding improvements that do not alter the existing V1-V7 security boundaries.

The Telemetry Platform is separated from StoX product implementation because it is a separate, independently deployable, product-independent application. StoX is its first intended client, but building the Telemetry product and integrating StoX with it are separate roadmap concerns.

V8 also contains the one-time Historical Fundamental Data Bootstrap, deferred from V7 until the historical dataset/source is prepared and selected, a guest-to-admin **Account Access Request** workflow that preserves admin-controlled onboarding while removing the need for an applicant to obtain an invitation before expressing interest, and a broader ML follow-on program covering scheduled retraining/deployment operations, fundamental feature expansion, technical/market feature engineering, empirically calibrated multi-window promotion criteria, and a richer Admin ML management/observability experience.

V8 also includes a lightweight **Guided Tour / Welcome Onboarding** experience so new users can discover the StoX shell and important workflows in context without introducing a heavyweight third-party onboarding dependency.

## 2. Current V8 backlog

| ID | Feature | Scope / rationale | Status |
|---|---|---|---|
| V4-FEAT-052 | Standalone Telemetry Platform | Build the product-independent telemetry/analytics platform as a separate repository/application. Core architecture is already decided in `specs/V7-Telemetry-Platform.md`; the historical filename is retained for now, but this V8 register supersedes its earlier V7 roadmap placement. | CORE ARCHITECTURE DECIDED |
| V4-FEAT-054 | Historical Fundamental Data Bootstrap | Complete the V7 fundamentals foundation with canonical fact-gap filling, curated derived metrics, official NSE/BSE historical backfill with Yahoo fallback, resumable queue-backed bootstrap, investor Fundamentals UI, historical charts and Screener eligibility integration. **Canonical implementation spec:** [`V8-Historical-Fundamentals-Bootstrap-Specification.md`](V8-Historical-Fundamentals-Bootstrap-Specification.md). | FROZEN / IMPLEMENTATION-READY |
| V4-FEAT-055 | Account Access Request / Admin Approval Workflow | Add a guest-facing **Request an account** flow linked from Login. Human applicants submit the information required for admin onboarding behind CAPTCHA and abuse controls. Pending duplicates are deduplicated; admins review requests in User Management, receive operational notifications, and resolve them as **Create**, **Ignore**, or **Reject**. Reject creates a reversible email ban; Ignore closes the request without banning; Create hands off into the existing secure admin onboarding/invite flow with request details prefilled. | WISHLIST / NEEDS DESIGN |
| V4-FEAT-056 | Scheduled ML Retraining, Model Deployment & Operations Messaging | Add cron/scheduler-driven retraining for the 1m/3m/6m models, retain manual retrain/promotion controls, automatically evaluate scheduled candidates and deploy a newer model only when the normal promotion gates pass. Send informational messages for upcoming scheduled training, successful training and successful model deployment; generate Admin alerts only for failures/errors that require attention. | WISHLIST / NEEDS DESIGN |
| V4-FEAT-057 | ML Fundamental Feature Expansion & Retraining | Follow on from V4-FEAT-054. After historical fundamental coverage is expanded, define a curated/versioned fundamental ML feature set, validate point-in-time safety and coverage, retrain the 1m/3m/6m models, and compare the resulting candidates with existing models and the deterministic StoX baseline before any Admin promotion. | WISHLIST / DEPENDS ON V4-FEAT-054 |
| V4-FEAT-058 | ML Technical & Market Feature Engineering | Expand the point-in-time ML feature space using data StoX already owns: multi-horizon momentum/trend, volatility/risk, volume/liquidity, breakout/consolidation, deterministic candlestick/chart-pattern signals, market breadth/regime and sector-relative context. Version feature definitions, measure incremental predictive value by horizon, and retain only features that improve out-of-sample results. | WISHLIST / NEEDS DESIGN |
| V4-FEAT-059 | ML Promotion-Threshold Calibration & Multi-Window Validation | Replace reliance on a single fixed holdout and largely heuristic global promotion cutoffs with repeated chronological evaluation, horizon-aware baseline calibration, stability analysis and evidence-backed promotion criteria. Keep promotion conservative and reproducible; do not lower thresholds merely to make a model pass. | WISHLIST / NEEDS DESIGN |
| V4-FEAT-060 | ML Admin UI & Training Observability Refinement | Bring the Admin ML UI up to the capabilities already present in the backend and expose richer training lifecycle visibility: eligibility/rejection reasons, per-gate metrics, retained versions, rollback, cutoff/configuration controls, granular run state/progress, failures, model metadata, drift and lifecycle history. | WISHLIST / NEEDS DESIGN |
| V4-FEAT-061 | Guided Tour / Welcome Onboarding | Add a lightweight in-product guided tour for first-time/new users using real StoX UI elements, a configurable step engine, spotlight/dim overlays, tooltip navigation and a welcome entry modal. Persist completion/dismiss state per user, support responsive positioning and hidden-menu coordination, and keep the implementation small and in-house rather than adding a third-party tour framework. | WISHLIST / NEEDS DESIGN |

## 3. V4-FEAT-055 — Account Access Request / Admin Approval Workflow

### 3.1 Product intent

StoX remains **admin-controlled / invite-only** for actual account creation. This feature does **not** introduce open self-registration.

It adds a public way for a prospective user to ask an administrator for access:

```text
Login
  -> Request an account
      -> CAPTCHA + request form
          -> pending request
              -> Admin Create -> existing secure create/invite flow
              -> Admin Ignore -> closed; applicant may request again later
              -> Admin Reject -> closed + email banned
                                  -> Admin may later clear ban
```

The applicant never chooses their own role, receives admin privileges, or creates an account merely by submitting the request.

### 3.2 Guest request form

The Login page SHALL expose a clear **Request an account** link to a dedicated unauthenticated form.

The form SHALL collect the fields required by the current admin onboarding contract. Initial V8 design should at minimum support:

- full/display name;
- normalized email address;
- any additional non-secret fields that the eventual Admin **Create user** flow requires.

The request model and Admin create form SHOULD share a common field contract so they cannot silently drift apart as onboarding fields evolve.

The form MUST NOT collect:

- password;
- TOTP/recovery secrets;
- broker credentials;
- API tokens;
- requested Admin role or execution entitlement.

Password establishment remains inside the existing secure invitation/onboarding flow.

### 3.3 Human / abuse verification

Submission SHALL require server-verified CAPTCHA or an equivalent human-verification mechanism.

Design requirements:

- CAPTCHA validation occurs server-side;
- CAPTCHA response tokens are transient and are not persisted as applicant data;
- provider keys remain server-side;
- failure degrades explicitly rather than silently accepting unverified submissions;
- public endpoint remains rate-limited in addition to CAPTCHA;
- rate limits SHOULD consider normalized email and source/IP dimensions;
- repeated failed CAPTCHA/submission attempts must not create request rows.

The CAPTCHA implementation SHOULD be provider-abstracted so StoX is not permanently coupled to one vendor.

### 3.4 Identity normalization and duplicate handling

Email is the primary request identity and SHALL be normalized consistently with existing users/invites.

Before creating a new request, the system SHALL distinguish:

1. **existing StoX user** — do not create an access request; direct the person to normal login/password-recovery guidance;
2. **active/pending invitation already exists** — do not create a duplicate request; tell the applicant that onboarding is already in progress and to use/contact the administrator for the invitation;
3. **pending access request already exists** — do not create another row; return a clear, non-destructive "request already pending" message;
4. **email is on rejected/banned list** — do not create a request; return the product-defined blocked-request message;
5. **previous request was ignored/closed without ban** — a future request may be created normally;
6. **no conflict** — create a new pending request.

Duplicate protection SHOULD be enforced transactionally/database-backed where practical, not only by a frontend check.

### 3.5 Request lifecycle

Minimum lifecycle:

```text
pending
  -> created
  -> ignored
  -> rejected
```

Recommended semantic meaning:

- **pending** — awaiting Admin action;
- **created** — Admin chose Create and the request has been handed into the secure account onboarding flow;
- **ignored** — request closed without a ban; a new request from the same email is allowed later;
- **rejected** — request closed and the normalized email is placed on the rejected/banned list.

The design SHALL define whether `created` means "invite/onboarding issued" or "user account ultimately accepted." Preferred V8 semantics are:

- access request becomes `created` when Admin successfully initiates the existing invitation/account-create workflow;
- link the request to the resulting invite and, once accepted, to the resulting user where feasible;
- do not reopen the request merely because an invitation later expires.

All transitions must be auditable and idempotent.

### 3.6 Admin experience

User Management/Admin SHALL expose:

- pending request count/badge;
- request list;
- request detail;
- applicant name/email and submitted metadata;
- submitted timestamp;
- current status;
- prior request history for the same normalized email;
- ban/rejection state where applicable;
- Admin actor and decision timestamp/reason.

Admin actions:

#### Create

- opens the existing Admin user/invite creation flow;
- pre-populates all compatible request fields;
- allows Admin to review/edit allowed onboarding fields before committing;
- actual user creation/onboarding still uses the existing security model;
- success links the request to the generated invite/user workflow;
- concurrent double-click/two-admin resolution must not create duplicate users or invites.

#### Ignore

- closes the request;
- optionally records an Admin note/reason;
- does **not** ban the email;
- applicant may submit a future request;
- any future anti-spam cooldown, if introduced, must be explicit and configurable rather than silently treating Ignore as Reject.

#### Reject

- closes the request;
- adds the normalized email to a rejected/banned identity list;
- optionally records a reason/internal note;
- future requests for that identity are blocked until an Admin clears the ban.

### 3.7 Rejected / banned identities

The Admin UI SHALL provide a dedicated rejected/banned list with:

- normalized email;
- originating request;
- rejected by;
- rejected at;
- optional internal reason;
- **Clear ban / Allow future requests** action.

Clearing a ban SHALL:

- be explicit and confirmed;
- be audited;
- permit a future request;
- not resurrect the original rejected request automatically.

A ban applies to the **request workflow**, not to an already-existing StoX user account unless a separate account-administration action explicitly says otherwise.

### 3.8 Notifications

Creation of a new pending request SHALL notify administrators using the existing StoX notification architecture.

Initial channels:

- in-app/admin notification;
- Telegram;
- email where configured;
- other enabled notification channels supported by the notification subsystem.

Notification requirements:

- target the appropriate Admin recipients, not the applicant;
- include enough non-secret context to identify the request;
- link/deep-link to the Admin request detail where supported;
- deduplicate retries/redelivery so one request does not create notification storms;
- notification delivery failure must **not** delete or roll back a valid access request;
- channel delivery status remains notification state, not request-domain state.

### 3.9 Security and privacy

The public workflow SHALL preserve existing authentication/security boundaries.

At minimum:

- no open `POST /register` equivalent;
- no applicant-controlled role or entitlement;
- Admin-only list/detail/resolve/ban APIs;
- server-side authorization on every Admin action;
- normalized and validated input;
- no password or credential collection;
- CAPTCHA + rate limiting;
- CSRF/session rules appropriate to public vs Admin endpoints;
- request data retained only as long as product policy requires;
- sensitive internal rejection notes are never returned to the applicant;
- public responses should reveal only the minimum useful state needed for the submitting applicant.

### 3.10 Auditability and concurrency

Persist an immutable/auditable decision trail covering:

- request submitted;
- duplicate prevented where useful for operational metrics without storing unnecessary PII;
- Admin Create / Ignore / Reject;
- ban created;
- ban cleared;
- linked invite/user identifier when Create succeeds.

Two Admins acting concurrently on the same pending request SHALL result in one authoritative transition. Losing requests receive a clear "already resolved" response rather than producing duplicate side effects.

### 3.11 Operational/admin filters

Admin request list SHOULD support at least:

- status;
- submitted date range;
- email/name search;
- pending-only default view;
- rejected/banned view.

Useful counters:

- pending;
- created;
- ignored;
- rejected;
- banned identities.

### 3.12 Initial acceptance criteria

1. Guest can reach **Request an account** from Login without authenticating.
2. CAPTCHA failure creates no request.
3. Valid first submission creates exactly one pending request.
4. Repeating a pending request for the same normalized email creates no second pending request and returns a clear duplicate message.
5. Existing-user email creates no request and is directed to existing-account recovery/login guidance.
6. Existing pending invite creates no duplicate request.
7. Admin receives a visible pending request and notification.
8. Non-admin cannot list, inspect, resolve, reject, or unban requests.
9. **Ignore** closes the request without banning; a later request for that email is allowed.
10. **Reject** closes the request and blocks subsequent requests for that email.
11. Admin can clear the rejection ban; a later request is then allowed.
12. **Create** opens the existing secure user/invite onboarding flow with compatible fields prefilled.
13. Successful Create cannot create a duplicate account/invite even under concurrent Admin actions.
14. Applicant-supplied request data never sets Admin role, automated-execution entitlement, password, broker credentials, or other privileged state.
15. Notification-provider failure does not lose or revert the pending request.
16. Admin decision and ban/unban actions are auditable.

### 3.13 Open design decisions

The detailed V8 design should explicitly decide:

- CAPTCHA provider and fallback behavior;
- exact applicant fields beyond name/email;
- whether Ignore has a minimum resubmission cooldown;
- retention/anonymization period for old ignored/rejected requests;
- wording shown for banned identities;
- whether rejection reason is entirely internal or supports a separate applicant-facing generic reason;
- whether email confirmation/OTP is required before a request becomes pending;
- whether Admin Create hands off to the current invite flow only or a future direct-create workflow;
- whether a successful Create notification should also be sent to the applicant, separate from the invitation itself.

## 4. V4-FEAT-056 — Scheduled ML Retraining, Model Deployment & Operations Messaging

### 4.1 Product intent

V8 should operationalize the V7 ML lifecycle so routine model maintenance no longer depends on an Admin remembering to retrain each horizon manually.

The normal scheduled flow should be:

```text
configured schedule / cron
    -> informational message: training upcoming
        -> retrain horizon
            -> evaluate statistical + investment metrics
            -> compare with deterministic StoX baseline
            -> apply normal promotion gates
                -> gates pass: deploy/promote newer model
                    -> informational message: training + deployment successful
                -> gates do not pass: retain current active model
                    -> informational result; no failure alert
                -> technical/runtime failure
                    -> Admin alert
```

Manual retraining, candidate review, promotion, rollback and drift checks SHALL remain available.

### 4.2 Scheduling

StoX SHALL support scheduler/cron-driven retraining for:

- 1m;
- 3m;
- 6m.

Each horizon SHOULD have an independently configurable schedule because the useful retraining cadence may differ by horizon.

The detailed V8 design should define sensible defaults, for example more frequent retraining for 1m and less frequent retraining for 3m/6m, while allowing Admin configuration.

Scheduling requirements:

- use the existing StoX/Laravel scheduler/cron infrastructure;
- preserve the per-horizon retraining lock;
- do not overlap two retrains for the same horizon;
- skip or defer safely when a same-horizon retrain is already running;
- retain an auditable record of scheduled versus manually initiated runs;
- manual retraining must continue to work regardless of the schedule.

### 4.3 Scheduled candidate evaluation and deployment

A scheduled retrain SHALL use the same canonical training/evaluation path as a manual retrain.

After successful training:

1. create the normal candidate/rejected model version;
2. evaluate the existing promotion thresholds;
3. compare against the deterministic StoX Strategy/Evaluation baseline;
4. verify artifact integrity;
5. deploy/promote the newer model only when the configured promotion gates pass;
6. retain the existing active model when the candidate does not pass;
7. never treat "candidate did not outperform/pass gates" as an operational error.

Automatic deployment applies only to candidates produced by this scheduled lifecycle and only after all normal safety/evaluation gates pass.

Manual promotion and rollback SHALL remain available to the Admin.

### 4.4 Model-health signals

Model age, drift and matured live performance remain useful operational inputs, but they no longer exist primarily to ask the Admin to remember to retrain.

They SHOULD be used to:

- enrich scheduler/model-health status;
- allow optional early retraining outside the normal cadence when configured;
- explain why an unscheduled/early run was initiated;
- support Admin diagnostics.

The design SHOULD consider:

- model age;
- score/distribution drift;
- matured prediction hit rate;
- benchmark-relative realised return;
- drawdown/downside deterioration;
- comparison with the active model's original validation/test metrics;
- sustained degradation across 3/6/12-month windows.

Any drift-triggered early retraining policy must be explicit, configurable and use the same lock/evaluation/deployment gates as cron-triggered training.

### 4.5 Information messages vs Admin alerts

Routine successful ML operations SHALL be communicated as **informational messages**, not alerts.

Informational messages SHOULD cover at least:

- upcoming scheduled training, with horizon and planned time;
- training started where useful;
- successful training completion;
- result of candidate evaluation;
- successful deployment/promotion of a newer model;
- scheduled run completed but current active model retained because the candidate did not pass promotion gates;
- manual versus scheduled origin where relevant.

An **Admin alert** SHALL be generated only for an error/failure condition requiring attention, such as:

- dataset build failure;
- training process failure;
- artifact integrity failure;
- deployment/promotion transaction failure;
- lock/runtime infrastructure failure;
- repeated scheduler failure;
- other conditions that prevent the expected ML lifecycle from completing safely.

A model that trains successfully but does not meet promotion thresholds is **not** an error and must not generate an error alert.

Information and alert delivery should use the existing StoX notification architecture with normal deduplication/cooldown behavior.

### 4.6 Admin experience

Admin ML/model-management surfaces SHOULD expose:

- next scheduled retraining time by horizon;
- last scheduled and last manual training;
- current active model/version;
- latest training result;
- latest deployed model/version;
- schedule enabled/disabled state;
- model-health/drift context;
- recent informational events;
- unresolved ML failures/alerts.

Admin controls SHALL retain:

- manual retrain;
- manual promotion where applicable;
- rollback to retained model;
- drift check;
- schedule enable/disable;
- schedule/cadence configuration subject to product policy.

### 4.7 Auditability and safety

Persist enough evidence to reconstruct each lifecycle event:

- trigger type: scheduled / manual / optional health-triggered;
- schedule identity/version;
- requested/start/completion timestamps;
- training cutoff;
- dataset and feature-definition versions;
- metrics and deterministic baseline comparison;
- promotion-gate result;
- previous active model;
- resulting active model;
- deployment timestamp;
- failure details where applicable;
- Admin actor for manual actions.

Scheduled execution must not bypass any V7 integrity, point-in-time, artifact-verification, promotion-threshold or rollback safeguards.

### 4.8 Initial acceptance criteria

1. 1m/3m/6m models support independently configurable scheduled retraining.
2. Scheduled runs use the same canonical training path as manual runs.
3. Same-horizon scheduled/manual runs cannot execute concurrently.
4. Manual retraining remains available.
5. A successful scheduled candidate is evaluated against all normal promotion gates and the deterministic baseline.
6. A newer model is automatically deployed only when all required gates pass.
7. A candidate that does not pass remains non-active and the existing active model is retained.
8. Manual promotion/rollback controls remain available.
9. Admin receives an informational message before an upcoming scheduled training.
10. Successful training generates an informational completion message.
11. Successful deployment of a newer model generates an informational deployment message.
12. A successful run whose candidate is not deployed is informational, not an error alert.
13. Only operational/error conditions generate an Admin alert.
14. Scheduler retries/deduplication do not create duplicate runs or notification storms.
15. Every scheduled/manual training and deployment transition is auditable.
16. Model-health/drift evidence remains visible and may support explicitly configured early retraining without bypassing normal gates.

### 4.9 Open design decisions

The detailed V8 design should decide:

- default cron cadence for 1m/3m/6m;
- whether schedules are fixed product defaults or Admin-configurable;
- how far in advance the "upcoming training" information message is sent;
- whether model-health deterioration can trigger an early run or only influence the next scheduled run;
- retry/backoff policy for failed scheduled runs;
- maximum consecutive failures before escalation;
- information-channel preferences versus error-alert channels;
- whether successful training and successful deployment are separate messages or can be consolidated;
- whether an automatically deployed model has a short post-deployment observation/watch state before being considered fully settled.

## 5. V4-FEAT-057 — ML Fundamental Feature Expansion & Retraining

### 5.1 Product intent

This epic follows **V4-FEAT-054 — Historical Fundamental Data Bootstrap**.

Once StoX has materially richer historical fundamental coverage, expand the ML inputs beyond the current small fundamental subset and retrain the horizon models using a curated, versioned feature definition.

The goal is not to feed every raw fundamental field into ML. The feature set should contain useful, sufficiently covered, point-in-time-safe derived inputs.

### 5.2 Scope

The design SHOULD evaluate candidate features such as:

- EPS / earnings growth;
- revenue growth and acceleration;
- ROE / ROIC;
- operating and net margins;
- free-cash-flow measures;
- debt/equity and debt trends;
- balance-sheet quality indicators;
- valuation measures such as P/E and P/B where point-in-time reconstruction is reliable;
- other fundamental ratios supported by the canonical fact store.

Final inclusion must be evidence-driven rather than assuming every available field improves the model.

### 5.3 Point-in-time and data-quality requirements

Before training:

- validate historical coverage by feature, year and stock universe;
- preserve period end, availability/publication date, revision and provenance semantics;
- prevent future revisions or later-published fundamentals from leaking into earlier observations;
- distinguish missing from zero;
- quantify missingness and sparsity;
- reject or exclude features whose historical reconstruction is not trustworthy.

### 5.4 Feature-set versioning

A materially changed fundamental feature set SHALL have its own version/definition.

Persist enough metadata with training runs and model versions to reproduce:

- exact included features;
- transformations/derived formulas;
- missing-value policy;
- categorical handling;
- source/provenance expectations;
- feature-definition version/hash.

Existing historical production predictions remain associated with the model/version that originally produced them; do not rewrite them retroactively.

### 5.5 Retraining and comparison

After the expanded feature set is validated:

1. rebuild the point-in-time ML datasets;
2. retrain 1m, 3m and 6m candidates;
3. evaluate statistical and investment metrics;
4. compare each candidate with the currently active model for that horizon;
5. compare against the deterministic StoX Strategy/Evaluation baseline over comparable test periods;
6. expose the candidate and evidence to Admin;
7. require explicit Admin promotion.

Richer fundamentals must not imply automatic promotion. If an expanded-feature candidate performs worse, the existing active model may remain in service.

### 5.6 Initial acceptance criteria

1. V4-FEAT-054 historical fundamentals bootstrap is complete enough for ML use.
2. Feature coverage and point-in-time integrity are measured before training.
3. The expanded fundamental feature set is curated and versioned.
4. No feature uses information unavailable as of the observation reference date.
5. Missing values remain distinguishable from legitimate zero values.
6. 1m/3m/6m datasets can be rebuilt with the new feature definition.
7. All three horizons can be retrained successfully.
8. New candidates are compared with current active models and the deterministic StoX baseline.
9. Existing historical production predictions are not rewritten.
10. Promotion remains explicit and Admin-controlled.
11. Training/model metadata records the exact fundamental feature-set version and provenance assumptions.

### 5.7 Open design decisions

The detailed V8 design should decide:

- the final fundamental features to include;
- whether some features are horizon-specific;
- minimum acceptable historical coverage for inclusion;
- handling of highly correlated/redundant features;
- winsorization/outlier policy;
- whether valuation features can be reconstructed reliably enough for PIT use;
- whether feature selection is fixed by product design or assisted by offline analysis.


## 6. V4-FEAT-058 — ML Technical & Market Feature Engineering

### 6.1 Product intent

Expand the V7 ML models beyond the current narrow technical input set by deriving richer, point-in-time-safe features from historical market data StoX already stores.

This is **feature engineering**, not synthetic outcome generation. Historical prices, volume and market context remain the source of truth; derived features describe the conditions that existed at each observation date so the model can learn relationships between those conditions and later outcomes.

The goal is to answer questions such as:

> Given the technical, market and sector conditions observable today, what is the probability that this stock will achieve the configured risk-aware benchmark-relative outcome over the selected horizon?

### 6.2 Candidate feature families

The detailed design SHOULD evaluate, at minimum:

- multi-horizon returns: 1w / 1m / 3m / 6m / 12m where history permits;
- relative strength against NIFTY50 and, where available, sector/index context;
- moving-average location, spread and slope features;
- trend alignment such as price > SMA20/50/100/200 and SMA stack relationships;
- distance from 52-week high/low and recent breakout levels;
- momentum and momentum-acceleration features;
- RSI / rate-of-change style momentum indicators;
- volatility over multiple lookbacks;
- ATR / normalized ATR;
- recent maximum drawdown and downside-volatility features;
- volatility contraction/expansion;
- historical volume relative to rolling averages;
- volume trend / breakout-volume ratios;
- accumulation/distribution style measures where the data contract supports them;
- market breadth such as percentage of eligible stocks above major moving averages;
- advancing/declining breadth and new-high/new-low style context where reconstructable;
- market regime features derived from benchmark trend, breadth and volatility;
- sector-relative strength and within-sector ranking once sector history is sufficiently reliable;
- deterministic candlestick signals;
- deterministic chart/pattern signals such as breakouts, consolidations and other mathematically defined structures.

### 6.3 Multi-timescale representation

Features MAY be derived at multiple observation scales where justified, for example:

- daily;
- weekly;
- rolling N-trading-day windows.

A feature name/definition must make its timeframe explicit. Arbitrary resampling such as 10-trading-day or biweekly bars may be evaluated, but inclusion must be evidence-driven rather than assumed useful.

The same underlying signal MAY have multiple horizon-specific representations if empirical validation shows that, for example, short-horizon patterns are useful for 1m while broader trend/volatility context is more useful for 6m.

### 6.4 Pattern feature requirements

Pattern detection used for training MUST be deterministic and reproducible.

For each pattern:

- define the exact mathematical rule;
- define the input bars/timeframe;
- version the rule;
- avoid subjective/manual chart interpretation;
- ensure the same historical input always produces the same feature value;
- persist enough metadata to reproduce the pattern state later.

The model must not assume that a textbook-named pattern is predictive merely because it is traditionally labelled bullish/bearish. Its value is determined by out-of-sample evidence.

### 6.5 Point-in-time safety

Every derived feature must be computable using only information available at the observation timestamp.

At minimum:

- no future bars;
- no later corporate-action repairs leaking into feature values;
- no future constituent/sector information unless historically reconstructed;
- rolling statistics terminate at the reference date;
- market-breadth calculations use only the eligible historical universe/data known for that date;
- feature preprocessing is fitted only on training partitions where fitting is required.

### 6.6 Feature research and selection

Do not add all candidate features directly to the production model.

The research flow SHOULD:

1. generate a versioned candidate feature matrix;
2. measure coverage and missingness;
3. quantify redundancy/correlation;
4. train/evaluate by 1m/3m/6m horizon;
5. measure incremental out-of-sample value relative to the current feature set;
6. test stability across chronological windows;
7. retain only features or feature families that improve predictive/investment outcomes without introducing leakage or unacceptable complexity.

Feature importance/explainability should remain inspectable.

### 6.7 Initial acceptance criteria

1. Technical/market feature definitions are deterministic, versioned and reproducible.
2. All features are point-in-time safe.
3. Candidate features cover trend, momentum, volatility/risk and volume/liquidity where source data permits.
4. Market breadth/regime features are available where historical reconstruction is reliable.
5. Pattern features use explicit mathematical definitions rather than subjective chart interpretation.
6. Multi-timescale features can be evaluated independently by horizon.
7. Feature coverage/missingness and redundancy are measured.
8. 1m/3m/6m candidates can be retrained using selected expanded features.
9. Expanded-feature models are compared against the current model and deterministic StoX baseline.
10. Features that do not improve out-of-sample evidence can be excluded without weakening reproducibility.
11. Production prediction explanations can identify the most material retained technical/market contributors.

### 6.8 Open design decisions

The detailed design should decide:

- exact technical indicator library/formulas;
- which candlestick/chart patterns are worth implementing;
- whether complex patterns use rule-based detectors or separately versioned feature extractors;
- supported resampling/timeframes;
- minimum historical coverage per feature;
- volume-data quality requirements;
- market-breadth universe definition;
- sector-relative feature availability/history requirements;
- feature-count/complexity guardrails;
- whether feature selection remains logistic-regression compatible or also targets tree/boosting candidates.

## 7. V4-FEAT-059 — ML Promotion-Threshold Calibration & Multi-Window Validation

### 7.1 Product intent

Improve confidence in candidate promotion decisions by replacing dependence on one chronological holdout and fixed global thresholds with empirically calibrated, horizon-aware evidence.

Current V7 defaults remain valid safety gates, but values such as ROC-AUC >= 0.52 and PR-AUC >= 0.50 are not treated as universal constants.

The V8 design should determine promotion criteria from repeated historical evidence while remaining conservative.

### 7.2 Multi-window chronological validation

Evaluation SHOULD support repeated chronological windows for each horizon.

For example:

```text
train -> validation -> test
        move forward
train --------> validation -> test
        move forward
train ----------------> validation -> test
```

Requirements:

- preserve chronological order;
- preserve horizon label separation/embargo;
- prevent preprocessing leakage;
- use comparable benchmark semantics;
- retain per-window metrics rather than only an aggregate;
- record exact window definitions for reproducibility.

### 7.3 Threshold calibration

Promotion criteria SHOULD be calibrated against:

- random/no-skill discrimination behavior;
- observed class prevalence;
- naive statistical baselines;
- deterministic StoX baseline performance;
- current active model performance over comparable periods;
- distribution of results across multiple chronological windows.

In particular:

- ROC-AUC thresholds should require evidence above no-skill behavior;
- PR-AUC interpretation should account for positive-class prevalence rather than assuming 0.50 is universally meaningful;
- investment-outcome gates should require acceptable benchmark-relative and deterministic-baseline performance;
- stability across windows should matter, not only one favorable test period.

### 7.4 Horizon-aware criteria

1m, 3m and 6m may use different calibrated thresholds or stability requirements if empirical evidence supports that difference.

The detailed design SHOULD evaluate:

- horizon-specific minimum ROC-AUC;
- horizon-specific PR-AUC improvement over prevalence/no-skill baseline;
- minimum benchmark-relative outcome;
- minimum improvement over deterministic StoX baseline;
- maximum tolerated degradation across windows;
- minimum number of valid evaluation windows;
- confidence intervals/bootstrap uncertainty where practical.

### 7.5 Promotion semantics

Passing calibrated thresholds makes a model **eligible**, not automatically authoritative unless a separate scheduled-deployment policy explicitly applies.

Do not:

- lower a threshold simply because a preferred model narrowly failed;
- tune thresholds against the final test window;
- select criteria after observing the exact candidate intended for promotion;
- hide poor windows behind a favorable average.

Threshold definitions/version/hash must be persisted with every training run/model.

### 7.6 Initial acceptance criteria

1. Evaluation supports multiple leakage-safe chronological windows.
2. Per-window and aggregate metrics are persisted.
3. ROC-AUC promotion criteria are benchmarked against no-skill behavior.
4. PR-AUC criteria account for positive-class prevalence or an equivalent no-skill baseline.
5. Promotion considers deterministic StoX baseline and benchmark-relative outcomes.
6. 1m/3m/6m may use different thresholds only when supported by documented evidence.
7. Stability/dispersion across windows is visible to Admin.
8. Threshold calibration is performed independently from the final candidate test result.
9. Threshold/configuration versions are reproducible.
10. A model cannot pass solely because one unusually favorable window dominates the aggregate.
11. Existing conservative defaults remain usable until calibrated replacements are approved.

### 7.7 Open design decisions

The detailed design should decide:

- rolling-window count and spacing by horizon;
- expanding vs fixed training windows;
- minimum observations/classes per window;
- whether confidence intervals/bootstrap estimates are required;
- exact stability rules;
- how current-active-model comparison affects eligibility;
- whether threshold calibration is offline/admin-approved or product-managed;
- how recalibration itself is versioned and audited.

## 8. V4-FEAT-060 — ML Admin UI & Training Observability Refinement

### 8.1 Product intent

Complete the Admin-facing ML management experience so normal lifecycle operations do not require SSH/Tinker and so the UI clearly explains what the backend has decided.

The backend already supports retrain, candidate/rejected classification, explicit promotion, rollback, drift checks, cutoff/configuration input and persisted model/training metadata. V8 should expose these capabilities coherently and add granular training observability.

### 8.2 Candidate eligibility and rejection presentation

After training, the UI SHALL clearly distinguish:

- training failed;
- training completed but model rejected;
- training completed and model is an eligible candidate;
- candidate promoted/active;
- retained historical version.

For completed models, show promotion gates individually, for example:

```text
ROC-AUC                     0.519   required >= 0.520   FAIL
PR-AUC                      0.473   required >= 0.500   FAIL
Benchmark-relative return  +1.45%  required >= 0       PASS
Deterministic baseline     +1.45%  required >= 0       PASS
```

A rejected model must not display a Promote action.

### 8.3 Training lifecycle observability

Move the Admin experience away from a single long-running opaque request.

Preferred architecture:

```text
Admin starts retrain
    -> server creates/queues training run
    -> API returns training_run_id
    -> background worker executes canonical training
    -> UI polls/subscribes to run status
    -> granular stage/progress updates are shown
```

The UI SHOULD expose stages such as:

- queued;
- preparing dataset;
- resolving benchmark/history;
- writing train/validation/test partitions;
- deterministic baseline evaluation;
- model fitting;
- candidate evaluation;
- artifact verification/persistence;
- completed candidate;
- completed rejected;
- failed.

Where precise percentage completion is not trustworthy, use stage/progress counters rather than fabricated percentages.

### 8.4 Run detail

A training-run detail view SHOULD expose:

- horizon;
- trigger type/manual/scheduled;
- requested/start/completion timestamps;
- elapsed duration;
- cutoff date;
- current stage/status;
- dataset row counts;
- train/validation/test date ranges;
- purge/embargo diagnostics;
- configured/effective/excluded features;
- feature coverage;
- model family/hyperparameters;
- metrics and baselines;
- promotion thresholds and pass/fail result;
- artifact/model version produced;
- failure type/message when applicable;
- operator/requesting Admin.

### 8.5 Model/version management

Admin UI SHALL expose:

- active model per horizon;
- latest candidate;
- rejected versions;
- retained versions;
- promotion history;
- rollback action for eligible retained same-horizon versions;
- exactly-one-active status;
- model age;
- selected feature set;
- model metadata/version;
- evaluation summary;
- drift/live-health status.

Rollback should use the existing backend contract and require a deliberate confirmation.

### 8.6 Training controls

Expose backend-supported controls where product policy permits:

- horizon;
- training cutoff date;
- configurable training options/overrides;
- promotion thresholds where Admin-editable;
- manual retrain;
- explicit promotion;
- rollback;
- drift-check window.

Configuration values must be validated server-side; frontend controls are not the security boundary.

### 8.7 Failure and operations UX

Training failures SHOULD be visible without shell access.

Provide:

- clear failed state;
- concise failure reason;
- timestamps/stage of failure;
- retry action where safe;
- correlation/run identifier;
- link to relevant operational details/log surfaces where available;
- distinction between operational failure and a successfully trained-but-rejected model.

### 8.8 Integration with scheduled operations

When V4-FEAT-056 is implemented, the same UI SHOULD also surface:

- next scheduled training by horizon;
- schedule enabled/disabled;
- last scheduled vs manual run;
- scheduled candidate/deployment result;
- informational lifecycle messages;
- unresolved ML operational alerts.

Manual and scheduled training should share the same run-detail/status model.

### 8.9 Initial acceptance criteria

1. Admin can see whether a completed model is candidate or rejected.
2. Every promotion gate is shown with observed value, threshold and pass/fail state.
3. Rejected models cannot be promoted from the UI.
4. Admin can start a retrain without keeping one opaque long-running HTTP request open for the full training duration.
5. Training status can be refreshed/polled and exposes meaningful lifecycle stages.
6. Failed runs expose a useful failure reason/stage.
7. Admin can inspect dataset partitions, selected/excluded features, metrics and baselines.
8. Admin can promote an eligible candidate.
9. Admin can view retained model versions and roll back to a valid prior same-horizon version.
10. Admin can set/inspect supported cutoff/configuration values.
11. Admin can run/review drift checks.
12. Active/candidate/rejected/retained states are clearly differentiated.
13. Exactly-one-active-per-horizon remains enforced by the backend.
14. Scheduled and manual runs can share the same observability UI once V4-FEAT-056 is implemented.

### 8.10 Open design decisions

The detailed V8 design should decide:

- queue/job implementation for retraining;
- polling interval vs SSE/WebSocket updates;
- stage model and whether any stages expose quantitative progress;
- retention period for run logs/details;
- amount of raw diagnostics exposed in UI;
- threshold-editing permissions/guardrails;
- whether advanced configuration lives inline or behind an Advanced panel;
- confirmation UX for promotion/rollback;
- relationship between ML run history and the general notification/telemetry surfaces.

## 9. V4-FEAT-061 — Guided Tour / Welcome Onboarding

### 9.1 Product intent

Add a lightweight first-run onboarding experience that explains StoX by highlighting the **real UI already on screen** rather than rendering a duplicate walkthrough UI.

The feature should have two related pieces:

- an optional welcome modal that offers **Begin tour** / **Skip**;
- an in-context guided tour that spotlights existing navigation, header/actions and other important product areas.

This is a guided-tour feature, not a replacement for setup wizards or long-form help content.

### 9.2 Architecture direction

Prefer a small in-house implementation over a third-party tour library.

Recommended structure:

- ordered, configuration-driven tour steps;
- manager/state machine holding current/previous step;
- presentation layer for dimming overlay, tooltip, Back/Next/Finish and close;
- stable DOM hooks on existing StoX UI targets;
- shell-level coordination for opening/closing hidden menus, drawers or profile panels required by a step;
- scoped persistence for welcome/tour state.

The tour SHALL target real UI elements using stable IDs/data attributes or equivalent selectors. Step order, copy, target selector, tooltip placement and shell coordination keys SHOULD be centralized in configuration.

### 9.3 Runtime behavior

Minimum behavior:

1. Opening the tour starts at the first configured step.
2. **Next** and **Back** move through ordered steps.
3. The active target is visually raised above a dimmed background; optional inner controls may receive a focus outline.
4. Non-active shell regions may be muted and/or pointer-blocked while the tour is active.
5. A step may request the shell to reveal hidden UI such as a collapsed navigation area or profile menu before highlighting it.
6. Missing target nodes must fail softly rather than crashing the application.
7. **Finish** on the last step marks the tour completed.
8. Closing mid-tour clears temporary styling but does **not** mark the tour completed.

A short delayed style application after step changes is acceptable to allow React-rendered menus/panels to appear before targeting.

### 9.4 Welcome and persistence

Persist onboarding state per StoX user, and per tenant/account as applicable.

At minimum retain:

- welcome modal show count;
- "don't show again" / permanent welcome dismissal;
- guided-tour completion.

The welcome modal SHOULD stop appearing after a small configurable number of displays, permanent dismissal, or tour completion.

Tour completion SHALL be recorded only when the user explicitly finishes the final step, not when the welcome modal is skipped or the tour is closed early.

### 9.5 Responsive and accessibility requirements

The implementation SHOULD:

- support narrow and wide StoX layouts;
- allow alternate tooltip placement at responsive breakpoints;
- avoid hard failure when a target is temporarily off-screen or not rendered;
- scroll targets into view where needed;
- provide keyboard-accessible controls and appropriate focus behavior;
- define Escape/close behavior explicitly;
- restore all temporary z-index, border, blur and pointer-event changes on close/finish.

Where practical, tooltip positioning SHOULD be derived from target geometry rather than relying only on fixed coordinates.

### 9.6 Internationalization and telemetry

Tour/welcome copy SHOULD use the normal StoX i18n mechanism.

At minimum capture:

- tour started;
- tour completed;
- welcome shown;
- welcome skipped/dismissed where useful.

Telemetry must not be required for the tour to function.

### 9.7 Initial acceptance criteria

1. Eligible first-time/new users can be offered a welcome modal with **Begin tour** and **Skip**.
2. The tour highlights existing StoX UI elements rather than duplicate mock controls.
3. Steps are configuration-driven and can be added/reordered without rewriting the tour engine.
4. Back, Next, Finish and close work correctly.
5. Hidden/collapsed shell UI can be opened for a step and cleaned up on transition/close.
6. Missing or slow-rendering target elements do not crash StoX.
7. Completion is persisted only after finishing the final step.
8. Skip/close does not incorrectly mark the tour complete.
9. Temporary styles and interaction blocking are fully cleared after close/finish.
10. The tour works across supported responsive layouts.
11. Tour strings are localizable.
12. Basic start/completion telemetry is emitted without becoming a functional dependency.

### 9.8 Open design decisions

The detailed V8 design should decide:

- which StoX areas belong in the initial tour and the final step order;
- exact eligibility/readiness gate for showing onboarding;
- storage key scope and welcome display cap;
- whether the tour can be relaunched manually from Help/Profile;
- tooltip positioning strategy;
- whether shell coordination uses React state/context/events rather than window messaging;
- accessibility details including focus trap and Escape behavior;
- whether tours may span routes, or remain shell/page-local.

## 10. Boundary

V8 owns the Telemetry product itself: ingestion, storage, analytics/query APIs, dashboards/explorer, SDKs, identity/correlation model, events/metrics/logs/traces, retention, export, deletion, administration and the other capabilities frozen in the Telemetry architecture specification.

V8 also owns the one-time historical fundamental bootstrap outcome, but does not change FEAT-053's ongoing provider-driven fundamental ingestion architecture.

V8 owns the account-access request workflow through Admin disposition and handoff into the existing secure user onboarding flow. It does **not** replace the existing invitation/token security model, establish open registration, or allow a guest request to grant any account privilege by itself.

V8 also owns scheduled ML retraining and gated deployment of newer model versions, while retaining manual retrain/promotion/rollback controls. Routine upcoming/successful training and deployment events are informational; Admin alerts are reserved for operational failures/errors requiring attention.

V8 also owns the ML follow-on to the historical fundamentals bootstrap: curated fundamental feature expansion, PIT/coverage validation, horizon retraining and candidate evaluation. This does not alter the rule that model promotion remains explicit and Admin-controlled.

V8 also owns technical/market ML feature engineering from existing historical market data, including deterministic multi-timescale technical, volatility, volume, breadth, regime, pattern and sector-relative features, subject to point-in-time correctness and out-of-sample evidence.

V8 also owns promotion-threshold calibration and repeated chronological validation so model eligibility can be based on horizon-aware, baseline-relative and stability-aware evidence rather than relying only on one fixed holdout and heuristic global cutoffs.

V8 also owns refinement of the Admin ML experience so backend-supported lifecycle functions are available through the UI with clear candidate/rejection reasons, model/version management, rollback, cutoff/configuration controls, granular training state, failure visibility, metrics/baselines and drift context.

V8 also owns the Guided Tour / Welcome Onboarding experience: welcome eligibility/persistence, configuration-driven spotlight steps, shell coordination, responsive/accessibility behavior and onboarding telemetry. It does not replace setup workflows or long-form product documentation.

V8 does **not** own StoX-specific Telemetry instrumentation/integration. That is planned as a separate V9 StoX epic.
