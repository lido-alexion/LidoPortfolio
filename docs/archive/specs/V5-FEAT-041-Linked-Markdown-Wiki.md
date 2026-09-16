# V5 FEAT-041 — Linked Markdown Wiki

**Status:** COMPLETE / VERIFIED 2026-09-11
**Feature:** V4-FEAT-041  
**Priority:** P2

## Problem

Knowledge Board already provides portfolio-scoped research Notes, tags, images and Markdown-capable editing, but Notes are intentionally lightweight and flat/tag-oriented. V5 needs a complementary durable wiki for structured long-form portfolio knowledge: hierarchical pages, stable cross-page links, Markdown-native authoring, safe rendering, search, history, portability and controlled public sharing.

The Wiki must extend Knowledge Board without replacing or changing the semantics of existing Notes, and without exposing private portfolio structure when a page is shared publicly.

## Frozen behaviour

### Ownership and relationship to Knowledge Board

- Knowledge Board remains the root/landing experience.
- Wiki Pages are a new content type alongside existing Notes; Notes are not migrated or converted.
- Wiki content remains portfolio-scoped. Switching active portfolio switches the private Wiki hierarchy.
- Notes retain their existing tag-based organization. Wiki Pages use hierarchy, internal links and search; Wiki tags are out of scope for V5.
- Existing Knowledge Board behaviour must remain backward compatible.

### Markdown-native pages

- Markdown is the canonical source of truth for every Wiki Page. Rendered HTML is derived presentation only.
- V5 provides Markdown editing and preview rather than a separate WYSIWYG representation.
- Normal Markdown capabilities such as headings, lists, tables, code blocks, blockquotes, links and images are supported subject to safe rendering.
- Generated HTML must not become the authoritative stored content.
- Autosave may be used, but persistence remains Markdown-native.

### Identity, hierarchy and navigation

- Every page has an immutable internal page identity independent of title, slug or hierarchy.
- A page also has title, derived human-readable slug, optional parent and sibling display order.
- Root-level Wiki pages sit directly beneath Knowledge Board.
- Hierarchy is parent-based; a page cannot become its own ancestor.
- Private Wiki UI provides a persistent expandable/collapsible tree, current-page/ancestor indication and hierarchy-derived breadcrumbs beginning at Knowledge Board.
- Users can create root pages or child pages.
- Users can reorder/reparent pages by drag/drop and through an explicit Move control.
- Duplicate titles are allowed; hierarchy provides context.
- No artificial product-level nesting-depth limit is required, though implementation may impose a generous safety limit.
- Rename or move may change display path/slug but must not break StoX-managed links.

### Internal Wiki links

- The editor provides an Insert Wiki Link flow that lets the user search/select a target page instead of constructing deployment URLs or IDs manually.
- StoX-managed Wiki links resolve through stable page identity and remain valid across page rename, move and configured application base-path changes.
- Default rendered link text follows the target's current title; custom link text is allowed.
- Authenticated in-app Wiki navigation uses SPA navigation where practical.
- A missing/deleted/unavailable target is rendered clearly as a broken/missing Wiki link rather than silently routing to a generic 404.
- Private broken-link UX provides a route back to the Knowledge Board hierarchy.
- Ordinary external Markdown links continue to work subject to security policy.

### Images and attachments

- Wiki Pages support portfolio-scoped images and should reuse/extend existing Knowledge Board image infrastructure where practical.
- Image insertion must not require users to construct storage URLs manually.
- Paste/drag upload may upload and insert an image automatically.
- Page rename/move must not break managed images.
- External Markdown images may be rendered only under the application's sanitization/security policy.
- General file attachments or a portfolio file repository are not part of V5.

### Revision history

- Meaningful saved Wiki changes create immutable revisions.
- Revision history preserves Markdown content, title, timestamp and relevant hierarchy metadata where useful.
- Users can view previous revisions and compare a historical revision with the current page.
- Restoring an older revision creates a new current revision; later history is not erased.
- Rename/move operations are auditable.
- Autosave must be debounced/coalesced so normal typing does not create meaningless revision floods.
- Revision history is not a recycle bin and does not provide branching, merging, approvals or collaborative version-control workflows.

### Search

- Knowledge Board search covers both existing Notes and Wiki Pages within the active portfolio.
- Results clearly identify Note versus Wiki Page.
- Wiki search covers at least title and Markdown content and shows enough hierarchy context to disambiguate duplicate/similar titles.
- Selecting a Wiki result opens that page.
- Existing Note archive/search semantics remain unchanged.
- Search remains deterministic/text-based in V5; semantic/vector/AI retrieval is not part of FEAT-041.

### Deletion

