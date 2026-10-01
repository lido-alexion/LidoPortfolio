# V9-AI-001 implementation and governed-runtime remediation

Starting implementation checkout: `97e875f9`. During the work, `origin/master` advanced to `3191e80d`; the checkout was fast-forwarded and the AI work was reconciled without overlap.

## Governed runtime remediation

- Python now consumes the Laravel-authoritative budget projection per request and filters exhausted overall/capability/user/path scopes before provider invocation. Python no longer owns an authoritative spend ledger.
- `documentation_chat` injects retrieved maintained StoX evidence into the governed prompt. Accepted answers use an extractive citation contract: every returned quote must be a non-empty exact excerpt from a retrieved source with a valid source ID. Unsupported or invalid output degrades to `grounding_insufficient`.
- Browser-facing SSE is projected through Laravel onto a strict safe-event allowlist. Provider, model, routing trace, usage and raw internal errors remain server-side.
- Read-only scope violations are rejected explicitly. Runtime/provider/budget/grounding failures remain normalized and fail safely.

## V9-AI-001 assistant

- Global authenticated **Ask StoX** drawer.
- Read-only, documentation/journey-first answers with expandable source snippets and links.
- Session-only follow-up memory and bounded visible-page context.
- Deterministic **How do I?** fallback when AI is unavailable or grounding is insufficient.
- Navigation-only actions, contextual prompts, copy, helpful/not-helpful feedback, and clear conversation.
- Provider/model internals are not shown in normal UX.
- Feedback is authorized against the requesting user's `documentation_chat` inference record.
- No account mutation, broker execution, direct browser-to-Python route, hidden account-data tool, or Python database access was added.

## Documentation and journey integration

AI-01 through AI-03 cover grounded help, conversation controls/feedback and degraded recovery. Static documentation generation now regenerates journey metadata and the assistant corpus from maintained sources, and the static checker validates journey anchors.

## Verification

Focused and feature-relevant checks completed during implementation:

- Python AI runtime: 18 tests passed.
- Focused Laravel AI/runtime/projection: 9 tests, 27 assertions passed.
- Node/static JS: 199 tests passed.
- Vitest: 29 files / 108 tests passed in the broad frontend run.
- Focused Chromium assistant journey: mobile 390px and desktop 1440px passed; the degraded deterministic-help journey also passed in the full journey run.
- TypeScript typecheck passed.
- Hosted-path production build passed.
- Static documentation check passed (53 topics).
- OpenAPI check passed (219 operations).
- `git diff --check` passed.

The repository-wide journey run on the unmodified V8 baseline reached 33 passed / 22 intentional skips and one unrelated responsive-shell mobile timeout. The repository-wide backend gate also exposed pre-existing V8 fixture/runtime failures unrelated to AI-001. Temporary local repairs used to diagnose those failures were deliberately excluded from this AI-001 delivery and preserved only on a local safety branch.

The frontend helper's optional browser OS-package installation step requires password-protected `sudo` on this VPS. Existing Playwright Chromium artifacts were sufficient to run the focused assistant journeys directly without sudo.

No production deployment was performed.
