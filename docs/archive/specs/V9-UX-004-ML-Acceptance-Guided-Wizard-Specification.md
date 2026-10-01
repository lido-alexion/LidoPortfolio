# StoX V9 — Guided Production ML Acceptance Wizard Specification

| Field | Value |
|---|---|
| **Epic** | V9-UX-004 — Guided Production ML Acceptance Wizard |
| **Version target** | V9 |
| **Status** | **FROZEN / IMPLEMENTATION-READY** |
| **Owner** | Product / Architecture |
| **Canonical path** | `docs/archive/specs/V9-UX-004-ML-Acceptance-Guided-Wizard-Specification.md` |
| **Parent register** | `docs/archive/specs/LidoPortfolio-V9-Wishlist.md` |
| **Primary route** | Settings → ML Scoring (`/settings/ml-scoring`) |
| **Extends** | V8 production ML acceptance UI and E2E-08 |
| **Preserves** | V4-FEAT-056 lifecycle operations, V4-FEAT-057 training/validation, V4-FEAT-054 historical fundamentals, production acceptance amendment |
| **Primary implementation agent** | Codex / basic implementation engineer model |

---

## 1. Purpose

The Production ML acceptance panel currently exposes every control in one technical form. The operations are related, ordered and asynchronous, but an Admin must already understand terms such as source staging, backfill, preflight, candidate training, production qualification, promotion, lifecycle scheduling and drift.

This epic replaces that panel with a guided wizard that:

- tells the Admin what StoX is trying to prove;
- shows one logical stage at a time;
- derives progress from durable server state;
- explains why an action is available, blocked, running or complete;
- survives refresh, logout, browser closure, deploy and worker restart;
- keeps training, promotion and automation as explicit separate decisions;
- preserves every V8 safety, evidence and authorization rule.

The wizard is an orchestration and explanation layer over the existing ML acceptance domain. It must not introduce a second acceptance engine or reinterpret the evidence.

---

## 2. Product outcome

An Admin who does not know how machine learning works must be able to answer these questions from the page:

1. What are we doing?
2. Why is this step needed?
3. What will happen if I click this button?
4. Is work currently running?
5. What succeeded or failed?
6. What can I do next?
7. Will this activate a model or enable automation?

The page must make this overall sequence visible:

```text
check production readiness
    -> discover the historical dates StoX needs
    -> provide and validate official dated NSE sources
    -> preview and apply missing historical universe membership
    -> run final preflight
    -> explicitly train 1m/3m/6m candidates
    -> review production acceptance evidence
    -> separately review promotion and lifecycle automation
```

Some installations will already have enough historical data. The wizard may mark source/backfill steps complete or unnecessary, but it must not silently invent evidence or skip a server-side gate.

---

## 3. Scope

### 3.1 In scope

- Replace the current `MlAcceptancePanel` presentation with a seven-step guided wizard.
- Keep the wizard inside Settings → ML Scoring.
- Derive current step and step status from authoritative acceptance/source/backfill/campaign data.
- Add a read-only aggregate wizard-state response if needed for reliable recovery.
- Present plain-language summaries before raw technical evidence.
- Add reusable info icons/tooltips and expandable “Learn more” explanations.
- Show action impact before upload, backfill apply, campaign start and cancellation.
- Poll running acceptance operations and recover after a disconnected browser.
- Show clear success, blocked, running, failed and cancelled states.
- Link completed candidate training to the existing evidence/promotion review surface.
- Keep raw JSON available under an Advanced diagnostics disclosure.
- Add journey metadata, static help, component tests and browser journey coverage.

### 3.2 Out of scope

- Changing feature formulas, model families, hyperparameters, thresholds, calibration or training logic.
- Creating a new source parser, membership engine, dataset builder or training engine.
- Automatic upload of NSE files.
- Automatic backfill apply.
- Automatic start of training.
- Automatic model promotion or rollback.
- Automatic lifecycle, schedule or drift enablement.
- Combining candidate promotion into acceptance.
- New ML roles or permissions.
- Editing raw cron expressions.
- Replacing existing durable campaign/run history.
- Requiring an LLM to explain states or blockers.

All help text and step decisions must work deterministically without AI availability.

---

## 4. Ownership and dependencies

### 4.1 Existing owners remain authoritative