- A leaf page may be permanently deleted after confirmation.
- A page with descendants cannot be casually deleted. The user must first move/reparent children or explicitly choose recursive branch deletion.
- Recursive deletion requires stronger confirmation and must disclose the number of affected pages.
- Children are never silently reparented.
- Links to deleted pages remain references and render as broken Wiki links; they are not silently removed or rewritten.
- Wiki deletion never deletes existing Knowledge Board Notes.
- No Wiki trash/recycle-bin subsystem in V5.

### Markdown portability and export

- Stored content remains ordinary, human-readable Markdown. StoX-specific stable-link representation must be minimal and understandable enough not to turn the source into opaque proprietary content.
- A page can be exported/downloaded as `.md`.
- A branch or the complete portfolio Wiki can be exported as a folder/ZIP containing Markdown pages and locally referenced managed images.
- Export rewrites StoX stable internal links into appropriate portable relative Markdown links where the targets are included, so the exported Wiki remains navigable outside StoX.
- Export is a snapshot, not filesystem/Git synchronization.
- Bulk Markdown import and bidirectional synchronization are out of scope for V5.

### Public sharing

- Wiki Pages are private by default.
- The owner can explicitly choose Share publicly for an individual page.
- Public sharing creates an unguessable bearer-token URL. Knowledge of portfolio/page IDs alone must never authorize anonymous access.
- Anyone possessing a valid public URL can view that page without logging into StoX.
- Public access is read-only.
- Public view exposes only the shared page's permitted rendered content/title/images and safe links. It must not expose the private Knowledge Board tree, portfolio hierarchy, private breadcrumbs, Notes, Knowledge search, editing controls, revision history or other private portfolio information.
- Sharing a page does not implicitly share its parent, children, descendants or linked pages.
- A Wiki link from a public page is navigable only when the target page is independently publicly shared. Otherwise it is rendered as private/unavailable and must not leak target content or private hierarchy.
- Rename/move does not invalidate the public URL.
- Owner can Stop sharing at any time; the existing token becomes invalid immediately.
- Re-sharing after revocation generates a new token/URL; the old URL remains invalid.
- Regenerate public link invalidates the old token and creates a new one without recreating the page.
- Public links do not expire automatically in V5.

## Architecture

- Extend the existing Knowledge Board domain rather than creating an unrelated document subsystem.
- Keep existing portfolio-scoped Notes/tags APIs and semantics intact; Wiki Pages/revisions/shares are separate domain records/resources.
- Page identity must be immutable and independent of hierarchy/path. Parent relationship and display order represent hierarchy.
- Canonical persistence stores Markdown, not rendered HTML. HTML is rendered on demand or treated only as a disposable cache.
- Internal Wiki-link representation must resolve to immutable page identity through a central resolver that generates base-path-aware application routes.
- Public sharing uses a separate anonymous resolver keyed by a cryptographically strong opaque token. Store tokens using an appropriate one-way/hash or otherwise secure representation; never treat page IDs as public authorization secrets.
- Anonymous rendering must use a deliberately restricted public-page projection rather than serializing the authenticated Wiki object and hiding fields in the UI.
- Managed image access for public pages must authorize through the public share context without opening arbitrary portfolio images.
- Markdown rendering must sanitize unsafe HTML/scripts/URLs and prevent path traversal, executable-content injection and leakage of filesystem/storage paths.
- All authenticated Wiki CRUD/search/history/export operations remain protected by existing Investor authentication and active-portfolio authorization boundaries.
- Admin Portal does not acquire portfolio Wiki ownership through this feature.
- Revision writes and page mutations should be transactional so current page state and audit history cannot diverge.

## Algorithms / invariants

### Stable internal-link resolution

1. Persist the target page's immutable identity in StoX-managed Wiki-link metadata/syntax.
2. At render time resolve that identity within the source page's authorized portfolio context.
3. If accessible, generate the current route/title from current page metadata and configured application base path.
4. If missing/deleted/inaccessible, emit explicit broken/private link presentation without leaking unauthorized metadata.
5. In anonymous rendering, additionally require the target to have its own valid public share before generating a navigable target URL.

### Hierarchy mutation

1. Validate source page belongs to active authorized portfolio.
2. Validate requested parent belongs to the same portfolio or is root.
3. Reject self-parenting or any move that would create an ancestor cycle.
4. Apply parent/order change atomically and record revision/audit information.
5. Do not rewrite StoX-managed incoming links; stable identity makes rewriting unnecessary.

### Revision creation

- Content/title/hierarchy changes produce revision evidence, with autosave edits coalesced/debounced into meaningful revisions.
- Restore copies selected historical state into a new current revision; historical revisions remain immutable.

### Public-share lifecycle

