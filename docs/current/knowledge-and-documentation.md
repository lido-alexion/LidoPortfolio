# Knowledge And Documentation

## Current Behaviour

Knowledge Board is the portfolio-scoped research and note-taking area. It supports notes, tags, palettes, images, search, bulk actions, duplication, markdown/rich editing paths, image lightbox behaviour, and export.

Wiki is a structured long-form extension of Knowledge Board. Wiki pages are hierarchical, Markdown-authored, revisioned, searchable, movable, exportable, and shareable by unguessable bearer-token URL. Markdown is the canonical stored content; rendered HTML is derived and must be sanitized. StoX-managed wiki links use stable page identity so page rename/move/base-path changes do not break managed links.

Public wiki sharing exposes only the shared page’s permitted rendered content/title/images/safe links. It must not expose private Knowledge Board tree, portfolio hierarchy, private breadcrumbs, Notes, search, editing controls, revision history, or unrelated portfolio data.

In-app static documentation is served from `app/public/docs` and rendered through the Documentation page. Those files are product help assets. They remain live after archiving old specs.

## Technical Contract

Key API routes:

- Knowledge search: `/api/knowledge-board/search`
- Notes: `/api/knowledge-board/notes`, bulk, show, update, delete, duplicate
- Tags: `/api/knowledge-board/tags`, merge, CRUD
- Images: `/api/knowledge-board/images`, show, full image
- Wiki private: `/api/knowledge-board/wiki/pages`, show, preview, move, revisions, restore, share, image attach, delete, export page/branch/wiki
- Wiki public: `/api/wiki/shared/{token}`, `/api/wiki/shared/{token}/images/{image}`

Primary models include `KnowledgeNote`, `KnowledgeTag`, `KnowledgeImage`, `WikiPage`, `WikiPageRevision`, and `WikiPageShare`.

Primary services include `KnowledgeBoardNoteService`, `KnowledgeBoardTagService`, `KnowledgeBoardImageService`, `KnowledgeNotePaletteCatalog`, `WikiPageService`, `WikiMarkdownRenderer`, `WikiShareService`, and `WikiExportService`.

Frontend components include Knowledge Board pages/components, TipTap editor integration, Markdown editor/preview, palette picker, image upload utilities, wiki pages, and public wiki page.

## Data Rules

- Notes and wiki pages are separate concepts; wiki deletion never deletes notes.
- Wiki source is Markdown. HTML is rendered output only.
- Renderers must sanitize unsafe HTML/scripts/URLs and avoid filesystem/storage path leaks.
- Public sharing is bearer-token access to the selected public page, not recursive public access to a private knowledge graph.
- Search results should identify whether a hit is a note or wiki page and include useful context.

## Debugging Sources

- Broken wiki link: inspect stable page identity syntax, page move/rename history, renderer output, and public/private mode.
- Public leak concern: inspect `WikiShareService`, `WikiMarkdownRenderer`, shared route payload, and image authorization.
- Image issue: inspect Knowledge image model/storage, upload utility, attached page/note relationships, and public image route.

## Related Docs

- [Frontend And Navigation](./frontend-and-navigation.md)
- [Discovery, Screeners, And Registries](./discovery-screeners-registries.md)
- [Administration, Security, And API](./administration-security-api.md)

## Historical Context

Knowledge Board was formalized as V2 F144, then Wiki was added as V5 FEAT-041. Current docs treat them as one knowledge domain with two content types: notes and wiki pages.

