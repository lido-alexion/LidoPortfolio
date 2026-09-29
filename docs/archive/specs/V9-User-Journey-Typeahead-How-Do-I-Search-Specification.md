# StoX V9 User Journey Typeahead / “How Do I?” Search Specification

| Field | Value |
|---|---|
| **Epic** | `V9-UX-002` — User Journey Typeahead / “How Do I?” Search |
| **Version target** | V9 |
| **Status** | FROZEN — implementation-ready |
| **Owner** | Product / Architecture |
| **Parent register** | `docs/archive/specs/LidoPortfolio-V9-Wishlist.md` |
| **Primary source of truth** | `docs/user-journeys/` plus selected approved user-facing help/reference content |
| **Dependency** | Builds on the maintained journey corpus and governance established by `V9-UX-001` |

## 1. Product intent

Provide a fast, deterministic, non-LLM in-product help experience that lets an authenticated StoX user type a natural question such as “How do I create a screener?” or “How do I cancel an order?” and immediately receive concise actionable instructions derived from the authoritative StoX user-journey corpus.

The feature must not merely navigate to documentation. It must textually tell the user what steps to perform, while also offering direct navigation to the relevant StoX page and the full authoritative guide.

This epic is a deterministic assistance layer. It must not execute business actions, mutate StoX data, invoke an LLM implicitly, or become an authorization boundary.

## 2. Scope

In scope:

- a persistent global help/search entry in the authenticated StoX application shell;
- a global keyboard shortcut plus standard clickable access;
- deterministic token/keyword matching across titles, aliases, keywords and approved synonyms;
- search over authoritative user journeys plus selected user-facing help/reference/troubleshooting content;
- concise textual task steps rendered directly in the search/help result;
- exact links to the full corresponding journey/help section;
- safe contextual navigation actions such as “Open Screeners” or “Go to Pending Executions”;
- light current-page context boosts to ranking without hiding otherwise valid results;
- best-match presentation plus a short ranked list of alternative matches;
- material prerequisites and warnings shown inline;
- lightweight explanations of why a result matched;
- deterministic fallback suggestions when no strong match exists;
- explicit future handoff to `V9-AI-001` via “Ask StoX Assistant” once that capability exists, without silently invoking AI;
- deep-linkable and shareable authenticated help-topic URLs;
- bounded per-account recent help-search history with user-controlled clearing;
- light explainable personalization based only on help/search interaction signals;
- lightweight Helpful / Not helpful feedback;
- privacy-conscious aggregated diagnostics for no-match, weak-match, selection and feedback behavior;
- English-only content/search for V9.

Out of scope:

- unauthenticated/global-public help search;
- LLM-generated search results or dynamic AI summarization;
- agentic actions or mutation of StoX state;
- permission-based filtering of search results;
- public help publishing outside authenticated StoX;
- favorites/bookmarks/pinned help topics;
- broad technical/architecture/deployment documentation search;
- full personalized help dashboards;
- multilingual content in V9;
- semantic vector/embedding search as the default ranking engine.

## 3. Global entry and invocation

The help/search entry must be available globally within the authenticated StoX application shell.

It must support both:

1. a persistent clickable UI entry; and
2. a global keyboard shortcut, with `Cmd/Ctrl + K` preferred unless it conflicts with an existing global command.

The interaction must support full keyboard navigation through suggestions and Enter to open/select a result.

The help search is authenticated-only for V9. Login, invitation, reset and other unauthenticated assistance remain with their existing flows.

## 4. Search corpus

The searchable corpus consists of:

1. authoritative user journeys under `docs/user-journeys/`;
2. approved golden-journey content where applicable;
3. selected user-facing help/reference material;
4. selected user-facing troubleshooting guidance.

Do not index general architecture, deployment, engineering-history, internal planning or low-level implementation documents for normal user-facing search.

The human-readable journey/help content remains authoritative. Search metadata exists to expose that content, not replace it.

## 5. Search metadata contract

Each searchable help topic should have structured metadata sufficient to support deterministic discovery. Recommended fields include:

- stable topic/journey identifier;
- canonical question/title;
- aliases and alternate phrasings;
- keywords;
- approved synonyms;
- topic/category;
- relevant StoX route(s);
- concise step markers or machine-readable extraction hints;
- material prerequisites;
- material warnings;
- full-guide anchor/deep link;
- optional related-topic identifiers.

The exact storage representation is implementation-level. Prefer a maintainable format colocated with, generated from, or directly tied to the authoritative documentation so that metadata cannot silently drift away from product guidance.

## 6. Deterministic matching and ranking

Use deterministic token/keyword matching with aliases and synonyms.

The ranking model may include weighted signals such as:

- exact title/question match;
- alias match;
- keyword/token overlap;
- approved synonym match;
- current-page contextual relevance;
- recent help-topic interaction;
- prior Helpful / Not helpful feedback at an aggregated/topic level where appropriate.

Current-page context may boost relevant topics but must never hide otherwise valid matches.