- Enable: generate a cryptographically strong new token and mark that page publicly shared.
- Disable: revoke current token immediately.
- Regenerate: atomically revoke old token and create a new one.
- Re-enable after revocation: create a new token; never reactivate an old token.
- Anonymous request: resolve token -> verify active share -> load only permitted page projection/assets -> sanitize/render.

### Export

- Determine requested page/branch/Wiki set within authorized portfolio.
- Generate deterministic safe filenames/relative hierarchy for the snapshot.
- Rewrite internal links whose targets are included to relative Markdown paths.
- References to excluded/unavailable targets remain clearly unresolved rather than causing private content to be exported implicitly.
- Include only managed images actually authorized/referenced by exported pages.

## UX

- Knowledge Board remains the root landing page and retains the existing Notes experience.
- Wiki is accessible as a structured Knowledge capability with tree navigation.
- When entering Wiki, show the last-used page where appropriate or a neutral Wiki overview if no page is selected.
- Page view shows title, rendered content and private breadcrumb/tree context when authenticated.
- Edit mode provides Markdown editor, Preview and Insert Wiki Link search/selection.
- Page management supports create child/root, rename, move/reorder, delete, history, export and public sharing.
- Public page is intentionally minimal and standalone: no private tree/breadcrumb/portfolio navigation. It may identify the content as a StoX shared Wiki page without exposing private ownership details.
- Broken/private links must be visually understandable and must not masquerade as successful navigation.

## Acceptance criteria

1. Existing Knowledge Board Notes/tags/images continue to function without migration or semantic regression.
2. An Investor can create, edit, preview, rename, move, reorder and view hierarchical Wiki Pages in the active portfolio.
3. Markdown is the canonical persisted page content and is safely rendered to HTML.
4. Private tree and breadcrumbs accurately reflect current hierarchy and always root at Knowledge Board.
5. StoX-managed links survive target rename/move and application base-path changes.
6. Broken/deleted/inaccessible targets are handled explicitly without unauthorized metadata leakage.
7. Wiki images remain portfolio-isolated and managed images survive page rename/move.
8. Meaningful changes produce revision history; historical revisions can be viewed/compared and restored without destroying later history.
9. Knowledge Board search returns both Notes and Wiki Pages with type and useful Wiki hierarchy context.
10. Leaf and recursive deletion follow the frozen safeguards and never silently reparent children.
11. Page, branch and complete-Wiki Markdown export produces portable Markdown and authorized images with usable relative internal links where possible.
12. Pages remain anonymous-inaccessible by default.
13. Explicit Share publicly creates an unguessable anonymous read-only URL that works without login.
14. Anonymous page response does not expose private Knowledge Board/portfolio hierarchy, Notes, search, editing/history controls or unrelated portfolio data.
15. Sharing one page never implicitly grants anonymous access to linked/parent/child pages.
16. Anonymous internal links navigate only to independently public targets; private targets do not leak content/hierarchy.
17. Stop sharing invalidates the old URL immediately; re-share/regenerate creates a different URL and never revives the old token.
18. Publicly shared managed images render without opening anonymous access to unrelated portfolio images.
19. Authenticated Wiki APIs enforce active-portfolio isolation; cross-portfolio resource access fails according to existing authorization conventions.
20. Markdown/public rendering prevents script execution, unsafe HTML/URL injection, path traversal and storage-path disclosure.

## Dependencies

- Existing F144 Knowledge Board Notes/tags/image infrastructure and active-portfolio authorization.
- Existing Investor application shell/routing and configured application base-path handling.
- FEAT-042 role separation must preserve the invariant that portfolio Wiki ownership belongs to Investor-side portfolio data, not Admin Portal accounts.
- Deployment/storage design must ensure Wiki Markdown, revisions, share state and managed images are durable across releases.

## Non-goals for V5

- Converting/migrating existing Notes into Wiki Pages.
- Cross-portfolio/global personal Wiki or cross-portfolio page sharing.
- Wiki tags.
- WYSIWYG/rich-text Wiki editing.
- Semantic/vector/AI Wiki search or AI assistant integration.
- General file attachments/document repository.
- Bulk Markdown import or bidirectional filesystem/Git synchronization.
- Collaborative multi-user editing, branching, merging, approvals or Git-style workflows.
- Wiki recycle bin/trash.
- Public Knowledge Board, public portfolio hierarchy, public Notes/search or implicit recursive/linked-page sharing.
- Automatic public-link expiry.

## Freeze note

This specification supersedes the earlier authenticated-only interpretation of FEAT-041. Portfolio ownership remains private, but individual pages may be explicitly exposed through revocable anonymous bearer links under the isolation rules above.