| Concern | Authoritative owner |
|---|---|
| Historical fundamental semantics | V4-FEAT-054 |
| Training data, features, models, validation and candidate evidence | V4-FEAT-057 |
| Queuing, progress, cancellation, promotion, rollback, scheduling and drift | V4-FEAT-056 |
| Production acceptance source/backfill/campaign rules | `docs/current/ml-production-acceptance.md` |
| This guided presentation and recovery flow | V9-UX-004 |

### 4.2 Implementation rule

Use the existing services, policies, models, jobs and endpoints. Additive read models and response fields are allowed when the existing APIs cannot reconstruct the wizard reliably. Mutation semantics must remain unchanged.

### 4.3 Dependencies

- The implemented V8 production acceptance endpoints and dedicated `ml-acceptance` worker are prerequisites.
- The wizard must use existing Admin authorization, Sanctum session and CSRF protection.
- V9-UX-001 conventions apply to journey metadata, browser automation and accessibility checks.
- V9-UX-002 and V9-AI-001 may later link to this wizard, but neither is a runtime dependency.

---

## 5. Frozen UX decisions

| Decision | Frozen choice |
|---|---|
| UX004-01 | Use a seven-step wizard in the existing ML Scoring page. |
| UX004-02 | The server remains authoritative; the client derives/reloads state and never treats a local step number as proof. |
| UX004-03 | The wizard can revisit completed steps and may mark unnecessary steps as `Not required`. |
| UX004-04 | Only the current actionable step is expanded by default. Completed and future steps remain visible in the stepper. |
| UX004-05 | Each step contains a short “What this does” explanation and optional deeper help through an info icon or `Learn more` disclosure. |
| UX004-06 | Tooltips supplement visible labels; essential instructions and warnings must remain visible without hover. |
| UX004-07 | Preflight is allowed before source upload because it discovers the exact missing dates. A later final preflight is required after blockers are resolved. |
| UX004-08 | Upload, backfill apply, training start and cancellation remain explicit Admin actions. |
| UX004-09 | Starting training requires a confirmation dialog summarizing horizons, cutoff, current build/configuration identities and the fact that models remain candidates. |
| UX004-10 | Acceptance completion never promotes a model or enables lifecycle/schedule/drift controls. |
| UX004-11 | Running jobs show persistent server state, automatic refresh and last refreshed time. Browser disconnection does not change the job. |
| UX004-12 | Raw report JSON remains available only as Advanced diagnostics. |
| UX004-13 | Technical blocker codes are mapped to plain language while preserving the original code in diagnostics. Unknown codes use a safe generic explanation. |
| UX004-14 | Desktop uses a vertical or compact top stepper; narrow screens use a vertical stepper with one expanded card. No horizontal overflow is permitted. |
| UX004-15 | A wizard cannot be “reset” by deleting evidence. Starting a fresh campaign is the supported restart path. |

---

## 6. Terminology and required knowledge content

The following explanations are normative starter copy. Wording may be shortened for a tooltip if the full text is available through `Learn more`.

| Term | Short tooltip | Expanded explanation |
|---|---|---|
| **Production acceptance** | Proof that StoX can prepare data and run all three model horizons correctly in the deployed environment. | Acceptance records evidence for the current production build, registry and configuration. It does not activate a candidate model or enable recurring automation. |
| **Historical source** | An official dated NSE CSV or ZIP used to reconstruct what was known on a past date. | StoX needs dated exchange files so historical membership is based on that date rather than today’s stock list. Files are validated, hashed and stored privately. |
| **Backfill** | Rebuild missing historical universe membership from validated dated sources. | A dry-run first shows what would be written. Apply performs the reviewed changes. Existing conflicting or unproven history is blocked rather than overwritten. |
| **Preflight** | A readiness check performed before model training. | Preflight builds/checks the required historical datasets for 1m, 3m and 6m, records coverage and lists blockers. It does not train or activate a model. |
| **Training cutoff** | Latest date allowed in this campaign’s training data. | The cutoff fixes the historical boundary used to build reproducible datasets. It is not a schedule or execution date. |
| **Horizon** | The future period a model estimates: 1 month, 3 months or 6 months. | StoX trains and evaluates a separate candidate for each horizon because useful signals and outcomes differ by time period. |
| **Candidate model** | A newly trained model awaiting evidence review. | Candidate training may complete successfully or be rejected by quality gates. A candidate never becomes active automatically. |
| **Promotion** | Explicitly make an eligible candidate the active model for one horizon. | Promotion is a later Admin decision in the existing model review controls. It is outside this wizard’s acceptance flow. |
| **Lifecycle automation** | Scheduled or drift-triggered creation of future candidates. | Lifecycle automation can queue new training runs after valid production acceptance. It does not automatically promote or roll back models. |
| **Drift** | A material change in live data compared with the data used for training. | Drift may recommend or trigger candidate retraining when enabled. The resulting candidate still requires the normal evidence and promotion review. |
| **Worker** | Background process that performs long-running acceptance work. | The browser requests work and may be closed. The dedicated production worker continues the queued job and stores progress on the server. |

