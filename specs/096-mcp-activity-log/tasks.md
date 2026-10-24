---

description: "Task list for F096 — MCP Activity Log"
---

# Tasks: MCP Activity Log

**Input**: Design documents from `/specs/096-mcp-activity-log/`
**Prerequisites**: plan.md, spec.md (45 FR, 23 SC, 4 user stories), research.md, data-model.md,
contracts/rest-activity.md, memory-synthesis.md, security-constraints.md

**Tests**: INCLUDED. Constitution VII's Definition of Done requires unit tests for all new
logic, and `plan.md` specifies eight test files. Tests are not optional for this feature.

**Organization**: grouped by user story so each is independently implementable and testable.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on incomplete work)
- **[Story]**: US1–US4 from spec.md
- Exact file paths included

## Path Conventions

WordPress plugin at repository root: `includes/`, `admin/Partials/`, `src/js/`,
`tests/phpunit/`. Namespace mirrors directory per the constitution's PHP Namespace Rule.

---

## Phase 1: Setup & Seam Verification

> ✅ **COMPLETE 2026-10-16.** Ran against live traffic on `wordpress-7-0.local`
> (`/wp-json/acrossai/mcp`). Four real JSON-RPC requests through `RequestRouter`. **All four
> facts confirmed** — `server_id` is the slug, `tool_name` present, `session_id` populates after
> `initialize` (absent on `initialize` itself, arriving as `new_session_id`), `duration_ms`
> non-null. **Four corrections followed** (`research.md` D-10…D-13): `component_name` sources
> `ability_name`, not the sanitizer-rewritten `tool_name`; our column is `event_source` because
> the adapter already sends `source` = `ability`; `error_code` has no tag; `user_id` comes from
> `get_current_user_id()`. Probe handler, credential and memory shim all reverted.
>
> Two **structural** checks need the ability-capture path that does not exist until T054, so they
> are carried into Phase 2b and checked **first** there: that a `toolset/*` call produces a
> distinct ability event, and that a thrown ability still disarms.
>
> ⚠ **Environment**: this site's 256 MB `memory_limit` is exhausted during plugin load, before
> any MCP code runs — every MCP request 500s until it is raised. Not a feature defect, but it
> blocks any re-run and any manual testing.

**Purpose**: confirm the four facts every later task assumes, before any storage exists.
`research.md` §"Still to verify" and the F093 precedent (a three-bug fix commit after its
Phase 0 ran for real) are why this is a phase and not an assumption.

- [x] T001 Add a throwaway observability handler in `includes/MCP/Controller.php` (line 234, temporarily) that `error_log()`s **tag keys and the duration only — never tag values**. `sanitize_tags()` does not exist until T028, so logging the `params` tag here writes unredacted tool arguments to `debug.log`, which is often web-readable and routinely pasted into support threads (SEC-T05, CWE-532). The four facts T002 verifies need no argument content
- [x] T002 Connect the local `acrossai` server from a real client, invoke one `toolset/*` tool, and record in `specs/096-mcp-activity-log/research.md` whether: (a) the `server_id` tag is our server **slug**, (b) `tool_name` is present, (c) `session_id` is populated after `initialize` and arrives as `new_session_id` on `initialize` itself, (d) `duration_ms` is non-null
- [x] T003 Confirm in the same run that one `tools/call` to a `toolset/*` dispatcher produces **both** a request event and a distinct ability execution — the parent/child relation is the table's whole shape
- [x] T004 Detect which ability hooks exist on this WordPress version (`wp_ability_invoked` is `@since 7.1.0`, floor is 6.9) and record the result in `research.md`
- [x] T005 Pin the observed `wordpress/mcp-adapter` version in `research.md` — we read tag names from vendor `RequestRouter`, and the 0.6.1 → 0.7.0 meta-ability episode is the precedent for checking the adapter before blaming our own code
- [x] T006 **Checkpoint, not optional:** revert T001's throwaway handler so `includes/MCP/Controller.php` returns to `NullMcpObservabilityHandler::class` until T034. Nothing else prevents a value-logging handler reaching production (SEC-T05)

**Checkpoint**: if any of T002's four facts is false, stop and revise `data-model.md` before
Phase 2. Everything downstream assumes them.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: storage, the shared constants, and the request-scoped holder pattern. No user story
can proceed without these.

