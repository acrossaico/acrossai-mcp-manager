# Phase 1 — Data Model

> **Verified against live traffic 2026-10-16** (see `research.md` §Phase 1). All four
> load-bearing assumptions hold; four column notes below were corrected as a result — D-10
> (`component_name` sources `ability_name`), D-11 (`source` → `event_source`), D-12
> (`error_code` has no tag), D-13 (`user_id` from `get_current_user_id()`).

One table. `{$wpdb->prefix}acrossai_mcp_activity_log`, BerlinDB module
`includes/Database/ActivityLog/`, table version `1.0.0`, per-site prefix,
`Table::maybe_upgrade()` carrying the same phantom-version guard as `CliAuthLog\Table`.

## Entity — Activity event

One row is one thing that happened. `event_type` says which kind; `parent_id` relates them.

| Column | Type | Null | Default | On | Notes |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned AI | no | — | both | PK. Stable and addressable — F097 points at it |
| `parent_id` | bigint(20) unsigned | no | `0` | ability | The request event that caused it. `0` for request events and for non-MCP ability runs |
| `event_type` | varchar(16) | no | `'request'` | both | `request` \| `ability` |
| `event_source` | varchar(32) | no | `'mcp'` | both | Reserved: `webmcp` / `auth` later without a reshape. **Named `event_source`, not `source`** — the adapter sends its own `source` tag whose value on a toolset call is `ability`, and a tag-to-column map by name would silently write that here (D-11) |
| `server_id` | bigint(20) unsigned | no | `0` | both | → `acrossai_mcp_servers.id`; `0` when the slug no longer resolves. Denormalised onto ability rows so the per-server view filters without walking `parent_id` |
| `server_slug` | varchar(191) | no | `''` | request | The `server_id` tag verbatim — survives server deletion (SC-014) |
| `connection_type` | varchar(32) | no | `'unknown'` | request | `ai-connector` \| `oauth` \| `app-password` \| `browser` \| `unknown` |
| `invoked_via` | varchar(32) | no | `''` | ability | `mcp` \| `rest` \| `cli` \| `admin` \| `unknown`. **Separate from `connection_type`** — one is how the credential arrived, the other is which surface called |
| `client_name` | varchar(191) | no | `''` | request | From `clientInfo.name`. Denormalised: names an application, has no row to join to |
| `client_version` | varchar(64) | no | `''` | request | From `clientInfo.version` |
| `connector_slug` | varchar(64) | no | `''` | request | Raw slug. Resolved to a display name **in bulk at read time**, never denormalised |
| `transport` | varchar(64) | no | `''` | request | The `transport` tag — observed value is the short label `HTTP`, not a class FQN |
| `method` | varchar(64) | no | `''` | request | `tools/call`, `tools/list`, `initialize`, … |
| `protocol_version` | varchar(16) | no | `''` | request | From `initialize`; first thing wanted when a client breaks after an upgrade |
| `component_type` | varchar(32) | no | `''` | both | `tool` \| `prompt` \| `resource` \| `ability` |
| `component_name` | varchar(191) | no | `''` | both | **The canonical, joinable name.** Sourced from the adapter's `ability_name` tag (`toolset/server-guide`), falling back to `tool_name` / `prompt_name` / `resource_uri` when no ability is involved. **Not** `tool_name`, which the vendor's `McpNameSanitizer` rewrites to `toolset-server-guide` and which therefore matches no ability slug anywhere else (D-10, B31) |
| `invoked_name` | varchar(191) | no | `''` | request | The name the client actually sent, when it differs from `component_name` — the sanitized string that appears in the client's own `tools/list`. Needed to answer "my client says tool X doesn't exist" (D-10) |
| `component_category` | varchar(64) | no | `''` | ability | Registered ability category |
| `status` | varchar(32) | no | `''` | both | `success` \| `error` \| `denied` \| `invalid_input` |
| `error_code` | varchar(64) | no | `''` | both | `WP_Error` code on ability rows. **Often empty on request rows**: there is no `error_code` tag, and an input-validation failure arrives as `status = error` with `failure_reason` prose only (D-12) |
| `error_message` | varchar(500) | no | `''` | both | Truncated |
| `duration_ms` | int unsigned | yes | `NULL` | both | Request: 3rd `record_event()` arg. Ability: invoked → result |
| `user_id` | bigint(20) unsigned | no | `0` | both | **Personal data** (FR-039). From `get_current_user_id()` — there is no `user_id` tag, nor `site_id` or `timestamp`, despite the plan's assumption (D-13) |
| `ip_address` | varchar(45) | no | `''` | both | IPv4/IPv6, full. **Personal data** (FR-035) |
| `user_agent` | varchar(255) | no | `''` | request | Distinguishes the npm bridge from a CLI client — both authenticate identically |
| `session_id` | varchar(64) | no | `''` | request | `Mcp-Session-Id`, coalesced with `new_session_id` on `initialize` |
| `request_id` | varchar(64) | no | `''` | request | JSON-RPC id |
| `input_preview` | text | yes | `NULL` | both | Sanitised then truncated |
| `output_preview` | text | yes | `NULL` | ability | **Empty on request rows** — the seam never passes the payload |
| `input_bytes` | int unsigned | yes | `NULL` | both | `strlen()` before preview truncation |
| `output_bytes` | int unsigned | yes | `NULL` | both | `strlen()` of the response; filled by `ResponseSizeProbe` |
| `created_at` | datetime | no | — | both | **UTC.** BerlinDB flags `created`, `date_query`, `sortable` |

