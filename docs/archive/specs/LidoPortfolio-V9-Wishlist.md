# LidoPortfolio / StoX V9 Wishlist

| Field | Value |
|---|---|
| **Document type** | Canonical V9 planning register |
| **Created** | 2026-09-09 |
| **Status** | EARLY PLANNING |
| **Canonical path** | `specs/LidoPortfolio-V9-Wishlist.md` |
| **Predecessor** | `specs/LidoPortfolio-V8-Wishlist.md` |

## 1. Purpose

V9 currently contains later StoX product expansion that follows the V7 analytical-data work and V8 standalone Telemetry Platform.

The Telemetry separation remains deliberate: V8 builds the reusable Telemetry product; V9 changes StoX to become a properly instrumented producer/consumer of that product.

The V9 assistance roadmap also builds on the task-oriented user-journey corpus under `docs/user-journeys/`. The human-readable journeys remain useful independently; V9 can progressively expose the same knowledge through deterministic search, conversational assistance, automation-ready UI contracts and eventually agentic actions.

## 2. Current V9 backlog

| ID | Feature | Scope / rationale | Status |
|---|---|---|---|
| V4-FEAT-017 | AI Assistant | Introduce an AI-assisted StoX experience after earlier platform, safety, analytical-data and ML foundations are established. Architecture, tool/data access, explainability, interaction model and decision-authority boundaries require dedicated V9 deliberation. AI must not silently override deterministic Strategy behavior or bypass artifact/versioning and execution safeguards. The three assistance epics below refine this broad item into documentation Q&A, deterministic journey discovery and later agentic execution. | OPEN |
| V4-FEAT-019 | ETF / Options / Crypto Expansion | Expand StoX beyond its current equity-centric instrument model. ETF, Options and Crypto have materially different market-data, pricing, lifecycle, accounting, settlement, risk and execution needs and may be decomposed into separate V9 epics during planning. | OPEN |
| V9-COMM-001 | StoX Email Notifications & Account Lifecycle Messaging | Add a reusable email channel for account lifecycle and user-selected StoX notifications. Use authenticated SMTP, replace the temporary `admin@lidoalexion.com` sender with a dedicated `stox@lidoalexion.com` mailbox, and send Admin invitations, access-request updates and account/security notices automatically. See Section 3 for the technical and behavior requirements. | WISHLIST |
| TBD | StoX Telemetry Platform Integration | Integrate StoX with the standalone Telemetry Platform after the V8 platform is available. Scope includes StoX-side SDK/API integration, product/environment credentials, identity/session/context mapping, operational telemetry, product-usage events, correlation propagation, privacy-safe metadata, delivery/failure isolation, and any StoX-facing analytics/query/deep-link integration deliberately selected during V9 planning. | OPEN |
| TBD | Historical Fundamentals Bootstrap Admin Operations | Enhance the V8 FEAT-054 dedicated, resumable historical-fundamentals bootstrap workflow into a full Admin UI-driven operational capability. Add UI controls for starting targeted/full backfills, pausing/resuming, retrying failures, inspecting per-stock/cadence/source progress, viewing checkpoints and coverage/provenance diagnostics, and rerunning after mapping/source improvements. Preserve the V8 bootstrap service as the execution engine; V9 should add operational UX rather than create a parallel ingestion path. | WISHLIST |
| TBD | Data Export Framework | Add investor-facing export capabilities across StoX data surfaces. Support exporting data shown in tables and chart series, with formats and scope to be decided during V9 planning. Prefer reusable export plumbing rather than page-specific implementations. | WISHLIST |
| TBD | Customizable Summary Fields & Dashboard Layouts | Allow investors to customize which fields/metrics appear in stock summaries and to personalize dashboard card composition/layout. Scope may include pin/unpin, reorder, visibility preferences and reusable layout persistence, while preserving sensible defaults and responsive behavior. | WISHLIST |
| TBD | Combo Chart Support | Add reusable multi-series chart support for compatible metrics, including shared range/frequency controls, legends, tooltips and dual-axis handling where appropriate. Preserve V8 one-metric-per-chart as the default. | WISHLIST |
| V9-UX-001 | User Journey Automation Readiness & E2E Automation | Evolve the `docs/user-journeys/` human workflow corpus into an automation-ready contract. Review each important journey for usability and missing states; simplify unnecessarily difficult flows where accepted; add stable semantic UI hooks/IDs such as `data-testid` only where needed; map journey IDs to automated cases; implement Playwright/Selenium-style end-to-end automation for the high-value screener -> strategy -> recommendation -> review -> pending execution -> transaction workflows and recovery paths. Human journey documentation remains the starting point; automation selectors/code must not pollute the user-facing instructions. | WISHLIST |
| V9-AI-001 | Documentation-Grounded StoX Chatbot | Add a read-only LLM chatbot that answers StoX “how do I?” and product-usage questions from the maintained documentation/user-journey corpus, for example “How do I create a screener?” or “How do I cancel a pending execution?”. Support configurable model providers: a low/no-cost Gemini path where available, configurable frontier-model providers, and an approved Codex/OpenAI path where technically/licensing-wise appropriate. Retrieval must ground answers in StoX documentation and expose relevant page/journey links. This phase explains and navigates only: it must not mutate StoX data, approve recommendations, or place trades. | WISHLIST |
| V9-UX-002 | User Journey Typeahead / “How Do I?” Search | Add deterministic, non-LLM discovery of user journeys from a search/help box. Maintain searchable question-style titles/aliases such as “How do I create a screener?”, “How do I edit a strategy?” and “How do I cancel an order?”. As the user types words such as `screener`, perform simple text/token matching against journey question headings/aliases and show typeahead suggestions. Selecting a suggestion opens the corresponding rendered documentation page directly at that journey/section. Keep this fast, deterministic and usable without an LLM. | WISHLIST |
| V9-AI-002 | Agentic StoX Assistant / MCP Action Layer | Extend the assistance experience from read-only guidance to explicitly authorized actions. A user should eventually be able to request, for example, “Create a screener with price above MA200 and RSI below 70,” and have the assistant construct and perform the corresponding StoX workflow through governed tools/APIs. Define an MCP/tool layer (or then-current equivalent), typed action contracts, permissions, validation, previews, confirmations, audit/provenance, idempotency and recovery. High-impact operations must preserve StoX deterministic strategy, recommendation, execution and broker-safety boundaries; the agent must not silently approve or execute money-moving actions. Reuse the documented user journeys as task semantics and the automation-ready UI/API contracts where useful, rather than teaching the model to click arbitrary UI. | WISHLIST |

