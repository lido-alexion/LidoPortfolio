# Sidebar & Navigation Architecture

**Status:** As-built (production)  
**Audience:** Engineers and agents extending primary navigation  
**Related living notes:** [`implementation.md`](../../implementation.md)  
**Code entry:** `app/resources/js/src/navigation/`

---

## 1. Architecture

Primary navigation is **sidebar-only**. Top tabs are removed. Everything the sidebar shows is driven by a **navigation registry** seeded from static catalogs and open to future modules (plugins, marketplace packs, custom dashboards).

```text
AuthenticatedShell
├── SidebarProvider          layout / collapse / Ctrl+B / group expand
├── AppHeader                brand + sidebar toggle
└── lido-shell
    ├── Sidebar
    │   ├── Favourites       pinned catalog pages (per-user localStorage)
    │   ├── Quick Actions    registry actions (navigate | handler)
    │   └── Navigation       groups → pages from registry
    └── lido-main
        ├── PageChrome       breadcrumbs + title (same registry)
        └── AppRoutes        React Router (URLs unchanged)
```

### Design goals

| Goal | How |
|------|-----|
| No duplicated menu JSX | Shared `NavMenuItem`, `NavGroup`, `NavBadge`, `NavTooltip` |
| No duplicated icons | Single icon registry (`registerIcon` / `NavIcon`) |
| No hardcoded nav routes | `ROUTES` constants + catalog `route` / `match` |
| Config-driven IA | Flat `NAVIGATION_CATALOG` → registry → tree |
| Future extensibility | `navigationRegistry.registerModule(...)` |

### Key packages

| Path | Role |
|------|------|
| `navigation/routes.js` | Canonical path strings (`ROUTES.*`) |
| `navigation/constants.js` | Favourites max, storage keys |
| `navigation/permissions.js` | `createNavAccessContext`, `canAccessNavItem` |
| `navigation/icons.js` | Lucide/custom icon registry |
| `navigation/registry.js` | Items, quick actions, handlers, modules |
| `navigation/bootstrap.js` | Seeds core catalogs once |
| `config/navigation.js` | Core page/group catalog |
| `config/quickActions.js` | Core quick actions |
| `utils/navigationTree.js` | Tree, active page, breadcrumbs, titles |
| `components/sidebar/*` | Presentation only |

---

## 2. Navigation configuration

Each catalog entry is a flat **NavItem**:

| Field | Purpose |
|-------|---------|
| `id` | Stable id (favourites, registry keys) |
| `title` | Label |
| `icon` | Registered icon name (PascalCase) |
| `route` | Path from `ROUTES` (or `null` for groups / match-only pages) |
| `group` | Owning group id (pages) |
| `parent` | Group or parent page id (breadcrumbs / active walk) |
| `order` | Sort within siblings |
| `kind` | `'group'` \| `'page'` |
| `showInSidebar` | Top-level sidebar visibility |
| `favouriteEligible` | May be pinned |
| `badge` / `tag` | Numeric badge or `NEW` / `BETA` |
| `disabled` / `external` | Non-interactive or new-tab link |
| `permission` | `'admin'`, string, or string[] (future) |
| `moduleId` | Set automatically when registered via a module |
| `match` | Optional pathname predicate (preferred over prefix alone) |

**Internal routes** (editors, registries, settings tools) stay in the catalog with `showInSidebar: false` so breadcrumbs/titles/active-parent highlighting work without listing them in the sidebar.

---

## 3. How to add a page

1. Add a path constant to `navigation/routes.js` if it does not exist.
2. Add a `kind: 'page'` entry to `config/navigation.js`:
   - `route: ROUTES.YOUR_PAGE`
   - `parent` / `group`: the group id (e.g. `'group-market'`)
   - `showInSidebar: true` for top-level IA; `false` for editors
   - `match: (p) => pathStartsWith(p, ROUTES.YOUR_PAGE)`
   - `favouriteEligible: true` if pin-worthy
   - `icon`: an already registered name (or register one — §5)
3. Register the React route in `App.jsx` (same `ROUTES` value).
4. Rebuild; no sidebar JSX changes required.

Example:

