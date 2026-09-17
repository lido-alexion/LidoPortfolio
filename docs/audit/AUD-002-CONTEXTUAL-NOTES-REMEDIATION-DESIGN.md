# AUD-002 Contextual Notes / Shared Right Utility Rail

## 1. Finding Recap

`AUD-002` is a partial implementation of the accepted V6 E4 right-utility
contract. Contextual Notes persistence, profile scoping, API authorization and
basic editing already exist, but the presentation is not integrated with the
shared utility rail and does not provide the accepted mobile, focus or
reduced-motion behavior.

The remediation must preserve the Notes domain contract and make the existing
`RightUtilityRail` the single shell owner for History and Notes utilities. It
must not change the Notes API, ownership model, context-key policy or backend
authorization.

Relevant current evidence:

- `app/resources/js/src/components/ContextualNotesPane.jsx`
- `app/resources/js/src/components/navigation/RightUtilityRail.jsx`
- `app/resources/js/src/components/navigation/PageHistorySheet.jsx`
- `app/resources/js/src/App.jsx:254-273`
- `app/resources/js/src/styles/lido-app.css:275-329`
- `app/app/Http/Controllers/Api/ContextualNoteController.php`
- `app/tests/Feature/V6ContextualNotesTest.php`
- `docs/current/knowledge-and-documentation.md` §§3-5, 12, 19-21
- `docs/archive/specs/V6-E4-Investor-UX-Client-Evolution.md` §§3.5, 9, 19

## 2. Current Notes Architecture

| Concern | Current behavior | Component/service | Contract status |
| --- | --- | --- | --- |
| Shell mount | `AuthenticatedShell` mounts `ContextualNotesPane` for every non-documentation authenticated route, then mounts `RightUtilityRail` separately for non-admin users | `App.jsx:254-269` | Data is reachable, but Notes is not a child/utility of the shared rail |
| Role | `ContextualNotesPane` returns `null` for unauthenticated or Admin users | `ContextualNotesPane.jsx` | Investor-only behavior is aligned |
| Trigger | Fixed bottom-right icon button with no explicit `aria-label` or expanded state | `.lido-notes-rail-button` | Partial accessibility and duplicate shell-control architecture |
| Open state | Local `open` state in the Notes component; trigger sets it true; close button sets it false | `ContextualNotesPane.jsx` | Basic open/close works |
| Desktop pane | Fixed, right-aligned 360px pane from below the header to near the viewport bottom | `.lido-context-notes-pane` | Overlays rather than resizing `.lido-main`; not placed in the persistent rail |
| Mobile pane | Same fixed pane rules with `width: min(360px, calc(100vw - 1.5rem))` | `lido-app.css` | Not the accepted near-full-width drawer/sheet |
| Context key | Normalizes the current pathname into an allowed `context_key`; `/` becomes `dashboard` | `contextFromPath()` | Stable for the current implementation; it does not include entity subject IDs |
| Profile scope | Reads `activePortfolio?.id`, sends it on read/create, and sends `null` for an unscoped note | `usePortfolio`, `ContextualNotesPane.jsx` | Client behavior matches current API contract |
| Read timing | Loads only when the pane is open; route/profile changes change the `load` callback and reload while open | `useEffect([load, open])` | Existing behavior; route changes can replace a local draft without confirmation |
| Create/update | Creates or updates by user/profile/context identity and sends only the body on update | `ContextualNotesPane.jsx`, `ContextualNoteController` | Domain behavior is preserved |
| Delete | Uses browser confirmation, deletes by note ID, then clears local note/body state | `ContextualNotesPane.jsx` | Domain behavior is preserved |
| Loading | No explicit loading state is rendered; `load()` clears the prior body only after a failed request and otherwise updates asynchronously | `ContextualNotesPane.jsx` | Shell remediation should not silently claim a complete loading-state contract |
| Errors | Load failures clear the editor silently; save/delete failures use the existing toast message | `ContextualNotesPane.jsx`, `getApiErrorMessage` | Preserve existing API/error semantics; improve presentation only if needed |
| Focus | No focus move, focus trap, focus return, or explicit modal semantics | `ContextualNotesPane.jsx` | Incomplete for the accepted desktop/mobile interaction contract |
| Motion | No Notes-specific open/close transition or reduced-motion rule | `lido-app.css` | Incomplete |

The current `RightUtilityRail` has its own History state, desktop rail and
mobile sheet coordination. It does not currently render Notes or know about
the Notes API. This is a useful shell foundation, but the two utility systems
are currently siblings rather than one coordinated right-utility model.