## 3. Email notifications & account lifecycle messaging

### 3.1 Goal and scope

Deliver a reusable, secure email channel for transactional StoX account messages and user-selected product notifications. Keep StoX admin-controlled and invite-only: this epic extends the existing Admin invitation flow and V8 V4-FEAT-055 account-access request handoff; it does not introduce open registration or change authorization rules.

The notification event catalogue should cover, at minimum:

- **Account onboarding:** Admin-created invitations/account setup links, invitation accepted/account activated, and resend or expiry outcomes.
- **Access requests:** applicant receipt and Admin decision/status updates, coordinated with V8 V4-FEAT-055.
- **Account and security updates:** password-reset links, email-address changes, credential/security changes, and account enabled/suspended or role/permission changes, where applicable.
- **StoX product notifications:** an extensible set of account-scoped events, such as recommendation/order/execution status and important operational failures. Final event types, detail level and user preference controls are to be frozen during detailed design.

Mandatory account/security messages must not be suppressible by optional product-notification preferences. Emails must not expose secrets or sensitive portfolio/trading data by default, and email links must not approve recommendations, place trades or bypass StoX authorization.

### 3.2 Admin invitation and account lifecycle

When an Admin creates a user or accepts an account-access request through the existing Admin Console flow, StoX should create the existing secure invitation and automatically email a setup link to the intended address. Do not email passwords. Preserve the current invite-only model and token security.

The detailed design shall define and implement:

- Single-use, cryptographically random, expiring invitation/reset tokens, stored safely and invalidated after use, expiry, revocation or replacement.
- Sending only after the user/invitation database transaction commits; a mail failure must not roll back account administration or leave a misleading success state.
- Admin-visible delivery state (queued/provider accepted/failed), actionable failure detail, and safe resend/revoke behavior. Resending must invalidate the previous token and must not create duplicate accounts.
- A confirmation email after successful invitation acceptance/account activation.
- Appropriate messages for access-request receipt and outcome, password reset, and relevant profile/security or account-state changes.
- Per-event deduplication/idempotency so queue retries cannot send repeated invitations or repeated status notices.

Use the V8 V4-FEAT-055 workflow as the upstream account-request contract. Keep Admin creation, request approval and invitation acceptance distinct, auditable steps.

### 3.3 Delivery architecture and operations

Use Laravel's mail/notification services through the existing database-backed queue. Dispatch mail jobs after commit; use bounded retries with backoff and record delivery outcomes without blocking the web request. Distinguish “accepted by SMTP” from confirmed inbox delivery; surface available bounce/delivery diagnostics where the provider supports them. Keep message templates centralized, version-controlled and escaped.

