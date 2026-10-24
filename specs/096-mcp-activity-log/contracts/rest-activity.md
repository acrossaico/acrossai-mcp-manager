# Contract — Activity Log REST API

Namespace `acrossai-mcp-manager/v1`. One controller,
`includes/REST/ActivityLogController.php`. Six routes. **No change-log routes** — F097.

## Authorization (applies to every route)

Every route is administrative and declares an explicit `permission_callback` pointing at a
`permission_check` method requiring `manage_options`, matching the three existing admin
controllers. `__return_true` appears **nowhere** in this controller — Principle III permits it
only on public read endpoints, and S7 reserves the single existing exception for the OAuth
token endpoint.

Verified during planning: there is no `AccessControl` class governing admin screens.
`AccessControlTab` governs MCP *client* access per server, which is a different concern.

## Routes

| # | Method | Route | Returns |
|---|---|---|---|
| 1 | `GET` | `/activity` | A page of events. **Never** returns `input_preview` / `output_preview` |
| 2 | `GET` | `/activity/summary` | Period aggregates for the stat tiles |
| 3 | `GET` | `/activity/filters` | Distinct values for the filter controls |
| 4 | `GET` | `/activity/{id}` | One event in full, previews included |
| 5 | `GET` | `/activity/{id}/children` | The ability events beneath one request |
| 6 | `DELETE` | `/activity` | Purge; returns the count removed |

### 1. `GET /activity`

**Collection params** (19). Every one is sanitised with the most specific function available
and validated against `Schema::columns()` before reaching a query — see B7.

| Param | Type | Notes |
|---|---|---|
| `event_type` | enum | `request` \| `ability`. Default `request` — the tree's top level |
| `parent_id` | integer | Returns one request's children; equivalent to route 5 |
| `server_id` | integer | |
| `connection_type` | enum | five values |
| `invoked_via` | enum | five values |
| `status` | enum | four values |
| `method` | string | |
| `component_name` | string | The canonical, joinable name (the ability slug), not the sanitized client-facing spelling — see D-10 |
| `component_category` | string | |
| `user_id` | integer | |
| `date_from`, `date_to` | string | **Site time zone in, UTC out** — see D-9 |
| `search` | string | `LIKE` over `component_name` + `error_message` |
| `page`, `per_page` | integer | Offset pagination |
| `orderby`, `order` | string | `orderby=timestamp` enables keyset |
| `cursor_id`, `cursor_timestamp` | integer, string | Keyset pagination |

**Pagination — both modes.** Offset by default. When `orderby=timestamp` and both cursor
params are supplied, the query uses
`( created_at < %s ) OR ( created_at = %s AND id < %d )` instead of `LIMIT/OFFSET` and returns
`next_cursor`. **This helper does not exist yet** — verified: no REST controller in this plugin paginates, so
there is no prior art to reuse. It is built here, in a shape F097 can consume, which is what
discharges Principle VI going forward. SC-013 requires a deep page to be no slower than the
first.

**Response shape**: rows with `connector_slug` resolved to a display name **in bulk, one pass
per page** (after easy-mcp-ai's `attach_labels()`), never denormalised into storage.
`output_bytes` accompanied by an approximate token figure derived at read time and explicitly
marked approximate.

### 2. `GET /activity/summary`

Volume, average duration, success rate, distinct active clients, total context returned, for
the requested period. Transient-cached with explicit invalidation on write and on purge.

**B11**: the cached array MUST be validated on read with `is_array()` + `isset()` +
`is_numeric()`. A partial write would otherwise surface as a fatal on the stat tiles.

### 3. `GET /activity/filters`

Distinct `server_id`, `connection_type`, `invoked_via`, `status`, `method`,
`component_category`. Same caching and the same B11 validation.

### 4. `GET /activity/{id}`

The only route returning `input_preview` and `output_preview`. Keeps list responses small and
means a large payload is fetched only when an operator opens that row.

### 5. `GET /activity/{id}/children`

`WHERE parent_id = %d`. This is the read that two tables would have needed a second controller
and a second endpoint to express.

### 6. `DELETE /activity`

Purges this table and returns the number of rows removed.

**Isolation, asserted by test in both directions**: this purge MUST NOT touch F097's change
table, and F097's day-count cleanup MUST NOT touch this one.

## Error contract

| Condition | Status |
|---|---|
| Not logged in, or lacking `manage_options` | `401` / `403` |
| Unknown `id` | `404` |
| Invalid enum, malformed date, bad cursor pair | `400` with the offending param named |
| Logging disabled | `200` with an empty set — **not** an error. The UI distinguishes "recording is off" from "nothing happened" |

## Non-contract

Not exposed by this feature, deliberately: any change-log route; a bulk export; a
write/mutate route of any kind beyond the purge. The table is append-only and written only by
`ActivityLog\Recorder` from inside the capture paths.
