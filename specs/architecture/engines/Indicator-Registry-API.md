# Indicator Registry API

| Field | Value |
|-------|-------|
| **Document** | Indicator Registry HTTP API |
| **Version** | 1.0 |
| **Status** | Implemented |
| **Date** | 2026-07-30 |
| **Auth** | Sanctum session + **admin** middleware |
| **Envelope** | Trading OS `ApiEnvelope` (`success` / `data` / `meta`) |

Base path: `/api/v1`

---

## GET `/api/v1/indicators`

List Registry entries.

### Query parameters

| Param | Type | Description |
|-------|------|-------------|
| `q` | string | Search id, display name, description |
| `type` | string | `primary` \| `composite` \| `metric` |
| `category` | string | Category id (e.g. `liquidity`) |
| `status` | string | `active` \| `stub` \| `planned` \| `deprecated` |
| `consumer` | string | Consumer id |
| `screenable` | bool | Filter screenable |
| `chartable` | bool | Filter chartable |
| `visible` | bool | Filter visible |
| `strategy_scorable` | bool | When true, only strategy-scorable |

### Response

```json
{
  "success": true,
  "data": [ { "id": "rsi", "display_name": "RSI", "type": "primary", "...": "..." } ],
  "meta": { "count": 42, "filters": {}, "q": null }
}
```

---

## GET `/api/v1/indicators/meta`

Filter option lists + counts.

```json
{
  "success": true,
  "data": {
    "types": [{ "id": "primary", "label": "Primary" }],
    "categories": [],
    "statuses": [],
    "consumers": [],
    "counts": { "total": 0, "primary": 0, "composite": 0, "metric": 0 }
  },
  "meta": {}
}
```

---

## GET `/api/v1/indicators/{id}`

Detail for one indicator (aliases resolve).

### Response `data`

| Field | Description |
|-------|-------------|
| `indicator` | Full `IndicatorDefinition::toArray()` |
| `dependencies` | Flat list with display metadata |
| `dependency_tree` | Nested tree (`id`, `display_name`, `type`, `status`, `depends_on[]`) |

404 when unknown: `error.code = INDICATOR_NOT_FOUND`.

---

## Errors

| HTTP | When |
|------|------|
| 401 | Unauthenticated |
| 403 | Authenticated non-admin |
| 404 | Unknown indicator id |

---

## Non-goals

- No POST/PUT/PATCH formula editor
- No runtime plugin registration
- Does not replace `/api/screeners/meta` or `/api/v1/strategy/catalogue` yet (façades remain)
