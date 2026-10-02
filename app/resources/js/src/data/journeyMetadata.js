/** Generated from docs/user-journeys/*.md; do not edit manually. */
export const JOURNEY_METADATA_VERSION = 'v9.ux001.generated';
export const JOURNEY_TOPICS = Object.freeze([
  {
    "id": "AI-01",
    "title": "How do I ask a grounded product question?",
    "aliases": [
      "Ask a grounded product question",
      "ai-01"
    ],
    "keywords": [
      "ask",
      "grounded",
      "product",
      "question"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/",
    "guide": "/docs/journeys/06-assistant.html#ai-01-ask-a-grounded-product-question",
    "steps": [
      "Sign in and choose Ask StoX in the global header.",
      "Ask a product question such as “How do I create a screener?”",
      "Read the answer and its non-numeric grounding state.",
      "Expand Sources used for titles, sections, links and supporting snippets.",
      "Use a source link to navigate to maintained documentation. Existing authorization still applies to application pages."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "AI-02",
    "title": "How do I follow up, copy and give feedback?",
    "aliases": [
      "Follow up, copy and give feedback",
      "ai-02"
    ],
    "keywords": [
      "follow",
      "copy",
      "and",
      "give",
      "feedback"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/",
    "guide": "/docs/journeys/06-assistant.html#ai-02-follow-up-copy-and-give-feedback",
    "steps": [
      "Ask a follow-up or select an explanation prompt below an answer.",
      "Choose Copy response to copy the answer and source links.",
      "Choose Helpful, or expand Not helpful, optionally enter a comment and send feedback.",
      "Choose Clear conversation to remove the current conversation and cancel a pending answer."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "AI-03",
    "title": "How do I recover when AI is unavailable?",
    "aliases": [
      "Recover when AI is unavailable",
      "ai-03"
    ],
    "keywords": [
      "recover",
      "when",
      "unavailable"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/",
    "guide": "/docs/journeys/06-assistant.html#ai-03-recover-when-ai-is-unavailable",
    "steps": [
      "Open the assistant while the runtime is unavailable or its budget is exhausted.",
      "Ask a question and observe the temporary-unavailability message.",
      "Use How do I? links or Browse documentation.",
      "Close the drawer with Close assistant or Escape. Focus returns to Ask StoX."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "AI-04",
    "title": "How do I investigate account evidence?",
    "aliases": [
      "Investigate account evidence",
      "ai-04"
    ],
    "keywords": [
      "investigate",
      "account",
      "evidence"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/",
    "guide": "/docs/journeys/06-assistant.html#ai-04-investigate-account-evidence",
    "steps": [
      "Open Ask StoX and select Account investigation and actions.",
      "Ask an account question, such as “How concentrated are my holdings?”",
      "Read the answer and any missing-evidence disclosure.",
      "Expand Investigation trace to see which tools supplied evidence."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "AI-05",
    "title": "How do I preview and approve changes?",
    "aliases": [
      "Preview and approve changes",
      "ai-05"
    ],
    "keywords": [
      "preview",
      "and",
      "approve",
      "changes"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/",
    "guide": "/docs/journeys/06-assistant.html#ai-05-preview-and-approve-changes",
    "steps": [
      "In account mode, request a supported change, such as creating a watchlist.",
      "Review Proposed changes, affected objects, warnings and Review field changes.",
      "Choose Approve changes or Reject plan. Deletions also require the explicit deletion checkbox.",
      "Read the verified action results. If state changed or approval expired, choose Build a fresh plan and review the new preview."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "AI-06",
    "title": "How do I inspect action history and recover?",
    "aliases": [
      "Inspect action history and recover",
      "ai-06"
    ],
    "keywords": [
      "inspect",
      "action",
      "history",
      "and",
      "recover"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/",
    "guide": "/docs/journeys/06-assistant.html#ai-06-inspect-action-history-and-recover",
    "steps": [
      "Open Run history in the existing assistant drawer.",
      "Review a run's original objective, portfolio, preview, trace and action results.",
      "For a failed, partial, expired or stale run, choose Build a fresh plan.",
      "Review and approve any new mutations separately."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "AI-07",
    "title": "How do I open a stock insight?",
    "aliases": [
      "Open a stock insight",
      "ai-07"
    ],
    "keywords": [
      "open",
      "stock",
      "insight"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/",
    "guide": "/docs/journeys/06-assistant.html#ai-07-open-a-stock-insight",
    "steps": [
      "Choose the AI puzzle icon beside a stock in Holdings, either Dashboard stock context, Watchlist rows, the selected Watchlist stock, or either Stock Explorer result card.",
      "Read the structured AI Insights and Based on / Data used dates. Missing evidence is disclosed.",
      "Held stocks show Personalized with your active portfolio holding. Other portfolios and private watchlist notes are excluded.",
      "Choose Copy insight, Refresh insight, or Open stock details.",
      "Close the section, pane or modal to continue using the page."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "AI-08",
    "title": "How do I recover an embedded insight?",
    "aliases": [
      "Recover an embedded insight",
      "ai-08"
    ],
    "keywords": [
      "recover",
      "embedded",
      "insight"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/",
    "guide": "/docs/journeys/06-assistant.html#ai-08-recover-an-embedded-insight",
    "steps": [
      "Open an insight or choose Refresh insight while managed AI is unavailable.",
      "If a matching saved result exists, continue reading it with the degraded status.",
      "Choose Copy AI Prompt to use the existing manual prompt workflow."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "AI-09",
    "title": "How do I design and explicitly create a draft strategy?",
    "aliases": [
      "Design and explicitly create a draft strategy",
      "ai-09",
      "new strategy",
      "create strategy"
    ],
    "keywords": [
      "design",
      "and",
      "explicitly",
      "create",
      "draft",
      "strategy"
    ],
    "synonyms": [],
    "category": "StoX",
    "route": "/",
    "guide": "/docs/journeys/06-assistant.html#ai-09-design-and-explicitly-create-a-draft-strategy",
    "steps": [
      "Open AI Strategy Designer on Strategy and choose the structured design inputs.",
      "Choose Generate strategy. Read the summary, entry/exit logic, sizing, risk controls, assumptions, caveats and artifact compatibility notes.",
      "Use Copy result or Regenerate as needed. Returning to matching inputs restores the account's saved result; changed inputs hide mismatched results.",
      "Optionally choose Create draft strategy. Review the proposed changes and choose Approve changes or Reject plan.",
      "Read the verified result and inspect the run in assistant history. Stale or expired plans require a fresh preview and approval."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "E2E-01",
    "title": "How do I new idea to first BUY?",
    "aliases": [
      "New idea to first BUY",
      "e2e-01"
    ],
    "keywords": [
      "new",
      "idea",
      "first",
      "buy"
    ],
    "synonyms": [],
    "category": "End-to-end journeys",
    "route": "/recommendations",
    "guide": "/docs/journeys/05-end-to-end.html#e2e-01-new-idea-to-first-buy",
    "steps": [
      "Create and validate the discovery rule using [SCR-02](01-screeners.md#scr-02--create-a-multi-condition-and-screener).",
      "Run it once and inspect representative matches using [SCR-06](01-screeners.md#scr-06--run-a-screener-and-inspect-matches).",
      "Create the strategy and attach the screener using [STR-02](02-strategies.md#str-02--create-a-strategy-when-the-required-screener-does-not-exist).",
      "Configure scoring/thresholds, sizing, exit policy and market gates as required.",
      "Enable the strategy using [STR-13](02-strategies.md#str-13--enable-a-strategy).",
      "Run/wait for the decision pipeline using [REC-01](03-recommendations-review.md#rec-01--generate-recommendations-through-the-decision-pipeline).",
      "Open `/recommendations` and inspect the generated OPEN/INCREASE recommendation and its evidence.",
      "Confirm capital state and actual executable amount."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "E2E-02",
    "title": "How do I existing screener to BUY?",
    "aliases": [
      "Existing screener to BUY",
      "e2e-02"
    ],
    "keywords": [
      "existing",
      "screener",
      "buy"
    ],
    "synonyms": [],
    "category": "End-to-end journeys",
    "route": "/screeners",
    "guide": "/docs/journeys/05-end-to-end.html#e2e-02-existing-screener-to-buy",
    "steps": [
      "Find and inspect the existing screener under `/screeners` or `/screeners/registry`.",
      "Validate/reuse it using [SCR-07](01-screeners.md#scr-07--reuse-or-import-an-existing-screener).",
      "Create the strategy using [STR-01](02-strategies.md#str-01--create-a-strategy-using-an-existing-screener).",
      "Configure and enable it.",
      "Run the decision pipeline.",
      "Review the generated recommendation.",
      "Approve if desired.",
      "Review Pending Execution."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "E2E-03",
    "title": "How do I exit signal to closed transaction?",
    "aliases": [
      "Exit signal to closed transaction",
      "e2e-03"
    ],
    "keywords": [
      "exit",
      "signal",
      "closed",
      "transaction"
    ],
    "synonyms": [],
    "category": "End-to-end journeys",
    "route": "/recommendations",
    "guide": "/docs/journeys/05-end-to-end.html#e2e-03-exit-signal-to-closed-transaction",
    "steps": [
      "Confirm the holding belongs to the intended strategy.",
      "Ensure the strategy's exit policy is current.",
      "Run/wait for the decision pipeline.",
      "Open `/recommendations` and locate the `EXIT_POSITION` or `REDUCE_POSITION` recommendation.",
      "Inspect the primary exit reason and quantity.",
      "Confirm an entry market gate has not incorrectly suppressed the exit.",
      "Approve the actionable exit recommendation.",
      "Open `/transactions/pending`."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "E2E-04",
    "title": "How do I strategy change and supersession?",
    "aliases": [
      "Strategy change and supersession",
      "e2e-04"
    ],
    "keywords": [
      "strategy",
      "change",
      "and",
      "supersession"
    ],
    "synonyms": [],
    "category": "End-to-end journeys",
    "route": "/recommendations",
    "guide": "/docs/journeys/05-end-to-end.html#e2e-04-strategy-change-and-supersession",
    "steps": [
      "Open the existing strategy and make the intended policy change using [STR-10](02-strategies.md#str-10--edit-an-existing-strategy).",
      "Save the strategy. Confirm that saving alone did not create or cancel recommendations.",
      "Run the decision pipeline separately.",
      "Open `/recommendations`.",
      "If the new decision materially changes the same strategy/security target, inspect the old recommendation's superseded state and its link to the replacement.",
      "Confirm the old recommendation is no longer treated as current executable intent.",
      "Review the new recommendation normally.",
      "Confirm old reservations/readiness were reconciled appropriately."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "E2E-05",
    "title": "How do I approve, cancel and retry?",
    "aliases": [
      "Approve, cancel and retry",
      "e2e-05"
    ],
    "keywords": [
      "approve",
      "cancel",
      "and",
      "retry"
    ],
    "synonyms": [],
    "category": "End-to-end journeys",
    "route": "/",
    "guide": "/docs/journeys/05-end-to-end.html#e2e-05-approve-cancel-and-retry",
    "steps": [
      "Approve the recommendation and confirm `pending_execution`.",
      "Start execution.",
      "If no broker submission occurred, cancel using [EXE-08](04-execution-transactions.md#exe-08--cancel-before-broker-submission).",
      "If a broker order was submitted, request cancellation using [EXE-09](04-execution-transactions.md#exe-09--cancel-a-submitted-broker-order).",
      "Wait for final broker confirmation. “Cancellation requested” is not final cancellation.",
      "Confirm whether any quantity filled before cancellation.",
      "Reconcile partial fills exactly once.",
      "Confirm the recommendation remains current/eligible for retry according to lifecycle policy."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "E2E-06",
    "title": "How do I insufficient capital to execution?",
    "aliases": [
      "Insufficient capital to execution",
      "e2e-06"
    ],
    "keywords": [
      "insufficient",
      "capital",
      "execution"
    ],
    "synonyms": [],
    "category": "End-to-end journeys",
    "route": "/transactions/pending",
    "guide": "/docs/journeys/05-end-to-end.html#e2e-06-insufficient-capital-to-execution",
    "steps": [
      "Open the recommendation and confirm the underlying action is OPEN/INCREASE.",
      "Compare desired target with currently fundable amount.",
      "Inspect the capital-resolution path: own capital, recall, lending/bridge or other supported source.",
      "Do not rewrite the investment opinion merely because funding is unavailable.",
      "Complete the supported capital-resolution step if you choose to fund it.",
      "Return to the recommendation and confirm capital state/readiness changed.",
      "Approve when lifecycle rules permit.",
      "In `/transactions/pending`, verify actual executable amount and whole-share quantity."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "E2E-07",
    "title": "How do I same stock in two strategies?",
    "aliases": [
      "Same stock in two strategies",
      "e2e-07"
    ],
    "keywords": [
      "same",
      "stock",
      "two",
      "strategies"
    ],
    "synonyms": [],
    "category": "End-to-end journeys",
    "route": "/",
    "guide": "/docs/journeys/05-end-to-end.html#e2e-07-same-stock-in-two-strategies",
    "steps": [
      "Open Strategy A's recommendation.",
      "Confirm it is `EXIT_POSITION` and its owned quantity is 10 in this example.",
      "Confirm Strategy B remains a separate 15-share position/context.",
      "Approve Strategy A's exit.",
      "In Pending Execution, verify the SELL quantity is 10, not the portfolio-wide 25.",
      "Execute and reconcile the actual fill.",
      "Verify Strategy A's holding episode closes.",
      "Verify Strategy B still owns its 15 shares and retains its own basis/policy/evidence."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "E2E-08",
    "title": "How do I admin production ML acceptance?",
    "aliases": [
      "Admin production ML acceptance",
      "e2e-08"
    ],
    "keywords": [
      "admin",
      "production",
      "acceptance"
    ],
    "synonyms": [],
    "category": "End-to-end journeys",
    "route": "/settings/ml-scoring",
    "guide": "/docs/journeys/05-end-to-end.html#e2e-08-admin-production-ml-acceptance",
    "steps": [
      "As StoX Admin, open Settings → ML Scoring (`/settings/ml-scoring`) and refresh Production ML acceptance. Unknown evidence remains blocking.",
      "Queue campaign preflight for the desired cutoff; inspect each horizon's exact required source dates and blocking reasons.",
      "Upload official dated NSE CSV/ZIP sources in the private upload panel. Reselect the identical file to resume an incomplete upload. Refresh to see queued validation results; resume queued validation after interrupted dispatch if needed.",
      "Select sealed sources, queue a backfill dry-run, and review each date's mapping and provenance. Apply only after preview succeeds. Save the backfill ID to reopen status, resume completed progress, or cancel between dates.",
      "Create a fresh campaign preflight after resolving blockers. When all three horizons pass, explicitly choose Start 1m/3m/6m training.",
      "Refresh progress and inspect linked run, feature coverage/exclusion, calibration, fold, baseline and candidate evidence. Cancel requests cancellation through the existing training controls."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "EXE-01",
    "title": "How do I execute an approved recommendation manually?",
    "aliases": [
      "Execute an approved recommendation manually",
      "exe-01"
    ],
    "keywords": [
      "execute",
      "approved",
      "recommendation",
      "manually"
    ],
    "synonyms": [],
    "category": "Execution",
    "route": "/transactions/pending",
    "guide": "/docs/journeys/04-execution-transactions.html#exe-01-execute-an-approved-recommendation-manually",
    "steps": [
      "Open `/transactions/pending`.",
      "Select the approved recommendation.",
      "Verify action, stock, strategy, quantity and expected amount.",
      "Place the corresponding trade manually with the broker.",
      "Wait for the actual broker result.",
      "Record the actual execution in StoX using the supported transaction flow.",
      "Use actual quantity/price/fees where required rather than copying an estimate blindly.",
      "Confirm the pending recommendation is reconciled to the resulting transaction/holding."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "EXE-02",
    "title": "How do I record a manually executed transaction?",
    "aliases": [
      "Record a manually executed transaction",
      "exe-02"
    ],
    "keywords": [
      "record",
      "manually",
      "executed",
      "transaction"
    ],
    "synonyms": [],
    "category": "Execution",
    "route": "/transactions",
    "guide": "/docs/journeys/04-execution-transactions.html#exe-02-record-a-manually-executed-transaction",
    "steps": [
      "Open `/transactions`.",
      "Start the supported add/record transaction flow.",
      "Select the correct stock and BUY/SELL side.",
      "Enter the actual trade date/time, quantity and execution price as required.",
      "Enter charges/fees or other required accounting data where supported.",
      "If the transaction completes a pending recommendation, use the supported linkage/reconciliation path rather than creating unrelated duplicate intent.",
      "Save.",
      "Verify holdings/cash/closed-position effects."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "EXE-03",
    "title": "How do I execute in Semi-Automatic mode?",
    "aliases": [
      "Execute in Semi-Automatic mode",
      "exe-03"
    ],
    "keywords": [
      "execute",
      "semi",
      "automatic",
      "mode"
    ],
    "synonyms": [],
    "category": "Execution",
    "route": "/transactions/pending",
    "guide": "/docs/journeys/04-execution-transactions.html#exe-03-execute-in-semi-automatic-mode",
    "steps": [
      "Ensure the Zerodha/Kite connection is valid for the day/session.",
      "Open `/transactions/pending`.",
      "Select the approved recommendation/order candidate.",
      "Review stock, strategy, BUY/SELL side, quantity, order type and estimated amount.",
      "Start the Semi-Automatic execution action.",
      "Complete the StoX execution authorization challenge when prompted.",
      "Confirm submission.",
      "Wait for broker acknowledgement/state rather than assuming a click means a fill."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "EXE-04",
    "title": "How do I authorize a Semi-Automatic execution?",
    "aliases": [
      "Authorize a Semi-Automatic execution",
      "exe-04"
    ],
    "keywords": [
      "authorize",
      "semi",
      "automatic",
      "execution"
    ],
    "synonyms": [],
    "category": "Execution",
    "route": "/transactions/pending",
    "guide": "/docs/journeys/04-execution-transactions.html#exe-04-authorize-a-semi-automatic-execution",
    "steps": [
      "Kite authentication/session: Used to establish the broker connection. UI text should explicitly identify this as Kite when a Kite-provided code/login is required.",
      "StoX execution authorization: Used to authorize sensitive Semi-Automatic trade submission. Use the authenticator application registered for StoX; the UI should identify the registered authenticator name where available.",
      "If a recovery flow is being used, use a StoX recovery code only in the recovery-code field; an ordinary rotating authenticator code is not a recovery code."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "EXE-05",
    "title": "How do I review Pending Execution?",
    "aliases": [
      "Review Pending Execution",
      "exe-05"
    ],
    "keywords": [
      "review",
      "pending",
      "execution"
    ],
    "synonyms": [],
    "category": "Execution",
    "route": "/transactions/pending",
    "guide": "/docs/journeys/04-execution-transactions.html#exe-05-review-pending-execution",
    "steps": [
      "Open `/transactions/pending`.",
      "Locate the approved recommendation.",
      "Confirm the strategy and stock.",
      "Confirm side/action: OPEN/INCREASE normally implies BUY; REDUCE/EXIT implies SELL.",
      "Confirm actual executable quantity and amount rather than only the original desired target.",
      "Confirm the execution window/lifetime has not expired.",
      "Confirm capital reservation/readiness for a BUY.",
      "Confirm the broker session is usable for broker-assisted modes."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "EXE-06",
    "title": "How do I submit a BUY order?",
    "aliases": [
      "Submit a BUY order",
      "exe-06"
    ],
    "keywords": [
      "submit",
      "buy",
      "order"
    ],
    "synonyms": [],
    "category": "Execution",
    "route": "/transactions/pending",
    "guide": "/docs/journeys/04-execution-transactions.html#exe-06-submit-a-buy-order",
    "steps": [
      "Start from `/transactions/pending`.",
      "Verify recommendation identity and strategy.",
      "Verify stock/instrument mapping, quantity, variety and order type shown by the UI.",
      "Confirm available/reserved capital and actual execution amount.",
      "Complete required execution authorization.",
      "Submit once.",
      "Wait for the broker acknowledgement/status.",
      "If the response is uncertain, do not immediately resubmit; follow EXE-13."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "EXE-07",
    "title": "How do I submit a REDUCE or EXIT SELL order?",
    "aliases": [
      "Submit a REDUCE or EXIT SELL order",
      "exe-07"
    ],
    "keywords": [
      "submit",
      "reduce",
      "exit",
      "sell",
      "order"
    ],
    "synonyms": [],
    "category": "Execution",
    "route": "/transactions/pending",
    "guide": "/docs/journeys/04-execution-transactions.html#exe-07-submit-a-reduce-or-exit-sell-order",
    "steps": [
      "Open the pending REDUCE/EXIT item.",
      "Verify strategy identity and the holding episode it owns.",
      "Confirm whether the intent is partial REDUCE or full EXIT.",
      "Verify sell quantity against that strategy's owned quantity.",
      "Complete required authorization and submit.",
      "Monitor broker status.",
      "After fill, verify the remaining holding for REDUCE or closure for EXIT.",
      "If another strategy owns the same security, confirm that its position remains unchanged."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "EXE-08",
    "title": "How do I cancel before broker submission?",
    "aliases": [
      "Cancel before broker submission",
      "exe-08"
    ],
    "keywords": [
      "cancel",
      "before",
      "broker",
      "submission"
    ],
    "synonyms": [],
    "category": "Execution",
    "route": "/transactions/pending",
    "guide": "/docs/journeys/04-execution-transactions.html#exe-08-cancel-before-broker-submission",
    "steps": [
      "Open `/transactions/pending`.",
      "Select the pending item.",
      "Confirm that no broker order has already been submitted.",
      "Use the supported cancel action.",
      "Confirm the recommendation becomes cancelled and relevant reservation is released/reconciled."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "EXE-09",
    "title": "How do I cancel a submitted broker order?",
    "aliases": [
      "Cancel a submitted broker order",
      "exe-09"
    ],
    "keywords": [
      "cancel",
      "submitted",
      "broker",
      "order"
    ],
    "synonyms": [],
    "category": "Execution",
    "route": "/transactions/pending",
    "guide": "/docs/journeys/04-execution-transactions.html#exe-09-cancel-a-submitted-broker-order",
    "steps": [
      "Open the submitted order/execution detail.",
      "Verify the broker order is still cancellable.",
      "Request cancellation.",
      "Treat cancellation requested as an intermediate state while broker confirmation is pending.",
      "Wait for reconciliation/confirmation from Kite.",
      "Only after broker confirmation treat the order as cancelled.",
      "Verify recommendation status, fills and reserved capital are reconciled correctly."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "EXE-10",
    "title": "How do I handle an unfilled or cancelled order?",
    "aliases": [
      "Handle an unfilled or cancelled order",
      "exe-10"
    ],
    "keywords": [
      "handle",
      "unfilled",
      "cancelled",
      "order"
    ],
    "synonyms": [],
    "category": "Execution",
    "route": "/transactions/pending",
    "guide": "/docs/journeys/04-execution-transactions.html#exe-10-handle-an-unfilled-or-cancelled-order",
    "steps": [
      "Inspect broker order status and confirm filled quantity is zero.",
      "Confirm the cancellation/rejection is final rather than pending reconciliation.",
      "Verify no transaction was created for an unfilled quantity.",
      "Review the recommendation lifecycle. Where the current product policy retains the recommendation for retry, confirm it remains in the appropriate pending state and funding reservation remains consistent.",
      "Retry later only through the supported execution path."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "EXE-11",
    "title": "How do I handle a partial fill?",
    "aliases": [
      "Handle a partial fill",
      "exe-11"
    ],
    "keywords": [
      "handle",
      "partial",
      "fill"
    ],
    "synonyms": [],
    "category": "Execution",
    "route": "/transactions/pending",
    "guide": "/docs/journeys/04-execution-transactions.html#exe-11-handle-a-partial-fill",
    "steps": [
      "Inspect broker-reported ordered quantity and filled quantity.",
      "Confirm StoX records the filled part once and only once.",
      "Verify holding/cash changes use the actual fill.",
      "Inspect the remaining unfilled quantity/order state.",
      "If cancellation is requested for the remainder, wait for broker confirmation.",
      "Do not recreate the already filled quantity during a retry/reconciliation."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "EXE-12",
    "title": "How do I handle broker rejection?",
    "aliases": [
      "Handle broker rejection",
      "exe-12"
    ],
    "keywords": [
      "handle",
      "broker",
      "rejection"
    ],
    "synonyms": [],
    "category": "Execution",
    "route": "/transactions/pending",
    "guide": "/docs/journeys/04-execution-transactions.html#exe-12-handle-broker-rejection",
    "steps": [
      "Read the broker rejection reason.",
      "Confirm that no fill occurred.",
      "Verify StoX has not created a completed transaction merely because submission was attempted.",
      "Correct the cause if it is user-actionable, such as session/order/input readiness.",
      "Recheck the recommendation and execution window before retrying.",
      "Retry through the existing intent rather than creating a duplicate recommendation/order unless the lifecycle explicitly requires replacement."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "EXE-13",
    "title": "How do I reconcile uncertain broker state?",
    "aliases": [
      "Reconcile uncertain broker state",
      "exe-13"
    ],
    "keywords": [
      "reconcile",
      "uncertain",
      "broker",
      "state"
    ],
    "synonyms": [],
    "category": "Execution",
    "route": "/transactions/pending",
    "guide": "/docs/journeys/04-execution-transactions.html#exe-13-reconcile-uncertain-broker-state",
    "steps": [
      "Do not submit the same trade again immediately.",
      "Keep the StoX execution/order record in its pending/uncertain state.",
      "Trigger or wait for the supported broker reconciliation process.",
      "Compare StoX order identity with Kite's order state.",
      "If Kite confirms the order/fill, reconcile that existing order.",
      "If Kite confirms cancellation/rejection/no submission, allow the normal lifecycle to expose the safe next action."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "EXE-14",
    "title": "How do I retry without creating a duplicate order?",
    "aliases": [
      "Retry without creating a duplicate order",
      "exe-14"
    ],
    "keywords": [
      "retry",
      "without",
      "creating",
      "duplicate",
      "order"
    ],
    "synonyms": [],
    "category": "Execution",
    "route": "/transactions/pending",
    "guide": "/docs/journeys/04-execution-transactions.html#exe-14-retry-without-creating-a-duplicate-order",
    "steps": [
      "First complete EXE-13 when prior broker state was uncertain.",
      "Confirm the previous order is finally cancelled/rejected/unfilled as required by policy.",
      "Confirm the recommendation is still current, not expired or superseded.",
      "Confirm the intended quantity has not already been partially filled.",
      "Reuse the supported retry path for the existing recommendation/execution intent.",
      "Authorize and submit once."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "EXE-15",
    "title": "How do I verify transaction and holding after execution?",
    "aliases": [
      "Verify transaction and holding after execution",
      "exe-15"
    ],
    "keywords": [
      "verify",
      "transaction",
      "and",
      "holding",
      "after",
      "execution"
    ],
    "synonyms": [],
    "category": "Execution",
    "route": "/transactions",
    "guide": "/docs/journeys/04-execution-transactions.html#exe-15-verify-transaction-and-holding-after-execution",
    "steps": [
      "Open `/transactions` and locate the resulting transaction.",
      "Confirm stock, side, quantity, actual price and linkage/provenance.",
      "Open `/holdings`.",
      "For BUY, verify the strategy-owned position increased/was created as expected.",
      "For REDUCE, verify the remaining strategy-owned quantity.",
      "For EXIT, verify the relevant holding episode is closed and inspect `/transactions/closed` where applicable.",
      "Check cash/reservation effects when relevant.",
      "If the same stock belongs to another strategy, verify that unrelated strategy ownership was not changed."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "REC-01",
    "title": "How do I generate recommendations through the decision pipeline?",
    "aliases": [
      "Generate recommendations through the decision pipeline",
      "rec-01"
    ],
    "keywords": [
      "generate",
      "recommendations",
      "through",
      "the",
      "decision",
      "pipeline"
    ],
    "synonyms": [],
    "category": "Recommendations",
    "route": "/recommendations",
    "guide": "/docs/journeys/03-recommendations-review.html#rec-01-generate-recommendations-through-the-decision-pipeline",
    "steps": [
      "Ensure the intended strategy is configured and enabled.",
      "Ensure required market data/discovery/evaluation prerequisites are available and current.",
      "Trigger the supported decision-pipeline flow, or allow its scheduled run to execute.",
      "Wait for completion; do not interpret a failed/incomplete pipeline as a valid “no recommendations” result.",
      "Open `/recommendations`.",
      "Review newly generated records by strategy, stock, action, score/evidence, capital state and status."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "REC-02",
    "title": "How do I review new recommendations?",
    "aliases": [
      "Review new recommendations",
      "rec-02"
    ],
    "keywords": [
      "review",
      "new",
      "recommendations"
    ],
    "synonyms": [],
    "category": "Recommendations",
    "route": "/recommendations",
    "guide": "/docs/journeys/03-recommendations-review.html#rec-02-review-new-recommendations",
    "steps": [
      "Open `/recommendations` or `/review` as appropriate to the current UI flow.",
      "Focus on open/current recommendations before browsing historical records.",
      "Select a recommendation.",
      "Confirm the stock and strategy.",
      "Read the proposed action and quantity/amount.",
      "Review the evidence, failed checks, market-gate result, sizing and capital status.",
      "Choose Approve, Reject or Defer according to your decision.",
      "Add review notes where useful."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "REC-03",
    "title": "How do I understand why StoX recommended an action?",
    "aliases": [
      "Understand why StoX recommended an action",
      "rec-03"
    ],
    "keywords": [
      "understand",
      "why",
      "stox",
      "recommended",
      "action"
    ],
    "synonyms": [],
    "category": "Recommendations",
    "route": "/recommendations",
    "guide": "/docs/journeys/03-recommendations-review.html#rec-03-understand-why-stox-recommended-an-action",
    "steps": [
      "Open the recommendation detail.",
      "Confirm the strategy and strategy version/configuration.",
      "Inspect the discovery/screener evidence that made the stock eligible.",
      "Inspect factor facts and strategy weighting/score.",
      "Inspect threshold/label interpretation.",
      "Check market-gate status.",
      "Check whether the strategy already owns a position in the stock.",
      "Review desired position sizing and whole-share constraints."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "REC-04",
    "title": "How do I understand OPEN, INCREASE, REDUCE and EXIT?",
    "aliases": [
      "Understand OPEN, INCREASE, REDUCE and EXIT",
      "rec-04"
    ],
    "keywords": [
      "understand",
      "open",
      "increase",
      "reduce",
      "and",
      "exit"
    ],
    "synonyms": [],
    "category": "Recommendations",
    "route": "/recommendations",
    "guide": "/docs/journeys/03-recommendations-review.html#rec-04-understand-open-increase-reduce-and-exit",
    "steps": [
      "Open the relevant StoX page.",
      "Follow the documented workflow.",
      "Confirm the expected result."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "REC-05",
    "title": "How do I understand WATCH and HOLD?",
    "aliases": [
      "Understand WATCH and HOLD",
      "rec-05"
    ],
    "keywords": [
      "understand",
      "watch",
      "and",
      "hold"
    ],
    "synonyms": [],
    "category": "Recommendations",
    "route": "/recommendations",
    "guide": "/docs/journeys/03-recommendations-review.html#rec-05-understand-watch-and-hold",
    "steps": [
      "Open the relevant StoX page.",
      "Follow the documented workflow.",
      "Confirm the expected result."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "REC-06",
    "title": "How do I preview a recommendation for one stock?",
    "aliases": [
      "Preview a recommendation for one stock",
      "rec-06"
    ],
    "keywords": [
      "preview",
      "recommendation",
      "for",
      "one",
      "stock"
    ],
    "synonyms": [],
    "category": "Recommendations",
    "route": "/recommendations",
    "guide": "/docs/journeys/03-recommendations-review.html#rec-06-preview-a-recommendation-for-one-stock",
    "steps": [
      "Open the UI surface that exposes recommendation preview for the selected stock/strategy.",
      "Select the stock.",
      "Select the strategy.",
      "Run/view the preview.",
      "Read eligibility, evaluation, market-gate and explanation information.",
      "If prerequisites are unavailable, read the reason instead of treating the preview as a valid neutral result."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "REC-07",
    "title": "How do I approve a recommendation?",
    "aliases": [
      "Approve a recommendation",
      "rec-07"
    ],
    "keywords": [
      "approve",
      "recommendation"
    ],
    "synonyms": [],
    "category": "Recommendations",
    "route": "/recommendations",
    "guide": "/docs/journeys/03-recommendations-review.html#rec-07-approve-a-recommendation",
    "steps": [
      "Open the actionable recommendation.",
      "Verify stock, strategy, action and intended quantity/amount.",
      "Review the evidence and capital state.",
      "Select Approve.",
      "Complete any confirmation/review-note step presented by the UI.",
      "Confirm the resulting status."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "REC-08",
    "title": "How do I reject a recommendation?",
    "aliases": [
      "Reject a recommendation",
      "rec-08"
    ],
    "keywords": [
      "reject",
      "recommendation"
    ],
    "synonyms": [],
    "category": "Recommendations",
    "route": "/recommendations",
    "guide": "/docs/journeys/03-recommendations-review.html#rec-08-reject-a-recommendation",
    "steps": [
      "Open the recommendation.",
      "Review the evidence.",
      "Select Reject.",
      "Record a note when the reason will be useful later.",
      "Confirm the recommendation is no longer in the normal approval queue."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "REC-09",
    "title": "How do I defer and later reopen?",
    "aliases": [
      "Defer and later reopen",
      "rec-09"
    ],
    "keywords": [
      "defer",
      "and",
      "later",
      "reopen"
    ],
    "synonyms": [],
    "category": "Recommendations",
    "route": "/recommendations",
    "guide": "/docs/journeys/03-recommendations-review.html#rec-09-defer-and-later-reopen",
    "steps": [
      "Open the recommendation and select Defer.",
      "Add a note explaining what you are waiting for if useful.",
      "Later locate the deferred recommendation/history.",
      "Confirm it is still eligible to be reopened.",
      "Select the supported Reopen action.",
      "Review it again using current context before approving/rejecting."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "REC-10",
    "title": "How do I handle partial funding?",
    "aliases": [
      "Handle partial funding",
      "rec-10"
    ],
    "keywords": [
      "handle",
      "partial",
      "funding"
    ],
    "synonyms": [],
    "category": "Recommendations",
    "route": "/recommendations",
    "guide": "/docs/journeys/03-recommendations-review.html#rec-10-handle-partial-funding",
    "steps": [
      "Open the recommendation detail.",
      "Compare the desired/target amount with the currently fundable/actual execution amount.",
      "Inspect own cash, recall/lending/capital-resolution information where relevant.",
      "Do not interpret partial funding as a weaker investment score unless the strategy evidence separately says so.",
      "Proceed to approval only when the lifecycle says the capital state is eligible.",
      "In Pending Execution, verify the executable amount/quantity again."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "REC-11",
    "title": "How do I handle an unfunded recommendation?",
    "aliases": [
      "Handle an unfunded recommendation",
      "rec-11"
    ],
    "keywords": [
      "handle",
      "unfunded",
      "recommendation"
    ],
    "synonyms": [],
    "category": "Recommendations",
    "route": "/recommendations",
    "guide": "/docs/journeys/03-recommendations-review.html#rec-11-handle-an-unfunded-recommendation",
    "steps": [
      "Open the recommendation.",
      "Confirm that the underlying action is still OPEN/INCREASE rather than assuming “no cash = WATCH”.",
      "Inspect the funding gap and capital-resolution state.",
      "Resolve funding through the supported cash/recall/lending flow if desired.",
      "Return to the recommendation after capital status changes.",
      "Approve only when readiness rules permit movement to pending execution."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "REC-12",
    "title": "How do I handle a superseded recommendation?",
    "aliases": [
      "Handle a superseded recommendation",
      "rec-12"
    ],
    "keywords": [
      "handle",
      "superseded",
      "recommendation"
    ],
    "synonyms": [],
    "category": "Recommendations",
    "route": "/recommendations",
    "guide": "/docs/journeys/03-recommendations-review.html#rec-12-handle-a-superseded-recommendation",
    "steps": [
      "When a recommendation is marked superseded, do not treat it as the current executable instruction.",
      "Follow the old-to-new relationship to the replacement recommendation.",
      "Compare action, target, quantity, reason and strategy version/evidence.",
      "Review the replacement recommendation normally.",
      "Confirm reservations/readiness associated with the old intent have been reconciled according to lifecycle rules."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "SCR-01",
    "title": "How do I create a screener from scratch?",
    "aliases": [
      "Create a screener from scratch",
      "scr-01",
      "new screener",
      "create screener"
    ],
    "keywords": [
      "create",
      "screener",
      "from",
      "scratch"
    ],
    "synonyms": [],
    "category": "Screeners",
    "route": "/screeners",
    "guide": "/docs/journeys/01-screeners.html#scr-01-create-a-screener-from-scratch",
    "steps": [
      "Open Screeners at `/screeners`.",
      "Start creation of a new screener.",
      "Enter a descriptive name, for example Price Above MA200. Prefer names that describe the rule rather than names such as “My Screener 1”.",
      "Add the first condition.",
      "Select the price/close indicator on one side.",
      "Select the supported moving-average indicator and set its period to `200` on the other side.",
      "Select the `>` comparison.",
      "Review the definition and save it."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "SCR-02",
    "title": "How do I create a multi-condition AND screener?",
    "aliases": [
      "Create a multi-condition AND screener",
      "scr-02",
      "new screener",
      "create screener"
    ],
    "keywords": [
      "create",
      "multi",
      "condition",
      "and",
      "screener"
    ],
    "synonyms": [],
    "category": "Screeners",
    "route": "/screeners",
    "guide": "/docs/journeys/01-screeners.html#scr-02-create-a-multi-condition-and-screener",
    "steps": [
      "Open `/screeners` and create a new screener.",
      "Name it, for example Momentum Entry — MA200 + RSI.",
      "Add condition 1: Price > MA(200).",
      "Add condition 2: RSI(14) < 70.",
      "Put both conditions in an AND group.",
      "Save and validate the definition.",
      "Run or inspect the screener results."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "SCR-03",
    "title": "How do I create nested AND/OR logic?",
    "aliases": [
      "Create nested AND/OR logic",
      "scr-03"
    ],
    "keywords": [
      "create",
      "nested",
      "and",
      "logic"
    ],
    "synonyms": [],
    "category": "Screeners",
    "route": "/screeners",
    "guide": "/docs/journeys/01-screeners.html#scr-03-create-nested-and-or-logic",
    "steps": [
      "Create or edit the screener at `/screeners`.",
      "Create the outer AND group.",
      "Add the long-term trend condition.",
      "Add a nested OR group.",
      "Add the alternative momentum conditions inside the OR group.",
      "Review the visual grouping carefully. Group placement changes the meaning of the rule.",
      "Save and validate.",
      "Run the screener and inspect examples near the boundaries to confirm that the logic means what you intended."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "SCR-04",
    "title": "How do I edit an existing screener?",
    "aliases": [
      "Edit an existing screener",
      "scr-04"
    ],
    "keywords": [
      "edit",
      "existing",
      "screener"
    ],
    "synonyms": [],
    "category": "Screeners",
    "route": "/screeners",
    "guide": "/docs/journeys/01-screeners.html#scr-04-edit-an-existing-screener",
    "steps": [
      "Open `/screeners`.",
      "Locate the intended screener. Confirm the name/identity before editing, especially when similarly named definitions exist.",
      "Open the screener editor.",
      "Change the required condition, parameter, threshold or group.",
      "Validate the new definition.",
      "Save the change.",
      "Run the screener again when you need candidate evidence from the changed definition."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "SCR-05",
    "title": "How do I validate a screener?",
    "aliases": [
      "Validate a screener",
      "scr-05"
    ],
    "keywords": [
      "validate",
      "screener"
    ],
    "synonyms": [],
    "category": "Screeners",
    "route": "/screeners",
    "guide": "/docs/journeys/01-screeners.html#scr-05-validate-a-screener",
    "steps": [
      "Open the screener in `/screeners`.",
      "Use the available validation/save validation flow.",
      "If validation succeeds, review the definition once more for business meaning. Syntactically valid is not the same as economically sensible.",
      "If validation fails, read the reported condition/indicator/parameter problem.",
      "Correct the definition and validate again."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "SCR-06",
    "title": "How do I run a screener and inspect matches?",
    "aliases": [
      "Run a screener and inspect matches",
      "scr-06"
    ],
    "keywords": [
      "run",
      "screener",
      "and",
      "inspect",
      "matches"
    ],
    "synonyms": [],
    "category": "Screeners",
    "route": "/screeners",
    "guide": "/docs/journeys/01-screeners.html#scr-06-run-a-screener-and-inspect-matches",
    "steps": [
      "Open `/screeners` and select the screener.",
      "Trigger the supported screener run, or use the normal scheduled pipeline if that is the intended workflow.",
      "Wait for the run to complete rather than interpreting an in-progress/failed run as an empty result.",
      "Inspect the candidate/match results and run metadata.",
      "For an interesting stock, inspect the evidence showing which predicates/indicators matched."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "SCR-07",
    "title": "How do I reuse or import an existing screener?",
    "aliases": [
      "Reuse or import an existing screener",
      "scr-07"
    ],
    "keywords": [
      "reuse",
      "import",
      "existing",
      "screener"
    ],
    "synonyms": [],
    "category": "Screeners",
    "route": "/screeners",
    "guide": "/docs/journeys/01-screeners.html#scr-07-reuse-or-import-an-existing-screener",
    "steps": [
      "Check `/screeners` and `/screeners/registry` for the required definition.",
      "Inspect the screener's description, conditions and version rather than selecting it only by name.",
      "If it is a reusable/shared/factory artifact, use the supported import/create/binding flow for the current portfolio.",
      "Confirm that the resulting runtime screener belongs to or is valid for the intended portfolio/user scope.",
      "Validate it before attaching it to strategy policy."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "SCR-08",
    "title": "How do I retire or archive a screener?",
    "aliases": [
      "Retire or archive a screener",
      "scr-08"
    ],
    "keywords": [
      "retire",
      "archive",
      "screener"
    ],
    "synonyms": [],
    "category": "Screeners",
    "route": "/screeners",
    "guide": "/docs/journeys/01-screeners.html#scr-08-retire-or-archive-a-screener",
    "steps": [
      "Open the screener management/registry surface.",
      "Identify where the screener is currently used before retiring it.",
      "If a live strategy depends on it, change the strategy to another valid screener first or otherwise resolve the dependency.",
      "Use the supported archive/retire lifecycle action.",
      "Confirm that it is no longer offered for new runtime use while historical runs remain understandable."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "SCR-09",
    "title": "How do I diagnose a match or non-match?",
    "aliases": [
      "Diagnose a match or non-match",
      "scr-09"
    ],
    "keywords": [
      "diagnose",
      "match",
      "non"
    ],
    "synonyms": [],
    "category": "Screeners",
    "route": "/screeners",
    "guide": "/docs/journeys/01-screeners.html#scr-09-diagnose-a-match-or-non-match",
    "steps": [
      "Identify the exact screener and run being investigated.",
      "Confirm the screener/version used by that run.",
      "Inspect each condition and its indicator parameters.",
      "Check whether enough historical bars existed for each indicator.",
      "For volume-dependent rules, check whether valid volume history existed. Missing volume is not zero.",
      "Check dataset freshness/data-quality status.",
      "Check whether the security was active and inside the configured universe at the relevant time.",
      "For historical runs, confirm that the investigation uses data available at that historical point rather than today's mutable state."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "STR-01",
    "title": "How do I create a strategy using an existing screener?",
    "aliases": [
      "Create a strategy using an existing screener",
      "str-01",
      "new screener",
      "create screener",
      "new strategy",
      "create strategy"
    ],
    "keywords": [
      "create",
      "strategy",
      "using",
      "existing",
      "screener"
    ],
    "synonyms": [],
    "category": "Strategies",
    "route": "/screeners",
    "guide": "/docs/journeys/02-strategies.html#str-01-create-a-strategy-using-an-existing-screener",
    "steps": [
      "Confirm the required screener exists and is valid under `/screeners` or `/screeners/registry`.",
      "Open Strategy at `/strategy` or the management surface at `/strategy/registry`.",
      "Start creation of a new strategy.",
      "Give it a descriptive name, for example Momentum Core.",
      "Select/bind the intended screener for entry eligibility.",
      "Configure the remaining strategy policy: factors/weights, thresholds, exit policy, position sizing, limits, capital allocation and market gates as required.",
      "Review the complete policy. A screener is only one input to the strategy.",
      "Save the strategy."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "STR-02",
    "title": "How do I create a strategy when the required screener does not exist?",
    "aliases": [
      "Create a strategy when the required screener does not exist",
      "str-02",
      "new screener",
      "create screener",
      "new strategy",
      "create strategy"
    ],
    "keywords": [
      "create",
      "strategy",
      "when",
      "the",
      "required",
      "screener",
      "does",
      "not",
      "exist"
    ],
    "synonyms": [],
    "category": "Strategies",
    "route": "/screeners",
    "guide": "/docs/journeys/02-strategies.html#str-02-create-a-strategy-when-the-required-screener-does-not-exist",
    "steps": [
      "Go to `/screeners`.",
      "Create Momentum Entry — MA200 + RSI.",
      "Add Price > MA(200).",
      "Add RSI(14) < 70.",
      "Combine the conditions with AND.",
      "Save and validate the screener.",
      "Optionally run it and inspect representative matches to confirm the rule behaves as intended.",
      "Go to `/strategy` or `/strategy/registry`."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "STR-03",
    "title": "How do I configure entry criteria?",
    "aliases": [
      "Configure entry criteria",
      "str-03"
    ],
    "keywords": [
      "configure",
      "entry",
      "criteria"
    ],
    "synonyms": [],
    "category": "Strategies",
    "route": "/strategy",
    "guide": "/docs/journeys/02-strategies.html#str-03-configure-entry-criteria",
    "steps": [
      "Open the intended strategy at `/strategy`.",
      "Confirm the strategy identity before changing policy.",
      "Select the intended screener/eligibility inputs.",
      "Configure relevant scoring factors and thresholds.",
      "Configure any entry market gates.",
      "Configure position sizing, minimum actionable amount, maximum position/holding constraints and stagger/cooldown behavior where exposed.",
      "Save the configuration.",
      "Run the decision pipeline later to consume the changed policy."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "STR-04",
    "title": "How do I configure exit criteria?",
    "aliases": [
      "Configure exit criteria",
      "str-04"
    ],
    "keywords": [
      "configure",
      "exit",
      "criteria"
    ],
    "synonyms": [],
    "category": "Strategies",
    "route": "/strategy",
    "guide": "/docs/journeys/02-strategies.html#str-04-configure-exit-criteria",
    "steps": [
      "Open the strategy at `/strategy`.",
      "Locate the exit-policy configuration.",
      "Configure the supported exit conditions relevant to the strategy.",
      "Configure stop-loss/trailing-stop/horizon rules where part of the intended policy.",
      "Review precedence where several exit reasons could apply at once.",
      "Save the strategy.",
      "Allow a later pipeline run to evaluate existing strategy-owned holdings against the new policy."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "STR-05",
    "title": "How do I use separate entry and exit definitions?",
    "aliases": [
      "Use separate entry and exit definitions",
      "str-05"
    ],
    "keywords": [
      "use",
      "separate",
      "entry",
      "and",
      "exit",
      "definitions"
    ],
    "synonyms": [],
    "category": "Strategies",
    "route": "/screeners",
    "guide": "/docs/journeys/02-strategies.html#str-05-use-separate-entry-and-exit-definitions",
    "steps": [
      "Create/validate the entry discovery rule under `/screeners` if required.",
      "Create/validate any separately modeled exit definition required by the supported strategy configuration.",
      "Open the strategy at `/strategy`.",
      "Bind/select the entry eligibility definition in the entry part of policy.",
      "Configure the exit definition/policy separately.",
      "Verify that the two have not accidentally been reversed.",
      "Save and later run the pipeline."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "STR-06",
    "title": "How do I configure factors, weights and thresholds?",
    "aliases": [
      "Configure factors, weights and thresholds",
      "str-06"
    ],
    "keywords": [
      "configure",
      "factors",
      "weights",
      "and",
      "thresholds"
    ],
    "synonyms": [],
    "category": "Strategies",
    "route": "/strategy",
    "guide": "/docs/journeys/02-strategies.html#str-06-configure-factors-weights-and-thresholds",
    "steps": [
      "Open the strategy.",
      "Review the supported factor/indicator set.",
      "Enable only factors the strategy intends to use.",
      "Assign weights according to the intended importance. Disabled factors should not contribute.",
      "Configure thresholds/labels in a valid order.",
      "Review the normalized result shown by StoX where applicable.",
      "Save the configuration."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "STR-07",
    "title": "How do I configure position sizing and portfolio limits?",
    "aliases": [
      "Configure position sizing and portfolio limits",
      "str-07"
    ],
    "keywords": [
      "configure",
      "position",
      "sizing",
      "and",
      "portfolio",
      "limits"
    ],
    "synonyms": [],
    "category": "Strategies",
    "route": "/strategy",
    "guide": "/docs/journeys/02-strategies.html#str-07-configure-position-sizing-and-portfolio-limits",
    "steps": [
      "Open the strategy.",
      "Configure target allocation/sizing policy.",
      "Review maximum position size, maximum holdings and other portfolio constraints.",
      "Review minimum actionable amount and whole-share behavior.",
      "Configure staggered-entry/cooldown rules where required.",
      "Save the strategy."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "STR-08",
    "title": "How do I configure stop-loss, trailing stop and exit policy?",
    "aliases": [
      "Configure stop-loss, trailing stop and exit policy",
      "str-08"
    ],
    "keywords": [
      "configure",
      "stop",
      "loss",
      "trailing",
      "and",
      "exit",
      "policy"
    ],
    "synonyms": [],
    "category": "Strategies",
    "route": "/strategy",
    "guide": "/docs/journeys/02-strategies.html#str-08-configure-stop-loss-trailing-stop-and-exit-policy",
    "steps": [
      "Open the strategy.",
      "Locate exit/risk controls.",
      "Configure the intended stop-loss rule.",
      "Configure trailing-stop behavior if used.",
      "Configure horizon or other supported exit causes.",
      "Review which reason should take precedence if multiple exit causes become true together.",
      "Save the strategy."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "STR-09",
    "title": "How do I configure market gates?",
    "aliases": [
      "Configure market gates",
      "str-09"
    ],
    "keywords": [
      "configure",
      "market",
      "gates"
    ],
    "synonyms": [],
    "category": "Strategies",
    "route": "/strategy",
    "guide": "/docs/journeys/02-strategies.html#str-09-configure-market-gates",
    "steps": [
      "Open the strategy.",
      "Locate market-regime/gate configuration.",
      "Configure how bullish/neutral/bearish conditions affect entry policy.",
      "Save the strategy.",
      "On a later recommendation, inspect whether the gate blocked or adjusted an otherwise valid entry."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "STR-10",
    "title": "How do I edit an existing strategy?",
    "aliases": [
      "Edit an existing strategy",
      "str-10"
    ],
    "keywords": [
      "edit",
      "existing",
      "strategy"
    ],
    "synonyms": [],
    "category": "Strategies",
    "route": "/strategy/registry",
    "guide": "/docs/journeys/02-strategies.html#str-10-edit-an-existing-strategy",
    "steps": [
      "Open `/strategy/registry` and select the intended strategy, or open it through `/strategy`.",
      "Confirm its name/status and current configuration/version.",
      "Make the required changes.",
      "Review all affected policy sections, not only the edited field, when the change can interact with sizing/exit/capital rules.",
      "Save.",
      "Run the decision pipeline separately when new recommendations are desired."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "STR-11",
    "title": "How do I change the screener without rebuilding the strategy?",
    "aliases": [
      "Change the screener without rebuilding the strategy",
      "str-11"
    ],
    "keywords": [
      "change",
      "the",
      "screener",
      "without",
      "rebuilding",
      "strategy"
    ],
    "synonyms": [],
    "category": "Strategies",
    "route": "/strategy",
    "guide": "/docs/journeys/02-strategies.html#str-11-change-the-screener-without-rebuilding-the-strategy",
    "steps": [
      "Validate the replacement screener first.",
      "Open the existing strategy.",
      "Replace the current screener/binding with the intended definition.",
      "Leave unrelated scoring, sizing and exit policy unchanged unless deliberately modifying them.",
      "Save.",
      "On the next pipeline run, verify candidate/recommendation evidence identifies the intended definition/version."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "STR-12",
    "title": "How do I change exit policy without changing entry policy?",
    "aliases": [
      "Change exit policy without changing entry policy",
      "str-12"
    ],
    "keywords": [
      "change",
      "exit",
      "policy",
      "without",
      "changing",
      "entry"
    ],
    "synonyms": [],
    "category": "Strategies",
    "route": "/strategy",
    "guide": "/docs/journeys/02-strategies.html#str-12-change-exit-policy-without-changing-entry-policy",
    "steps": [
      "Open the strategy.",
      "Record/confirm the current entry screener and entry policy.",
      "Modify only the required exit settings.",
      "Review precedence with existing stop/trailing/horizon rules.",
      "Save.",
      "Run the pipeline later and inspect recommendations for strategy-owned holdings."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "STR-13",
    "title": "How do I enable a strategy?",
    "aliases": [
      "Enable a strategy",
      "str-13"
    ],
    "keywords": [
      "enable",
      "strategy"
    ],
    "synonyms": [],
    "category": "Strategies",
    "route": "/strategy/registry",
    "guide": "/docs/journeys/02-strategies.html#str-13-enable-a-strategy",
    "steps": [
      "Open `/strategy/registry`.",
      "Select the strategy.",
      "Confirm its configuration is complete and valid.",
      "Enable/activate it using the available lifecycle action.",
      "Confirm its status is active/enabled."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "STR-14",
    "title": "How do I archive or disable a strategy?",
    "aliases": [
      "Archive or disable a strategy",
      "str-14"
    ],
    "keywords": [
      "archive",
      "disable",
      "strategy"
    ],
    "synonyms": [],
    "category": "Strategies",
    "route": "/strategy/registry",
    "guide": "/docs/journeys/02-strategies.html#str-14-archive-or-disable-a-strategy",
    "steps": [
      "Open `/strategy/registry`.",
      "Select the strategy to retire.",
      "Check for live recommendations, pending execution and owned holdings that need deliberate handling.",
      "Use the supported disable/archive lifecycle action.",
      "Confirm the strategy is no longer enabled for future runs."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "STR-15",
    "title": "How do I operate multiple strategies concurrently?",
    "aliases": [
      "Operate multiple strategies concurrently",
      "str-15"
    ],
    "keywords": [
      "operate",
      "multiple",
      "strategies",
      "concurrently"
    ],
    "synonyms": [],
    "category": "Strategies",
    "route": "/strategy/registry",
    "guide": "/docs/journeys/02-strategies.html#str-15-operate-multiple-strategies-concurrently",
    "steps": [
      "Configure and validate each strategy independently.",
      "Enable each required strategy in `/strategy/registry`.",
      "Run the normal decision pipeline.",
      "In `/recommendations`, inspect strategy identity on recommendations rather than treating all recommendations for a stock as one combined opinion.",
      "Review/approve each actionable recommendation in its own strategy context."
    ],
    "prerequisites": [],
    "warnings": []
  },
  {
    "id": "STR-16",
    "title": "How do I same stock used by multiple strategies?",
    "aliases": [
      "Same stock used by multiple strategies",
      "str-16"
    ],
    "keywords": [
      "same",
      "stock",
      "used",
      "multiple",
      "strategies"
    ],
    "synonyms": [],
    "category": "Strategies",
    "route": "/strategy",
    "guide": "/docs/journeys/02-strategies.html#str-16-same-stock-used-by-multiple-strategies",
    "steps": [
      "Open the recommendation for Strategy A and confirm the strategy identity.",
      "Inspect the strategy-owned position/quantity associated with Strategy A.",
      "Confirm Strategy B's position remains separately attributable.",
      "Review/approve Strategy A's EXIT if appropriate.",
      "Execute only the quantity owned by Strategy A's holding episode.",
      "After execution, verify Strategy B's holding remains intact."
    ],
    "prerequisites": [],
    "warnings": []
  }
]);

const STOP_WORDS = new Set(['a', 'an', 'and', 'do', 'how', 'i', 'the', 'to']);
export function normalizeHelpQuery(value) { return String(value || '').toLowerCase().normalize('NFKD').replace(/[^a-z0-9]+/g, ' ').trim(); }
function tokens(value) { return normalizeHelpQuery(value).split(/\s+/).filter((token) => token && !STOP_WORDS.has(token)); }
function scoreTopic(topic, query, currentPath = '', history = []) { const normalized = normalizeHelpQuery(query); if (!normalized) return 0; const qTokens = new Set(tokens(normalized)); let score = 0; if ([topic.title, ...(topic.aliases || [])].map(normalizeHelpQuery).includes(normalized)) score += 100; for (const token of qTokens) { if (tokens(topic.title).includes(token)) score += 24; if ((topic.aliases || []).some((value) => tokens(value).includes(token))) score += 16; if ((topic.keywords || []).includes(token)) score += 10; if ((topic.synonyms || []).includes(token)) score += 7; } if (currentPath && topic.route === currentPath) score += 6; if (history.includes(topic.id)) score += 2; return score; }
export function searchJourneyTopics(query, { currentPath = '', history = [], limit = 6 } = {}) { return JOURNEY_TOPICS.map((topic) => ({ ...topic, score: scoreTopic(topic, query, currentPath, history) })).filter((topic) => topic.score > 0).sort((a, b) => b.score - a.score || a.id.localeCompare(b.id)).slice(0, limit); }
export function explainJourneyMatch(topic, query) { const q = new Set(tokens(query)); const matched = [topic.title, ...(topic.aliases || []), ...(topic.keywords || []), ...(topic.synonyms || [])].flatMap(tokens).filter((token) => q.has(token)); return matched.length ? 'Matched ' + [...new Set(matched)].slice(0, 3).join(', ') : 'Related StoX journey'; }