Info icons must be keyboard focusable and expose the same text through accessible descriptions. Do not rely on title attributes alone.

---

## 7. Wizard structure

### 7.1 Step list

| Step | Label | Primary question | Main action |
|---|---|---|---|
| 1 | Understand & check readiness | Is production capable of running acceptance? | Refresh readiness |
| 2 | Discover required data | Which dates and evidence are missing? | Queue/inspect discovery preflight |
| 3 | Add historical sources | Are the official dated NSE files validated and sealed? | Upload/resume validation |
| 4 | Preview & apply backfill | Can missing membership be safely reconstructed? | Dry-run, review, apply |
| 5 | Run final preflight | Are all 1m/3m/6m gates ready for training? | Queue fresh preflight |
| 6 | Train candidates | Should StoX train the three candidate horizons now? | Confirm and start training |
| 7 | Review completion | Did production acceptance qualify, and what remains separate? | Review evidence / go to model review |

### 7.2 Step status vocabulary

Every step uses one of:

- `Not started`
- `Action required`
- `In progress`
- `Blocked`
- `Failed`
- `Cancelled`
- `Complete`
- `Not required`

Do not expose only raw database status names as the user-facing label.

### 7.3 Navigation rules

- `Back` navigates to the prior visible step and never mutates server state.
- `Continue` is enabled only when the current step’s completion rule is satisfied.
- Clicking a completed step reopens it in read-only/review mode.
- Clicking a future blocked step opens a summary explaining the prerequisite; it must not expose enabled mutation buttons.
- On load, open the earliest step whose status is `Action required`, `Blocked`, `Failed` or `In progress`.
- If acceptance is already current and qualified, open Step 7.
- The route stays `/settings/ml-scoring`; optional `?acceptanceStep=<1-7>` deep linking is permitted but the server state overrides an invalid requested step.

---

## 8. Detailed step behavior

### 8.1 Step 1 — Understand & check readiness

Visible content:

- two-sentence overview of the complete process;
- a compact diagram or ordered list of all seven steps;
- build/environment/registry/configuration summary;
- migrations, queue configuration, worker evidence and Python evidence;
- lifecycle, schedules and drift shown as separate current states;
- `Refresh readiness` button;
- `Continue` when configuration prerequisites are ready enough to run preflight.

Rules:

- `queue_configuration_ready=false` is blocking and must show a plain-language operator message.
- Missing worker/Python evidence before the first preflight is `Not yet observed`, not an application failure.
- Existing current production qualification routes directly to Step 7.
- The page must say: “This wizard records production evidence. It will not activate a model or enable schedules.”

### 8.2 Step 2 — Discover required data

Visible content:

- training cutoff field;
- explanation of cutoff and preflight;
- `Run discovery preflight` button;
- current campaign identity/status when present;
- per-horizon cards for 1m, 3m and 6m;
- plain-language blocker summary grouped into data, runtime and model/dataset categories;
- exact required source dates when the server reports them;
- automatic refresh while preflight is queued/running.

Rules:

- This is the same canonical campaign preflight operation; “discovery” describes its role in the wizard, not a new backend mode.
- If preflight passes for all horizons, Steps 3 and 4 become `Not required` and Step 5 becomes `Complete`; proceed to Step 6.
- If missing source/membership evidence is reported, proceed to Step 3.
- If only a runtime/configuration blocker exists, remain on Step 2 and provide the reason; do not direct the user to upload unrelated files.
- `Resume` appears only for a genuinely interrupted/resumable campaign. A running campaign shows `Preflight is running`; it must not present Resume as the normal next action.
- Cancellation explains that completed evidence is retained and no training will start.

