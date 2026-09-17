# Knowledge And Documentation

## 1. Purpose And Scope

This document owns contextual notes, private/shared wiki knowledge, page context, documentation/help serving, and knowledge search/navigation. Frontend placement and responsive shell behavior belong in [Frontend And Navigation](./frontend-and-navigation.md); authentication, profile ownership, and public capability tokens belong in [Administration, Security, And API](./administration-security-api.md).

## 2. Knowledge Domains

StoX has four distinct concepts:

- **Contextual notes:** short user-owned notes keyed to an explicit application context.
- **Private wiki/knowledge:** profile-owned, hierarchical Markdown pages and revisions.
- **Shared/public wiki:** capability-token read access to one shared wiki page and its explicitly attached assets.
- **Product documentation/help:** static/generated product-help assets, derived from the in-app help source.

They must not accidentally inherit each other's ownership, sharing, or mutation semantics.

## 3. Contextual Notes

Contextual notes are persisted user-owned records for a stable page/context key, optionally scoped to a portfolio profile and subject identity. Creation updates-or-creates the record at its user/profile/context/subject identity; update/delete require the same owning user. The shell exposes Notes through the right utility rail/overlay on desktop and transformed mobile presentation.

**Current implementation anchors:** `ContextualNote`, `ContextualNoteController`, `ContextualNotesPane`, `ContextualNotesPane` shell integration, and `V6ContextualNotesTest`.

## 4. Page Context Identity

Notes bind to `context_key`, optionally `subject_type` and `subject_id`, rather than transient React component state. Keys are constrained to stable identifier characters and make a note retrievable after remount, navigation, layout change, or device change. Route/entity context should use deterministic documented keys; an ephemeral UI selection alone is not sufficient ownership identity.

## 5. Note Ownership

Contextual notes are scoped to `user_id`; optional profile IDs must belong to that user or fail non-disclosing `404`. Foreign note mutation also fails `404`. They are not public wiki content, and a personal API token must satisfy the contextual-note scope rules defined in Administration/Security. Admin does not silently acquire Investor notes by role.

## 6. Wiki Model

Private wiki pages are profile-owned Markdown documents arranged in a hierarchy/tree. A page has stable identity, title/slug/path context, Markdown source, rendered/sanitized HTML, revisions, parent/order placement, and explicitly attached Knowledge images. Managed wiki links use stable page identity so rename/move/base-path change does not break internal managed references.

**Current implementation anchors:** `WikiPage`, `WikiPageRevision`, `WikiPageService`, `WikiMarkdownRenderer`, `WikiPage`, and `KnowledgeImage`.

## 7. Wiki Lifecycle

Private wiki supports create, update/edit, preview, move/reorder, revision inspection/restore, image attach, export page/branch/wiki, and delete. The current model retains revision evidence; delete/move/restore behavior must remain profile-scoped. No separate archive state is documented for wiki pages, so archive semantics must not be invented.

## 8. Wiki Sharing

The share lifecycle is **private -> active share token -> public read -> revoked**, with regeneration revoking the prior active token then creating a new token. A page may have one active share record; sharing is explicit and profile-authorized, never inherited from wiki hierarchy visibility.

## 9. Share Token Security

Share tokens are 64-character random bearer capabilities. Lookup uses a SHA-256 token hash; recoverable encrypted token storage supports authorized share URL presentation. Revocation marks active share records revoked and public lookup then fails. The capability authorizes only the selected page's title and sanitized rendered Markdown/managed safe links; it does not authorize private hierarchy/tree, notes, search, edit controls, revision history, portfolio resources, or unrelated pages.

**Current implementation anchors:** `WikiShareService`, `WikiPageShare`, `WikiShareController`, and `V5WikiFoundationTest`.

## 10. Shared Images And Attachments

Public shared images are authorized by the same active page-share capability and must be attached to that shared page. The public image endpoint checks token hash/revocation and page-image relationship; it does not expose arbitrary private Knowledge image-library files or unrelated page attachments.

## 11. Knowledge Search

Knowledge search combines profile-scoped notes and wiki pages, returning the kind of hit and useful hierarchy/context. Notes support text/tag search/filter/sort; wiki search uses private page context. Public share endpoints do not expose general search or private tree discovery.

**Current implementation anchors:** `KnowledgeBoardSearchController`, `KnowledgeBoardNoteService`, `WikiPageService`, `KnowledgeBoardPage`, and `KnowledgeBoardTest`.

## 12. Help And Documentation Surface

In-app documentation is surfaced by Documentation pages, contextual/header help, and static `/docs` pages. `appDocumentation.js` supplies topic metadata and help links; `HeaderHelpButton` links a relevant topic. Help availability is contextual where a documented topic exists, not proof that every route has bespoke help.

## 13. Source Documentation Vs Served Help

`docs/current/**` is the authoritative engineering/product contract. `app/resources/js/src/data/appDocumentation.js` is the source for in-app help topic content; `app/public/docs/**` is generated/static served help output. Archived specs are historical evidence. Generated help is a product-facing derivative and must not become a competing authority over current docs or source data.

## 14. Documentation Generation

`node app/scripts/generate-static-docs.mjs` generates static HTML into `app/public/docs/{keyword}.html`, an index, the downloadable trading-artifact guide, and an OpenAPI copy when available. The production build invokes this generator. Regenerate after changing in-app documentation source or the current artifact guide; static output drift should be detected through build/test verification rather than manually edited as an authority.

