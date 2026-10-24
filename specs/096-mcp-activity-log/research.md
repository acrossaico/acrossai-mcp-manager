# Phase 0 — Research

No `NEEDS CLARIFICATION` markers entered this phase: five were resolved in
`/speckit-clarify`, two by `memory-synthesis.md`, one by codebase inspection. This document
records those decisions in the required form, plus the four facts that must still be verified
against **live traffic** before storage is built.

## Decisions

### D-1 — One table with `event_type` + `parent_id`, not two tables

**Decision**: a single `acrossai_mcp_activity_log` holding request and ability events,
discriminated by `event_type`, related by a self-referencing `parent_id`.
**Rationale**: the two row shapes were near-duplicates — nine identical columns, four renamed
pairs (`params_preview`/`input_preview`, `component_name`/`ability_slug`,
`failure_reason`/`error_message`, plus `ability_category`/`component_category`) — and the child's
`tool_name` was the parent's `component_name` denormalised, which `parent_id` supplies for free.
All four reference plugins that log anything (WordPress AI, mcp-tracker, easy-mcp-ai) use one
call-level table; novamira logs nothing.
**Alternatives considered**: two tables (rejected — five extra files, a second REST controller,
a second schema-contract suite, and the UI tree faked through a second endpoint); three tables
including change history (rejected — a change row is not a span and needs its own retention and
`object_lookup` index); per-connection-method tables (rejected — identical rows differing in
three attribution columns, and a three-way `UNION` that breaks keyset pagination).

### D-2 — Capture requests at the adapter's observability seam

**Decision**: implement `McpObservabilityHandlerInterface` and pass the class-string as
`create_server()`'s 9th argument, replacing `NullMcpObservabilityHandler`, only when the
request toggle is on. Handle `mcp.request`; ignore `mcp.server.created` and
`mcp.component.registration`.
**Rationale**: the adapter already measures every dispatch and hands over method, tool name,
duration, status, failure reason, session id and our server slug on all four exit paths. We
currently discard all of it. The handler is constructed with no arguments, so everything else
resolves from tags plus request scope.
**Alternatives considered**: filtering `rest_pre_dispatch`/`rest_post_dispatch` as mcp-tracker
does (rejected — misses `initialize`, which is the one request carrying `clientInfo`, because
no session exists yet; fires on all REST traffic; and that plugin stores entire bodies as
LONGTEXT with no truncation or redaction).

### D-3 — Response size via five published result filters; response content from the child row

**Decision**: `ResponseSizeProbe` hooks `mcp_adapter_tool_call_result`,
`mcp_adapter_tools_list`, `mcp_adapter_initialize_response`, `mcp_adapter_prompt_get_result` /
`mcp_adapter_prompts_list`, `mcp_adapter_resource_read_result` / `mcp_adapter_resources_list`,
takes `strlen( wp_json_encode( $result ) )` into request scope, and returns `$result`
untouched. Request rows leave `output_preview` empty.
**Rationale**: the seam passes tags and a duration and **never the payload**, so a request-row
preview was unreachable without these filters — but a preview was not worth them. A `strlen()`
is: no payload retained, no redaction needed, no truncation question.
`mcp_adapter_tools_list` is load-bearing: `tools/list` has no ability children, so it cannot be
measured by rolling up `parent_id`, and the tool catalogue injected at connect is usually the
largest single context cost a server imposes.
**Alternatives considered**: storing a result preview on the request row (rejected — the child
ability row already carries the real result from `wp_ability_execute_result`); rolling size up
from children (rejected — misses `tools/list`, `initialize` and every list method).

### D-4 — Two ability capture paths, neither raising the WP floor