## 3. Notes Domain Contract To Preserve

The implementation must retain these behaviors:

- Notes are personal records owned by the authenticated `user_id`.
- An optional `profile_id` must belong to that user; invalid foreign profile
  scope is rejected with the current non-disclosing behavior.
- `context_key` is a persisted, validated page/context identity rather than
  transient React state.
- `subject_type` and `subject_id` are supported by the API even though the
  current shell does not send them.
- Create uses the current user/profile/context/subject identity and
  update-or-create behavior.
- Update and delete require the note to belong to the authenticated user.
- Personal API tokens still require the existing `notes:read` or
  `notes:write` scope; a rail or pane must not alter that boundary.
- Notes remain Investor-facing in the shell. Admin does not silently become
  the owner of Investor Notes.
- No note body, server response, draft or ownership state is moved into
  `localStorage` or `sessionStorage` as part of AUD-002.

The authoritative server behavior is in
`app/app/Http/Controllers/Api/ContextualNoteController.php` and is covered by
`app/tests/Feature/V6ContextualNotesTest.php`. The shell change should remain
an adapter around that behavior.

## 4. Context Identity

The current client derives:

```text
pathname with leading/trailing slashes removed
→ non [A-Za-z0-9_.:-] characters replaced by `:`
→ maximum 120 characters
→ `/` represented as `dashboard`
```

This differs intentionally from AUD-001 Page History identity. Page History
uses normalized pathname as a navigation destination and ignores query/hash;
Notes uses a server-compatible `context_key` string. They should not be
forced into one helper merely for code reuse.

The current pane does not send `subject_type` or `subject_id`, so detail pages
whose pathname includes an identifier still receive a distinct pathname-based
context, but entity metadata is not separately represented. That is an
existing implementation limitation, not a reason to expand AUD-002 into a
new subject-resolution system. The remediation should preserve the existing
key unless a separately accepted product decision requires entity-level note
subjects.

The target shell should obtain the context from the same route-aware Notes
content hook/component that exists today. The rail should not invent a second
context key or derive context from the visual utility state.

## 5. RightUtilityRail Integration

`RightUtilityRail` should become the single owner of right-side utility
presentation and coordination:

```text
RightUtilityRail
  PageHistoryRail                  desktop
  Notes utility trigger            desktop
  mobile utility actions           constrained widths
  PageHistorySheet                 mobile, mutually exclusive
  ContextualNotesPane/Sheet        desktop or mobile presentation
```

This is a small coordination model, not a plugin framework. The rail owns:

- which utility is active (`null`, `history`, or `notes`);
- desktop utility triggers and pane visibility;
- mobile utility actions and the active modal sheet;
- one-pane/one-sheet coordination;
- Escape handling at the active shell surface;
- focus origin and restoration for utility controls.

Notes owns:

- current route/profile context;
- loading and note state;
- draft body;
- API requests;
- save/delete busy state;
- domain errors and existing toast behavior.

Recommended props are intentionally narrow, for example:

```text
RightUtilityRail
  history state
  notes content/presentation callbacks or a Notes utility child
```

The rail should not accept or manipulate raw Notes records, call the Notes
API, or duplicate the Notes context-key calculation.

Opening Notes closes the active History mobile sheet. Opening History closes
the active Notes mobile sheet. On desktop, the History rail remains a compact
navigation utility while the Notes pane overlays the workspace; the two must
not create competing large panes.

## 6. Desktop Notes Interaction

The desktop target follows V6 E4 §9:

- a persistent Notes utility is available from the right utility rail;
- activating it opens a narrow overlay pane, approximately 360px or slightly
  wider where the current design system requires;
- the pane overlays the workspace and does not change `.lido-main` width,
  flex behavior or permanent right padding;
- the Notes utility exposes an active state while its pane is open;
- the pane has a visible heading, context label where useful, close control,
  editor and existing Save/Delete actions;
- a short right-to-left slide may communicate open/close;
- opacity treatment may keep the idle utility quiet, but Notes content and
  controls must be fully opaque when open or focused;
- only one large contextual pane is open at a time.

The Page History bars remain mounted and usable while Notes is open. The
Notes pane should be layered above the workspace but below any global toast or
critical blocking dialog. It must not cover the utility rail's controls in a
way that prevents closing Notes or changing utility.

Desktop Notes is best treated as a non-modal contextual pane: it overlays
secondary workspace content, but the user may continue normal application
interaction outside it. Therefore it should use an accessible region/label,
not `aria-modal="true"`, and should not trap all document focus. On open,
focus should move to the Notes heading or textarea when that is consistent
with the existing form workflow; Escape and the close control return focus to
the Notes utility trigger.