**Current implementation anchors:** `app/scripts/generate-static-docs.mjs`, `app/package.json`, `appDocumentation.js`, and documentation help tests.

## 15. Documentation Serving

Static help is served from `app/public/docs` under `/docs`, outside normal SPA product routes. The Documentation page can render/link topic help; static pages remain readable without sign-in. Laravel/API routing must preserve API/static `404` behavior and must not route unknown API paths to SPA HTML.

## 16. Current Docs Authority

`docs/current/**` defines current accepted behavior, technical contracts, implementation anchors, and alignment notes. It is maintained intentionally and should be updated after accepted product decisions, not regenerated from code. The source of truth for a specific product-help screen is the in-app help metadata; the source of truth for engineering behavior is current documentation plus verified implementation.

## 17. Archive Boundary

`docs/archive/**` holds version specs, prior decisions, implementation histories, and source archaeology. It is not automatically authoritative today. Archived accepted detail may be promoted into `docs/current` when it remains unsuperseded and implementation-significant; audit artifacts record uncertainty rather than silently rewriting history.

## 18. Contextual Help

Contextual help maps application/page context to documented topic metadata and links. It explains screens and contracts but does not grant access, alter state, or determine note ownership. Missing/mismatched help should be treated as a documentation-link issue, not a reason to fabricate a route/context key.

## 19. Knowledge Data Model

`ContextualNote` is user/context/profile/subject-scoped. `KnowledgeNote`, `KnowledgeTag`, and `KnowledgeImage` form the profile-owned Knowledge Board. `WikiPage` and `WikiPageRevision` hold hierarchical Markdown/revision content. `WikiPageShare` holds page-bound hashed/encrypted capability token state. Rendered HTML, search results, exports, and public pages are derived views, not primary content ownership records.

## 20. API Contract

- **Contextual notes:** `/api/contextual-notes` CRUD, with authenticated user/profile ownership and contextual-notes token scope where applicable.
- **Knowledge Board:** `/api/knowledge-board/notes*`, tags, images, bulk/duplicate/search.
- **Private wiki:** `/api/knowledge-board/wiki/pages*`, preview/move/revisions/restore/share/images/export.
- **Public wiki:** `GET /api/wiki/shared/{token}` and `/api/wiki/shared/{token}/images/{image}` are guest capability reads only.
- **Help/docs:** static `/docs/*` assets and in-app documentation routes; they do not expose private knowledge APIs.

## 21. Services And Ownership

`KnowledgeBoardNoteService`, tag/image services, and palette catalog own board content and profile enforcement. `WikiPageService` owns private page lifecycle; `WikiMarkdownRenderer` produces sanitized private/public render output; `WikiShareService` owns capability lifecycle; `WikiExportService` owns export. Static-doc generation owns served-help derivation; current documentation remains authored contract material.

## 22. Security And Rendering Invariants

- Private note/wiki/profile data must not cross profile/user boundaries.
- Contextual notes bind to stable explicit context, never transient component memory.
- Markdown is canonical wiki source; HTML is derived and sanitized.
- Public share is page-scoped capability read, not graph traversal or authentication.
- Shared images must be page-attached and capability-authorized.
- Help/static docs must never expose authenticated/private content merely because they are public.

## 23. Error And Recovery Semantics

| Condition | Expected behavior |
| --- | --- |
| Foreign profile/note/page | Non-disclosing `404`; no cross-user/profile read or mutation. |
| Invalid contextual key | Validation failure; do not create ambiguous context state. |
| Revoked/unknown share token | Public `404`; no page/title/tree leak. |
| Unrelated shared image | Public `404`; do not serve image. |
| Broken managed wiki link | Render safe missing/broken-link behavior; do not resolve by title guessing. |
| Generated help drift | Regenerate through documented build process; do not hand-edit output as authority. |

## 24. Test And Verification Anchors

`V6ContextualNotesTest` proves contextual note CRUD/ownership/context behavior. `KnowledgeBoardTest` and `KnowledgeBoardImageTest` prove profile-scoped notes/tags/images. `V5WikiFoundationTest` proves hierarchy/privacy/share-token revocation/public rendering/image isolation. `appDocumentation*Help.test.mjs` anchors help-topic content/link behavior. Static-help generator/build coverage and complete right-rail/mobile runtime reachability remain implementation-audit verification areas.

## 25. Debugging Guide

| Symptom | Inspect |
| --- | --- |
| Note missing/wrong page | `context_key`, subject/profile scope, owner, `ContextualNoteController`, shell context source. |
| Foreign knowledge visible | Active profile/controller service scope and public/private route boundary. |
| Shared page/image leak | `WikiShareService`, token hash/revocation, renderer public mode, page image relation. |
| Wiki link broken | Stable page identity, parent/move history, renderer output, private/public mode. |
| Help missing/stale | `appDocumentation.js`, generator/build, `/docs` asset, HeaderHelpButton/topic link. |

## 26. Implementation Alignment Notes

Verify under the V1-V7 audit: complete desktop/mobile contextual Notes mounting and accessibility, exact context-key catalog consistency, token-scope coverage, wiki archive/deletion UI semantics, public rendering/link edge cases, static help deployment/build drift, and full documentation search/navigation reachability.

## 27. Historical Context

Knowledge Board originated as V2 F144; V5 added linked Markdown Wiki/public sharing; V6 added contextual notes. Current behavior is defined by this document, its linked current docs, and verified implementation rather than archived feature chronology.
