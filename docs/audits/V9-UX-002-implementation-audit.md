# V9-UX-002 implementation audit

- **Frozen specification:** `docs/archive/specs/V9-User-Journey-Typeahead-How-Do-I-Search-Specification.md`
- **Starting SHA:** `1782da64` (local implementation base, 2026-10-05)
- **Final reconciled base:** `8fba404a` (`origin/master`, fast-forwarded after inspecting fourteen upstream documentation commits since the local starting SHA on 2026-10-05)
- **Audit status:** **IMPLEMENTED / VERIFIED**; all required backend, frontend, browser, and contract gates are green.

## Gap matrix

| # | Acceptance area | Existing implementation / evidence | Gap and disposition |
|---:|---|---|---|
| 1 | Authenticated-only global entry | `AppHeader` renders `GlobalSearch` only for authenticated users; component guards missing user. | Admin users are included. Search and feedback API require Sanctum auth. |
| 2 | Clickable and Ctrl/Cmd+K invocation | `GlobalSearch` has persistent desktop field, mobile trigger, global shortcut listener. | Covered; component acceptance tests run below. |
| 3 | Keyboard navigation | Arrow keys change active result; Enter selects; Escape closes; mobile focus containment. | Added `aria-controls`/`aria-activedescendant` and stable result IDs; verify in component/browser gates. |
| 4 | Enter selects/opens | Active result selection navigates through React Router. | Existing behavior retained; acceptance coverage in global search tests. |
| 5 | Title/alias/keyword/synonym ranking | Generated `journeyMetadata.js` deterministic scoring; source aliases/keywords; search synonym extraction added. | Tests cover exact title, aliases/keywords and source synonyms. |
| 6 | Typo tolerance/normalization | Query normalization and bounded edit-distance typo match. | Fixed diacritic normalization; tests cover diacritics and one-character typo. |
| 7 | Current-page boost does not hide matches | Route context contributes a small score. | Ranking now orders on textual score first; route is a tie-breaker, so valid/textually stronger topics cannot be hidden. |
| 8 | History boost subordinate | Eight-entry account-keyed recent topic list in localStorage; history score is contextual. | Ranking orders textual score before context/history. |
| 9 | Complete inline essential steps | Generated from numbered authoritative journey steps. | Removed generic fabricated fallback; added steps to REC-04/REC-05 authoritative journeys that lacked them. |
| 10 | Prerequisites inline | Generated metadata extracts explicit `Prerequisite:` / `Requires:` source bullets; page renders them. | REC-07, STR-13, and EXE-03 now declare material prerequisites in source. |
| 11 | Warnings inline | Generated metadata extracts explicit `Warning:` source bullets and `Important` source callouts; page renders them. | EXE-03 and EXE-09 now expose broker authorization/fill and cancellation warnings. |
| 12 | Why this matched | Search results expose token explanation. | Topic page now preserves query and displays explanation. |
| 13 | Ranked alternatives | Deterministic alternatives were absent on selected topic page. | Added up to four alternatives ranked by same deterministic query score. |
| 14 | Safe navigation / zero mutation | Results navigate only; underlying routes retain normal auth. | Kept; feedback is separate aggregate-only telemetry. |
| 15 | Exact full-guide links/anchors | Generated guide anchors point to source journey sections. | Kept and rendered via app base URL. |
| 16 | No-match fallback | Search displayed generic no match. | Added deterministic browse-help and explicit assistant handoff; fuzzy nearest matches remain deterministic. |
| 17 | Explicit Ask StoX Assistant; no silent AI | Assistant drawer has deterministic fallback and explicit submit flow. | Search handoff opens the drawer with question prefilled; does not submit/invoke AI. |
| 18 | Deep-link refresh safety | `/documentation?journey=<stable-id>` resolves generated metadata. | Kept; selected link carries query only to explain/share the selected topic. |
| 19 | Back/forward safety | Documentation selection driven by router search params. | Fixed duplicate result-click navigation; mobile browser back/forward restores previous and selected topic states. |
| 20 | Shareable without user context | Selected URL contains only the stable topic ID. | Search query and account/portfolio context are omitted from share URLs. |
| 21 | Bounded account history | Eight stable topic IDs in `stox_help_history_<user-id>` localStorage key. | Exact boundary: local to each browser profile/device, account-keyed, no query text, maximum eight IDs; not cross-device synchronized. |
| 22 | Clear history | Clear action removes account-keyed localStorage item. | Existing behavior retained. |
| 23 | Helpful / Not helpful | Topic page has both controls. | Existing behavior retained; backend acceptance below. |
| 24 | Aggregate diagnostics | Existing daily topic aggregate counted selection/feedback. | Added daily no-match/weak-match counters and HMAC query digests for frequency counts. Raw query text and user ID are not persisted; no portfolio/trading joins. |
| 25 | No portfolio/trading data | Ranking only receives URL path and bounded help topic IDs; aggregate has topic/date/query digest/counters. | No portfolio/trading lookup or join. |
| 26 | English-only | Journey corpus/search labels are English. | No localization architecture added. |
| 27 | No favorites/pinning | No favorite/pin metadata or controls. | Metadata test checks this contract. |
| 28 | Accessibility/focus/no trap | Search labels, focus management, Escape restoration, arrow selection; mobile dialog traps Tab only within its modal while open. | Added active descendant semantics; browser/accessibility gate below. |
| 29 | Mobile/touch | Constrained layouts use modal surface/backdrop, touch buttons and focus containment. | Existing behavior retained; mobile browser gate below. |
| 30 | Metadata/source lifecycle | Generator derives topics from current `docs/user-journeys/*.md`; static docs check validates generated contract. | Source synonym/prerequisite/warning extraction supported; generator regeneration drops removed topics. |