Light personalization may modestly influence ranking using help/search activity only. It must never override strong textual relevance and must remain explainable.

Do not use portfolio holdings, trade history, recommendation state, strategy content or inferred investment intent to personalize help ranking.

Fuzzy typo tolerance, normalization, stemming and similar text-processing improvements are implementation-level and should be added where they improve usability without making ranking opaque.

## 7. Search result presentation

When a user selects or focuses a result, StoX should show:

1. the canonical help question/title;
2. concise but complete essential task steps;
3. material prerequisites and warnings where applicable;
4. a lightweight “why this matched” hint;
5. safe contextual navigation action(s), when relevant;
6. a “View full guide” action pointing to the exact authoritative section;
7. a short ranked list of plausible alternative matches when the query is ambiguous.

The best match should be immediately actionable without forcing the user to open the full documentation.

Long explanations, background material, detailed caveats and uncommon edge cases belong in the full guide rather than the primary inline answer.

## 8. Inline textual steps

The inline answer must tell the user the actual steps to perform. Navigating to a page alone is explicitly insufficient.

Example shape:

> **How do I create a screener?**
> 1. Open Screeners.
> 2. Select Create Screener.
> 3. Add the required conditions.
> 4. Save the screener.
> 5. Run it to review matching stocks.
> **Open Screeners** · **View full guide**

The concise task steps must be derived from the authoritative journey content/metadata rather than independently maintained parallel prose.

Small context-specific presentation adjustments are allowed. For example, if the user is already on the Screeners page, StoX may omit or mark “Open Screeners” as already satisfied. The underlying canonical journey semantics must not change.

## 9. Prerequisites and warnings

Show only material prerequisites/warnings inline, for example:

- Requires Kite connection.
- Requires an approved recommendation.
- Admin permission required.
- This action does not place a trade.
- Cancellation may remain pending until broker confirmation.

Do not overload the concise answer with every caveat from the full documentation.

## 10. Safe navigation actions

Help results may include direct navigation actions such as:

- Open Screeners;
- Open Strategies;
- Go to Pending Executions;
- Open Account Settings.

These actions navigate only. They must not create, edit, approve, cancel, submit, trade or otherwise mutate application state.

Normal StoX authorization still applies after navigation.

This epic must not perform agentic actions. Such behavior belongs to the governed action layer under `V9-AI-002`.

## 11. Permissions behavior

Search discovery intentionally ignores user permissions.

A user may discover guidance for functionality that the current account cannot access. This is informational only and does not grant capability.

Any target route/action invoked from the result must still pass the normal StoX authorization checks. If access is denied, the existing application authorization behavior applies.

Search must not be treated as an authorization boundary.

## 12. No-match and weak-match behavior

When no strong deterministic match exists:

- do not silently fabricate an answer;
- show the nearest deterministic matches if useful;
- offer broader relevant categories or the full journey/help index;
- once `V9-AI-001` exists, show an explicit “Ask StoX Assistant” handoff.

The deterministic feature must never silently invoke the LLM chatbot.

## 13. Deep links and sharing

Selecting a help result must create a stable deep-linkable help state.

The URL must support:

- refresh without losing the selected topic;
- browser back/forward navigation;
- bookmarking;
- copying/sharing the exact topic with another authenticated StoX user.

Shared help links must not embed user-specific portfolio/account context and must not bypass authentication or authorization.

The feature does not expose help topics publicly outside authenticated StoX.

## 14. Help history and personalization

Persist a bounded recent help-search/history list per account.

Requirements:

- keep only a limited recent history appropriate for convenience;
- allow the user to clear it;
- do not retain unlimited search history;
- do not mix help-history signals with portfolio/trading behavior;
- use history only for convenience and modest ranking assistance;
- never hide valid results because of personalization.

Exact retention count/time window is implementation-level; choose a conservative bounded default.

No favorites/pinning capability is included in V9-UX-002.

## 15. Feedback

Each displayed help answer should provide a lightweight **Helpful / Not helpful** control.

Use feedback to identify:

- weak ranking;
- missing aliases/synonyms;
- stale instructions;
- missing user journeys/help topics.

Feedback must not automatically rewrite content, modify ranking rules without governance, or trigger model training behavior within StoX.

A free-text feedback workflow is not required for this epic.

## 16. Diagnostics and analytics

Collect privacy-conscious aggregated diagnostics sufficient to improve deterministic help quality, including:

- queries producing no useful result;
- weak/low-confidence matches;
- selected topics;
- aggregate Helpful / Not helpful rates;
- high-frequency search terms/topics.

Do not join these analytics with portfolio/trading behavior to infer intent.

Avoid retaining unnecessary raw per-user query history beyond the bounded user-facing recent-history feature.

Where V8 OpenTelemetry instrumentation is available, prefer emitting appropriately redacted/pseudonymous help-search telemetry through the established StoX telemetry pipeline rather than building a separate analytics subsystem.

## 17. Content lifecycle and governance

Maintain one continuously updated help corpus rather than separately versioning help content per StoX release.