```js
{
  id: 'my-page',
  title: 'My Page',
  icon: 'Gauge',
  route: ROUTES.MY_PAGE,
  group: 'group-portfolio',
  order: 55,
  parent: 'group-portfolio',
  showInSidebar: true,
  favouriteEligible: true,
  kind: 'page',
  permission: null,
  match: (p) => pathStartsWith(p, ROUTES.MY_PAGE),
}
```

---

## 4. How to add a group

1. Add a `kind: 'group'` item with `showInSidebar: true`, `route: null`, and a unique `id` (e.g. `'group-research'`).
2. Point new pages at `parent: 'group-research'` and `group: 'group-research'`.
3. Group expand state is stored in `localStorage` (`lido-nav-groups`).

---

## 5. How to add icons

1. Prefer an existing Lucide name already in `navigation/icons.js` (`CORE_NAV_ICONS`).
2. To add a new Lucide icon:

```js
import { MyIcon } from 'lucide-react';
import { registerIcon } from '../navigation';

registerIcon('MyIcon', MyIcon);
```

3. Set catalog `icon: 'MyIcon'`.
4. Plugins should pass `icons: { MyIcon }` inside `registerModule` (auto-registered).

Never import Lucide directly inside sidebar row components — use `NavIcon` / the registry.

---

## 6. How to add favourites

Favourites are **user pins of existing catalog pages**, not a separate menu.

1. Set `favouriteEligible: true` and a non-null `route` on the page.
2. Users pin via the star on the sidebar row (max `MAX_NAV_FAVOURITES`, currently 8).
3. Storage key: `lido-nav-favourites-u{userId}` (ordered id list).
4. Reorder is drag-and-drop in the Favourites block.

Server-synced favourites are a future enhancement; the hook API (`useNavFavourites`) is the seam.

---

## 7. How to add quick actions

1. Add an entry to `config/quickActions.js` using `ROUTES` for navigate targets.
2. Types:
   - `type: 'navigate'` + `route` + optional `state`
   - `type: 'action'` + `actionId` + handler registration
3. For `action` types, register a handler:

```js
import { navigationRegistry } from '../navigation';

navigationRegistry.registerActionHandler('my-action', async () => {
  // ...
});
```

Core handlers live in `navigation/coreActionHandlers.js` and are seeded at bootstrap.

Optional `permission: 'admin'` (or future permission keys) filters visibility via `canRunQuickAction`.

---

## 8. Future extensibility

### Permissions

`createNavAccessContext(user)` exposes `isAdmin`, `permissions[]`, `workspaceId`.  
`canAccessNavItem` supports `'admin'`, a single permission string, or an array.  
Pass richer `user.permissions` when the API provides them — no sidebar rewrite needed.

### Plugins / dynamic modules / marketplace / custom dashboards

```js
navigationRegistry.registerModule({
  id: 'marketplace-pack-alpha',
  icons: { Sparkles },
  items: [/* groups + pages */],
  quickActions: [/* optional */],
  actionHandlers: { 'pack-sync': async () => {} },
});
```

Unregister with `navigationRegistry.unregisterModule('marketplace-pack-alpha')`.

### Workspace switching

`NavAccessContext.workspaceId` is reserved. Filter or swap module contributions per workspace without changing presentational components.

---

## 9. Reusable UI primitives

| Component | Use |
|-----------|-----|
| `NavMenuItem` | Link / external / disabled / action button |
| `NavGroup` | Collapsible group + child rows + favourite pin |
| `NavBadge` | Badge + NEW/BETA tags |
| `NavTooltip` | Collapsed ribbon tooltip |
| `NavIcon` | Registry-backed icon |

---

## 10. Active highlighting & history

- `findActiveNavItem` picks the most specific catalog page (child over parent).
- `findActiveSidebarPageId` walks parents until a `showInSidebar` page — editors keep **Screeners** / **Strategies** / **Settings** highlighted.
- Sidebar links use React Router `NavLink` **push** navigation — browser Back/Forward work normally.

---

## 11. Production checklist

- [ ] New pages use `ROUTES` + catalog + `App.jsx` route
- [ ] Editors stay `showInSidebar: false`
- [ ] Icons registered before first paint (bootstrap imports icons)
- [ ] Quick action handlers registered for every `actionId`
- [ ] Permission keys documented when introduced
- [ ] Update this file + `implementation.md` when IA changes