## Reused implementation and changes

Reused existing authenticated `GlobalSearch`, deterministic `searchJourneyTopics`, generated journey corpus/guide links, DocumentationPage, AssistantDrawer deterministic fallback, account-keyed bounded local history, and daily `HelpFeedbackAggregate`.

Completed gaps: current-page/history boosts now act only as textual-score tie-breakers; typo/diacritic normalization; synonym/prerequisite/warning extraction from authoritative journey content; all numbered steps retained; no fabricated generic steps; inline match explanation and ranked alternatives; explicit assistant handoff; privacy-conscious no-match/weak-match/query-frequency aggregates; accessibility active descendant references; duplicate navigation removed; authenticated admin entry enabled; mobile dialog landmark corrected; additional acceptance tests. REC-04 and REC-05 now have source-authored steps.

## Ranking, privacy, and URL behavior

Ranking is deterministic. Exact title/alias, lexical token overlap, keywords, declared synonyms and one-edit typo signals form textual score. Current route and recent topic history break textual ties only. The browser history key includes the authenticated user ID and stores at most eight topic IDs; it does not store queries and is not shared across devices.

Selected URLs use `/documentation?journey=<topic-id>`. Search query and account/portfolio context are omitted; the selected topic alone restores on refresh and is shareable. The user must still authenticate, and destination routes enforce their normal authorization.

No-match/weak-match diagnostics are submitted after a debounce. The backend HMACs normalized query text with the application key and stores only a daily digest and aggregate counters; it stores no raw query or per-user diagnostic row. Selection and feedback are daily topic aggregates. No feedback changes ranking or source content.

## Verification results

- `npm --prefix app run test:js:unit`: **PASS**, 52/52 Node unit tests, including journey metadata.
- Focused `global-search` and `documentation-journey` Vitest: **PASS**, 14 tests.
- Full frontend suite: **PASS**, 204 Node tests and 152 Vitest tests; typecheck and production build passed.
- `npm --prefix app run typecheck`: **PASS** after the current component changes.
- `VITE_APP_BASE=/portfolio/build/ npm --prefix app run build`: **PASS** after the current component changes; external Bunny font access was required; Vite reports existing large-chunk warnings.
- Full serial browser journey suite: **PASS**, 59 passed, 56 expected skips, 0 failures.
- Focused pinned Chromium mobile journey: **PASS** after fixing the nested banner landmark; selected help survives refresh, browser back/forward restores state, Axe reports no violations in the search surface, and there is no horizontal overflow.
- Focused pinned Chromium desktop journey: **PASS**. Ctrl+K, arrow navigation, Enter selection and selected topic URL verified.
- `npm --prefix app run docs:static:check`: **PASS**, generated static documentation contract current (53 documentation topics); journey metadata generator produces 69 current journey topics.
- `php artisan openapi:v1 --check`: **PASS**, 219 operations current.
- `php scripts/verify-migration-portability.php`: **PASS**, 174 migrations including the new aggregate extension.
- `git diff --check`: **PASS** after the latest source/test edits.
- `php artisan test --filter=V9HelpFeedbackTest`: **PASS**, 2 tests / 10 assertions against a fresh isolated MySQL database; aggregate persistence, query privacy, and authentication rejection covered.
- `./scripts/verify-ci.sh --backend`: **PASS** against a separate fresh isolated MySQL database. PHP platform checks passed; migration portability passed (174); Python tests passed (22, 8 expected skips); PHPUnit passed (2,258 passed, 1 skipped, 15,437 assertions); OpenAPI passed (219 operations).

The register is updated to `IMPLEMENTED / VERIFIED`. The full frontend and browser evidence above was already green before reconciliation; the three upstream commits changed archived specifications only, so those suites were not rerun.

## Environment/runtime limitations

Backend verification used `PHP_INI_SCAN_DIR=/etc/php/8.4/cli/conf.d:/tmp/stox-ai003-php-conf` and two newly created databases, `stox_ux002_focus_20261005` and `stox_ux002_ci_20261005`, on MySQL 8.4 at `127.0.0.1:3314`. No existing database was dropped or refreshed. Frontend font compilation requires external Bunny Fonts resolution; the production build passed with access available. Playwright used the already-installed pinned Chromium executable.
