# V9-AI-001 implementation and runtime remediation

Starting revision: `97e875f9` (`origin/master`). The pre-existing prompt files and `V9-AI-001-PLATFORM-BLOCKERS.md` were preserved.

## Runtime contract remediation

- Python loads a request-scoped Laravel budget projection and checks overall, capability, path, configured extra scopes and authenticated-user scopes before provider invocation. Canonical path order is preserved. The Python spend/reset ledger API was removed. Laravel records reported spend even when it crosses a hard limit; unlimited budgets are not projected as zero limits.
- The governed active prompt receives stable source IDs, titles, paths, sections, snippets and relevance, together with bounded conversation/page context. Accepted documentation answers use an extractive contract: each quote must occur exactly in its identified retrieved source. Unknown citations, unsupported prose and missing evidence degrade to `grounding_insufficient`. Refusals and insufficient answers emit audit records for feedback.
- Configured provider streaming is consumed privately and buffered until evidence validation. Laravel parses SSE frames and allowlists browser fields, removing provider/model/path, routing trace, usage and raw errors.

Budget enforcement uses authoritative snapshots, not concurrent spend reservations. The extractive answer contract intentionally does not generate free-form synthesis or undocumented interpretations.

## Assistant

The authenticated global drawer provides documentation answers, expandable source excerpts/links, non-numeric grounding states, session-only follow-ups, bounded route/tab/visible-dashboard context, deterministic How do I? fallback, navigation-only links, contextual prompts, Copy with source URLs, Helpful/Not helpful feedback and Clear conversation with cancellation. Feedback is authorized against the user's inference record. No domain mutation, broker execution, hidden account-read tool, direct browser-to-Python route or Python database access was added.

Retrieval prioritizes maintained numbered journeys, then the generated approved product documentation registry. The corpus has 767 stable product source IDs; all 839 retrieved chunks have resolvable generated documentation links. Documentation builds now generate journey pages after the static output directory is rebuilt; journey anchor mismatches were corrected. Stale corpus content and missing journey anchors fail static checks.

AI-01 through AI-03 document grounded help, session controls/feedback and degraded recovery. Desktop/mobile assistant browser tests include axe accessibility and focus return. The existing mobile route sweep was split into independent route tests with its assertions retained, eliminating an aggregate seven-navigation timeout.

## Pre-existing MySQL gate repairs

The broad gate exposed existing failures independently reproduced outside the AI tests:

- The screener migration attempted to drop a unique index while MySQL still needed it for a foreign key, swallowed that error, and left obsolete date-only uniqueness in place. An additive repair removes it only after confirming the version-specific replacement exists. Historical version results remain intact.
- The dual-listing repair queried the removed holding `user_id`; it now matches the canonical `profile_id` and `owner_key`. A regression test proves cross-portfolio holdings remain separate.
- Broker, ML and screener tests now refer to generated fixture IDs rather than assuming IDs start at one. Existing behavioral assertions are retained.

These are verification repairs, not new V8 features or changes to frozen authorization/versioning semantics.

## Verification

- Python runtime: 18 passing tests.
- Focused Laravel assistant/runtime/projection: 9 passing tests, 27 assertions.
- Focused MySQL baseline repairs: 20 passing tests, 79 assertions.
- JavaScript: 199 Node tests and 110 Vitest tests passing.
- Full Chromium journeys: 40 passed, 46 explicit viewport-specific skips.
- Typecheck, hosted-path production build, static documentation/corpus/source-link checks, OpenAPI check and migration portability checks passed.
- Full MySQL backend verifier: passed, 2,005 tests (2,004 passed; one existing opt-in ML-campaign skip), 12,770 assertions. Its 15 application Python tests and OpenAPI check also passed.

System PHP lacked `pdo_sqlite`; the required extension was loaded from a local package without sudo. The backend verifier uses isolated MariaDB on port 3318, not SQLite as a substitute. The frontend verifier passed JS/typecheck/build but its `playwright install --with-deps` step required password-protected sudo. Exact package-selected Chromium artifacts were installed and verified, and the entire repository browser suite was run directly with those artifacts. That OS package-install step is environment-limited, not reported as passed.

During verification, origin/master advanced to `3191e80d` (NSE source bootstrap). The branch was fast-forwarded without conflicts; the four added upstream tests passed separately on the integrated MySQL working tree (11 assertions). No existing implementation files overlapped.

No manual deployment was performed. Final revisions and remote/working-tree state are reported with the delivery.

The required commit/push script created local commits, but HTTPS push has no configured GitHub credentials and SSH authentication also fails. The checkout uses the repository-local author `Codex <codex@localhost>`. Remote publication is pending working Git authentication; no branch protection or CI gate was bypassed.