**Decision**: feature-detect and register the WP 7.1 quartet (`wp_ability_invoked`,
`wp_ability_execute_result`, `wp_ability_permission_result`, `wp_ability_validate_input`), plus
an always-on `try`/`finally` bracket at `Base_Toolset_Ability::do_execute()` and
`Abilities\Execute`. A static re-entry flag keyed on ability name + current `parent_id` keeps
one call to one row.
**Rationale**: the plugin floor is WP 6.9 and the four rich hooks are `@since 7.1.0`. The
guaranteed 6.9 pair is not sufficient alone — `wp_after_execute_ability` **returns early on
`WP_Error`**, so it records successes and is blind to every failure and denial, which is worse
than no log because it reads as "nothing went wrong".
**Alternatives considered**: raising the floor to 7.1 (rejected — the floor is a product
constraint); wrapping callbacks via `CallbackReplacer` (rejected — core already instruments
`WP_Ability::execute()` for every caller, so wrapping duplicates core's seam and still misses
non-MCP callers).

### D-5 — Both request-scoped holders carry the three-stage lifecycle

**Decision**: `AuthContextHolder` and `CaptureContext` each capture before dispatch, clear on
the normal path, **and clear again on `shutdown`**. Hooks at priorities 5 / 999 / 999, wired in
`Main.php`.
**Rationale**: `docs/memory/ARCHITECTURE.md` A17 is the established pattern here and states
that omitting the `shutdown` binding is "the number-one silent bug in ad-hoc versions". Under a
persistent SAPI (FrankenPHP, Roadrunner, keep-alive wp-env) a fatal leaks captured auth
identity into the next request — which, in a log whose entire purpose is attribution, would
credit one client's calls to another.
**Alternatives considered**: the two-hook version the spec originally implied (rejected by
`memory-synthesis.md` Conflict 1 before planning).

### D-6 — `TokenValidator` fires an action; it does not call the holder

**Decision**: `OAuth\TokenValidator::authenticate()` fires one scoped, fire-and-forget
`do_action()` after successful validation. `AuthContextHolder` listens, wired in `Main.php`.
**Rationale**: Principle V prescribes hooks over modifying existing files, and D19 already
establishes the fire-and-forget observability action for this codebase. A direct
`AuthContextHolder::set()` call would couple the OAuth module to the observability module for
no gain. If the action ever needs fail-forward semantics, D39 requires per-listener isolation
rather than native `do_action()`.
**Alternatives considered**: direct coupling (rejected — Finding C); re-resolving the bearer
token at `rest_pre_dispatch` (rejected — duplicates token validation, violating Principle VI).

### D-7 — Bytes stored, tokens derived, never the reverse

**Decision**: store `input_bytes` / `output_bytes`. Derive an approximate token count at read
time: divide by a filterable `acrossai_mcp_bytes_per_token` (default 4), then pass through
`acrossai_mcp_estimated_tokens` so a site that can produce an exact count substitutes one, or
returns `null` to render a dash. Every figure is labelled approximate.
**Rationale**: bytes are exact and cost a `strlen()`; a token count is a guess, and a guess in
a column named `tokens_output` gets aggregated into a cost report later by someone who did not
read the doc. The filter-with-default shape mirrors the AI plugin's `wpai_request_log_tokens`.
**Alternatives considered**: copying the AI plugin's method (impossible — verified that
`Log_Data_Extractor::extract_token_usage()` performs no estimation; it reads the provider's
reported usage out of a response body it received, which is exact *because that plugin made
the model call*. There is no tokenizer in that plugin or its vendored SDK, and we never make a
model call, so no `usage` block exists on our side of the wire).

### D-8 — Retention plus a retained-event ceiling; never sampling

**Decision**: 30-day retention per `event_type`, plus a configurable maximum retained-event
count trimmed oldest-first by the same cron.
**Rationale**: retention alone leaves worst-case size a function of traffic. Pruning per
`event_type` stops the noisy ability half forcing its cutoff onto the quiet request half.
**Alternatives considered**: sampling above a threshold (rejected — it makes the success-rate
tile and the context-size ranking estimates rather than facts, and an operator cannot tell a
sampled log from a quiet one).

### D-9 — UTC storage, site-time presentation

**Decision**: store UTC; convert for display, the period picker, date-range filter input and
the retention cutoff.
**Rationale**: matches `CliAuthLog\Recorder` (`current_time( 'mysql', 1 )`) and easy-mcp-ai
(`current_time( 'mysql', true )`). It is the only option where "last 24 hours" means the same
thing to the reader and to the pruning job.
**Alternatives considered**: site-local storage (rejected — a time-zone or DST change makes
existing rows unorderable against new ones).

