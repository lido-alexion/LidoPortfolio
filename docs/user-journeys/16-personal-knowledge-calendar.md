# StoX User Journeys — Personal Knowledge and Calendar

[Back to journey index](README.md)

Notes, Wiki Pages and contextual notes remain private to their owner and selected Portfolio. Wiki sharing is an explicit capability for one page, not permission to browse its private hierarchy. These journeys preserve the [Linked Markdown Wiki](../archive/specs/V5-FEAT-041-Linked-Markdown-Wiki.md) and [Calendar and notifications](../current/notifications-calendar-alerts.md) contracts.

## E2E-40 — Recover personal Notes and preserve failed changes

- Requires: An authenticated Investor and an owned Portfolio.
- Warning: Blank notes are not saved. Autosave and manual Save use the same personal note; a transport failure cannot establish whether a write committed. Inspect the retained note before retrying. Copy/export does not publish a note.

1. Open Knowledge Board (`/knowledge-board`). Retry knowledge notes or Retry knowledge tags recovers unavailable reads; a failed read is not a confirmed empty Board.
2. Choose New note, enter title and research content, and inspect the save status. A rejected save keeps the draft available for manual Save. Close and reopen the confirmed note to verify persistence.
3. Choose Manage notes. Pin, Duplicate and Archive are explicit actions; a rejected action preserves the original note. Archive removes it from the active list only after confirmation from the service.
4. Use Show archived, search and tags to find personal research. Duplicating creates a distinct note; editing the copy does not replace its source.
5. Select notes and Export to review Markdown. A failed clipboard copy leaves the export available to copy again.
6. Cancel a bulk deletion to leave selection and notes intact. On a failed confirmed deletion, inspect the retained selection and retry deliberately.

**Expected result:** Read/write failures remain recoverable without lost drafts, false archive success or accidental publication.

## E2E-41 — Maintain personal tags with deliberate merge and deletion

- Requires: Owned Portfolio notes and tags.
- Warning: Merging consolidates the source tag into the selected target. Deleting a tag does not delete its notes.

1. Open Manage tags (`/knowledge-board/tags`). Retry knowledge tags recovers an unavailable list.
2. Enter a nonblank New tag name and choose Add. A failed creation retains the name for correction/retry.
3. Rename a tag. Cancel preserves its original name; a rejected Save keeps the entered name available.
4. Select distinct Source and Target tags before Merge. On failure, retain both selections and inspect the current list before retrying.
5. Cancel deletion to preserve the tag. A rejected confirmed deletion retains it; retry removes only the intended tag.

**Expected result:** Tag recovery preserves note ownership and explicit merge/delete intent.

## E2E-42 — Author private Wiki Pages and recover history and portability

- Requires: An owned Portfolio and access to its Wiki.
- Warning: Markdown is the canonical source. Rendered preview is derived. Moving/renaming preserves immutable page identity and managed links. Restoring creates a new revision; branch deletion requires explicit confirmation of the branch size.

1. Open Wiki (`/knowledge-board/wiki`); Retry Wiki hierarchy and Retry Wiki Page recover unavailable reads without treating them as a missing page or navigating away silently.
2. Create a root page or a child beneath the selected parent. Cancelling the prompt creates nothing; rejected creation retains existing hierarchy.
3. Edit title/Markdown and Insert Wiki Link to a selected page. Preview failure retains source; retry displays safe derived content. Save failure retains the draft; verify confirmed source/history after retry.
4. Compare a stored revision with current Markdown. Cancel Restore to leave history intact; retry a rejected confirmed restore to create a forward revision while retaining old evidence.
5. Use Move Wiki Page to reparent explicitly. A failed move preserves the old parent and permits retrying the same target. Stable links remain usable.
6. Insert image. Upload and page attachment must both succeed before the image is inserted into Markdown. Save deliberately to persist the Markdown change. Partial upload failures do not imply rollback of uploaded files.
7. Export page, Export branch or Export Wiki deliberately. Read failures leave the page available to retry. Exports do not publish or alter source/history.
8. Cancel branch deletion to preserve all pages. A rejected confirmed deletion retains the branch; inspect and retry deliberately.