- [x] T007 [P] Create `includes/Utilities/ActivityLogConstants.php` as a `final class` with a `private` constructor and only `const` declarations — the recorder ignore list, the table's option keys and the settings keys, per A9 (reference impl `includes/Utilities/AdminPageSlugs.php`). The ignore list is one constant because F097 adds a third recorder behind the same guard
- [x] T008 Create `includes/Database/ActivityLog/Schema.php` with the **32** columns from `data-model.md`, exact types and widths — including `event_source` (**not** `source`, D-11) and `invoked_name` (D-10). Declare no `modified` column — this table is append-only, and B21 records that the auto-update flag is spelled `modified`, not `date_updated`
- [x] T009 Create `includes/Database/ActivityLog/Table.php` — table `acrossai_mcp_activity_log`, version `1.0.0`, the 9 indexes from `data-model.md`, **no FULLTEXT**, and the phantom-version guard override of `maybe_upgrade()` copied from `includes/Database/CliAuthLog/Table.php`
- [x] T010 [P] Create `includes/Database/ActivityLog/Row.php`
- [x] T011 Create `includes/Database/ActivityLog/Recorder.php` to the A15 contract exactly: static methods only, no constructor, no `instance()`, each method wrapping one `( new Query() )->add_item([...])` in `try/catch (\Throwable)`, failures to `error_log()`, returns `void`, never Loader-wired. Build the insert from a **declarative column map**, never a positional `$wpdb->insert()`
- [x] T012 In `includes/Database/ActivityLog/Recorder.php`, filter the column map against `Schema::columns()` before persisting, per B7 — forged keys must not mass-assign
- [x] T013 In `includes/Database/ActivityLog/Recorder.php`, make the recorders ignore their own table and guard re-entry using `ActivityLogConstants`' shared ignore list (FR-015)
- [x] T014 Create `includes/Database/ActivityLog/Query.php` with `summary()`, `filter_options()`, `children( int $parent_id )`, and bulk `connector_slug` → display-name labelling (one pass per page, after easy-mcp-ai's `attach_labels()`); never denormalise a connector name into storage
- [x] T015 Create `includes/Observability/AuthContextHolder.php` as a request-scoped holder implementing the **A17 three-stage lifecycle**: capture at `rest_pre_dispatch` priority 5, clear at `rest_post_dispatch` priority 999, **and clear again at `shutdown` priority 999**. The shutdown stage is not optional — A17 names its omission the number-one silent bug in ad-hoc versions (FR-016, FR-017)
- [x] T016 Wire T015's three hooks in `includes/Main.php` via `define_admin_hooks()` — A1 admits no exceptions
- [x] T017 Add table installation to `includes/Activator.php` using `use` imports for the DB class, never bare or inline FQN (D6)
- [x] T018 Add the table drop to `uninstall.php` inside the existing opt-in gate, and confirm the `wp_options` LIKE-sweep covers the new `acrossai_mcp_activity_*` keys. **Verified: there is no longer a `acrossai_mcp_connector_%` exclusion** — F095 reclaimed the connector stack, so A20's precondition does not hold and the plain `acrossai_mcp_%` sweep is correct
- [x] T019 [P] Create `tests/phpunit/ActivityLogDb/SchemaContractTest.php` asserting every declared column exists **at its declared width** after install — not merely that it exists. B34: a live table that drifts while `db_version` still matches makes `add_item()` return `false` while callers read success, and the phantom-version guard catches only a *missing* table
- [x] T020 [P] Create `tests/phpunit/ActivityLogDb/RecorderTest.php` — insert round-trip, and a forced storage failure that asserts the recorder returns `void` without throwing
- [x] T021 [P] Create `tests/phpunit/ActivityLog/HolderLifecycleTest.php` — `shutdown` clears the holder, and no captured context survives into a following request (SC-020). Assert on a code path that reuses the process, not only one that tears it down

**Checkpoint**: storage installs, the contract test passes, the holder cannot leak.

> ✅ **T007 delivered 2026-10-16**, out of phase order and deliberately: it is pure constants,
> so it depends on none of the four facts T002 verifies, and it is verifiable with no database.
> `includes/Utilities/ActivityLogConstants.php` + `tests/phpunit/ActivityLog/ActivityLogConstantsTest.php`
> (6 tests, 32 assertions), new `activity-log` suite registered in `phpunit.xml.dist`.
> PHPCS clean, PHPStan level 8 clean (result cache cleared first). T008 onward remain blocked.

---

## Phase 3: User Story 1 — See what AI clients asked my servers to do (P1) 🎯 MVP

**Goal**: one row per MCP request, readable on a screen, filterable by server, connection type,
status and date.

**Independent test**: enable request logging, connect a client, invoke one tool, then confirm
the call appears with the right server, connection type, tool name, status and duration.

- [x] T022 [P] [US1] Create `includes/Observability/ConnectionContext.php` deriving `connection_type` — `ai-connector` (bearer whose `OAuthClients` row has a `connector_slug`), `oauth` (bearer without one), `app-password` (`$GLOBALS['wp_rest_application_password_uuid']`), `browser` (cookie + nonce), `unknown` — plus the slug → numeric `server_id` resolver with a per-request static cache
- [x] T023 [US1] Add one scoped, fire-and-forget `do_action()` to `includes/OAuth/TokenValidator.php` after successful validation, carrying `connector_slug` + `client_name`. Do **not** call `AuthContextHolder::set()` directly — Principle V prescribes hooks, and D19 is the established pattern. If this ever needs fail-forward semantics, D39 requires per-listener isolation over native `do_action()`
- [x] T024 [US1] Register `AuthContextHolder`'s listener for T023's action in `includes/Main.php`
- [x] T025 [US1] In `includes/Observability/ConnectionContext.php`, resolve `client_name` / `client_version` from the adapter session's stored `client_params.clientInfo` via `SessionManager::get_session()`
- [x] T026 [US1] Create `includes/Observability/ActivityObservabilityHandler.php` implementing `McpObservabilityHandlerInterface`. Handle `mcp.request` only; ignore `mcp.server.created` and `mcp.component.registration`. It is constructed with **no arguments**, so resolve everything else from tags and request scope
- [x] T027 [US1] In `includes/Observability/ActivityObservabilityHandler.php`, map tags per `research.md` D-10…D-13: `component_name` ← `ability_name` (falling back to `tool_name` / `prompt_name` / `resource_uri`), `invoked_name` ← `tool_name` when it differs, `user_id` ← `get_current_user_id()` (no such tag exists, nor `site_id` or `timestamp`), `error_code` left empty when no code is available, and **never** map the adapter's `source` tag onto `event_source`
- [x] T028 [US1] In `includes/Observability/ActivityObservabilityHandler.php`, run tags through `McpObservabilityHelperTrait::sanitize_tags()` before storing, then truncate the preview again. Never store raw request or response bodies
- [x] T029 [US1] In `includes/Observability/ActivityObservabilityHandler.php`, coalesce `session_id = session_id ?: new_session_id`, so the one event carrying `clientInfo` can still be tied to the session it opened
- [x] T030 [US1] In `includes/Observability/ActivityObservabilityHandler.php`, record `protocol_version`, `user_agent` and `ip_address` (full, per FR-039) on request rows
- [x] T031 [US1] In a new `includes/Observability/PersonalData.php`, register a personal-data **exporter** returning the subject's events, source addresses included (FR-037)
- [x] T032 [US1] In `includes/Observability/PersonalData.php`, register a personal-data **eraser** that **de-identifies only**: null `user_id`, clear `ip_address` and `user_agent`, retain the event, its timing, outcome and server. It MUST NOT delete events — deleting would make an unprivileged subject request an evidence-destruction path (FR-038, D60, SC-C2)
- [x] T033 [US1] Wrap the entire body of `includes/Observability/ActivityObservabilityHandler.php` so nothing propagates — a logging failure must not alter what the client receives (FR-013, SC-006)
- [x] T034 [US1] Swap `NullMcpObservabilityHandler::class` for our class-string at `includes/MCP/Controller.php` line 234, **read behind the request toggle**, so a disabled log never reaches the recording path (FR-029)
- [x] T035 [US1] In `includes/Database/ActivityLog/Recorder.php`, store `created_at` in UTC via `current_time( 'mysql', 1 )`, matching `CliAuthLog\Recorder` (FR-041)
- [x] T036 [US1] Create `includes/REST/ActivityLogController.php` with `GET /activity`, `/activity/summary`, `/activity/filters` and `/activity/{id}`. Every route declares an explicit `permission_callback` → a `permission_check` method → `manage_options`; `__return_true` must appear nowhere (SC-C8). **`DELETE /activity` is deliberately NOT in US1** — a purge that records nothing is SEC-001, so it ships in US4 with T079 (SEC-T01)
- [x] T037 [US1] Implement the 19 collection params from `contracts/rest-activity.md`, each sanitised with the most specific function and validated against `Schema::columns()` (B7)
- [x] T038 [US1] **Build the keyset-pagination helper in `includes/Database/ActivityLog/Query.php` — this is net-new work.** Verified: no REST controller in this plugin paginates, so there is nothing to reuse (B70). Implement `( created_at < %s ) OR ( created_at = %s AND id < %d )` under `$wpdb->prepare()`, engaged when `orderby=timestamp` and both cursor params are present, returning `next_cursor`. Shape it so F097 can consume it
- [x] T039 [US1] In `includes/REST/ActivityLogController.php`, omit `input_preview` / `output_preview` from the list response; return them only from `GET /activity/{id}`
- [x] T040 [US1] In `includes/REST/ActivityLogController.php`, convert `date_from` / `date_to` from site time zone to UTC on the way in, and UTC to site time on the way out (FR-042)
- [x] T041 [US1] In `includes/Database/ActivityLog/Query.php`, transient-cache `summary()` and `filter_options()` with explicit invalidation on write and purge, and validate the cached arrays on read with `is_array()` + `isset()` + `is_numeric()` (B11) — a partial write must not surface as a fatal on the tiles
- [x] T042 [P] [US1] Create `admin/Partials/ActivityLogPage.php` registering a submenu under the existing AcrossAI menu via `Admin\Partials\Menu` — **not** `tools.php`. Localise `rest` + `initialState` using `wp_json_encode()`, never `esc_html()`/`esc_attr()` (B36, SC-C5)
- [x] T043 [P] [US1] Create `src/js/activity-log/index.js` + `fields.js` — the DataViews shell, period picker, search, filter chips. No `dangerouslySetInnerHTML` anywhere in this directory (SC-C6)
- [x] T044 [P] [US1] Create `src/js/activity-log/StatTiles.js` — Requests, Avg Time, Success Rate, **Active Clients**. Not tokens: this table cannot report LLM usage (FR-022)
- [x] T045 [P] [US1] Create `src/js/activity-log/DetailPanel.js` — sanitised input, session id, request id, error code + message, user, transport. Leave the layout able to take one more row; F097 adds "Changes (N)"
- [x] T046 [US1] Add the `activity-log` entry to `webpack.config.js`
- [x] T047 [US1] **Accepted deviation — kept `AbstractServerTab`.** `AbstractReactMountServerTab`'s `register_rest_routes()` is `final` and registers a `CREATABLE` route unconditionally, and its two abstract state methods assume a save contract. A read-only log has no state to save, so extending it would mean two no-op methods plus a **mutating REST endpoint that does nothing** — added attack surface for no benefit. The migration plan's Phase 2.4 escape hatch, taken for the reason it anticipated. Instead `render_body()` mounts the same bundle via `ActivityLogPage::enqueue_bundle()`. Was: change to `extends AbstractReactMountServerTab` — the F037 primitive `EmbedsTab` already consumes — rather than hand-rolling a mount, and configure it for the current server with the Server column hidden. If the primitive cannot express the two-mount requirement, record an accepted deviation in `plan.md` naming what it lacks (`architecture-migration-plan.md` Phase 2). Keep slug `mcp-log` and label `Logs`; keep the "MCP Tracker is installed" detection as a secondary note and drop the install CTA
- [x] T048 [US1] In `admin/Partials/ActivityLogPage.php` and `admin/Partials/ServerTabs/McpTrackerTab.php`, wrap every `admin_url()` in `esc_url()` — it is filterable via the `admin_url` hook (S5, SC-C7)
- [x] T049 [US1] Add the request-logging toggle and its retention setting to `admin/Partials/Settings.php`, off by default, laid out so two more rows can be added without restructuring
- [x] T050 [P] [US1] Create `tests/phpunit/ActivityLogDb/ObservabilityHandlerTest.php` — each of the five `connection_type` branches resolves from a faked request context; the handler swallows a `Recorder` exception without rethrowing; a disabled toggle leaves `NullMcpObservabilityHandler` in place; a request row has `event_type = request` and `parent_id = 0`
- [x] T051 [P] [US1] Create `tests/phpunit/ActivityLogDb/RestActivityControllerTest.php` — a subscriber is refused on every route; each filter narrows; cursor and offset pagination return the same rows; the list route omits previews and `{id}` includes them
- [x] T052 [P] [US1] In `tests/phpunit/ActivityLogDb/RestActivityControllerTest.php`, add a test — **parameterised over every client-influenced field**: `component_name`, `error_message`, `client_name`, `user_agent`, `input_preview` — asserting markup or a script in any of them reaches the screen as inert text (SC-023, SEC-T06). US2 extends it to `output_preview` and `component_category`

**Checkpoint**: US1 is shippable alone. A broken connector becomes diagnosable for the first time.

---

## Phase 4: User Story 2 — See what actually ran behind a tool call (P2)

**Goal**: ability events as children of the request that caused them, plus ability events with
no MCP origin at all.

**Independent test**: enable both toggles, invoke one toolset tool, expand that row and confirm
the abilities beneath it. Then run the same ability over REST and confirm it appears with no
parent and `invoked_via = rest`.

- [x] T053 [US2] Create `includes/Observability/CaptureContext.php` with a single public `around( string $ability, callable $run ): mixed` wrapper that arms, invokes and disarms in `finally` (both choke points delegate to it — `architecture-migration-plan.md` Phase 1), holding the ambient "inside ability X of request N" state with a **public reader**, arm/disarm, the re-entry guard, and the current activity id. Same A17 three-stage lifecycle as T015. **Not** `includes/History/`: that name is F097's domain, and a directory owned jointly by two features satisfies neither "singular purpose" nor "replaceable without affecting siblings"
- [x] T054 [US2] In `includes/Abilities/Toolset/Base_Toolset_Ability.php` (line 1069, `private function do_execute`), delegate to `CaptureContext::around()` — **do not write a bracket here**. Two hand-written `try`/`finally` blocks would cross Principle VI's extraction threshold and can drift, and the disarm guarantee is F097's contract (see `architecture-migration-plan.md` Phase 1)
- [x] T055 [US2] In `includes/Abilities/Execute.php`, delegate to the same `CaptureContext::around()` wrapper — one line, no control flow of its own
- [x] T056 [US2] Create `includes/Abilities/AbilityActivityRecorder.php` registering the WP 7.1 quartet when feature-detected: `wp_ability_invoked` (start/timer, raw input), `wp_ability_execute_result` (end hook — it receives `WP_Error`, so it sees failures), `wp_ability_permission_result` (denials), `wp_ability_validate_input`. Do **not** raise the WP floor
- [x] T057 [US2] In `includes/Abilities/AbilityActivityRecorder.php`, do **not** wrap callbacks via `CallbackReplacer` — core already instruments `WP_Ability::execute()` for every caller, so wrapping duplicates core's seam and still misses non-MCP callers
- [x] T058 [US2] In `includes/Abilities/AbilityActivityRecorder.php`, add the static re-entry guard keyed on ability name + current `parent_id`, so one call yields exactly one row when both capture paths observe it (FR-010)
- [x] T059 [US2] In `includes/Abilities/AbilityActivityRecorder.php`, derive `invoked_via` (`mcp` | `rest` | `cli` | `admin` | `unknown`) as a column separate from `connection_type` — the two answer different questions and populate different rows
- [x] T060 [US2] In `includes/Abilities/AbilityActivityRecorder.php`, record `component_category` and fill `output_preview` from `wp_ability_execute_result`, sanitised and truncated
- [x] T061 [US2] Wire the recorder's hooks in `includes/Main.php` (A1)
- [x] T062 [US2] Add `GET /activity/{id}/children` to `includes/REST/ActivityLogController.php` — `WHERE parent_id = %d`
- [x] T063 [US2] Add children-on-expand to `src/js/activity-log/index.js`, and an **Abilities** filter chip flipping the view to `event_type = ability`. A filter, not a tab — it is one table
- [x] T064 [US2] In `src/js/activity-log/index.js`, state in the UI that on WP 6.9/7.0 non-MCP callers are recorded success-only, because `wp_after_execute_ability` returns early on `WP_Error` (FR-032). Do not imply full coverage
- [x] T065 [US2] Add the ability-logging toggle and its retention setting to `admin/Partials/Settings.php`, off by default, its description stating that **ability arguments are recorded** (FR-028, SC-C3)
- [x] T066 [P] [US2] Create `tests/phpunit/ActivityLogDb/AbilityRecorderTest.php` — a success writes one row with `parent_id` set via MCP and 0 via REST; a `WP_Error` result records `status = error` (the `wp_after_execute_ability` early-return trap); a denial records `status = denied` on WP 7.1+; both paths firing for one call produce exactly **one** row; an ability that throws still leaves the bracket via `finally`; the recorder never rethrows
- [x] T067 [P] [US2] In `tests/phpunit/ActivityLogDb/AbilityRecorderTest.php`, add a test asserting `CaptureContext` can be armed and read from outside `AbilityActivityRecorder` — the contract F097 depends on (FR-012, SC-018)

**Checkpoint**: a failing dispatcher call now names the specific ability and its input.

---

## Phase 5: User Story 3 — Find which tool is eating the client's context (P3)

**Goal**: record what the server handed back and rank it.

**Independent test**: invoke a tool returning a large result and connect a fresh client. Confirm
both the call and the catalogue announcement record a size, sorting by size ranks them, and every
token figure is marked approximate.

- [x] T068 [US3] Create `includes/Observability/ResponseSizeProbe.php` hooking `mcp_adapter_tool_call_result`, `mcp_adapter_tools_list`, `mcp_adapter_initialize_response`, `mcp_adapter_prompt_get_result` / `mcp_adapter_prompts_list`, `mcp_adapter_resource_read_result` / `mcp_adapter_resources_list`. Each takes `strlen( wp_json_encode( $result ) )` into request scope and returns `$result` **untouched** — a filter that mutates a tool result in order to log it is a bug waiting for a support ticket
- [x] T069 [US3] Wire the five filters in `includes/Main.php` (A1)
- [x] T070 [US3] In `includes/Observability/ActivityObservabilityHandler.php` and `includes/Abilities/AbilityActivityRecorder.php`, fill `input_bytes` from the params JSON **before** preview truncation, and `output_bytes` from the probe
- [x] T071 [US3] In `includes/Database/ActivityLog/Query.php`, derive the approximate token figure at read time in `Query` / the UI: divide by a filterable `acrossai_mcp_bytes_per_token` (default 4), then pass through `acrossai_mcp_estimated_tokens`, where a site may substitute an exact count or return `null` to render a dash. **Store bytes only, never the derived number**
- [x] T072 [US3] In `src/js/activity-log/DetailPanel.js` and `fields.js`, render every token figure with an approximation marker — "12.4 KB ≈ 3.1k tokens", never a bare count (FR-021)
- [x] T073 [US3] In `src/js/activity-log/fields.js` and `StatTiles.js`, add a sortable `output_bytes` column and a "Context returned" total in the period header
- [x] T074 [P] [US3] Create `tests/phpunit/ActivityLogDb/ResponseSizeProbeTest.php` — each of the five filters returns its `$result` byte-identical, and a `tools/list` row has a non-null `output_bytes` despite having no ability children (the case that cannot be rolled up from `parent_id`)
- [x] T075 [P] [US3] In `tests/phpunit/ActivityLogDb/SchemaContractTest.php`, add a test asserting no `tokens_input` / `tokens_output` / `tokens_total` column exists in `Schema` (FR-022)

**Checkpoint**: "which tool is eating the context" is answerable, including the catalogue.

---

## Phase 6: User Story 4 — Run the log without it becoming a liability (P3)

**Goal**: bounded, prunable, accountable. (Privacy moved into US1 — see the Implementation
Strategy note.)

**Independent test**: confirm both toggles off on a fresh install and no rows accrue. Set
retention, age a row past it, run cleanup, confirm it goes while the other half survives.

- [x] T076 [US4] Implement `prune_by_event_type()` in `includes/Database/ActivityLog/Query.php` and the daily cleanup cron pruning **per `event_type`**, so the noisy ability half cannot force its cutoff onto the quiet request half (FR-030). The DELETE MUST use `$wpdb->prepare()` with `%d` casts — the cutoff comes from an option, and S4 is unconditional (SC-C9, SEC-T04)
- [x] T077 [US4] In `includes/Main.php`, schedule the cron only while at least one retention period is finite or a ceiling is set, and clear it otherwise (FR-031)
- [x] T078 [US4] Implement `enforce_ceiling()` in `includes/Database/ActivityLog/Query.php`, the retained-event ceiling, trimming oldest-first (FR-035), with the DELETE under `$wpdb->prepare()` and `%d` casts (SC-C9, SEC-T04). Do **not** sample: counts, success rate and size rankings must stay facts, and an operator cannot tell a sampled log from a quiet one (FR-036)
- [x] T079 [US4] **Record every removal** via a new static method in `includes/Database/ActivityLog/Recorder.php`. Purge, retention trim and ceiling trim each write a removal event *after* deleting, naming actor, time and count, so it survives as the first entry of the remaining history (FR-043, SC-021, A24). This is the one row a purge cannot erase
- [x] T080 [US4] Implement `DELETE /activity` in `includes/REST/ActivityLogController.php`, returning the count removed, and add the "Manage Logs" danger zone to `src/js/activity-log/index.js`
- [x] T081 [US4] In `includes/Observability/ActivityObservabilityHandler.php` and `includes/Abilities/AbilityActivityRecorder.php`, add the `acrossai_mcp_activity_input_preview` filter so a site handling regulated data can suppress or further scrub previews without disabling the log (FR-044, SC-C4)
- [x] T082 [US4] In `src/js/activity-log/index.js`, distinguish "recording is off" from "nothing happened in this period" in the empty state (FR-032)
- [x] T083 [P] [US4] Create `tests/phpunit/ActivityLogDb/RetentionTest.php` and `tests/phpunit/ActivityLogDb/PersonalDataTest.php`: both toggles off ⇒ zero rows; each half prunes on its own cutoff; the ceiling sheds oldest-first; a purge writes its own removal record; an erasure leaves no attributable event and removes none (SC-022); the purge does not touch F097's table and F097's cleanup does not touch this one (SC-C10)
- [x] T084 [P] [US4] In `tests/phpunit/ActivityLogDb/ObservabilityHandlerTest.php`, add a test asserting a sensitive **key** is redacted, and document in the same test that a secret inside a free-text value is **not** — the limit is key-name-based and the spec says so (SC-C3)

**Checkpoint**: the log is safe to own.

---

## Phase 7: Polish & Cross-Cutting

- [x] T085 [P] In `admin/Partials/ActivityLogPage.php` and `src/js/activity-log/index.js`, surface a "recording is failing" state driven by a consecutive-write-failure counter, so a full disk or B34 drift cannot masquerade as a quiet log (SEC-005)
- [x] T086 [P] In `includes/Observability/PersonalData.php`, document in the exporter's output that source addresses are included (SEC-006)
- [x] T087 Run PHPCS — zero errors, zero warnings
- [x] T088 Run PHPStan level 8 — zero errors. **Clear the result cache first**; a warm cache invents errors CI never sees
- [x] T089 Run ESLint — zero errors
- [x] T090 Run the full PHPUnit suite; confirm all 8 new test files pass alongside the existing 12 suites
- [x] T091 `npm run build` exits 0; `npm run validate-packages` passes
- [x] T092 Run the four pre-ship validate scripts (all `--dir=.`). **Result: 3 clean, 1 pre-existing failure unrelated to this feature.**
  - `detect-rest-endpoints` — **PASS**, and the strongest signal here: *all 37 routes have a `permission_callback`*, including F096's six.
  - `validate-security` — 197 warnings repo-wide, 10 in F096 files. All 10 are the same false positive: an interpolated table identifier built from `$wpdb->prefix` + a class constant, which `prepare()` cannot parameterise. Each carries a `phpcs:ignore` naming that reason. Nine are in test files; the one production case (`ActivityLogController::purge`) runs a statement that takes no values at all.
  - `detect-deprecations` — 7 warnings, **all pre-existing** (`get_profile()` in four `includes/OAuth/` files). None in F096 code.
  - `validate-structure` — **C04 FAILS**: unexpected PHP in the plugin root (`index.php`, `phpstan-bootstrap.php`). **Pre-existing**: both last touched by F095's commit `4c1a5fa` and unmodified by this feature. Worth fixing, but not as part of 096 — moving `phpstan-bootstrap.php` changes the static-analysis entry point for every feature. The 40 prefix warnings are WP core functions called from test bootstraps; zero are in F096 files.
- [x] T093 Verify every function, hook, filter and class is namespace- or `acrossai_mcp_`-prefixed
- [x] T094 Add the index row for this feature to `docs/planings-tasks/README.md` and mark 096 Implemented

---

## Phase 8: Post-implementation — found by running the feature against live MCP traffic

Phases 1–7 were complete and every gate was green when this phase opened. It exists because
**the feature had never been exercised against a real MCP client**: the suites passed, and the
log recorded nothing. Each task below is a defect or decision that only surfaced once traffic
flowed, and none of them could have been found by reading the code.

Issue **#183** belongs to this phase, not to the backlog — it is a defect in this feature,
found and fixed inside it.

### Critical — the log recorded nothing at all

- [x] T095 In `includes/Main.php`, construct `ActivityLog\Table` on **every** request, not only on `admin_init`. BerlinDB resolves the real table name from a `$wpdb` property set solely by the Table object's constructor; with no Table constructed it resolves to `''`, every insert targets `` and fails, and the A15 catch swallows it. `admin_init` does not fire on a front-end REST request (every MCP call) or on WP-Cron (the retention sweep). **Total silent write-loss, and every test passed throughout** — the test bootstrap constructs the Table itself, which is exactly why no assertion could see it.
- [x] T096 [P] In `tests/phpunit/ActivityLogDb/SchemaContractTest.php`, add two regression tests. The first UNSETS the `$wpdb` property and resets the Table singleton before asserting — without that it passes against the broken code and proves nothing.

### Critical — abilities were never linked to their request (#183)

- [x] T097 In `includes/Observability/CaptureContext.php`, collect the ids of ability rows written during a request (`remember_child()` / `take_children()` / `pending_children()`), and clear them in `wipe()` at A17 stage 3
- [x] T098 In `includes/Database/ActivityLog/Query.php`, add `adopt_children()` — sets `parent_id` and corrects `invoked_via` to `mcp` in one pass, row by row through `update_item()` so BerlinDB's object cache is invalidated (bulk UPDATE would leave cached reads serving `parent_id` 0 — the SC-C21 trap)
- [x] T099 Wire both ends: `AbilityActivityRecorder::write()` remembers the row it wrote; `ActivityObservabilityHandler::record_event()` adopts them once the request row exists
- [x] T100 [P] Add the **wiring** test to `ObservabilityHandlerTest` — record abilities, hand the handler `mcp.request`, assert the link exists, without calling `adopt_children()` directly. Verified load-bearing: removing only the adoption call reproduces the original signature exactly — unit tests pass, wiring test fails. `set_activity_id()` was correct, had six unit tests, and **no production caller**, which is how this shipped.

### Schema 1.1.0 — four indexes were mis-keyed

- [x] T101 In `Schema.php`, re-key and RENAME `status_created`, `component_created`, `connection_created` and `user_created` to lead with `event_type`. Measured: 742 index entries examined against 239 for the identical result. Renamed because the name is the only thing `index_exists()` can see, and dbDelta never re-keys an index that already exists under its own name — B34 in index form
- [x] T102 In `Table.php`, add `upgrade_to_1_1_0()` and bump `TABLE_VERSION`, plus a guard for the case where the version option is ABSENT but the table exists — BerlinDB then runs no callbacks and stamps the version anyway, so the migration is skipped undetectably
- [x] T103 [P] Add the index-column contract test (names alone cannot catch a re-key), the discriminator-prefix lock, the upgrade-path test, and the version-absent test

### Defaults — recording on, bounded out of the box

- [x] T104 Both halves default ON; retention 30 days requests / **7** ability runs; ceiling **50,000**. Replace `DEFAULT_RETENTION_DAYS` with per-half constants so the asymmetry cannot be "tidied" back into one number
- [x] T105 Align `register_setting()`'s declared defaults with the constants every reader passes to `get_option()`. They are separate mechanisms, and while they disagreed **unticking the box did not turn logging off**: an unchecked checkbox posts nothing, `update_option()` compared false against the registered false, wrote nothing, and the readers' `true` default kept recording
- [x] T106 In `Main.php`, reconcile the cleanup schedule on `admin_init` as well as on activation. Activation hooks are skipped on UPDATE (B68) and an operator happy with the defaults never saves the form — harmless while recording was off, but with T104 it means recording from the first request with nothing ever deleting a row
- [x] T107 [P] Tests: an untouched install records AND prunes; unticking really stops it (fails when the bug is reintroduced)

### Failure visibility (FR-013a, FR-013b, FR-031a)

- [x] T108 Add `includes/Observability/RecordingHealthCheck.php` — Site Health reporting four distinct faults: table missing, table unaddressable, writes failing, sweep overdue. The second is the T095 defect, invisible from every other surface
- [x] T109 Add `includes/Database/Support/SchemaFailureHealthCheck.php` — listener plus reporter for `acrossai_mcp_schema_upgrade_failed`, which every Table subclass fires and nothing listened to. Entries keyed by table+version so a retrying migration overwrites rather than grows; cleared once nothing is pending
- [x] T110 [P] Add `tests/phpunit/ActivityLogDb/HealthCheckTest.php`

### Gates

- [x] T111 PHPCS clean; PHPStan level 8 clean with the result cache cleared
- [x] T112 All 17 suites green — **1,478 tests, 3,864 assertions** (re-run 2026-10-24 after T114, on a reinstalled WP 7.1.3 harness)
- [x] T113 Verified against live MCP traffic: pages created, plugins activated, and the resulting rows inspected in the database — the check that Phases 1–7 never performed

### Architecture review remediation (V1)

- [x] T114 Move the purge into the Data layer: add `Query::purge_all()` and have `ActivityLogController::purge()` call it. The controller was the **only** place the entry boundary knew the physical table name and issued its own SQL, while every other delete in the module goes through `Query` (`prune_by_event_type()`, `enforce_ceiling()`). Correct code, but an invisible asymmetry the next controller would copy. `record_removal()` deliberately stays in the controller — A24 needs the acting user and the Query layer has none. Covers `includes/Database/ActivityLog/Query.php`, `includes/REST/ActivityLogController.php`, and two tests in `tests/phpunit/ActivityLogDb/RetentionTest.php`, the second of which asserts cached aggregates are dropped — a purge that empties the table while `summary()` still serves old counts reads as a log that refused to be cleared. Raised by `/speckit-architecture-guard-architecture-review` as V1 (MEDIUM); entry-boundary `$wpdb` references went 4 → 0.

---

## Completion record — 2026-10-21

**94 of 94 tasks complete.** All four user stories shipped.

### Gates

| Gate | Result |
|---|---|
| PHPCS | clean, zero errors **and** zero warnings, repo-wide |
| PHPStan level 8 | clean repo-wide, result cache cleared first |
| ESLint | clean |
| PHPUnit | **16 suites, 1,345 tests, 3,460 assertions, 0 failures** (29 skips, all pre-existing) |
| `npm run build` | exits 0 |
| `npm run validate-packages` | passes |
| `detect-rest-endpoints` | all 37 routes have a `permission_callback` |
| `validate-structure` C04 | **fails — pre-existing**, see T092 |

F096's own suites: `activity-log` (14 tests / 60 assertions, WP-free) and `activity-log-db`
(117 / 441, needs WordPress + a database).

### What this cost the baseline

PHPStan baseline entries went **down by four**, net, across the feature:

- +4 for BerlinDB's `$columns`/`$indexes` typing wart (identical to 27 existing `CliAuthLog`
  entries; the real fix is a vendor stub file, recorded as a follow-up in the baseline itself)
