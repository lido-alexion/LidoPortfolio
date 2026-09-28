# V9-AI-001 — Documentation-Grounded StoX Chatbot Specification

| Field | Value |
|---|---|
| **Epic** | V9-AI-001 |
| **Feature** | Documentation-Grounded StoX Chatbot |
| **Status** | FROZEN / IMPLEMENTATION-READY |
| **Version** | V9 |
| **Parent** | V4-FEAT-017 — AI Platform & Governance |

## 1. Purpose

Add a read-only StoX assistant that answers product-help questions and explains StoX data/analytics already visible to the authenticated user. The assistant must be grounded in maintained StoX documentation and user journeys, inherit the shared AI platform/governance contract from V4-FEAT-017, and remain strictly non-mutating in this epic.

This feature is not a general-purpose investment-advice chatbot and does not independently query arbitrary account data.

## 2. Scope

The assistant may:

- answer StoX “how do I?” and product-usage questions;
- explain documented StoX metrics, charts, scores, fields, statuses and analytical outputs already visible to the user;
- explain documented provenance or meaning where available;
- use safe current-page context to resolve references such as “this score” or “this chart”;
- use current visible page data only for explanation;
- maintain conversational context within the current chatbot session;
- provide safe deep links/navigation to relevant StoX pages, tabs, sections and documentation;
- suggest contextual follow-up questions;
- expose sources and grounding quality;
- collect simple answer feedback.

The assistant must not:

- mutate StoX data;
- approve/reject recommendations;
- place, modify or cancel trades;
- change settings or artifacts;
- query arbitrary account data beyond the currently visible page context/data;
- silently infer unsupported product behavior;
- answer from general model knowledge when StoX grounding is insufficient.

## 3. Grounding hierarchy

Retrieval priority is:

1. authoritative `docs/user-journeys/` content for task/how-to questions;
2. supporting StoX product/user documentation for concepts, fields, metrics, charts, scores and provenance;
3. other explicitly approved user-facing StoX reference content where relevant.

Technical/internal documentation must not override authoritative user-journey behavior where the two differ.

## 4. Grounding requirement

Every substantive answer must be grounded in retrieved StoX sources.

If the retrieved evidence is insufficient, the assistant must not invent or fill gaps from general model knowledge. It should:

- state that StoX documentation does not provide enough evidence;
- surface the closest relevant sources when available;
- optionally direct the user to the deterministic V9-UX-002 “How Do I?” search.

## 5. Source presentation

Every chatbot answer must expose the sources used.

The UI must provide:

- source title;
- relevant document/journey section;
- navigable link to the authoritative StoX source;
- expandable **Sources used** area;
- a concise supporting snippet/excerpt for each source.

Source snippets are for transparency and must remain concise.

## 6. Grounding-quality indicator

Each answer must show a simple non-numeric grounding state:

- **Grounded** — directly supported by StoX documentation/user journeys;
- **Partially grounded** — some supporting evidence exists but the answer includes limited inference/synthesis;
- **Insufficient documentation** — reliable grounded answer cannot be produced.

Do not show pseudo-precise numeric confidence percentages.

## 7. Current-page context

The global assistant may automatically receive safe current-page context, including where applicable:

- current route;
- visible entity identifiers;
- selected tab/section;
- currently displayed metric/score/chart identifiers;
- current visible values needed to explain what the user is looking at.

This context is explanatory only. It does not grant arbitrary read access to the account.

## 8. Visible data boundary

The assistant may explain currently visible/loaded StoX data such as:

- displayed scores;
- metric values;
- chart series/labels;
- recommendation/status fields;
- visible portfolio/account values;
- page-specific analytical outputs.

It must not independently fetch arbitrary historical/account/private data through hidden read tools in this epic.

## 9. Conversation model

The assistant maintains context only within the active chatbot session.

Required behavior:

- natural follow-up questions may reference previous turns;
- retrieved sources and prior page context may be reused where still valid;
- **New chat / Clear conversation** resets the in-session context;
- cross-session conversation persistence is out of scope for V9-AI-001.

## 10. Entry point and interaction surface

Provide a persistent global assistant entry throughout authenticated StoX.

Primary UX:

- button/icon available across authenticated pages;
- opens a side panel/drawer;
- user remains on the current page;
- current-page context remains available to the assistant;
- responsive/mobile-friendly behavior is required.