## Phase 1 — verified against live traffic, 2026-10-16

Executed on `wordpress-7-0.local` against the `acrossai` server
(`/wp-json/acrossai/mcp`, `server_slug = acrossai-mcp-server`). Four real JSON-RPC requests —
`initialize`, `tools/list`, and two `tools/call` — through the adapter's `RequestRouter`, using
an application password and a throwaway handler that logged **tag keys and duration only** (no
tag values; `sanitize_tags()` does not exist until T026 — SC-C17). Handler, probe credential and
a temporary memory-limit shim all reverted afterwards.

### The four facts: all confirmed

| # | Assumption | Result |
|---|---|---|
| 1 | `server_id` tag is our server **slug** | ✅ `acrossai-mcp-server`, `my-own` — matches `wp_acrossai_mcp_servers.server_slug` exactly. `server_slug varchar(191)` stands |
| 2 | `tool_name` present on `tools/call` | ✅ present — **but see D-10, it is not the name we want** |
| 3 | `session_id` populated after `initialize` | ✅ `bb9526ea-…` on `tools/list` and `tools/call`. On `initialize` itself `session_id` is **absent** and the id arrives as `new_session_id` — Open Decision #3's coalesce is confirmed necessary, not hypothetical |
| 4 | `duration_ms` non-null | ✅ 5.72 ms (`initialize`), 6.04 (`tools/list`), 35.66 (`tools/call`) |

Observed tag set on a `tools/call`:
`method, transport, server_id, params, request_id, session_id, component_type, tool_name,
ability_name, source, status` — plus `failure_reason` on the error path.

`mcp.server.created` fires **twice per HTTP request** (once per enabled server) with
`duration_ms = NULL`. Ignoring it, as planned, is correct — recording it would double every
request's row count.

**Adapter version pinned:** `wordpress/mcp-adapter` as vendored on 2026-10-16. Re-verify the tag
set on any adapter bump; the 0.6.1 → 0.7.0 meta-ability episode is the precedent.

### Four corrections to the plan — the reason this phase existed

#### D-10 — `component_name` must source `ability_name`, not `tool_name`

**Decision**: store the adapter's `ability_name` in `component_name`, falling back to
`tool_name` / `prompt_name` / `resource_uri` when no ability is involved. Record the name the
client actually used in a new `invoked_name` column when it differs.
**Rationale**: the two tags carry different strings for the same call —
`tool_name = toolset-server-guide` but `ability_name = toolset/server-guide`. `tool_name` is
passed through the vendor's `McpNameSanitizer::sanitize_name`, which replaces `/` with `-`.
Storing the sanitized form would mean `component_name` matches **no ability slug anywhere else
in the system** — not the abilities registry, not F097's change rows, not the "known raw-SQL
ability" check the Changes tab depends on. Memory B31 records this sanitizer biting a previous
feature that compared `$tool_name` against a constant.
**Alternatives considered**: storing only the sanitized name (rejected — unjoinable); storing
only the slug (rejected — an operator debugging "my client says tool X is missing" needs the
exact string the client sees in `tools/list`).

#### D-11 — our `source` column is renamed `event_source`

**Decision**: rename the reserved `mcp` | `webmcp` | `auth` column from `source` to
`event_source`.
**Rationale**: **the adapter already sends a `source` tag, and on a toolset call its value is
`ability`.** A recorder that maps tags to columns by name — which a declarative column map
invites — would write `ability` into a column whose entire purpose is to distinguish MCP traffic
from WebMCP and auth traffic. The collision is silent and the wrong value is plausible, which is
the worst combination. Renaming our column is better than remapping the tag, because the next
person reading tags alongside columns would make the same mistake again.
**Alternatives considered**: keeping `source` and excluding the tag explicitly (rejected — relies
on a comment surviving); capturing the adapter's `source` too (out of scope, no consumer).

#### D-12 — `error_code` has no tag to come from