### 8.3 Step 3 — Add historical sources

Visible content:

- checklist of exact required source family/date combinations from preflight;
- upload form scoped to the selected missing requirement;
- accepted file rules: official CSV or single-CSV ZIP, maximum 16 MiB;
- upload byte progress and validation status;
- source list with filters `Required`, `Uploading`, `Validating`, `Sealed`, `Failed`;
- clear resume instructions for incomplete uploads;
- validation error summary with safe actionable text;
- `Continue` after every currently required date has a selected/available sealed source.

Rules:

- Preserve paginated source loading and selections across pages.
- Preselecting a sealed source is allowed only when its family/date matches a reported requirement.
- The Admin must still choose the local file; StoX does not fetch an archive URL.
- Failed finalization starts a new upload, as defined by the acceptance amendment.
- Cancellation remains available only for incomplete upload/queued validation states.
- Do not display server paths, raw parser payloads or unbounded identifiers.

### 8.4 Step 4 — Preview & apply backfill

Visible content:

- selected sealed sources and covered dates;
- `Run safe preview` button;
- durable progress (`x of y dates`) during preview;
- per-date mapping %, provenance, proposed changes and conflicts;
- overall review summary;
- `Apply reviewed backfill` only after successful preview;
- explicit confirmation before Apply;
- durable apply progress, Resume where supported, and Cancel between work units;
- automatic recovery of the latest wizard-linked backfill without asking the Admin to type an ID.

Confirmation copy for Apply must include:

> Apply the reviewed historical membership changes for the listed dates. Matching proven data is idempotent. Conflicting or unproven existing history will be blocked and will not be overwritten.

Rules:

- Preview performs no membership writes.
- Apply must recheck source and mapped-membership hashes through the existing backend.
- A completed successful apply advances to Step 5.
- A preview with conflicts remains blocked and links to the per-date reason.
- The wizard must retain/recover the backfill ID from server state or an additive account-scoped wizard read model. Manual ID entry may remain only inside Advanced diagnostics.

### 8.5 Step 5 — Run final preflight

Visible content:

- summary of resolved data work;
- training cutoff copied from the current flow, editable before starting a new campaign;
- warning that changed cutoff may require different dates;
- `Run final preflight` button;
- per-horizon readiness checklist;
- dataset hashes/technical evidence under Advanced details;
- automatic refresh while running.

Rules:

- Final preflight must create a fresh reviewed campaign after data blockers are resolved.
- All three horizons must be ready before Continue is enabled.
- A changed source, build, registry, configuration or cutoff invalidates stale readiness according to existing server rules.
- A blocked final preflight routes the Admin back to the earliest relevant remediation step and preserves the blocker explanation.

### 8.6 Step 6 — Train candidates

Visible content:

- a clear explanation that training creates candidates for 1m, 3m and 6m;
- cutoff, build, registry/profile, configuration and dataset identity summary;
- estimated behavior: long-running queued work, browser may be closed, progress will be saved;
- `Start 1m/3m/6m candidate training` button;
- confirmation dialog;
- horizon progress cards linked to durable training run IDs;
- stage, last update, retry attempt and cancellation state;
- Cancel using existing campaign/training cancellation;
- automatic refresh and SSE integration where existing run SSE is available.

Required confirmation statements:

- three horizon runs will be queued;
- the datasets/configuration will be rechecked;
- models remain candidates;
- active models, schedules and drift settings will not change.

Rules:

- Starting training is possible only from a current `ready` campaign.
- A double click or retry must not duplicate successful/active runs.
- A quality rejection is shown as `Completed — quality gates not met`, not as an operational failure.
- A failed training campaign explains that the frozen contract requires a fresh reviewed campaign; Resume must not imply that failed training can be reused when the backend disallows it.

### 8.7 Step 7 — Review completion

Visible content:

- overall production qualification status;
- current/stale identity and qualified timestamp;
- one result card per horizon;
- operational completion vs quality eligibility clearly separated;
- links to full evidence, candidate review and existing promotion controls;
- lifecycle/schedule/drift status displayed as separate follow-up decisions;
- `Finish` collapses the wizard into a completion summary; it does not mutate ML state.