- −2 for `Loader::add_action`/`add_filter` being documented as taking an `object` when they
  demonstrably accept a class-string — the docblock was wrong, not the 13 callers
- −2 for a flapping `is_array()` error that had one baseline entry for a defect present in
  three files. `WP_Ability::get_meta()` declares `: array`, so every guard on it was dead code;
  removed rather than re-pinned.

### Bugs the tests caught that review did not

Seven, each recorded where it belongs rather than only in a commit message:

1. **`$column['name']` on a `Kern\Column` object** — BerlinDB hydrates the declared arrays into
   objects. The A15 `try/catch` swallowed the throw, so **every insert silently returned 0**: a
   log that looks enabled and records nothing.
2. **A declared `enum` was never enforced** — `WP_REST_Request::has_valid_params()` requires an
   explicit `validate_callback`. Seven params accepted any string. Recorded as SC-C19.
3. **Secrets were not redacted at all** — `NullMcpObservabilityHandler` does not use the helper
   trait, so the lookup failed and the fallback returned tags unredacted, invisibly. SC-C20.
4. **`sanitize_tags()` preserves nested secrets** — it inspects only top-level keys, then runs a
   word-level regex over the JSON, which replaces the sensitive *key name* and keeps the value.
5. **The privacy eraser left personal data readable from cache** — a bulk `UPDATE` bypassed
   BerlinDB's object cache, whose invalidation methods are all private. The subject's own export
   still returned their row after erasure. SC-C21.
