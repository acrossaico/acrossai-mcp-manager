# Planning: MCP Activity Log (Feature 096)

> **Status:** Planned · **Date:** 2026-10-09 · **Revised:** 2026-10-16 ·
> **Owner plugin:** `acrossai-mcp-manager`
>
> **Revision of 2026-10-16 — three tables became two.** The original plan specced
> `mcp_request_logs` + `mcp_ability_logs` here and `mcp_change_log` in 097. After
> reading how the four reference plugins actually do it (§"What the references
> actually do", all verified on disk), the request and ability tables are merged
> into **one** `wp_acrossai_mcp_activity_log` discriminated by `event_type` and
> self-joined by `parent_id`. See §"Why one activity table, not two".
>
> **Scope, narrowed 2026-10-16 — this feature is log tables only.** One table:
>
> **`wp_acrossai_mcp_activity_log`** — one row per MCP JSON-RPC request **and** one
> row per ability execution, in a parent/child tree.
>
> Change history — before/after snapshots of what an ability wrote — is **not here**.
> Its table, its hook capture and its screen all belong to
> [097 — MCP Change History](097-mcp-change-history.md), which owns that feature end
> to end. 096 ships the one seam 097 needs: `CaptureContext`, the ambient "we are
> inside ability X of request N" state that 097 arms its recorder from.
>
> WebMCP calls and auth/connection events are also out of scope; the `source` column
> reserves room for both without a row-shape migration.

---

## In plain English

Right now, when an AI client talks to one of your MCP servers, nothing is written
down. You can see *that* Claude connected, but not what it asked for, whether it
worked, how long it took, or which of your servers it hit.

This feature writes it all down, in one list with two levels. The top level is a
**request** — Claude called `tools/call` on `toolset/content`, it took 142 ms, it
worked. Expand that row and you see the **abilities** that actually ran behind it:
`content/update-post` with this input, that output. One request, one or more
abilities, parent and children in the same list. The ability rows also catch
abilities run with no MCP involved at all — over REST, through WP-CLI, from the
admin UI; those simply have no parent.

What this feature does **not** record is *what changed* — the before and after of
a post an ability edited, an option it flipped, a term it renamed. That is a
different question, a different table and a different screen, and it is
[Feature 097](097-mcp-change-history.md) end to end. The one thing 096 owes it is
the arming bracket: the ambient "we are inside ability X of request N" state that
097 hangs its listeners on. Build that as a general, readable context and 097 is
additive; bury it inside the ability recorder and 097 has to re-bracket the same
code.

The screen copies the layout of the WordPress AI plugin's
**Tools → AI Request Logs** page (period picker, four stat tiles, searchable and
filterable table, a "purge everything" box), because that page is the best example
of this in the ecosystem and you already know how to read it.

Two things the AI plugin's page does **not** do, and that we need:

1. **Which MCP server?** We run several (the default server, the AcrossAI managed
   server, anything the operator created). The log has to say which one answered.
2. **How did the client get in?** Over the OAuth connector from claude.ai? Through
   the npm bridge with an application password? From the browser via the admin UI?
   That's the *connection type*, and it's the column that turns the log from "a
   list of tool calls" into something you can debug a broken connector with.

The good news is that almost none of this needs new plumbing. The `mcp-adapter`
plugin already measures every request and hands the numbers to an **observability
handler** — and we currently pass it `NullMcpObservabilityHandler::class`, which
throws them in the bin. Swapping that one class name for one of our own gets us
method, tool name, duration, status, error reason, session id, and server id for
free.

---

## What the references actually do

Four plugins read on disk (`wp-content/plugins/` on the `wordpress-7-0` site).

| Plugin | Call-level log | Change log | Tables |
|---|---|---|---:|
| **easy-mcp-ai** | `wp_easy_mcp_ai_audit_log` — tool calls **and** OAuth/auth events, the latter wedged in behind pseudo tool names | `wp_easy_mcp_ai_change_log`, joined by `audit_id` | **2** |
| **WordPress AI** (`ai` 1.3.0) | one table, `type VARCHAR(32)` + `operation` as the discriminator | — | **1** |
| **MCP Tracker** (`mcp-tracker` 1.0.1) | one table — route, method, `tool_name`, session all on one row | — | **1** |
| **novamira** | **none.** Only chat-session and OAuth tables | — | **0** |

Two findings drive this plan's shape.

**Nobody splits the request layer from the tool layer.** Each reference taps a
different layer — the AI plugin wraps the AI Client SDK's HTTP transporter,
MCP Tracker filters `rest_pre_dispatch`/`rest_post_dispatch`, easy-mcp-ai
instruments its own tool dispatcher — but every one of them lands the result in a
*single* call-level table. The original 096 was alone in proposing two.

**The one reference that answers both questions uses exactly two tables,** and its
call-level table shows what over-merging costs. `class-oauth-token-manager.php:439`
writes an auth event into the audit table like this:

```php
$wpdb->insert( $wpdb->prefix . 'easy_mcp_ai_audit_log', array(
    'token_id'      => 0,
    'tool_name'     => '_oauth_refresh',   // not a tool
    'result_status' => $event,             // not a status
    …
) );
```

There is no `event_type` column, so a non-tool event has to lie in two columns to
fit. Our `source` column exists precisely so `webmcp` and `auth` rows can land
later as themselves. That reference is the argument *for* one call-level table and
*against* doing it without a discriminator.

### WordPress AI plugin — the UI model, and a lesson

`includes/Logging/` is a clean five-class module: `AI_Request_Log_Schema` (dbDelta
plus explicit `maybe_add_columns`/`maybe_add_indexes` passes),
`AI_Request_Log_Repository` (query, summary, filter options, retention cleanup,
cache invalidation), `AI_Request_Log_Manager` (facade, validation, cron),
`REST\Controller` (`ai/v1/logs`, `/summary`, `/filters`, `/{id}`, plus DELETE =
purge), `AI_Request_Log_Page` (Tools submenu mounting a React DataViews app).
Captured via `Logging_Http_Transporter`, a decorator around the SDK transporter.

Its table, verified at `AI_Request_Log_Schema.php:116`:

```
id, log_id varchar(36), timestamp, type varchar(32), operation varchar(255),
provider, model, duration_ms, tokens_input, tokens_output, tokens_total,
status, error_message text, user_id, context longtext,
request_preview text, response_preview text
+ 9 indexes incl. idx_timestamp_type_status (timestamp, type, status)
```

Details worth copying:

- **`type` + `operation` as a two-level discriminator in one table.** Exactly the
  pattern this plan adopts as `event_type` + `component_name`. Their composite
  index `(timestamp, type, status)` is the shape our `(event_type, created_at)`
  and `(server_id, event_type, created_at)` indexes take.
- **Cursor pagination** alongside offset pagination. When `orderby=timestamp` and
  a `cursor_id`/`cursor_timestamp` pair is supplied it uses
  `(timestamp < %s) OR (timestamp = %s AND id < %d)` instead of `LIMIT/OFFSET`.
  Deep pages stay fast. We want this — MCP logs grow faster than AI logs.
- **FULLTEXT index, added defensively** under `suppress_errors()` behind a MySQL
  5.6 version check, with `has_fulltext_index()` deciding at query time between
  `MATCH … AGAINST` and `LIKE`.
- **`request_preview` / `response_preview` as TEXT**, not full bodies. Keeps the
  table from exploding while staying searchable.
- **Retention defaults to forever** (`wpai_request_log_retention_days` returns 0)
  and the daily cleanup cron is only *scheduled* when retention > 0.

And the lesson: the whole module sits behind an **experiment toggle**
(`Experiments\AI_Request_Logging`, capability `none`). Logging every request is a
real cost and they chose not to make it unconditional. We ship **off by default**
too.

> Already in our `package.json`: `@wordpress/dataviews`, `@wordpress/components`,
> `@wordpress/element`, `@wordpress/api-fetch`, `@wordpress/data`. The UI half of
> this feature needs no new dependency.

### MCP Tracker — the MCP-specific columns

A much smaller plugin (one table, one logger, one REST controller, one React
screen). It captures by filtering `rest_pre_dispatch` to insert a row and
`rest_post_dispatch` to update it with the response, threading the insert id
through `$request->set_param( '_mcp_tracker_log_id', … )`.

Its columns are the ones the AI plugin lacks and we need the *idea* of:
`server_namespace`, `tool_name`, `mcp_session_id`, `method`, `route`, `headers`,
`request_body`, `response_status`, `response_body`, `tool_status`. Note that
`tool_name` sits on the same row as `method` — more evidence that one table across
both layers is the normal shape.

**But we should not copy its capture mechanism.** Three problems:

1. It detects "is this MCP?" by sniffing for an `Mcp-Session-Id` header, with a
   hardcoded escape hatch for one specific plugin namespace
   (`'stifli-flex-mcp'`). That misses the `initialize` request — the one carrying
   `clientInfo` — because no session exists yet.
2. `rest_post_dispatch` fires for every REST request on the site, so the filter
   runs on traffic that has nothing to do with MCP.
3. It stores **entire request and response bodies** as LONGTEXT with no truncation
   and no redaction (`mcp-tracker-db.php:43`). A tool returning a large post list
   writes that whole payload into the DB on every call.

We already ship `Admin/Partials/ServerTabs/McpTrackerTab.php` — a detection-only
tab that advertises this plugin and deep-links into it. **This feature replaces
that tab's body.** The slug (`mcp-log`) and label (`Logs`) already fit; only
`render_body()` changes.

### easy-mcp-ai — a real tool-call audit log, and patterns to steal

`wp_easy_mcp_ai_audit_log` (`includes/class-activator.php:250`) is a genuine
per-tool-call log: `token_id`, `tool_name`, `arguments`, `result_status`,
`duration_ms`, `ip_address`, `auth_source`, `wp_user_id`, `oauth_client_id`, plus
four approval columns. Read through `class-audit-log-repository.php` (filters,
`distinct()` for dropdowns, `before_id` keyset pagination), exposed at
`/activity/audit` and `/activity/audit/cleanup`.

It logs at the **tool layer**, not the JSON-RPC layer — no `method`, no
`session_id`, no `initialize`, no `tools/list`. So it does not replace the
observability-handler seam. But four things in it are better than the first draft
of this plan:

1. **`auth_source` is `connection_type`, already shipped.** Independent
   confirmation that the column belongs. Theirs carries two values (`legacy` |
   `oauth`, plus an `unknown` sentinel); our five-value bucket is the richer
   version of the same idea.
2. **`attach_labels()` / `credential_label()`** resolve `token_id` and
   `oauth_client_id` into human labels **in bulk at read time**, rather than
   denormalizing a credential name into every row. Adopt this for
   `connector_slug` → connector display name: the Query layer labels a page of
   rows in one pass. This is a *different* axis from `client_name` — that comes
   from `clientInfo`, names the client application, has no row to join to, and so
   stays denormalized.
3. **`ip_address varchar(45)`** — omitted from the first draft of this schema. It
   belongs: a connector that starts failing is often diagnosed by noticing the
   source address changed.
4. **`audit_id` on every change row.** The join that makes a change log debuggable
   rather than a bare activity feed. Not 096's concern — but it is why this table's
   `id` has to be stable and addressable, because [097](097-mcp-change-history.md)
   points at it.

Its approval columns (`approval_id`, `approved_by`, `approved_via`, `approved_at`)
implement a human-in-the-loop gate — a tool call parks pending until an admin
approves it. Out of scope here, worth remembering as a separate feature. Its
`wp_history_apply` tool (restore by re-calling ordinary tools under their normal
permission checks) is likewise out of scope; see 097.

### novamira — nothing to take

Verified: no logging of any kind. `includes/chat-schema.php` creates a chat-session
table and `includes/oauth/schema.php` creates six OAuth tables; there is no audit,
request, activity or change table anywhere in the plugin. It does not influence
this plan, and the absence is itself worth knowing — this is not a solved problem
with an obvious house style to copy.

---

## Why one activity table, not two

The original plan argued for splitting requests from abilities on three grounds.
Each is answered here, because the split cost five files, a second REST controller
and a second schema-contract test suite.

**"They stand in a one-to-many relationship."** True, and that is not an argument
for two tables — `parent_id` in one table *is* one-to-many. A `tools/call` on
`toolset/content` is one `event_type = request` row, and the abilities it ran are
`event_type = ability` rows whose `parent_id` is that row's id. Every tracing
system in existence stores spans this way. The join that was going to be
`ability_logs.request_log_id` becomes `activity_log.parent_id`, and the read that
was going to be `AbilityLog\Query::for_request()` becomes
`WHERE parent_id = %d` on the table you already queried.

**"They are different shapes."** They are near-duplicates wearing different names.
Nine columns were identical in both drafts (`id`, `server_id`, `status`,
`error_code`, `duration_ms`, `user_id`, `ip_address`, `created_at`, plus the error
text). Four more pairs were the same concept renamed:

| Request draft | Ability draft | Merged |
|---|---|---|
| `params_preview` / `result_preview` | `input_preview` / `output_preview` | `input_preview` / `output_preview` |
| `component_name` (tool/prompt/resource) | `ability_slug` | `component_name` |
| — | `ability_category` | `component_category` |
| `failure_reason` varchar(500) | `error_message` varchar(500) | `error_message` |

And two ability columns disappear entirely: `request_log_id` becomes `parent_id`,
and `tool_name` — "the dispatcher it arrived through" — was the parent row's
`component_name` denormalized into the child, which `parent_id` now supplies for
free. Net: ~26 columns in one table instead of 23 + 16 across two.

**"Abilities happen with no MCP involved."** They do, and in one table those rows
simply have `parent_id = 0` and `invoked_via = rest|cli|admin`. The only real
consequence is the table's *name*: it cannot be `request_logs`, because REST,
WP-CLI and admin ability calls live there too. Hence `activity_log`.

### What the merge actually buys

- Five fewer files, one REST controller instead of two, one schema-contract test
  suite instead of two.
- The planned "Abilities run (N)" cross-link section becomes **native row
  expansion** — a tree in one table, not a second fetch against a second
  endpoint.
- **Open decision #1 dissolves.** The observability handler never receives the
  result payload, so a request-row result *preview* could only be filled by adding
  vendor result filters. The *child* ability row's `output_preview` is the result,
  straight from `wp_ability_execute_result`, so the preview column needs no filters
  at all. (The filters return for one narrow job — filling `output_bytes` with a
  `strlen()`, which is not a payload. See §"Token accounting".)
- 097 gets strictly more precise, for free. Its `activity_id` can point at the
  **ability** row — naming which ability performed a write — and walk up `parent_id`
  to reach the call. Against two tables it could only have referenced the request
  row, losing which of several abilities did it.

### What the merge costs, stated plainly

1. **The name changes.** `wp_acrossai_mcp_activity_log`, not `request_logs`.
2. **Seven columns are request-only** (`method`, `transport`, `session_id`,
   `request_id`, `client_name`, `client_version`, `connector_slug`) and sit empty
   on ability rows. Cheaper than 16 duplicated columns in a second table, and
   MySQL stores an empty varchar in a byte.
3. **The two enable toggles now gate in the recorder**, keyed on `event_type`,
   not by which table is written. Same two toggles, same operator-visible
   behaviour; the request toggle is still read in `MCP\Controller` so a disabled
   request log keeps passing `NullMcpObservabilityHandler::class` and costs
   nothing at runtime.
4. **Retention must purge per `event_type`.** Ability rows are much noisier than
   request rows and should age out sooner. One global cutoff on one table would
   force the noisy half's retention onto the quiet half. The cleanup cron takes a
   cutoff per event type.

### Where the merge stops

Change history is **not** a third `event_type` on this table, and it is not this
feature. A change row is not a span — no duration, no status, no session, no
input/output, and two `longtext` blobs instead — so it needs its own table, its own
indexes and its own retention. That table and everything that fills it belong to
[097](097-mcp-change-history.md). The full argument lives there, next to the schema
it justifies.

The test being applied is the `cli_auth_logs` one: a table earns its own existence
by having a genuinely different *shape* — `auth_code_hash`, `code_challenge`,
`app_password_uuid` — not by being a different *source* of the same shape. Requests
and abilities are the same shape, so they share a table. A change row is not, so it
does not. A `connector_logs` table would fail the test, which is why `source` is a
column.

---

## The seam: the adapter's observability handler

`includes/MCP/Controller.php:225` currently reads (the handler argument is line 234):

```php
$result = $adapter->create_server(
    $slug, $namespace, $route, $server->server_name,
    self::instructions_for( $server ), $version,
    array( HttpTransport::class ),
    ErrorLogMcpErrorHandler::class,
    NullMcpObservabilityHandler::class,   // ← everything is thrown away here
    $tools, $resources, $prompts
);
```

`McpAdapter::create_server()` takes `?string $observability_handler` as a
**class-string** implementing
`WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface`
— a single method:

```php
public function record_event( string $event, array $tags = array(), ?float $duration_ms = null ): void;
```

`Transport\Infrastructure\RequestRouter` calls it on **all four** exit paths of
every JSON-RPC dispatch (error DTO, success DTO, unexpected type, thrown
exception) with event `mcp.request`, and these tags:

| Tag | Source | Notes |
|---|---|---|
| `method` | request | `tools/call`, `tools/list`, `initialize`, `ping`, `resources/read`, `prompts/get`, … |
| `transport` | transport class | currently always the HTTP transport |
| `server_id` | `McpServer::get_server_id()` | **our server slug** — joins straight to `wp_acrossai_mcp_servers.server_slug` |
| `params` | request, sanitized | via `sanitize_params_for_logging()` |
| `request_id` | JSON-RPC envelope | |
| `session_id` / `new_session_id` | `HttpRequestContext` | `new_session_id` only on `initialize` |
| `status` | outcome | `success` \| `error` |
| `error_code`, `failure_reason`, `error_type`, `error_category` | outcome | populated per failure path |
| `component_type`, `tool_name` / `prompt_name` / `resource_uri` | `resolve_component_observability_context()` | |
| `site_id`, `user_id`, `timestamp` | `McpObservabilityHelperTrait::get_default_tags()` | |
| *(duration)* | 3rd argument | float ms, measured by the router |

Plus two lifecycle events we can ignore or record cheaply: `mcp.server.created`
(`McpAdapter.php:290`) and `mcp.component.registration`.

The trait also ships `sanitize_tags()`, which **redacts 24 sensitive key patterns**
(`token`, `secret`, `authorization`, `api_key`, `client_secret`, …) and truncates
values at 1024 bytes. Our handler calls it rather than reinventing redaction — and
so does the change recorder, via the same `is_sensitive_key()` helper. One list,
not three.

### The one constraint this seam imposes

The handler is instantiated by the adapter as `new $observability_handler()` —
**no constructor arguments**. It cannot be handed the server row. That is fine:
`server_id` arrives in the tags and we resolve the numeric row id from the slug
with a per-request static cache. Everything the handler needs that is *not* in the
tags must come from request-scoped globals (`$_SERVER`, current user, the holder
described below) — which is exactly how the connection-type derivation works.

### What the seam does NOT give us

- **Pre-dispatch failures.** A 401 from `TokenValidator`, a transport permission
  denial, or a malformed-JSON body never reaches `RequestRouter`, so no
  `mcp.request` fires. Out of scope for 096; the `source` column leaves room for a
  later `auth` pass — done properly, with its own `event_type`, not behind a fake
  tool name the way easy-mcp-ai does it.
- **Raw request/response bodies.** Only the sanitized `params` tag. This is a
  feature, not a gap — see MCP Tracker's problem (3) — but it means the detail
  panel shows sanitized params, not a full transcript.
- **The result.** `RequestRouter` passes tags and a duration and **never the result
  payload**. On success nothing about what came back reaches `record_event()`; on
  failure only `failure_reason` does. This is why the request row's
  `output_preview` stays empty and why the merged table matters: the child ability
  row's `output_preview` carries the real result, from `wp_ability_execute_result`.
  The response's **size** is still reachable, via the five vendor result filters —
  see §"Token accounting". We hook them for `strlen()` only, never to store a
  payload.

---

## Connection type — the column the references don't have

"Connection type" is **derived**, not stored by anything upstream. It answers "how
did this client reach us?" and is assembled from four signals available during the
request:

| Signal | Where it lives | Gives us |
|---|---|---|
| OAuth bearer token → client row | `Database\OAuthClients` has `client_name` **and `connector_slug`**; `Connectors\ConnectorSlugDisplay::bucket()` already buckets the slug | `ai-connector` + which connector (claude, chatgpt, gemini, …) |
| Application password | WP sets `$GLOBALS['wp_rest_application_password_uuid']` on success | `app-password` |
| Logged-in cookie + REST nonce | `wp_validate_auth_cookie` path, no bearer, no app password | `browser` |
| `clientInfo` from `initialize` | `SessionManager::create_session()` stores `client_params` (the whole `initialize` params, `clientInfo` included) in user meta, readable later via `get_session( $user_id, $session_id )` | client **name and version** — "Claude Code 1.x", "Cursor", the npm bridge |

Resolved values for `connection_type`:

- `ai-connector` — OAuth bearer whose client has a `connector_slug`
- `oauth` — OAuth bearer with no connector profile (a bare DCR client)
- `app-password` — application password (the npm bridge and most CLI clients)
- `browser` — cookie + nonce (admin UI, Quick Connect preview)
- `unknown` — none of the above matched

`client_name` / `client_version` are stored as **separate columns**, resolved from
the session's `clientInfo`, and are what the UI shows as a chip. `connection_type`
is the coarse bucket you filter on.

`connection_type` and `invoked_via` are kept as **two columns, not one.** They
answer different questions — one is *how the credential arrived*, the other is
*which surface called the ability* — and they are populated on different rows. A
request row has `connection_type = ai-connector` and `invoked_via = mcp`; an
ability run from WP-CLI has `connection_type = unknown` and `invoked_via = cli`.
Collapsing them would force one five-value bucket to mean both things.

### Required: a request-scoped auth holder

`OAuth\TokenValidator::authenticate()` runs on `determine_current_user @ 20`,
resolves the token row and its client — and then **discards both**. By the time
`record_event()` fires, nothing knows an OAuth client was involved.

Fix: a tiny request-scoped holder in the shape of the existing
`Includes\Abilities\CurrentServerHolder` (same pattern, same lifetime, no DB).
`TokenValidator` sets the resolved `connector_slug` + `client_name` on it after a
successful validation; the observability handler reads it. Two one-line touches to
`TokenValidator`, zero behaviour change.

---

## The table — `wp_acrossai_mcp_activity_log`

One table, and the only one this feature creates. BerlinDB module under
`includes/Database/ActivityLog/`, matching the five-file `CliAuthLog` shape
exactly (`Schema`, `Table`, `Row`, `Query`, `Recorder` —
715 lines across those five for `CliAuthLog`, so expect roughly that). Table
version starts at `1.0.0`; `Table::maybe_upgrade()` carries the same
phantom-version guard as `CliAuthLog\Table`.

| Column | Type | Populated on | Why |
|---|---|---|---|
| `id` | bigint(20) unsigned AI | both | PK |
| `parent_id` | bigint(20) unsigned, default 0 | ability | → the `request` row that caused it. **The join that makes a dispatcher call readable.** 0 for request rows and for non-MCP ability calls |
| `event_type` | varchar(16), default `'request'` | both | `request` \| `ability`. The discriminator, after the AI plugin's `type` |
| `source` | varchar(32), default `'mcp'` | both | **reserved**: `mcp` now; `webmcp` / `auth` later without a row-shape migration |
| `server_id` | bigint(20) unsigned, default 0 | both | FK-ish to `wp_acrossai_mcp_servers.id`; 0 when the slug no longer resolves. Denormalized onto ability rows so the per-server tab filters without walking `parent_id` |
| `server_slug` | varchar(191), default `''` | request | the `server_id` tag verbatim — survives server deletion |
| `connection_type` | varchar(32), default `'unknown'` | request | derived bucket, above |
| `invoked_via` | varchar(32), default `''` | ability | `mcp` \| `rest` \| `cli` \| `admin` \| `unknown` |
| `client_name` | varchar(191), default `''` | request | from `clientInfo.name` |
| `client_version` | varchar(64), default `''` | request | from `clientInfo.version` |
| `connector_slug` | varchar(64), default `''` | request | from the OAuth client row, when any. Resolved to a display name **in bulk at read time** |
| `transport` | varchar(64), default `''` | request | the `transport` tag |
| `method` | varchar(64), default `''` | request | `tools/call`, `initialize`, … |
| `component_type` | varchar(32), default `''` | both | `tool` \| `prompt` \| `resource` \| `ability` |
| `component_name` | varchar(191), default `''` | both | tool name / prompt name / resource URI on a request row; the ability slug (`content/update-post`) on an ability row |
| `component_category` | varchar(64), default `''` | ability | from the registered ability category |
| `status` | varchar(32), default `''` | both | `success` \| `error` \| `denied` \| `invalid_input` |
| `error_code` | varchar(64), default `''` | both | JSON-RPC code, exception class marker, or `WP_Error` code |
| `error_message` | varchar(500), default `''` | both | truncated `failure_reason` / `error_category` / `WP_Error` message |
| `duration_ms` | int unsigned, nullable | both | request: the 3rd `record_event()` argument. ability: `wp_ability_invoked` → `wp_ability_execute_result` |
| `user_id` | bigint(20) unsigned, default 0 | both | |
| `ip_address` | varchar(45), default `''` | both | IPv4/IPv6; a connector that starts failing is often diagnosed by a changed source address (borrowed from easy-mcp-ai) |
| `session_id` | varchar(64), default `''` | request | `Mcp-Session-Id` |
| `request_id` | varchar(64), default `''` | request | JSON-RPC id |
| `input_preview` | text, nullable | both | **sanitized + truncated** — the `params` tag on a request row, the ability input on an ability row |
| `output_preview` | text, nullable | ability | the ability result, from `wp_ability_execute_result`. **Stays empty on request rows** — the seam never hands us the payload, and the child row carries it |
| `input_bytes` | int unsigned, nullable | both | `strlen()` of the params/input JSON **before** preview truncation. Exact, free, and the honest basis for a context-cost estimate — see §"Token accounting" |
| `output_bytes` | int unsigned, nullable | both | `strlen()` of the result JSON. Filled by the five vendor result filters — the one thing worth hooking them for |
| `created_at` | datetime, `created`, `date_query`, `sortable` | both | |

Indexes:

```
primary                   (id)
parent                    (parent_id)
type_created              (event_type, created_at)
server_type_created       (server_id, event_type, created_at)
type_status_created       (event_type, status, created_at)
type_component_created    (event_type, component_name, created_at)
type_connection_created   (event_type, connection_type, created_at)
type_user_created         (event_type, user_id, created_at)
created_at                (created_at)
```

`type_created` and `server_type_created` are the merge's price and its payoff: the
two tabs the UI used to get from two tables are now two index-covered queries on
one. The shape follows the AI plugin's `idx_timestamp_type_status`.

Deliberately **no FULLTEXT index in 1.0.0**. The AI plugin's `MATCH … AGAINST`
path exists because it searches long prose previews; our search target is
`component_name` + `error_message`, both short and both indexed. A `LIKE` search
over an indexed varchar is fine at our row widths, and skipping FULLTEXT avoids
the version-sniffing and `suppress_errors()` dance entirely. Revisit if search is
measurably slow on a real install.

Per the house convention on future columns, `Recorder` must build its insert from
a **declarative column map**, not a hardcoded positional `$wpdb->insert()` —
`source`, the `webmcp`-era columns and anything 097 brings must be additive.

`Query` exposes, beyond the BerlinDB defaults:

- `summary( array $filters )` — the stat-tile aggregate, transient-cached with
  explicit invalidation on write and purge
- `filter_options()` — distinct-value reader for the dropdowns, same caching
- `children( int $parent_id )` — the one-to-many read that was going to be
  `AbilityLog\Query::for_request()`
- bulk `connector_slug` → display-name labelling per page of rows, after
  easy-mcp-ai's `attach_labels()`

---

## Token accounting — what this table can and cannot tell you

**It cannot report LLM token usage, and no amount of schema fixes that.** The model
runs on the client's side — Claude.ai, Claude Code, Cursor. We are the tool server.
We never see the prompt, the completion or a usage block; the MCP protocol carries
no token counts, and neither do any of the adapter's observability tags. Anyone
expecting a "tokens spent" number from this table is expecting something that does
not exist on our side of the wire.

This is why the fourth stat tile is **Active Clients** and not the AI plugin's
**Tokens**. That plugin logs *LLM API calls* — it has `provider`, `model`,
`tokens_input`, `tokens_output`, `tokens_total` because it is the thing calling the
model. We are not, and copying those columns would produce five columns that are
always null.

**What it can report is context cost, which is the number an operator actually
needs.** Every byte we hand back is injected into the client's context window. A fat
`tools/list` or a `toolset/content` call that returns 200 posts silently eats the
user's context, and the operator has no way to find out which tool is the glutton.
`input_bytes` and `output_bytes` answer exactly that.

Three rules:

1. **Store bytes, not tokens.** Bytes are exact and cost a `strlen()`. A token count
   would be a guess, and a guess in a column named `tokens_output` gets aggregated
   into a cost report six months later by someone who did not read this document.
2. **Derive the estimate at read time**, in `Query` and the UI, and make the whole
   estimate pluggable — not just the constant. Compute a default from bytes, then
   hand it through a filter, exactly as the AI plugin wraps its own extraction in
   `wpai_request_log_tokens` so custom providers can substitute their logic:

   ```php
   $estimate = (int) ceil( $bytes / (int) apply_filters( 'acrossai_mcp_bytes_per_token', 4 ) );

   /**
    * Filters the estimated token count for a logged payload.
    *
    * Return an exact count if something on this site can produce one — a real
    * tokenizer, or a client that reports usage. Return null to render "—" rather
    * than a guess.
    */
   $tokens = apply_filters( 'acrossai_mcp_estimated_tokens', $estimate, $bytes, $row );
   ```

   Improving the heuristic then costs a filter, not a migration and not a backfill.

   **Why the AI plugin's method cannot be copied, only its shape.**
   `Log_Data_Extractor::extract_token_usage()`
   (`includes/Logging/Log_Data_Extractor.php:221`) does not estimate at all — it
   reads the provider's reported usage straight out of the HTTP response body, in
   three known shapes: OpenAI's `usage.prompt_tokens` / `usage.completion_tokens`,
   Anthropic's `usage.input_tokens` / `usage.output_tokens`, and Google's
   `usageMetadata.promptTokenCount` / `candidatesTokenCount + thoughtsTokenCount`.
   There is no tokenizer anywhere in that plugin or its vendored AiClient SDK. Their
   counts are **exact because they made the model call**; we never make one, so
   there is no `usage` block on our side of the wire to read. What transfers is the
   filter-with-default pattern above, not an algorithm.
3. **Label it as an estimate everywhere it appears** — "≈ 4.2k tokens", never
   "4,217 tokens". There is no PHP tokenizer in play and the real number depends on
   the client's model.

### Filling `output_bytes` — and the reversal of open decision #1

096 originally cut the vendor result filters because storing a result *preview* was
not worth three hooks. Measuring size is a much cheaper justification: one
`strlen( wp_json_encode( $result ) )` into a request-scoped integer, no payload
retained, no redaction required, no truncation question to answer. All of these are
verified present in `vendor/wordpress/mcp-adapter`, each firing before
`record_event()` in the same request:

| Filter | Where | Covers |
|---|---|---|
| `mcp_adapter_tool_call_result` | `Handlers/Tools/ToolsHandler.php:206` | `tools/call` |
| `mcp_adapter_tools_list` | `Handlers/Tools/ToolsHandler.php:84` | `tools/list` |
| `mcp_adapter_initialize_response` | `Handlers/Initialize/InitializeHandler.php:85` | `initialize` |
| `mcp_adapter_prompt_get_result` / `mcp_adapter_prompts_list` | prompt handlers | `prompts/get`, `prompts/list` |
| `mcp_adapter_resource_read_result` / `mcp_adapter_resources_list` | resource handlers | `resources/read`, `resources/list` |

`mcp_adapter_tools_list` is the load-bearing one and the reason this cannot be
solved by rolling up children. **`tools/list` has no ability rows** — it is the
server describing itself — so summing `output_bytes` over `parent_id` would miss the
single biggest context consumer in most MCP setups: the entire tool catalogue,
injected at connect, on every new conversation. A server whose catalogue costs 15k
tokens to announce is a real and common problem, and this is the only column that
would surface it.

The filters must be read-only pass-throughs: take the size, return `$result`
untouched. A filter that mutates a tool result in order to log it is a bug waiting
for a support ticket.

### What the numbers are good for

- **"Which tool is eating the context?"** Order by `output_bytes` over the period.
  This is the primary question and it has no other answer today.
- **"Did my catalogue get fat?"** `output_bytes` on `method = tools/list` over time.
  It moves when toolsets are added, and F092's stable tool menu is exactly the kind
  of change that should be measurable here.
- **"Is this call expensive because it is slow or because it is huge?"**
  `duration_ms` and `output_bytes` side by side separate the two.
- **Roll-up per call.** Sum the children's `output_bytes` against the parent's, and a
  dispatcher that returns little while its abilities produced a lot shows up as a
  gap — one more thing the merged table gives free.

---

## Capture

Two recorders, one shared rule: **neither may ever throw.** Both run inside the
execution path of real traffic.

### 1. Request rows — the observability handler

`includes/Observability/ActivityObservabilityHandler.php` implements
`McpObservabilityHandlerInterface`, handles `mcp.request`, ignores every other
event, runs tags through `sanitize_tags()`, and writes one `event_type = request`
row. Swapped in at `MCP\Controller.php:234` **only when the request toggle is on**
— otherwise `NullMcpObservabilityHandler::class` stays and a disabled log costs
nothing at runtime.

### 2. Ability rows — core hooks plus our own bracket

No callback wrapping and no `CallbackReplacer` trickery is needed. WordPress core
instruments `WP_Ability::execute()` directly, and the hooks fire for **every**
caller — MCP, the REST abilities route, WP-CLI, the admin UI.

| Hook | Since | Fires |
|---|---|---|
| `wp_ability_invoked` ( `$name`, `$input`, `$ability` ) | 7.1.0 | **every** call, before normalization, regardless of outcome — the start/timer hook, and the raw input |
| `wp_ability_validate_input` | 7.1.0 | input-validation failures |
| `wp_ability_permission_result` ( `$permission`, `$name`, `$input`, `$ability` ) | 7.1.0 | permission denials — a refused call is a row worth having |
| `wp_ability_execute_result` ( `$result`, `$name`, `$input`, `$ability` ) | 7.1.0 | the end hook that **sees `WP_Error`** |
| `wp_before_execute_ability` | 6.9.0 | after validation + permission check |
| `wp_after_execute_ability` | 6.9.0 | success only |

**The version split is load-bearing.** The plugin's floor is WP 6.9
(`acrossai-mcp-manager.php:27`), and the four richest hooks are `@since 7.1.0`.
Only the 6.9 pair is guaranteed — and `wp_after_execute_ability` **returns early on
`WP_Error`**, so on 6.9/7.0 that pair records successes and is blind to every
failure and every permission denial. A failure-blind log is worse than no log,
because it reads as "nothing went wrong".

Resolution — two capture paths, neither raising the floor:

1. **Core hooks when present.** Feature-detect `wp_ability_invoked` and register
   the 7.1 quartet. Full coverage of all callers, including denials and errors.
2. **Our own bracket, always.** `Base_Toolset_Ability::do_execute()`
   (`includes/Abilities/Toolset/Base_Toolset_Ability.php:1069`) and
   `Includes\Abilities\Execute` are plugin-owned choke points through which every
   MCP-originated ability call passes. Bracketing them in `try`/`finally` works on
   any supported WP and catches errors directly.

On WP 7.1+ path 1 is authoritative and path 2 only supplies MCP correlation (the
`parent_id`). On 6.9/7.0, MCP-originated calls are fully logged via path 2 and
non-MCP calls degrade to success-only — stated in the UI, not silently.

Guard against double-recording when both paths see the same call: a static
re-entry flag keyed on the ability name plus the current `parent_id`.

The bracket that supplies `parent_id` lives in `includes/History/CaptureContext.php`
— one ambient "we are inside ability X of request N" state, armed in `try`/`finally`
and readable by anything that needs to attribute work to the call that caused it.
096 needs it for `parent_id`. **097 arms its change recorder from this same
context**, which is the entire interface between the two features: 097 adds
listeners, it does not add a second bracket. Build it as a general context, not as a
private field of the ability recorder.

### Recursion and self-capture

Both recorders write rows while armed, and an ability that reads or writes the
activity table would otherwise record itself recording. Each must ignore its own
table and guard re-entry with a static flag — easy-mcp-ai needs a whole
`class-change-context.php` for exactly this and it is not optional. 097 adds a
third recorder behind the same guard, so the ignore list is a shared constant, not
a literal repeated per recorder.

## Files

**New — `includes/Database/ActivityLog/`** — `Schema`, `Table`, `Row`, `Query`,
`Recorder` (five files, `CliAuthLog` shape). `Query` adds `summary()`,
`filter_options()`, `children()` and bulk connector labelling.

**New — `includes/Observability/`**
- `ActivityObservabilityHandler.php` — implements
  `McpObservabilityHandlerInterface`. Handles `mcp.request`; ignores every other
  event. Uses `sanitize_tags()` for redaction. **Must never throw** — the whole
  body is wrapped and failures are swallowed (optionally to `error_log`).
- `ConnectionContext.php` — the derivation table above, plus the
  slug → `server_id` resolver with a per-request static cache.
- `AuthContextHolder.php` — request-scoped holder in the `CurrentServerHolder`
  shape.
- `ResponseSizeProbe.php` — the five vendor result filters
  (`mcp_adapter_tool_call_result`, `mcp_adapter_tools_list`,
  `mcp_adapter_initialize_response`, `mcp_adapter_prompt_get_result` /
  `mcp_adapter_prompts_list`, `mcp_adapter_resource_read_result` /
  `mcp_adapter_resources_list`), each taking `strlen( wp_json_encode( $result ) )`
  into request scope and returning `$result` **untouched**. Read-only
  pass-throughs; a filter that mutates a tool result in order to log it is a bug
  waiting for a support ticket.

**New — `includes/Abilities/AbilityActivityRecorder.php`** — the two capture paths
from §Capture.2: the WP 7.1 core-hook quartet when available, and the always-on
`try`/`finally` bracket. Owns the static re-entry guard. **Must never throw.**

**New — `includes/History/CaptureContext.php`** — the ambient "we are inside
ability X of request N" state: arm/disarm in `try`/`finally`, the re-entry guard,
and the current `activity_id`. 096 uses it for `parent_id`; **097 arms its change
recorder from it.** Build it as a general context with a public reader, not as a
private field of the ability recorder, or 097 has to re-bracket the same two
methods.

**New — `includes/REST/ActivityLogController.php`** — namespace
`acrossai-mcp-manager/v1`: `GET /activity`, `DELETE /activity` (purge),
`GET /activity/summary`, `GET /activity/filters`, `GET /activity/{id}`,
`GET /activity/{id}/children`. `permission_callback` = `manage_options` (match the
existing admin controllers; confirm against `AccessControl` before coding).
Collection params: `event_type`, `parent_id`, `server_id`, `connection_type`,
`invoked_via`, `status`, `method`, `component_name`, `component_category`,
`user_id`, `date_from`, `date_to`, `search`, `page`, `per_page`, `orderby`,
`order`, `cursor_id`, `cursor_timestamp`.

One controller, not two. The `event_type` param is what the old two controllers'
separate `get_collection_params()` were really expressing, and it is honest here
because every param applies to the one table — the ones that only make sense for
one event type narrow rather than error.

**New — `admin/Partials/ActivityLogPage.php`** — submenu under the existing
AcrossAI menu (via `Admin\Partials\Menu`, **not** `tools.php` — the AI plugin uses
Tools because it has no menu of its own; we do). Mounts the React app, localizes
`rest` + `initialState` as `AI_Request_Log_Page::enqueue_assets()` does.

**New — `src/js/activity-log/`** — `index.js`, `fields.js`, `StatTiles.js`,
`DetailPanel.js`, `ManageLogs.js`. Built via the existing `wp-scripts` pipeline;
add the entry to `webpack.config.js`. One component, two mount points — the global
page passes no `serverId`, the per-server tab passes one and hides the Server
column.

**Modified**
- `includes/MCP/Controller.php` (line 234) — swap `NullMcpObservabilityHandler::class` for
  our handler **when the request toggle is enabled**, else leave Null.
- `includes/OAuth/TokenValidator.php` — populate `AuthContextHolder`.
- `includes/Abilities/Toolset/Base_Toolset_Ability.php` — one `try`/`finally`
  bracket around `do_execute()` (line 1069) arming `CaptureContext`.
- `includes/Abilities/Execute.php` — the same bracket.
- `admin/Partials/ServerTabs/McpTrackerTab.php` — `render_body()` mounts the React
  table filtered to this server. Keep the "MCP Tracker is installed" detection as a
  secondary note if the third-party plugin is active; drop the install CTA.
- `includes/Activator.php` — install the activity table.
- `includes/Loader.php` / `includes/Main.php` — wire the page, controller, cron.
- `admin/Partials/Settings.php` — two independent toggles and a retention setting;
  see below.
- `uninstall.php` — drop the activity table under the existing opt-in.
- `webpack.config.js`, `docs/planings-tasks/README.md`.

---

## Settings

Ship **off by default**, like the AI plugin's experiment gate.

| Toggle | Default | Why separate |
|---|---|---|
| Request logging | off | Read in `MCP\Controller`, so off means `NullMcpObservabilityHandler` and zero runtime cost |
| Ability logging | off | Far noisier — one `tools/call` fans out into several abilities, and non-MCP callers add rows with no MCP traffic at all |

Two retention settings, both in days and both defaulting to 30: one for request
rows and one for ability rows. The daily cleanup cron is scheduled **only when at
least one is > 0** and cleared when both return to 0, and it prunes **per
`event_type`** so the noisy ability rows can age out before the quiet request rows.
A single global cutoff would force the ability half's retention onto the request
half, which is the one real obligation the merged table creates.

097 adds its own toggle and its own retention for the change log. Leave room in the
settings layout for a third row rather than hard-coding a two-row block.

---

## UI

Mirrors the screen at `tools.php?page=ai-request-logs`, with MCP columns, one
table, and parent/child expansion where the plan used to have two tabs.

```
┌─────────────────────────────────────────────────── [ Last 24 Hours ▾ ] ┐
│  MCP Activity                                                           │
│  Every request your MCP servers answered, and what ran behind it.       │
├─────────────┬─────────────┬─────────────┬─────────────────────────────  │
│  Requests   │  Avg Time   │ Success Rate│  Active Clients               │
├─────────────┴─────────────┴─────────────┴─────────────────────────────  │
│  [🔍 Search]  [⚙ filters]                      [↻] [⚙]  │ Manage Logs  │
│  TIME     SERVER      CONNECTION    METHOD/TOOL   STATUS  TIME │ Danger │
│                                                                │ Zone   │
│ ▾ 6:10 pm AcrossAI    Claude ·      tools/call     ok    142ms │ Purge  │
│                       ai-connector  toolset-content            │ All    │
│   └ ability           mcp           content/update-post ok 118ms│ Logs   │
│   6:09 pm Default     npm bridge ·  tools/list     ok     18ms │        │
│                       app-password                             │        │
└────────────────────────────────────────────────────────────────┴────────┘
```

The default view is `event_type = request` with children loaded on expand — a
tree, which is what the merged table makes free and what the two-table plan could
only fake with a second endpoint. An **Abilities** filter chip flips the view to
`event_type = ability` for the "what ran on this site today, MCP or not" question;
that is a filter, not a tab, because it is one table.

The fourth tile is **Active Clients** rather than the AI plugin's **Tokens** — MCP
requests have no token counts (§"Token accounting" explains why that is permanent,
not a gap), and distinct `client_name` in the period is the number an operator
actually wants. The period header carries a **Context returned** figure — the sum
of `output_bytes` over the period as an approximate token count — and the table
offers a sortable `output_bytes` column, because "which tool is eating the context"
is the question this log can answer that nothing else can.

Row expansion shows a detail panel: sanitized input, output preview (on ability
rows), session id, request id, error code + message, user, transport, and the
**context cost** (`output_bytes`, rendered as "12.4 KB ≈ 3.1k tokens" with the
approximation marked). 097 adds a "Changes (N)" line here; leave the layout able to
take one more row.

---

## Phases

**Phase 0 — confirm the seams (do this first, it is cheap and load-bearing).**
Drop a throwaway handler that `error_log`s every `record_event()` call, point
`Controller.php` at it, connect the local `acrossai` server from Claude, and
capture one real `tools/call`. Confirm by inspection: `server_id` really is our
slug; `tool_name` really is present; `session_id` is populated on calls after
`initialize`; `duration_ms` is non-null. **Everything downstream assumes these four
facts.** *(This mirrors how F093's Phase 0 was run and is why its three-bug fix
commit exists.)*

Phase 0 also covers the ability half: confirm on this WP version which ability hooks
exist (`wp_ability_invoked` is `@since 7.1.0`; the floor is 6.9), and that a
`tools/call` to a `toolset/*` dispatcher produces **both** a request event and a
distinct ability execution — the parent/child relationship is the whole shape of
this table. Prove the `try`/`finally` disarms on a **deliberately thrown** ability
before building anything on `CaptureContext`; 097 depends on that guarantee too.

**Phase 1 — storage.** The `ActivityLog/` BerlinDB module + `Activator` +
`uninstall`. PHPUnit: schema contract test (every column in `Schema` exists after
install, and widths match — the F029 drift class of bug), `Recorder` insert
round-trip, `Query::summary()` aggregation, `children()`.

**Phase 2 — request capture.** `ActivityObservabilityHandler`,
`ConnectionContext`, `AuthContextHolder`, the `TokenValidator` touch, the
`Controller.php` swap + toggle, plus `ResponseSizeProbe` — the five vendor result
filters stashing `strlen()` into request scope for the handler to collect. Tests:
each of the five `connection_type` branches resolves from a faked request context;
the handler swallows a `Recorder` exception without rethrowing; a disabled toggle
leaves `NullMcpObservabilityHandler` in place; a request row has
`event_type = request` and `parent_id = 0`; `input_bytes` and `output_bytes` are
non-null on a `tools/call`; **a `tools/list` row has a non-null `output_bytes`**
despite having no ability children; each probe filter returns its `$result`
byte-identical to what it received.

**Phase 2b — ability capture.** `AbilityActivityRecorder`, `CaptureContext`, both
paths, the re-entry guard, the `Base_Toolset_Ability` / `Execute` brackets. Tests: a
successful ability writes one row with `parent_id` set when called through MCP and
0 when called over REST; a `WP_Error` result records `status = error` (the test that
would have caught the `wp_after_execute_ability` early-return trap); a permission
denial records `status = denied` on WP 7.1+; both capture paths firing for one call
produce exactly **one** row; an ability that throws still leaves the bracket via
`finally`; the recorder never rethrows.

**Phase 3 — REST.** `ActivityLogController`, collection params, cursor pagination.
Tests: permission denial for a subscriber, each filter narrows, cursor and offset
pagination return the same rows, `?parent_id=` returns only that call's abilities.

**Phase 4 — UI.** React app, the tree view, the Abilities filter chip, the global
page, the per-server tab replacement, stat tiles, the context-cost figure, purge,
settings (two toggles + two retention settings + the cron).

**Phase 5 — polish.** Retention cron with per-`event_type` cutoffs, cache
invalidation on write, PHPStan clean (**clear the result cache first** — a warm
cache invents errors CI never sees), `npm run build` green, the four pre-ship
validate scripts.

---

## Risks and the answers

| Risk | Answer |
|---|---|
| **Write volume.** A chatty client can issue hundreds of calls a minute, now two or more rows each. | Off by default, three independent toggles, retention cron with per-`event_type` cutoffs. Single flat INSERT, no joins on the write path. If it still bites, batch per-request — but measure first. |
| **One table carries both volumes.** Ability rows outnumber request rows and age out sooner. | The cleanup cron prunes per `event_type`, so the noisy half does not drag the quiet half's retention. This is the merge's one genuine new obligation and it is in Phase 5. |
| **Empty columns on ability rows.** Seven request-only columns. | Cheaper than 16 duplicated columns, a second module and a second controller. An empty varchar costs a byte. |
| **PII / secrets in previews.** Tool arguments can contain anything. | `sanitize_tags()` runs first (24 redaction patterns, 1024-byte truncation), then we truncate again. Never store raw bodies — this is exactly where MCP Tracker goes wrong. 097 reuses the same `is_sensitive_key()` helper rather than growing a second list. |
| **A logging bug breaking MCP.** Both recorders run inside real execution paths. | Every body wrapped, nothing propagates. Non-negotiable, and each gets its own test. |
| **Adapter API drift.** We depend on tag names inside `RequestRouter`, which is vendor code. | Tags are read defensively with `??` defaults; a renamed tag degrades one column to empty, never fatals. Pin the observed adapter version here when Phase 0 confirms it — the 0.6.1 → 0.7.0 meta-ability episode is the precedent. |
| **Failure-blind ability logs on WP 6.9/7.0.** `wp_after_execute_ability` returns early on `WP_Error`. | The always-on `try`/`finally` bracket covers every MCP-originated call on any WP version. Non-MCP calls on 6.9/7.0 degrade to success-only, and the UI says so. |
| **Double-recording.** On WP 7.1+ both ability paths see the same call. | Static re-entry guard keyed on ability name + current `parent_id`, with a dedicated test asserting exactly one row. |
| **Overlap with MCP Tracker.** Both installed = double logging. | Harmless (different tables), but the tab should say so. Detection already exists in `McpTrackerTab`. |
| **Someone reads `output_bytes` as billed tokens.** The columns sit next to `duration_ms` and look like usage metering. | Columns are named in bytes, the derived figure is always rendered with an approximation marker, and §"Token accounting" states up front that LLM usage is unobtainable on our side of the wire. No `tokens_*` column exists to be mistaken for one. |
| **A result filter mutating a payload.** `ResponseSizeProbe` sits on five filters that can rewrite tool results. | Pass-throughs only, with a test per filter asserting `$result` comes back byte-identical. The probe takes a length and returns the input. |

---

## Open decisions

Raised in review 2026-10-09, revisited 2026-10-16. Resolve during
`/speckit.clarify` rather than silently during implementation.

| # | Question | Status |
|---|---|---|
| 1 | **`result_preview`** — add vendor result filters to fill it, or cut it? | **Resolved, twice.** The *preview* needs no filters: the child ability row's `output_preview` carries the result from `wp_ability_execute_result`. The filters come back only to fill `output_bytes` with a `strlen()` — a far cheaper justification, and the only way to measure `tools/list`, which has no ability children. |
| 2 | **Noise floor.** `ping` and `tools/list` are most of the volume and least of the value. | Open. Default: denylist `ping`, filterable. |
| 3 | **`initialize` session join.** For `initialize` the `session_id` tag is null and the id lives in `new_session_id`. | Open. Default: coalesce, `session_id = session_id ?: new_session_id`. |
| 4 | **`protocol_version`.** `initialize` carries it; first thing you want when a client breaks after an upgrade. One `varchar(16)`. | Open. Default: add it. |
| 5 | **npm bridge vs CLI client.** Both authenticate by application password. | Open. Default: add `user_agent varchar(255)` rather than inventing a sixth bucket. |
| 6 | **Retention default.** | **Resolved.** 30 days for both tables, pruned per `event_type` on the activity table. |
| 8 | **The `bytes_per_token` divisor.** 4 is the usual English rule of thumb; JSON and code are denser, so the estimate reads low on exactly the payloads we measure. | Open. Default: ship 4 behind `acrossai_mcp_bytes_per_token`, wrap the result in `acrossai_mcp_estimated_tokens` (after the AI plugin's `wpai_request_log_tokens` shape) so an exact count can be substituted, label every figure as approximate, and revisit once real `output_bytes` data exists. Never store the derived number. |
| 9 | **A fifth stat tile for context cost**, or detail panel only? | Open. Default: keep four tiles (the AI plugin's layout is familiar), add a "Context returned" figure to the period header and a sortable `output_bytes` column. |

---

## Speckit Workflow

```markdown
# 1. Branch
/speckit.git.feature "mcp-activity-log"

# 2. Specify
/speckit.specify "Add MCP logging to acrossai-mcp-manager in ONE table: an activity
log holding both MCP JSON-RPC requests and ability executions as a parent/child
tree, plus a React DataViews admin screen for it, modelled on the WordPress AI
plugin's Tools > AI Request Logs page.

TABLE SHAPE — this is a revision of an earlier three-table plan, do not re-split.
MCP requests and ability executions go in ONE table discriminated by an event_type
column and self-joined by parent_id. All four reference plugins that log anything
(wordpress ai, mcp-tracker, easy-mcp-ai; novamira logs nothing) use a single
call-level table, and the request/ability column lists were near-duplicates: nine
identical columns, plus params_preview/result_preview = input_preview/output_preview,
component_name = ability_slug, failure_reason = error_message, and
ability_logs.tool_name was just the parent's component_name denormalized.

OUT OF SCOPE, and this is the point of the narrowed scope: NO change log. Do not
create a change-log table, do not register any WordPress write hooks (post_updated,
update_option, update_postmeta and the rest), do not write a HookRecorder or a
ValueDiffer, and do not add a before/after diff anywhere. Change history is Feature
097 and it owns that table, its capture and its screen end to end. The ONE thing
096 owes it is includes/History/CaptureContext.php — see CAPTURE (2) below.

CAPTURE, two recorders, neither of which may EVER throw:
(1) Request rows via the adapter's observability seam. Do NOT filter
rest_pre_dispatch/rest_post_dispatch the way the third-party mcp-tracker plugin
does. Implement
WP\\MCP\\Infrastructure\\Observability\\Contracts\\McpObservabilityHandlerInterface
in includes/Observability/ActivityObservabilityHandler.php and pass its class-string
as the 9th argument to \$adapter->create_server() in includes/MCP/Controller.php,
replacing NullMcpObservabilityHandler::class — but only when the request toggle is
on, so a disabled log costs nothing. Handle only the 'mcp.request' event; ignore
mcp.server.created and mcp.component.registration. The handler is constructed with
NO arguments, so resolve everything else from the tags and from request scope. Run
tags through McpObservabilityHelperTrait::sanitize_tags() before storing. NOTE the
seam never passes the result payload — leave output_preview empty on request rows;
the child ability row carries the result, so no filter is needed for the PREVIEW.
DO add includes/Observability/ResponseSizeProbe.php hooking five vendor filters —
mcp_adapter_tool_call_result, mcp_adapter_tools_list,
mcp_adapter_initialize_response, mcp_adapter_prompt_get_result /
mcp_adapter_prompts_list, mcp_adapter_resource_read_result /
mcp_adapter_resources_list — purely to take strlen( wp_json_encode( \$result ) )
into request scope for the handler to read into output_bytes. Each must return
\$result UNTOUCHED. mcp_adapter_tools_list is the load-bearing one: tools/list has
no ability children, so it cannot be measured by rolling up parent_id, and the tool
catalogue injected at connect is usually the single biggest context cost a server
imposes.
(2) Ability rows. Do NOT wrap callbacks via CallbackReplacer — WordPress core
already instruments WP_Ability::execute() for every caller. Use TWO paths:
core hooks when available — wp_ability_invoked (start/timer, every call, raw input),
wp_ability_execute_result (end hook; it receives WP_Error so it sees failures),
wp_ability_permission_result (denials), wp_ability_validate_input — ALL FOUR are
@since 7.1.0 and the plugin floor is WP 6.9, so feature-detect, do not raise the
floor; plus an always-on try/finally bracket at
Includes\\Abilities\\Toolset\\Base_Toolset_Ability::do_execute() and
Includes\\Abilities\\Execute. The 6.9 pair
wp_before_execute_ability/wp_after_execute_ability is NOT sufficient alone:
wp_after_execute_ability returns early on WP_Error, so it records successes and is
blind to every failure and denial. On WP 6.9/7.0 MCP-originated calls are fully
covered by the bracket and non-MCP calls degrade to success-only — state that in the
UI. Guard double-recording with a static re-entry flag keyed on ability name plus
current parent_id. The bracket must live in a GENERAL class,
includes/History/CaptureContext.php, holding the ambient 'inside ability X of
request N' state with a PUBLIC reader — arm/disarm, re-entry guard, current
activity_id. 096 needs it for parent_id; Feature 097 arms its change recorder from
this same context, so it must not be a private field of the ability recorder or 097
has to re-bracket the same two methods.

STORAGE, table 1: BerlinDB module includes/Database/ActivityLog/ with Schema, Table,
Row, Query, Recorder — same five-file shape as includes/Database/CliAuthLog/,
including the phantom-version guard override of maybe_upgrade(). Table
acrossai_mcp_activity_log, version 1.0.0, per-site prefix. Columns: id, parent_id
(0 for request rows and non-MCP ability calls), event_type (request|ability, default
'request'), source (default 'mcp', reserved for later webmcp/auth rows), server_id,
server_slug, connection_type, invoked_via (mcp|rest|cli|admin|unknown — a SEPARATE
column from connection_type, they answer different questions), client_name,
client_version, connector_slug, transport, method, component_type, component_name
(the tool/prompt/resource name on a request row, the ability slug on an ability row),
component_category, status (success|error|denied|invalid_input), error_code,
error_message varchar(500), duration_ms, user_id, ip_address varchar(45), session_id,
request_id, input_preview (text), output_preview (text), input_bytes (int unsigned
nullable), output_bytes (int unsigned nullable), created_at. Indexes:
primary(id), parent(parent_id), type_created(event_type, created_at),
server_type_created(server_id, event_type, created_at),
type_status_created(event_type, status, created_at),
type_component_created(event_type, component_name, created_at),
type_connection_created(event_type, connection_type, created_at),
type_user_created(event_type, user_id, created_at), created_at(created_at).
EVERY composite key leads with event_type — one table holds two populations, so a
key without the discriminator matches rows of both kinds and every single-kind
query pays for the other's (measured: 742 rows examined vs 239, 2.8x). NO FULLTEXT index.
Query must expose summary(), filter_options(), children(int \$parent_id), and bulk
connector_slug -> display-name labelling per page of rows the way easy-mcp-ai's
AuditLogRepository::attach_labels()/credential_label() does — do NOT denormalize a
connector name into every row. client_name is the opposite case and stays
denormalized: it names the client application from clientInfo and has no row to join
to.

The Recorder builds its insert from a DECLARATIVE COLUMN MAP so future columns are
additive, never a hardcoded positional \$wpdb->insert().

REDACTION: run tags through
WP\\MCP\\Infrastructure\\Observability\\McpObservabilityHelperTrait::sanitize_tags()
(24 patterns, 1024-byte truncation) before storing, then truncate the preview again.
Never store raw request or response bodies — that is exactly where the third-party
mcp-tracker plugin goes wrong.

RECURSION: each recorder must ignore its own table and guard re-entry with a static
flag, and the ignore list must be a shared constant rather than a literal repeated
per recorder, because 097 adds a third recorder behind the same guard.

CONNECTION TYPE: derive in includes/Observability/ConnectionContext.php.
'ai-connector' = OAuth bearer whose OAuthClients row has a connector_slug; 'oauth' =
bearer with no connector_slug; 'app-password' = application-password auth; 'browser'
= cookie+nonce; 'unknown' otherwise. To make the OAuth case reachable, add a
request-scoped holder includes/Observability/AuthContextHolder.php in the shape of
includes/Abilities/CurrentServerHolder.php, populated from
OAuth\\TokenValidator::authenticate() after a successful validation (connector_slug +
client_name). Resolve client_name/client_version from the adapter session's stored
client_params.clientInfo via SessionManager::get_session(). Resolve numeric server_id
from the server_slug tag with a per-request static cache.

REST: ONE controller, includes/REST/ActivityLogController.php in namespace
acrossai-mcp-manager/v1, with GET /activity, DELETE /activity (purge),
GET /activity/summary, GET /activity/filters, GET /activity/{id},
GET /activity/{id}/children. Collection params: event_type, parent_id, server_id,
connection_type, invoked_via, status, method, component_name, component_category,
user_id, date_from, date_to, search, page, per_page, orderby, order, cursor_id,
cursor_timestamp. Support BOTH offset and keyset pagination: when orderby=timestamp
and cursor_id+cursor_timestamp are supplied use ((created_at < %s) OR (created_at =
%s AND id < %d)) instead of LIMIT/OFFSET, and return next_cursor. Cache summary and
filter-options queries in transients with explicit invalidation on write and purge.
Add NO change-log routes and no change-log anything — that is Feature 097.

UI: one React app in src/js/activity-log/ built with the existing wp-scripts pipeline
and @wordpress/dataviews (already a dependency). Mount it TWICE: a new global page
admin/Partials/ActivityLogPage.php registered under the existing AcrossAI menu (NOT
tools.php), and inside admin/Partials/ServerTabs/McpTrackerTab.php::render_body(),
which currently only advertises the third-party mcp-tracker plugin — replace that
body with the table pre-filtered to the current server, hiding the Server column.
Keep the slug 'mcp-log' and label 'Logs'. Layout mirrors the AI plugin's page: period
picker top right, four stat tiles (Requests, Avg Time, Success Rate, Active Clients —
NOT tokens; this table CANNOT report LLM token usage, because the model runs on the
client side and neither MCP nor the adapter's tags carry a usage block, so do not
add tokens_input/tokens_output/tokens_total columns that would always be null), search + filter chips, the table, and a 'Manage Logs' sidebar with a
Purge All Logs danger zone. The default view lists event_type=request rows with their
ability children loaded on EXPAND — a tree, not two tabs, because it is one table.
An 'Abilities' filter chip flips the view to event_type=ability for the
'what ran on this site today, MCP or not' question. Row expansion shows input_preview,
output_preview, session id, request id, error code, error message, user, transport,
and the CONTEXT COST. Context cost is output_bytes rendered as an
APPROXIMATE token count ('12.4 KB ~ 3.1k tokens'), derived at READ TIME: divide by a
filterable 'acrossai_mcp_bytes_per_token' defaulting to 4, then pass the result
through an 'acrossai_mcp_estimated_tokens' filter so a site that can produce an exact
count substitutes it and returning null renders a dash instead of a guess. This
mirrors how the AI plugin wraps its own extraction in 'wpai_request_log_tokens'. Note
that plugin has NO estimation algorithm to copy — Log_Data_Extractor::extract_token_usage()
reads the provider's reported usage out of the response body it received (OpenAI
usage.prompt_tokens, Anthropic usage.input_tokens, Google usageMetadata.promptTokenCount),
which is exact precisely because that plugin made the model call. We never make one.
Store BYTES only, never the derived number, and label every token figure as
approximate. A token count in a column named tokens_output would get aggregated into
a wrong cost report later. Leave the detail-panel layout able to take one more row:
Feature 097 adds a 'Changes (N)' line to it. The period header
carries a 'Context returned' total and the table offers a sortable output_bytes
column, because 'which tool is eating the client's context' is the question this log
can answer that nothing else can.

SETTINGS in admin/Partials/Settings.php: ship DISABLED by default, like the AI
plugin's experiment gate. TWO independent toggles — request logging and ability
logging — because ability logging is far noisier and an operator must be able to run
one without the other. Read the request toggle in MCP\\Controller so a disabled
request log keeps passing NullMcpObservabilityHandler::class and costs nothing at
runtime. TWO retention settings (request days, ability days, default 30 each).
Schedule the daily cleanup cron only when at least one retention > 0, clear it when
both return to 0, and have it prune PER event_type so noisy ability rows age out
before quiet request rows — a single global cutoff would force the ability half's
retention onto the request half. Lay the settings block out so a third toggle row can
be added without restructuring it; Feature 097 adds one. Drop the activity table in
uninstall.php under the existing opt-in.

OUT OF SCOPE: WebMCP calls through includes/REST/WebMcpController.php, and OAuth/auth
events. The 'source' column exists so those can be added later as their own rows
without reshaping anything — note that easy-mcp-ai, lacking such a column, writes auth
events into its audit table behind a fake tool_name of '_oauth_refresh' with the event
stuffed into result_status; do not repeat that. Also out of scope, restated because an
earlier draft of this plan included it: the entire change log — its table, its write
hooks, its recorder, its diff and its screen. That is Feature 097."

# 3. Clarify / Plan / Tasks / Implement
/speckit.clarify
/speckit.plan
/speckit.tasks
/speckit.implement
```

**Success criteria:**
- [ ] Phase 0 confirmed `server_id`, `tool_name`, `session_id`, `duration_ms` arrive in real traffic
- [ ] A real `tools/call` from a connected AI client writes exactly one `event_type = request` row with `parent_id = 0`
- [ ] That row's `connection_type` is `ai-connector` and `client_name` is the connecting client
- [ ] A request through the npm bridge writes `app-password`
- [ ] A failing tool call records `status = error` with a non-empty `error_message`
- [ ] One `tools/call` to a `toolset/*` dispatcher writes one request row **and** at least one `event_type = ability` child whose `parent_id` is the request row
- [ ] Expanding the request row in the UI shows those children without a second endpoint
- [ ] An ability invoked over the REST abilities route writes a row with `parent_id = 0` and `invoked_via = rest`
- [ ] An ability returning `WP_Error` records `status = error` — the `wp_after_execute_ability` early-return trap
- [ ] On WP 7.1+, a permission denial records `status = denied`
- [ ] Both ability capture paths seeing one call produce exactly **one** row
- [ ] An ability that throws still leaves the bracket via `finally`
- [ ] `CaptureContext` is publicly readable and arms/disarms independently of the ability recorder — 097 depends on it
- [ ] Request toggle off ⇒ zero request rows and `NullMcpObservabilityHandler` is what `create_server()` receives
- [ ] Ability toggle off ⇒ zero ability rows, request logging unaffected
- [ ] A thrown `Recorder` exception does not surface to the MCP client
- [ ] Per-server Logs tab shows only that server's rows; global page shows all
- [ ] `input_bytes` and `output_bytes` are non-null on a real `tools/call`
- [ ] A `tools/list` row has a non-null `output_bytes` — the case that cannot be rolled up from children
- [ ] Every `ResponseSizeProbe` filter returns its `$result` byte-identical
- [ ] No `tokens_*` column exists, and every token figure in the UI is rendered as an approximation
- [ ] The cleanup cron prunes ability rows and request rows on independent cutoffs
- [ ] **No change-log table, no write hooks, no diff** — nothing from Feature 097 landed here
- [ ] `npm run build` exits 0; PHPStan clean (cache cleared first); all PHPUnit suites pass

## Pre-ship Scripts (run after everything)

```bash
node .agents/skills/wp-plugin-development/scripts/validate-structure.mjs --dir=.
node .agents/skills/wp-plugin-development/scripts/validate-security.mjs --dir=.
node .agents/skills/wp-plugin-development/scripts/detect-deprecations.mjs --dir=.
node .agents/skills/wp-plugin-development/scripts/detect-rest-endpoints.mjs --dir=.
```