A dedicated full-page chatbot experience is not required by this epic.

## 11. Navigation actions

Answers may include safe navigation/deep-link actions, such as:

- open Screener;
- open Strategy;
- open Kite/account settings;
- jump to a Stock Details section/tab;
- open an exact user-journey/help section.

These actions must be navigation-only. They must not perform mutations.

## 12. Follow-up suggestions

The assistant should show a small set of contextual follow-up prompts derived from:

- the current answer;
- current page context;
- retrieved StoX documentation.

Examples include:

- “How is this score calculated?”
- “Where can I change this setting?”
- “What does the 3-month value mean?”

Suggestions must remain within the read-only scope.

## 13. Feedback

Each answer should expose:

- **Helpful**;
- **Not helpful**.

When marked Not helpful, allow an optional short comment.

Feedback must be linked to the answer’s retrieval/model/routing metadata so Admin/engineering can evaluate quality later.

## 14. Copy behavior

Provide an explicit **Copy** action for an answer.

The copied content should include:

- answer text;
- relevant StoX source links.

Public/shareable conversation links are out of scope.

## 15. Shared AI-platform integration

V9-AI-001 must consume V4-FEAT-017 instead of implementing its own provider infrastructure.

It must therefore use the shared:

- capability registry;
- capability-specific ordered inference routing;
- provider adapters;
- streaming support;
- prompt registry/versioning;
- structured logging and routing trace;
- budgets/cost controls;
- circuit-breaker/health handling;
- concurrency/service-class rules;
- configuration/governance model.

Recommended capability identity: `documentation_chat` or equivalent code-defined stable ID.

## 16. Streaming

User-facing chatbot responses should use the shared streaming capability where the selected inference path supports it.

The UX must still handle non-streaming providers cleanly through the common infrastructure.

## 17. Failure behavior

If all configured inference paths fail:

- do not fabricate an answer;
- surface a clear temporary-unavailability/degraded-state message;
- preserve the ability to use deterministic V9-UX-002 help search;
- expose only user-appropriate failure information in the chatbot UI;
- retain full operational routing/failure detail in Admin logs through V4-FEAT-017.

Feature-level external-AI clipboard fallback may be used where later explicitly designed, but no generic internal/system prompt exposure is required by this chatbot epic.

## 18. Authorization

The assistant is authenticated-only.

Normal StoX authorization continues to govern:

- which pages the user may navigate to;
- which visible data the user can see;
- which routes/deep links are accessible.

The assistant must not bypass normal authorization simply because a source mentions a feature or page.

## 19. Deterministic-help relationship

V9-AI-001 complements but does not replace V9-UX-002.

- V9-UX-002 remains deterministic and non-LLM.
- Both may share maintained journey/help metadata.
- The chatbot may recommend or deep-link into deterministic help results.
- Deterministic help must continue functioning independently of AI availability or budget.

## 20. Acceptance criteria

The epic is complete only when:

1. the authenticated global assistant drawer is available across supported StoX pages;
2. user-journey-first retrieval is implemented;
3. every substantive answer exposes authoritative source references;
4. insufficient grounding does not produce unsupported answers;
5. current-page context and current visible data can be explained without arbitrary account-data access;
6. in-session conversational follow-up works and can be reset with New chat/Clear conversation;
7. navigation actions are safe and non-mutating;
8. grounding-quality states are shown without numeric confidence;
9. expandable source snippets are available;
10. Helpful/Not helpful feedback is captured with answer metadata;
11. answers can be copied with source links;
12. contextual follow-up suggestions are available;
13. the feature uses V4-FEAT-017 routing, logging, budgets, prompt governance and health/failover infrastructure;
14. no mutation, recommendation approval or trade execution capability is exposed.

## 21. Frozen product decisions

The following decisions are frozen for V9-AI-001:

- product help plus explanation of currently visible StoX analytics;
- user journeys first, then supporting product documentation;
- citations always visible;
- insufficient grounding means no invented answer;
- safe current-page context is included automatically;
- conversational memory is session-only;
- global persistent side-panel entry;
- safe deep links/navigation allowed;
- visible-page data only, no arbitrary account-data querying;
- contextual follow-up suggestions;
- non-numeric grounding indicator;
- expandable source snippets;
- Helpful/Not helpful feedback;
- explicit Copy action;
- New chat/Clear conversation;
- strictly read-only behavior.