32 columns: 16 populated on both event kinds, 12 request-only, 4 ability-only. The 12 sit
empty on ability rows — still cheaper than 16 duplicated columns plus a second module, a second
controller and a second schema-contract suite.

> **BerlinDB flag caution (B21)**: the recognised auto-update flag is spelled `modified`, not
> `date_updated`. This table is append-only and declares no modified column, so the trap does
> not apply — do not add one by reflex.

## Indexes

```
primary                  (id)
parent                   (parent_id)
type_created             (event_type, created_at)
server_type_created      (server_id, event_type, created_at)
type_status_created      (event_type, status, created_at)
type_component_created   (event_type, component_name, created_at)
type_connection_created  (event_type, connection_type, created_at)
type_user_created        (event_type, user_id, created_at)
created_at               (created_at)
```

`type_created` and `server_type_created` are the merge's payoff: the two views the UI used to
get from two tables are two index-covered queries on one. Shape follows the AI plugin's
`idx_timestamp_type_status`.

**Every composite key leads with `event_type`** (schema 1.1.0). One table holds two
populations, so a key that omits the discriminator matches rows of BOTH kinds and every
single-kind query pays for the other kind's rows. Measured on a copy of this schema,
filtering server + status:

| key | rows examined | p50 |
|---|---|---|
| `(status, created_at)` | 742 | 1.155 ms |
| `(event_type, status, created_at)` | 239 | 0.413 ms |

Identical result, 2.8x less work. `ActivityLogController` defaults `event_type` to `request`,
so the predicate is on every list query and nothing wanted these un-prefixed. The four keys
shipped without it in 1.0.0; `Table::upgrade_to_1_1_0()` re-keys them, and they were RENAMED
because the name is the only thing `index_exists()` can see — dbDelta never re-keys an index
that already exists under its own name, so without the rename an upgraded install would keep
the old key while reporting the new version (B34 in index form).

The `(100)` prefix on `component_name` was dropped: BerlinDB's `Kern\Index` exposes no
`$length`, and it is unnecessary — 764 bytes for the varchar plus 65 for `event_type` and 5
for `created_at` is 834, well inside InnoDB's 3072-byte limit.

**No FULLTEXT in 1.0.0.** Search targets `component_name` and `error_message` — both short,
both indexed. `LIKE` over an indexed varchar is adequate at these widths, and skipping FULLTEXT
avoids the MySQL version sniff and the `suppress_errors()` dance. Revisit only if measured slow.

## Relationships

```
request event (event_type=request, parent_id=0)
   └── ability event (event_type=ability, parent_id=<request id>)   0..n
       │
       └── F097 change rows reference the ABILITY event id          0..n   (not this feature)

ability event invoked outside MCP → parent_id = 0, invoked_via ∈ {rest, cli, admin}
```

## Validation rules

- Writes go through `ActivityLog\Recorder` only. Per **A15** it is static-only, each method
  wrapping one `( new Query() )->add_item([...])` in `try/catch (\Throwable)`, routing failures
  to `error_log()`, returning `void`, never Loader-wired. Feature classes MUST NOT call
  `Query::add_item()` directly.
- The insert is built from a **declarative column map**, never a positional
  `$wpdb->insert()`, so `source`, the webmcp-era columns and anything F097 adds are additive.
- **B7**: the column map and all 19 REST collection params MUST be filtered against
  `Schema::columns()` before persisting or querying — both are untrusted-key surfaces.
- Tags run through `McpObservabilityHelperTrait::sanitize_tags()` (24 redaction patterns,
  1024-byte truncation) before storage; previews are then truncated again. Raw bodies are never
  stored.
- Recorders ignore this table and guard re-entry with a static flag. The ignore list is one
  shared constant in `includes/Utilities/ActivityLogConstants.php` per **A9**, because F097 adds
  a third recorder behind the same guard.

## Lifecycle

Append-only. No updates, no state transitions. Rows leave by exactly three paths:

| Path | Trigger | Scope |
|---|---|---|
| Retention | daily cron, per `event_type` cutoff | rows older than their kind's cutoff |
| Ceiling | same cron | oldest first, once the retained-event maximum is exceeded |
| Purge | `DELETE /activity` | everything in this table — **never F097's** |

Each of those three paths writes a removal record **after** it deletes (FR-043), so the
remaining history begins by stating what was taken. It is an ordinary event — no schema change —
written through `Recorder` like any other, and it is the one row a purge cannot erase, because
it is created once the purge has finished.

Erasure is a fourth, narrower path, and it is **not** a removal: the personal-data eraser
(FR-038) clears the acting identity and source address while retaining the event, its timing,
outcome and server. Deleting rows on a subject request would make erasure an
evidence-destruction path reachable without administrative capability.

## Drift guard (B34)

A live table that drifts from `Schema.php` while the stored `db_version` still matches code
causes `add_item()` to return `false` while callers read success — silent write-loss. The F011
phantom-version guard catches only a *missing* table. `SchemaContractTest` therefore asserts
every declared column exists **at its declared width**, not merely that it exists.