## 7. Mobile Notes Interaction

The current fixed 360px pane is not sufficient on mobile. The target is a
near-full-width modal sheet or drawer, consistent with the History sheet's
mobile interaction model while preserving enough vertical space for editing.

Recommended behavior:

- the mobile Notes utility action is a minimum 44px target with an accessible
  label and active/expanded state;
- activation opens a near-full-width bottom sheet with a backdrop;
- the sheet has a labelled heading, context label, close button, textarea and
  existing Save/Delete controls;
- the sheet owns its scroll region and remains usable with the soft keyboard;
- the editor has a practical minimum height but can scroll rather than forcing
  the viewport beyond the visible area;
- the backdrop closes only when the product's unsaved-edit policy permits it;
- Escape closes from a hardware keyboard and focus returns to the Notes
  action;
- the sheet uses `role="dialog"`, `aria-modal="true"` and a labelled
  heading;
- focus is contained while the sheet is open;
- opening History closes the Notes sheet and vice versa;
- no hover-only interaction is required.

The existing History sheet focus-trap pattern is the appropriate local
reference. A shared focus-trap helper may be extracted only if it remains
small and does not become a new modal framework.

## 8. Shared Mobile Utility Model

### Options

| Option | Description | Benefits | Risks |
| --- | --- | --- | --- |
| A | Two independent floating History and Notes buttons | Smallest local change | Repeats the collision problem, leaves two modal owners and scales poorly |
| B | Shared compact utility action group owned by `RightUtilityRail` | One coordination point, explicit mutual exclusion, clear 44px targets | Requires a small rail presentation change and careful positioning |
| C | Stacked utility action rail | Visible History and Notes controls without a launcher | More persistent mobile chrome and greater overlap risk |

Recommendation: Option B. The rail should expose a compact mobile utility
action group containing History and Notes, with one active sheet at a time.
The group may render as two labelled 44px actions at the current constrained
breakpoint; it does not need a generic menu or plugin registry. This removes
the current temporary Notes floating trigger and makes the History/Notes
coordination explicit.

## 9. Focus And Accessibility

### Rail and utility triggers

- Each utility has a stable accessible name (`Page visit history`,
  `Contextual notes`) and a visible focus indicator.
- Utility triggers expose active/expanded state and dialog relationship where
  applicable.
- Desktop hit targets are at least approximately 44px without making the
  visible History bars or rail visually heavy.
- Mobile actions and close buttons are at least 44px.
- Labels/tooltips are not hover-only; keyboard focus and screen readers expose
  the same meaning.

### Desktop Notes pane

- Use a labelled `aside` or region because the pane is non-modal.
- Move focus into the pane on open when appropriate.
- Escape closes the pane.
- Close returns focus to the Notes trigger.
- Do not trap document focus while the pane is non-modal.
- Label the textarea explicitly, not only through placeholder text.
- Preserve existing busy/disabled states for Save/Delete.

### Mobile Notes sheet

- Use dialog semantics and `aria-modal="true"`.
- Move focus into the sheet on open.
- Trap Tab and Shift+Tab within all enabled controls.
- Escape closes and restores focus to the invoking Notes action.
- History and Notes sheet activation must not leave two modal surfaces mounted.
- Announce or expose save/delete errors through the existing toast plus a
  useful local status where the current UI foundation supports it.

## 10. Reduced Motion

Add Notes-specific transitions alongside the existing Page History motion
rules:

- desktop Notes pane: short transform/opacity transition from the right;
- mobile Notes sheet: short translate transition from the bottom;
- utility active-state transitions: restrained and non-layout-shifting;
- under `prefers-reduced-motion: reduce`, remove or materially shorten
  transform/opacity animation and preserve immediate open/close state.

Do not animate `.lido-main`, reserve layout width for the pane, or introduce
motion that delays editor usability. The same reduced-motion rule should apply
to any shared overlay/backdrop transition introduced for Notes.

## 11. Route Change / Dirty State Behavior

The current component reloads when its pathname or active profile changes
while open. Because `body` is local draft state and there is no autosave or
dirty-state guard, a route change can replace an unsaved draft with the newly
loaded note. This is an implementation limitation that should be made
explicit during implementation and testing.

The smallest safe AUD-002 behavior is:

- keep the current route-change reload semantics unless product owners choose
  a dirty-draft policy;