6. **`wp_get_ability()` without `wp_has_ability()`** emits a core `_doing_it_wrong` notice for any
   name that has left the registry — from inside a logging path meant to be invisible.
7. **PHPStan found two dead defensive branches** (`get_category()` object handling, a
   `method_exists()` guard on a composer dependency), both removed rather than suppressed.

### Deliberate deviations

- **T047** keeps `AbstractServerTab` instead of `AbstractReactMountServerTab`. That primitive's
  `register_rest_routes()` is `final` and registers a `CREATABLE` route unconditionally, and its
  two abstract state methods assume a save contract. A read-only log would gain two no-op methods
  **and a mutating endpoint that does nothing**. Recorded in `plan.md`; revisit if the primitive
  ever gains a read-only mode.
- **One test asserts a limitation on purpose.** `test_a_secret_in_a_free_text_value_is_NOT_redacted_which_is_documented`
  asserts that a password inside a `content` field survives. Key-name redaction cannot cover free
  text; the spec says so and the settings copy warns operators. If that assertion flips, the spec
  and the UI text must change with it.

### Still open

- **A vendor deprecation**: booting this schema emits one PHP 8.2 notice from BerlinDB
  (`Kern\Index::$length`). None of the nine index definitions carries a `length` key, and
  renaming the column-colliding index did not clear it, so the origin is inside the vendor. Fails
  no gate. Recorded in `research.md` rather than guessed at.