**Expected result:** Private hierarchy, immutable identities and historical Markdown survive recoverable failures. Bulk Markdown import and bidirectional file sync remain outside the frozen Wiki scope.

## E2E-43 — Share one Wiki Page and revoke its public capability

- Requires: A page owner for sharing; a current public link for guest reads.
- Warning: Anyone with the public link may read the shared page and its attached images. Linked private pages, tree, breadcrumbs, revisions and editing remain inaccessible. Regeneration invalidates the old capability; Stop sharing revokes access.

1. On the owned private Wiki Page (`/knowledge-board/wiki`) choose Share publicly. Failure displays no confirmed link; retry shows the link field after service confirmation. If clipboard access fails, copy the displayed field manually.
2. Regenerate link explicitly. Failure preserves the current confirmed link; success supplies a replacement. Private Markdown/history is unchanged.
3. Open the link as a guest. An unavailable read offers Retry shared Wiki Page. An authoritative invalid/revoked link shows a generic unavailable message.
4. Inspect only the shared page and its attached images. Private hierarchy, revision history, linked-page content and personal contextual notes are absent.
5. Stop sharing deliberately. Failure retains the confirmed capability for inspection/retry; confirmed revocation removes the link field and makes guest access unavailable.

**Expected result:** Public reads are limited to one explicitly shared page; transport failure is distinct from revocation.

## E2E-44 — Maintain recurring Calendar events without holiday authority

- Requires: An authenticated Investor and an owned Portfolio.
- Warning: Global Trade Holidays are administrator-owned and shown on every Portfolio. An Investor cannot edit/delete them. Recurrence uses one event series; reminders use the account's enabled, verified notification channels and do not prove provider delivery.

1. Open Calendar (`/calendar`). Retry calendar recovers an unavailable year/occurrence read.
2. Open New event. Cancel makes no changes. Enter Title, Anchor date and Repeat; inspect the conditional recurrence fields and optional Repeat until.
3. For a monthly event on day 31, shorter months use the existing clamping rule. Select Event reminder and reminder offsets deliberately; custom offsets extend the selected list.
4. Save explicitly. A failure retains the entered event and an uncertain-write explanation. Inspect and retry; open a displayed occurrence to edit the underlying series.
5. A rejected series edit retains its draft. Retry and inspect another occurrence of the same series.
6. View a global Trade Holiday. Its fields are read-only for Investors and there are no Save/Delete actions.
7. Cancel Delete to preserve an owned event. A rejected confirmed deletion retains the series; retry removes only that series, preserving global holidays.

**Expected result:** Accessible desktop/mobile event dialogs preserve recurrence meaning, drafts, explicit deletion and holiday authority. Official holiday-source and actual reminder delivery qualification are separate evidence.

## E2E-45 — Recover route-scoped contextual notes without overwriting unknown content

- Requires: An authenticated Investor and a contextual-note surface.
- Warning: The note belongs to its owner, route context and active Portfolio where applicable. It is personal research, not shared advice or a trading instruction.

1. Open contextual notes on a supported route, such as Screeners (`/screeners`). If the existing note cannot be read, Retry contextual note first; Save remains disabled to prevent overwriting unknown content.
2. Enter a private note after a confirmed empty read and Save. Failure retains entered text; retry and reopen the pane to inspect the confirmed note in the same context.
3. Edit and Save deliberately. A rejected update retains the entered draft and existing record for recovery.
4. Cancel Delete to preserve the note. A rejected confirmed deletion retains it; retry clears only the current personal contextual note.
5. Switching Portfolio/context must not expose or replace the next context's personal content with a delayed response from the previous one.

**Expected result:** Unknown reads block unsafe upserts, and failed writes retain personal context and drafts.