Outcome text:

- Qualified: “Production acceptance is current for this build and configuration.”
- Completed with rejected candidate: explain that runtime execution is proven but candidate quality did not pass; use the server’s qualification result as authoritative.
- Stale: explain which identity/date changed and offer `Start a fresh acceptance campaign`.
- Failed/cancelled: show the durable reason and safe next action.

Promotion and lifecycle controls must remain in their existing sections. The wizard may deep-link/scroll to them only after clearly explaining that they are separate decisions.

---

## 9. State derivation contract

### 9.1 Server authority

The wizard must be reconstructed from server responses on every initial load and refresh. Local state may remember the expanded step and unsent form values, but it cannot mark a step complete.

### 9.2 Preferred additive read model

If the current report cannot identify the relevant source/backfill/campaign chain reliably, add:

```http
GET /api/v1/admin/ml/acceptance/wizard
```

This endpoint is read-only and may aggregate existing services. It must not dispatch work, probe Python, create settings or mutate schedules.

Minimum response shape:

```json
{
  "reported_at": "ISO-8601",
  "current_step": 4,
  "steps": [
    { "id": "readiness", "status": "complete", "reason_codes": [] },
    { "id": "discovery", "status": "complete", "reason_codes": [] },
    { "id": "sources", "status": "complete", "reason_codes": [] },
    { "id": "backfill", "status": "in_progress", "reason_codes": [] }
  ],
  "campaign": { "id": 123, "status": "preflight", "cutoff_date": "2026-10-01" },
  "backfill": { "id": 456, "status": "running", "mode": "apply" },
  "required_sources": [],
  "horizons": {},
  "links": {}
}
```

The server may use its existing external status vocabulary inside nested resources. The `steps[].status` values must be allowlisted and deterministic.

### 9.3 No duplicated business gates

The frontend may format and group reason codes. It must not recalculate:

- source validity;
- mapping thresholds;
- dataset coverage;
- campaign readiness;
- model quality/eligibility;
- production qualification;
- evidence staleness;
- identity matching.

Those decisions remain server-owned.

### 9.4 Reason-code presentation

Create a frontend registry:

```text
reason code
    -> user title
    -> plain-language explanation
    -> owning step
    -> suggested action
    -> severity
```

Examples:

| Code | User-facing explanation | Owning step |
|---|---|---|
| `preflight_not_recorded` | Run preflight so StoX can determine the required historical dates and coverage. | 2 |
| queue configuration not ready | The dedicated production worker configuration is not ready for long-running acceptance jobs. | 1 |
| missing required source date | Upload and validate the official NSE file for the listed date. | 3 |
| mapping below required coverage | The source could not map enough securities to StoX records; review the preview evidence. | 4 |
| missing fundamental coverage | Historical fundamentals are incomplete for this horizon/date. | 5 |
| stale build/configuration identity | The saved evidence belongs to an older build or configuration; run a fresh campaign. | 2 or 5 |

The implementation must enumerate all reason codes currently emitted by the backend and add a generic fallback:

> StoX reported an unrecognized blocker. Open Advanced diagnostics and copy the blocker code for investigation.

---

## 10. Asynchronous work and refresh behavior

- Poll every 5 seconds while a source validation, backfill, preflight or campaign is queued/running.
- Back off to 15 seconds after 2 minutes and 30 seconds after 10 minutes.
- Pause polling when the tab is hidden and refresh immediately when it becomes visible.
- Stop polling on terminal state, logout or component unmount.
- Never start/resume/cancel a job from a GET or automatic poll.
- Show `Last checked <time>` and a manual `Refresh` control.
- A request timeout means “status refresh failed”; it must not claim that the background job failed.
- After a mutation timeout, immediately re-read server state before enabling the same action again.
- Disable only the affected action during its request. Do not freeze unrelated read-only navigation.
- SSE may enhance training progress, but REST state remains authoritative after reconnect.

---

## 11. Error and recovery behavior