- **Nothing is committed.** The branch carries the full feature plus three durable-memory
  entries, two security reviews and nine spec artifacts.

---

## Dependencies

```
Phase 1 (Setup/verification)  ← blocks everything; T002's four facts are load-bearing
        ↓
Phase 2 (Foundational)        ← storage + constants + holder lifecycle
        ↓
   ┌────┴─────────────────────────────────┐
   ↓                                      ↓
Phase 3 US1 (P1) ── MVP            (US3 needs only T026/T036 from US1)
   ↓                                      ↓
Phase 4 US2 (P2)  ← needs US1's parent rows
   ↓
Phase 5 US3 (P3)  ← needs US1's handler + controller; independent of US2
   ↓
Phase 6 US4 (P3)  ← needs rows to govern; T079 needs US1's Recorder
   ↓
Phase 7 Polish
```

**Story independence**: US1 ships alone as the MVP. US2 depends on US1 for parent rows. US3
depends on US1's handler and controller but **not** on US2. US4 depends on rows existing but
not on US2 or US3.

## Parallel Execution Examples

**Phase 2**: T007, T010, T019, T020, T021 — different files, no shared dependency.
**Phase 3**: T042, T043, T044, T045 (four separate JS/PHP files) and T050, T051, T052 (three
test files) each run as a batch.
**Phase 5**: T074 and T075 together.
**Phase 6**: T083 and T084 together.
**Phase 7**: T085 and T086 together; T087–T092 are sequential gates.