The SMTP proof of concept was successfully delivered from `stoxla-prod` to both `lido.alexion@gmail.com` and `niti.rn.das@gmail.com` using the current `admin@lidoalexion.com` mailbox. It verified the Laravel-to-GoDaddy SMTP path with authenticated TLS. Production implementation must create and use the dedicated `stox@lidoalexion.com` mailbox instead; do not treat the temporary sender as the product identity.

Before switching the production sender:

1. Create and verify `stox@lidoalexion.com` in the GoDaddy cPanel email service.
2. Confirm SMTP authentication and delivery from the production VPS with the new mailbox.
3. Verify domain SPF, DKIM and DMARC records for the selected sender/service.
4. Update the production mail configuration and sender identity, then send a smoke test and confirm receipt before enabling application notifications.

### 3.4 Environment configuration and secret handling

The deployment `.env` must explicitly contain Laravel mail configuration. Keep matching non-secret keys in `.env.example`; leave the example password blank or clearly placeholder-only. Never commit the real SMTP password, print it in diagnostics, or include it in logs. Restrict access to the production environment file and use the deployment's normal configuration-cache refresh after changes.

Expected production settings after the dedicated mailbox is ready:

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=smtps
MAIL_HOST=bom1plzcpnl502771.prod.bom1.secureserver.net
MAIL_PORT=465
MAIL_USERNAME=stox@lidoalexion.com
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=stox@lidoalexion.com
MAIL_FROM_NAME=StoX
```

Supply `MAIL_PASSWORD` only in the protected production `.env` (or an approved secret store), not in source control. These settings reflect the tested GoDaddy cPanel endpoint; revise the host/port only if the actual mailbox settings change.

### 3.5 Acceptance criteria

- Creating an invitation in the Admin Console sends a setup link to the invited address and shows delivery state; resend/revoke/expiry behavior is safe and auditable.
- Accepting an invitation activates the account once and sends the configured confirmation; expired, revoked, reused and malformed tokens are rejected.
- Access-request decisions and the agreed account/security events generate the correct messages without granting access or changing role through email alone.
- Optional product notifications have explicit event preferences and do not duplicate on retries; mandatory security messages remain enabled.
- SMTP uses authenticated TLS, the dedicated `stox@lidoalexion.com` identity, and environment-based secrets. No credential or secret-bearing link appears in logs.
- Automated tests cover templates, event routing, queue failures/retries, token lifecycle and idempotency using a fake mail transport. A controlled staging/production smoke test verifies real SMTP delivery to nominated recipients.
- Mail-provider failure is visible and recoverable but does not silently block unrelated StoX workflows or falsely mark an invitation email as delivered.

## 4. Assistance roadmap relationship

The assistance epics are intentionally progressive rather than one monolithic chatbot project:

1. **V9-UX-001 — Automation readiness:** turn documented human journeys into stable, testable product workflows and build automated E2E coverage.
2. **V9-UX-002 — Deterministic discovery:** let users find a known “How do I?” journey quickly through typeahead/text matching and open the exact documentation section.
3. **V9-AI-001 — Conversational documentation:** let an LLM retrieve, synthesize and explain the same maintained StoX documentation without gaining write authority.
4. **V9-AI-002 — Agentic actions:** only after governed tool/action contracts exist, allow the assistant to perform explicitly authorized StoX operations.

Typeahead and chatbot may share the same maintained journey/question metadata, but deterministic typeahead must not depend on an LLM. The agentic phase may use the chatbot UX, but read-only retrieval and write/action authority must remain separable capabilities.

## 5. Inherited boundary

The Telemetry Platform remains independently deployable and product-independent. StoX must not absorb Telemetry storage, analytics, dashboards or platform administration into its own codebase.

Telemetry failure must not block StoX business workflows. StoX audit/business evidence remains distinct from telemetry.

AI/ML additions must preserve inherited deterministic, explainable Strategy behavior unless a later explicit product decision supersedes it. Instrument expansion must preserve existing equity behavior non-breakingly unless an instrument-specific specification explicitly changes a shared abstraction.

Documentation-grounded AI must distinguish sourced StoX behavior from model-generated explanation. Model-provider configuration must not weaken authorization, privacy, audit or execution safeguards. Agentic capabilities must use explicit governed actions rather than unrestricted database mutation or arbitrary UI control for money-moving workflows.

Detailed V9 behavior will be frozen during V9 planning against the completed V7/V8 foundations and the then-current StoX implementation.