| Situation | Required behavior |
|---|---|
| Network error while refreshing | Keep the last known state, label it stale, show Retry. |
| Mutation returns validation error | Keep form values, focus the error summary, associate errors with fields. |
| Mutation times out | Re-read server state before offering Retry; explain the job may already have been accepted. |
| Worker/deploy interruption | Show durable queued/running/failed state and Resume only when server says resumable. |
| Browser closed | On return, reconstruct current step and progress from the server. |
| Campaign cancelled | Show who/when if available; allow a fresh campaign without deleting evidence. |
| Training fails | Preserve run evidence; guide to investigate and then create a fresh campaign. |
| Candidate rejected | Present completed operationally with quality rejection; do not offer operational Retry as if it crashed. |
| Evidence stale | State the changed build/configuration/time dimension and route to fresh preflight. |
| Unknown reason code | Show generic blocker plus code in Advanced diagnostics. |

---

## 12. Component-level implementation guide

Refactor `MlAcceptancePanel.jsx` into small components. Suggested structure:

```text
pages/ml-acceptance/
  MlAcceptanceWizard.jsx
  useMlAcceptanceWizard.js
  acceptanceReasonRegistry.js
  components/
    AcceptanceStepper.jsx
    StepCard.jsx
    InfoHelp.jsx
    ReadinessStep.jsx
    DiscoveryPreflightStep.jsx
    HistoricalSourcesStep.jsx
    BackfillStep.jsx
    FinalPreflightStep.jsx
    CandidateTrainingStep.jsx
    AcceptanceReviewStep.jsx
    HorizonStatusCard.jsx
    AdvancedDiagnostics.jsx
```

Exact filenames may follow current project conventions. Responsibilities must remain separated:

- hook/service: fetching, polling, mutation wrappers and server-state normalization;
- reason registry: deterministic plain-language mappings;
- steps: presentation and step-specific forms;
- shared controls: consistent status badges, info help, error summary and confirmation dialogs.

Do not place all logic and markup back into one page component.

### 12.1 API mapping

Reuse the existing endpoints:

- `GET /api/v1/admin/ml/acceptance`
- source create/chunk/finalize/status/list/resume/cancel
- backfill preview/status/apply/resume/cancel
- campaign create/status/start/resume/cancel
- existing model evidence/promotion-review endpoints
- existing training-run progress/SSE endpoints

The optional `/wizard` endpoint is an aggregate read model only. Prefer expanding existing serializers/services over duplicating evidence queries in a new controller.

---

## 13. Visual and interaction requirements

- Use existing Bootstrap styles and StoX page conventions.
- Step status must use icon + text; color alone is insufficient.
- Destructive/cancellation actions use the existing danger treatment.
- Training start and backfill apply use a confirmation dialog with explicit consequences.
- Tooltips open on hover and keyboard focus; info icons have at least a 44×44 CSS-pixel touch target on narrow screens.
- Long evidence tables become responsive tables/cards without horizontal page overflow.
- Progress announcements use a polite live region and avoid announcing every poll when nothing changed.
- Focus moves to the new step heading after Continue.
- When an error occurs, focus moves to the error summary; each item links to the affected control where possible.
- Browser back/forward must not replay a mutation.
- Advanced diagnostics is collapsed by default and includes copy-to-clipboard for safe allowlisted JSON.

---

## 14. Security, privacy and audit requirements

- Existing Admin authorization is mandatory for every read and mutation.
- Preserve session CSRF and current throttles.
- Do not add public routes or query-string tokens.
- Do not expose source filesystem paths, credentials, subprocess stderr, raw provider payloads or unbounded identifiers.
- Confirmation UI does not replace server authorization, locks, idempotency or revalidation.
- Every mutation retains existing actor/time/action history.
- Tooltip/help content is static application text and must not render untrusted backend HTML.
- Copy diagnostics must use the backend’s allowlisted report only.

---

## 15. Documentation and journey updates

Implementation must update together:

- `docs/current/ml-production-acceptance.md` with the implemented wizard path;
- `docs/current/ml-lifecycle-operations.md` where the Admin sequence is described;
- `docs/user-journeys/05-end-to-end.md` E2E-08;
- `app/resources/js/src/data/journeyMetadata.js` generated/maintained journey entry;
- `app/resources/js/src/data/appDocumentation.js` ML Scoring help;
- product-served static journey/help output generated by the repository’s documented process;
- `implementation.md` with component/API/operational notes.

Until implementation lands, this V9 specification describes planned behavior and must not be copied into current product docs as already shipped.

---

## 16. Testing requirements

### 16.1 Backend feature tests

