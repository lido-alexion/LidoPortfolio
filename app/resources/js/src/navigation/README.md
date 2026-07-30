# Sidebar navigation (code)

**Canonical architecture & how-to:** [specs/architecture/15-Sidebar-Navigation-Architecture.md](../../../../../specs/architecture/15-Sidebar-Navigation-Architecture.md)

## Module map

| File | Role |
|------|------|
| `index.js` | Public exports |
| `bootstrap.js` | Seeds core catalog into the registry |
| `registry.js` | `navigationRegistry` (items, actions, modules) |
| `routes.js` | `ROUTES` path constants |
| `icons.js` | Icon registration |
| `permissions.js` | Access context + gates |
| `constants.js` | Favourites / storage keys |
| `coreActionHandlers.js` | Built-in quick-action handlers |

Import from `../navigation` (or `../../navigation`) in app code.