- do not silently add browser persistence or autosave;
- do not claim that route changes preserve unsaved text;
- when the pane remains open across a route change, load the new context and
  ensure the previous page's note is not shown as the new page's note;
- if implementation closes the pane on navigation, it must not silently
  discard a dirty draft without an explicit accepted decision.

Recommended product decision for this remediation: keep Notes open across
route changes and reload the new context, but add an explicit dirty-draft
guard before replacing or closing a non-empty changed draft. If that decision
is not approved, preserve the current behavior and record the risk in runtime
verification rather than inventing a new save policy.

## 12. Page History Coordination

History behavior remains unchanged:

- route observation and session persistence stay in `usePageVisitHistory`;
- Notes does not alter MRU ordering or route descriptor resolution;
- opening/closing Notes does not create a Page History visit;
- navigating through a History link uses ordinary React Router navigation and
  normal authorization/profile behavior;
- if Notes is open while History navigation changes the route, the chosen
  policy should close or reload Notes through the coordinated rail state, but
  it must never show stale note content under the new context;
- mobile History and Notes sheets are mutually exclusive;
- desktop History remains visually compact and available while Notes overlays
  the workspace;
- Notes context refresh is driven by the route/profile dependencies in the
  Notes content layer, not by History state.

The integration must not modify `PageHistorySheet` MRU semantics, storage,
focus containment or Investor-only mounting.

## 13. Geometry / Z-Index

Use the existing shell layering as the starting point rather than escalating
all z-index values:

| Surface | Current/target role | Target relationship |
| --- | --- | --- |
| Header/sidebar | Primary shell chrome | Remain above ordinary workspace content |
| Page History rail | Compact desktop utility | Fixed at the right edge, above workspace, below blocking dialogs |
| Notes rail utility | Compact utility action | At the same utility layer as History |
| Desktop Notes pane | Non-modal contextual overlay | Above workspace; must not obscure its own utility/close control |
| Mobile backdrop/sheet | Modal contextual surface | Above shell/workspace; below any global blocking dialog |
| Toast/critical banner | Global feedback/safety | Remain above Notes when their current contract requires it |
| Sidebar drawer | Navigation overlay | Coordinate explicitly with mobile utility sheets; no accidental dual modal state |

The current Notes CSS uses `1040` for the standalone trigger and `1045` for
the pane, while Page History uses `1035` for the rail, `1040` for the mobile
History action and `1048` for its sheet. AUD-002 should replace these ad hoc
relationships with a documented utility layer, preserving the existing
application's modal/toast layering. Exact values require browser verification.

## 14. CSS Migration

The implementation should retain the existing editor internals but move shell
styles into explicit presentation classes:

- remove the standalone fixed bottom-right Notes trigger positioning;
- add shared rail utility button styles with 44px interaction targets;
- retain a desktop overlay pane width near the current 360px value;
- add desktop open/close transform and reduced-motion rules;
- add mobile near-full-width sheet/backdrop rules;
- ensure the editor's textarea and action row remain usable in the sheet;
- keep `.lido-main` sizing and flex behavior unchanged;
- avoid broad shell cleanup or unrelated breakpoint changes.

The existing `.lido-context-notes-pane` and `.lido-notes-rail-button` should
be treated as migration anchors, not permanent second architecture.

## 15. Implementation Options

| Option | Design | Advantages | Disadvantages |
| --- | --- | --- | --- |
| A | Keep `ContextualNotesPane` intact and let the rail externally control its trigger/open state | Lowest immediate code movement; preserves data code | Leaves shell ownership split, complicates mobile/focus coordination and encourages prop/state coupling |
| B | Split Notes into a content/data component plus rail-controlled desktop/mobile wrappers | Cleanly preserves domain behavior while eliminating duplicate trigger architecture; straightforward tests | Requires a focused component extraction and shared open/focus state |
| C | Build a new generic utility-pane/modal framework and rewrite Notes around it | Broad future abstraction | Over-scoped, higher regression risk, and unnecessary for two utilities |

Option B is the smallest design that satisfies the accepted contract without
creating another temporary shell system.

## 16. Recommended Architecture

```text
AuthenticatedShell (Investor)
  RightUtilityRail
    PageHistoryRail                    desktop
    UtilityButton: Notes               desktop
    MobileUtilityActions               constrained widths
    PageHistorySheet                   active mobile history
    ContextualNotesOverlay             active desktop notes
    ContextualNotesSheet               active mobile notes
      ContextualNotesContent
        route/profile context
        load/save/delete API behavior
```

Recommended split:

- `ContextualNotesContent` owns the existing `contextKey`, active profile,
  note/body/busy state, load/save/delete operations and current toast/error
  behavior.