## Implementation Strategy

**MVP = Phase 1 + Phase 2 + Phase 3 (US1).** That is 51 tasks and delivers the whole point of
the feature: a broken connector becomes diagnosable. Ship it before starting US2.

The MVP boundary moved after the task security review
(`docs/security-reviews/2026-10-16-096-mcp-activity-log-tasks.md`): the personal-data exporter
and eraser came **into** US1, because US1 is what records full source addresses and
Clarification Q1 chose the full address on the condition that the subject-data path accompanied
it; and `DELETE /activity` went **out** of US1, because a purge that records nothing is the
SEC-001 finding and belongs beside the removal record in US4. **A constraint satisfied by a task
in a later phase is not satisfied for any release that ships only the earlier phase.**

**Then, in order of marginal value**: US2 (turns "the content tool failed" into the specific
ability), US4 (makes the log safe to leave on), US3 (the context-cost answer nothing else
provides). US4 before US3 is deliberate — an operator who cannot bound the log will switch it
off, and then US3 measures nothing.

**Three things that will be tempting to skip and must not be:**

1. **T002's verification.** Four downstream assumptions rest on it, and F093's three-bug fix
   commit exists because its equivalent phase was run for real.
2. **T015/T053's `shutdown` stage.** A17 names its omission the number-one silent bug in ad-hoc
   versions of this pattern. Omit it and the log attributes one client's calls to another.
3. **T079's removal record.** Cheap now, impossible later — history that predates it can never
   afterwards be shown to be complete.