If the aggregate wizard endpoint is added, cover:

- Admin-only authorization;
- GET is read-only and dispatches no jobs;
- deterministic step derivation for empty, blocked, ready, training, qualified, failed, cancelled and stale cases;
- current campaign/backfill recovery;
- no secrets/raw paths in payloads;
- query bounds and pagination/count summaries;
- unchanged existing acceptance mutations.

### 16.2 Frontend component tests

Cover at minimum:

- glossary/info help accessible by focus and hover;
- initial step selection from server state;
- initial preflight routes to missing sources;
- all-ready preflight skips source/backfill steps;
- source validation progress and error recovery;
- backfill preview cannot Apply before completion;
- Apply confirmation content;
- final preflight blocks training until all horizons are ready;
- training confirmation states that candidates remain inactive;
- rejected candidate vs failed operation presentation;
- stale evidence path;
- raw JSON only inside Advanced diagnostics;
- timeout triggers authoritative refresh before retry;
- polling stops at terminal state/unmount.

### 16.3 Browser journey tests

Extend E2E-08 with deterministic fixtures/adapters for:

1. first-time Admin with no evidence;
2. discovery preflight reporting missing dates;
3. resumable source upload and queued validation;
4. preview then confirmed apply;
5. successful final preflight;
6. training confirmation and durable progress recovery after reload;
7. completed qualified evidence with active models unchanged;
8. quality-rejected candidate;
9. interrupted/resumable operation;
10. narrow/mobile viewport keyboard-accessible help and navigation.

Production smoke coverage remains read-only unless a separately authorized acceptance operation is being performed.

---

## 17. Implementation sequence

1. Inventory all backend reason/status codes and document their owning step.
2. Add/extend the read-only server aggregate needed for durable wizard reconstruction.
3. Build the reason registry and state-normalization hook with unit tests.
4. Split the current panel into step components while initially preserving existing actions.
5. Add stepper/navigation, visible explanations, tooltips and Advanced diagnostics.
6. Add polling, mutation-timeout reconciliation and durable backfill/campaign recovery.
7. Add Apply/Start confirmations and links to existing review controls.
8. Update journey/help/current docs and generated static help.
9. Add component and E2E coverage.
10. Run frontend/backend CI parity checks and verify that acceptance GETs remain side-effect free.

Implementation should be delivered in small, independently testable slices. Do not wait until the final slice to preserve recovery or accessibility.

---

## 18. Acceptance criteria

V9-UX-004 is complete only when all statements below are true:

1. An Admin can understand the acceptance goal and consequences without external ML knowledge.
2. The page shows the seven-step flow and opens the correct step from durable server state.
3. Preflight is plainly described as a readiness check that does not train models.
4. The first preflight can discover missing dates and the final preflight rechecks them after remediation.
5. Source upload and validation are guided by exact reported requirements.
6. Backfill preview and apply are distinct; Apply requires successful preview and confirmation.
7. Refresh/browser closure/deploy does not lose the active campaign/backfill context.
8. Resume is offered only for a server-confirmed resumable state.
9. Running, blocked, failed, cancelled, quality-rejected and qualified outcomes are distinguishable.
10. Training requires a separate confirmation and queues all three horizons through the existing canonical path.
11. The page states and demonstrates that candidates, active models, schedules and drift settings remain separate.
12. Every known blocker code has plain-language copy and an owning remediation step.
13. Unknown blocker codes remain visible and safely diagnosable.
14. Essential instructions work without hover; info help works with mouse, keyboard and touch.
15. Mobile/narrow layout is usable without horizontal page overflow.
16. Existing authorization, CSRF, queue, locks, idempotency, evidence, promotion and lifecycle rules remain unchanged.
17. GET/automatic refresh performs no mutation or Python probe.
18. Component tests and E2E-08 cover the normal flow and material recovery paths.
19. Current product docs are updated only when implementation ships.
20. CI parity verification is green for the affected backend, frontend and browser journey scope.

---

## 19. Final implementation declaration

This specification contains no unresolved Product Owner decision.

**V9-UX-004 is FROZEN / IMPLEMENTATION-READY.**

The implementation agent may make ordinary component naming, layout spacing and internal service-organization choices while preserving the frozen workflow, education content, server-authoritative gates and V8 ownership boundaries above.