- `RightUtilityRail` owns active utility state and passes open/close/focus
  callbacks to the presentation wrappers.
- `ContextualNotesOverlay` renders the non-modal desktop pane.
- `ContextualNotesSheet` renders the modal mobile sheet and reuses the local
  focus-trap pattern already used by Page History/Sidebar.
- `ContextualNotesPane` may remain as a compatibility composition component
  during migration, but the authenticated shell must no longer mount a second
  standalone Notes trigger beside the rail.

Do not introduce a provider, global Notes store, browser-persisted draft or
generic plugin registry for this change.

## 17. Test Plan

### Existing domain/API coverage to retain

- `app/tests/Feature/V6ContextualNotesTest.php` — create, update, list,
  delete, user/profile scoping, foreign-note rejection and token scopes.
- `app/tests/Feature/RoleSeparatedApplicationTest.php` — role boundary
  coverage relevant to Investor-only Notes routes.

### New or extended frontend coverage

- Investor non-documentation shell renders the Notes utility through
  `RightUtilityRail`.
- Admin and documentation shells do not render the Investor Notes utility.
- The old independent fixed Notes trigger is no longer mounted.
- Opening Notes renders a desktop overlay without changing the `.lido-main`
  layout contract.
- Existing-note load, empty-note editing, save/update/delete and API error
  behavior remain intact.
- Desktop Notes open/close, active trigger state, Escape and focus return.
- Mobile Notes opens a dialog sheet, traps focus, closes on Escape and restores
  focus to the Notes trigger.
- History and Notes mobile sheets are mutually exclusive.
- Route changes refresh the Notes context and do not display the previous page's
  note under the new key.
- Profile changes refresh the scope and do not leak the previous profile's
  note.
- Save/Delete remain disabled during their existing busy state.
- Reduced-motion CSS is present; browser-level animation behavior remains a
  runtime check.
- Page History state semantics and existing History focus tests continue to
  pass unchanged.

The source tests should prove mounting, state coordination and accessibility
semantics. They must not claim to prove exact responsive geometry, touch
behavior, soft-keyboard layout or deployed CSS reachability.

## 18. Runtime Verification

After implementation, verify in a real browser at representative desktop,
tablet and mobile widths:

- Notes utility position and 44px target;
- exact pane width and right-edge relationship;
- `.lido-main` does not resize when Notes opens;
- History remains usable beside the desktop Notes pane;
- mobile Notes sheet is near-full-width and does not remain a 360px desktop
  pane;
- History and Notes cannot leave overlapping modal sheets;
- keyboard focus, Escape and focus restoration with real browser traversal;
- textarea behavior with soft keyboard and viewport resizing;
- backdrop, sidebar drawer, toast and critical-banner stacking;
- reduced-motion rendering under the browser media preference;
- route/profile changes while Notes is open;
- deployed bundle reachability and authorization behavior.

## 19. Implementation Sequence

1. Extract the existing Notes data/editor behavior into a reusable content
   component without changing API payloads or context-key calculation.
2. Move utility open/close state into `RightUtilityRail` and remove the
   standalone shell mount of `ContextualNotesPane`.
3. Add the desktop Notes utility trigger and non-modal overlay wrapper.
4. Add the mobile shared utility actions and mutually exclusive Notes/History
   sheet state.
5. Add mobile dialog semantics, focus trap, Escape and focus restoration.
6. Add desktop/mobile transitions and reduced-motion CSS without changing
   `.lido-main` sizing.
7. Preserve and extend Notes API/domain tests plus focused shell/utility tests.
8. Run Page History regression tests and browser verification.
9. Update only the AUD-002 audit/design outcome after the accepted static
   criteria pass; leave AUD-003 and AUD-016 unchanged.

## 20. Open Questions

1. Should route changes with a dirty Notes draft be guarded with a discard/save
   confirmation, or should the current reload-on-route-change behavior remain
   the accepted contract? The current implementation has no dirty-state
   protection.
2. Should the current pathname-derived context eventually be supplemented by
   `subject_type`/`subject_id` for entity detail pages? The API supports those
   fields, but the current shell does not use them and E4 does not settle the
   subject-resolution policy.
3. At tablet widths, should the shared mobile utility actions remain two
   labelled buttons or collapse behind one launcher? Option B with two compact
   actions is the lower-risk initial behavior, but breakpoint-level product
   preference is not explicit.

No API, ownership, profile, Page History, or Admin/Investor policy decision is
unresolved for this remediation.