**Decision**: leave `error_code` empty unless a JSON-RPC error code is available on the response
envelope; `failure_reason` carries the diagnostic text.
**Rationale**: the failing `tools/call` produced `status = error` with `failure_reason` and **no
`error_code` tag at all**. The plan's column note ("JSON-RPC code, exception class marker, or
`WP_Error` code" — from the tags) describes a tag that does not exist for this failure mode.
An input-validation failure surfaces as `status = error` plus prose, nothing more.
**Alternatives considered**: dropping the column (rejected — ability rows fill it from
`WP_Error::get_error_code()`, and pre-dispatch failures would fill it later).

#### D-13 — `user_id` comes from `get_current_user_id()`, not from tags

**Decision**: resolve `user_id` in the handler from `get_current_user_id()`. Do not expect
`site_id` or `timestamp` tags.
**Rationale**: the plan asserted `McpObservabilityHelperTrait::get_default_tags()` supplies
`site_id`, `user_id` and `timestamp`. The observed tag set contains **none of the three**. The
handler runs inside the authenticated request, so the current user is available directly; the
timestamp is the row's own `created_at`.
**Alternatives considered**: calling `get_default_tags()` ourselves (rejected — the handler is
constructed with no arguments and the trait's defaults are the adapter's business).

Also observed: `transport` is the short label `HTTP`, not a transport class FQN as the plan
assumed. `varchar(64)` is ample; no change needed.

### Still unverified — carried into Phase 2b

The two **structural** checks could not be completed, because the probe only reached the request
layer:

1. That one `tools/call` to a `toolset/*` dispatcher produces **both** a request event and a
   distinct ability event. The parent/child relationship is the table's whole shape, and the
   adapter's `ability_name` tag on the request row is suggestive but not proof that a separate
   `WP_Ability::execute()` fires.
2. That a **deliberately thrown** ability still leaves `CaptureContext` disarmed.

Both require the ability-capture path, which does not exist until T052. They are assertions in
`AbilityRecorderTest` (T062) and the thrown-ability test (T063) rather than pre-build checks —
but if (1) turns out false, `parent_id` has nothing to point at and the merged-table design needs
revisiting, so it is checked **first** in Phase 2b, not last.

### Open minor item — a BerlinDB deprecation this table triggers

Booting `ActivityLog\Schema` emits one PHP 8.2 deprecation from vendor code:
*"Creation of dynamic property `BerlinDB\Database\Kern\Index::$length`"*. `Kern\Index`
declares no `$length`, and `Traits\Base::set_vars()` assigns every arg key blindly, so
something upstream passes one.

Ruled out: none of our nine index definitions carries a `length` key, and renaming the index
whose name matched a column (`created_at`) did **not** clear it. The existing `database` suite,
which boots five other tables, emits it zero times — so it is specific to this table's shape,
but the assignment happens inside BerlinDB.

It is a notice, not an error: PHPCS, PHPStan level 8 and all 21 DB-backed tests pass with it
present. Recorded rather than guessed at. Worth ten minutes with a stack trace before the
feature ships, since a deprecation that nobody explains tends to get suppressed instead of fixed.

### Environment note

This site's PHP `memory_limit` is 256 MB and is exhausted during plugin load — by its own plugin
set (BuddyBoss, WooCommerce, The Events Calendar, Elementor) plus the abilities-manager's
registration pass — **before any MCP code runs**. Every MCP request returns HTTP 500 until the
limit is raised. Not a Feature 096 defect, but it will block T002 on re-run and any manual
testing of the finished screen.

---

## Phase 2 — D-1 re-tested empirically, 2026-10-21

D-1 (one table) was argued from design, not measured. It has now been benchmarked against the
rejected two-table split, in an isolated `acrossai_bench` database seeded with identical data
in both designs. The one-table side used the REAL shipped schema, dumped from the live site
with `SHOW CREATE TABLE` rather than re-declared, so the comparison covers what actually ships.

### D-14 — Two tables is faster on most queries, and the margin is immaterial

At 9,377 events (3,000 requests + 6,377 abilities), p50 over 60 runs:

| query | one table | two tables | |
|---|---|---|---|
| newest 25 requests | 0.25 ms | 0.17 ms | two, 1.5x |
| newest 25 abilities | 0.26 ms | 0.14 ms | two, 1.8x |
| children of one request | 0.12 ms | 0.10 ms | tie |
| **global feed, both kinds** | **0.22 ms** | 4.97 ms | **one, 23x** |
| summary tiles, 24 h | 0.32 ms | 0.26 ms | two, 1.2x |
| filter server + status | 0.69 ms | 0.28 ms | two, 2.4x |
| deep keyset page | 0.99 ms | 0.65 ms | two, 1.5x |
| retention scan | 2.49 ms | 1.12 ms | two, 2.2x |

Two tables wins six of eight. It does not matter: the slowest query in EITHER design is 2.5 ms
and the largest gap is 1.4 ms, on a cron job, behind a screen that takes ~200 ms to render.
Storage favours two tables — 8.0 MB against 5.5 MB, 46% more for one table, almost all index.

### D-15 — The mixed-kind query does not degrade gracefully, and the ceiling needs it

Scaled 16x to 150,032 rows, the two-table constant-factor edge holds — but the mixed feed
collapses, because the UNION must materialize and re-sort both tables before taking 25 rows:

```
global feed:    9,377 rows -> one 0.18 ms | two   4.97 ms  (28x)
              150,032 rows -> one 0.14 ms | two 124.89 ms  (911x)
```

The list view always filters by `event_type`, so that query is theoretical there. But
`Query::enforce_ceiling()` is genuinely mixed: it is a GLOBAL bound, trimming oldest-first
across both kinds. At 100,000 retained of 150,032 rows:

```
ONE TABLE  global bound, 1 statement       30.3 ms
TWO TABLES global bound, UNION cutoff      56.6 ms
TWO TABLES per-table caps, 2 statements    21.9 ms   <- not a global bound
```

Two tables only beats it by abandoning the guarantee: with per-table caps a burst of ability
rows can no longer evict old request rows, so "never exceed N events" stops being true. The
cost of the split is therefore not latency, it is a dropped invariant. **D-1 stands.**

### D-16 — Four shipped indexes were mis-keyed (fixed in schema 1.1.0)

The two-table advantage was traced rather than assumed. `EXPLAIN` on the server + status
filter showed the mechanism: a key that omits the discriminator matches rows of BOTH
populations.

```
(status, created_at)              742 rows examined, 1.155 ms
(event_type, status, created_at)  239 rows examined, 0.413 ms
```

Same answer, 2.8x less work. `status_created`, `component_created`, `connection_created` and
`user_created` all shipped without the prefix in 1.0.0, while `ActivityLogController` defaults
`event_type` to `request` — so the predicate is present on every list query and these keys had
no caller that wanted them un-prefixed. Schema 1.1.0 re-keys and renames all four;
`Table::upgrade_to_1_1_0()` performs it. Closing the gap did NOT make one table win the other
six queries — the residue is simply half as many entries per index — which is the point: the
decision rests on D-15, not on the margins.

### D-17 — Two defects found while testing the migration

1. **`created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00'`** — loading the real dumped
   schema into a strict-mode session FAILS. BerlinDB created it under WordPress's permissive
   `sql_mode`; the benchmark needed `SET SESSION sql_mode='NO_ENGINE_SUBSTITUTION'`. Harmless
   on this site, would break installation on a host that runs strict mode.

2. **A missing version option silently skips every migration.** BerlinDB takes the upgrade
   branch whenever the table exists, but `get_pending_upgrades()` returns nothing when
   `$db_version` is empty — so it runs no callbacks and then stamps the current version
   anyway. The table keeps its old physical shape while reporting itself current, and every
   later boot agrees. Found because the 1.1.0 migration did not apply in the test harness,
   which resets options but leaves tables standing. `Table::maybe_upgrade()` now seeds the
   oldest shipped version in that case — the mirror of the phantom-version guard already
   there. Both directions are now covered by tests.