The maintained journey/help corpus is the current authoritative guidance and must be kept synchronized with product behavior through the established journey-governance process.

Rules:

- behavior-changing journey updates follow the PO-approval rules established by `V9-UX-001`;
- search/help metadata must be updated with the authoritative journey when behavior changes;
- generated/indexed search artifacts should be rebuilt as part of normal deployment/build processes;
- stale search metadata must not survive after its source topic is removed or renamed.

## 18. Language

V9-UX-002 is English-only.

Do not introduce a parallel multilingual content architecture solely for this epic. Implementation should avoid unnecessary hard-coding that would make future localization impractical, but no translation workflow is required in V9.

## 19. Accessibility and responsive behavior

The global help/search interaction must work on desktop and mobile layouts supported by StoX.

Minimum expectations:

- keyboard accessibility on desktop;
- focus management when opening/closing search;
- semantic labels for input/results/actions;
- screen-reader-readable result structure;
- touch-friendly mobile result/actions;
- no keyboard trap in overlays/drawers/dialogs.

Exact presentation component (popover, drawer, dialog, dedicated panel) is implementation-level. Prefer the pattern that best preserves user context and works reliably on mobile.

## 20. Recommended implementation architecture

Implementation-level decisions are delegated to engineering/architecture. Recommended defaults:

- build a deterministic search index from structured journey/help metadata during build/deploy or application startup;
- keep the index small and local to the application unless corpus size later justifies a dedicated search service;
- use normalized tokenization, aliases, synonyms, typo tolerance and weighted deterministic scoring;
- use stable topic IDs and route anchors;
- derive concise steps from structured journey metadata/content rather than duplicating prose;
- cache the index safely and invalidate/rebuild when help content changes;
- add unit tests for ranking/scoring and integration/E2E tests for the help interaction;
- make ranking deterministic for the same query/context/history state;
- expose diagnostic scoring details only to engineering logs/debug tooling, not the normal user UI.

Do not add embeddings, vector databases or LLM dependency unless a later explicit epic changes the architecture.

## 21. Acceptance criteria

V9-UX-002 is complete when all of the following are true:

- authenticated users can invoke help globally from the application shell;
- clickable and keyboard-first entry paths both work;
- title/alias/keyword/synonym search is deterministic and predictable;
- the best match presents concise complete essential steps directly in-product;
- relevant material prerequisites/warnings appear inline;
- contextual ranking can improve relevance without hiding valid results;
- users can see a lightweight explanation of why a result matched;
- ambiguous searches show alternatives;
- no-match behavior provides deterministic fallback paths;
- safe direct navigation actions work without performing business mutations;
- search remains informational and ignores permissions while target routes retain normal authorization;
- full-guide links target the exact authoritative journey/help section;
- selected topics are deep-linkable, refresh-safe, browser-history-safe and shareable between authenticated users;
- bounded recent per-account history works and can be cleared;
- light help-only personalization works without using portfolio/trading intent;
- Helpful / Not helpful feedback works;
- privacy-conscious aggregated diagnostics are available;
- English-only content/search behavior is implemented;
- no favorites/pinning UI exists;
- no LLM is required or silently invoked;
- automated tests cover ranking, search interaction, deep links, history, feedback and important failure/no-match states.

## 22. Frozen PO decisions

The following product decisions are frozen for this epic:

1. Global help/search entry available across authenticated StoX.
2. Deterministic token/keyword matching with aliases and synonyms.
3. Selecting a result shows textual steps and preserves easy navigation to the exact detailed journey section.
4. Inline answer contains complete concise task steps, not merely a short summary.
5. Current-page context may influence ranking but never hide valid results.
6. No-match behavior shows deterministic fallbacks and later an explicit `V9-AI-001` handoff rather than silently invoking AI.
7. Search corpus includes user journeys plus selected user-facing help/reference/troubleshooting content.
8. Results lightly explain why they matched.
9. Results may provide safe navigation actions but may not perform the underlying task.
10. Inline steps may adapt presentation slightly to current context while preserving canonical semantics.
11. Include lightweight Helpful / Not helpful feedback.
12. Allow light explainable help-only personalization.
13. Persist bounded recent help-search history per account with user-controlled clearing.
14. English only for V9.
15. Provide both clickable access and a global keyboard shortcut with keyboard navigation.
16. Collect privacy-conscious aggregated diagnostics for failed/weak searches and result usefulness.
17. Show the best match with actionable steps plus a short ranked alternative list.
18. Show material prerequisites/warnings inline.
19. Derive concise answers from authoritative journey content/metadata rather than separately maintained prose.
20. Search discovery ignores permissions; normal authorization still applies to target product routes/actions.
21. Help search is authenticated-only.
22. Selected help state is deep-linkable and browser-history-safe.
23. Maintain one continuously updated help corpus rather than per-release help versions.
24. Help-topic links are shareable between authenticated StoX users without carrying user-specific context.
25. Do not add favorites/pinning for help topics.
