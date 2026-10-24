# Implementation Plan: MCP Activity Log

**Branch**: `096-mcp-activity-log` | **Date**: 2026-10-16 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `/specs/096-mcp-activity-log/spec.md`

## Summary

One append-only BerlinDB table, `acrossai_mcp_activity_log`, records two kinds of event in a
parent/child tree: an MCP JSON-RPC request (`event_type = request`, `parent_id = 0`) and an
ability run (`event_type = ability`, `parent_id` = the request that caused it, or 0 when no
MCP was involved). Requests are captured by implementing the mcp-adapter's published
observability handler interface and passing our class-string in place of
`NullMcpObservabilityHandler`. Abilities are captured by WordPress core's `wp_ability_*` hooks
where the WP version provides them, plus an always-on `try`/`finally` bracket at two
plugin-owned choke points that works on the 6.9 floor. One REST controller and one DataViews
screen read it; the screen mounts twice, globally and per server.

Approach is driven by three facts established before planning: the adapter's seam already
measures every request and we currently discard it; the seam never passes the response
payload, so response **size** comes from five published result filters while the response
**content** comes from the child ability row; and both new request-scoped holders must carry
the project's three-stage lifecycle, shutdown stage included.

## Technical Context

**Language/Version**: PHP 8.1+ (plugin header `Requires PHP: 8.1`; the floor is pinned by the
BerlinDB dependency and is not reducible). JavaScript via `@wordpress/scripts`.
**Primary Dependencies**: `wordpress/mcp-adapter` (observability handler interface + five
result filters), `berlindb/core` (Schema/Table/Row/Query), `@wordpress/dataviews`,
`@wordpress/components`, `@wordpress/element`, `@wordpress/api-fetch`, `@wordpress/data` — all
already present. **No new dependency.**
**Storage**: one custom table, `{$wpdb->prefix}acrossai_mcp_activity_log`, version **`1.1.0`**,
per-site prefix. Timestamps in UTC. 1.1.0 re-keys four composite indexes to lead with the
`event_type` discriminator — see "Schema 1.1.0" below.
**Testing**: PHPUnit (`tests/phpunit/`) for PHP; Jest (`tests/jest/`) where JS logic warrants.
**Target Platform**: WordPress 6.9+ (richer ability capture feature-detected on 7.1+), single
site per table; multisite-compatible per-site.
**Project Type**: WordPress plugin — admin + REST surface over a custom table.
**Performance Goals**: recording adds ≤5% to the duration of the call it observes (FR-038,
SC-006), one flat INSERT with no joins on the write path; deep pages no slower than the first
via keyset pagination (SC-013) — net-new work; no pagination helper exists to reuse.
**Constraints**: recorders MUST NOT throw (FR-013); no sampling (FR-034); worst-case size
bounded by retention **and** a retained-event ceiling (FR-033); no FULLTEXT index; no response
bodies stored. Every bound is applied by the daily sweep alone and therefore holds only while
WP-Cron runs (FR-031a) — stated, and reported rather than worked around.
**Scale/Scope**: **54 functional requirements, 31 success criteria**, 4 user stories, 113 tasks
across 8 phases. ~18 new PHP classes, 1 REST controller (6 routes + purge), 1 React app mounted
twice, 6 modified files. Benchmarked design point ~10,000 events per site, sanity-checked at
150,032 (`research.md` D-14/D-15).

No `NEEDS CLARIFICATION` remains: five were resolved in `/speckit-clarify`, two by
`memory-synthesis.md`, and the capability question by codebase inspection.

**Phase 1 ran on 2026-10-16** against live traffic (`research.md` §Phase 1). All four
load-bearing assumptions hold. Four corrections followed — `component_name` sources the
adapter's `ability_name` rather than the sanitizer-rewritten `tool_name`; our reserved column is
`event_source`, because the adapter already sends `source` with the value `ability`; `error_code`
has no tag to come from; and `user_id` comes from `get_current_user_id()`. The table is 32
columns — the original 30 was itself a miscount, before `invoked_name` was added.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

Checked against `.specify/memory/constitution.md` **v1.1.1**.
`architecture_constitution.md` and `security_constitution.md` **do not exist** — only the
architecture-guard templates. `/speckit-architecture-guard-init` has never been run, so
architectural gating falls back to this constitution plus `docs/memory/` (see §Governance Gaps).

| Principle | Verdict | Evidence |
|---|---|---|
| **I. Modular Architecture** | ⚠️ **Two findings** — see A and B below | Feature spans `Database/ActivityLog/`, `Observability/`, `Abilities/`, `REST/`, `admin/Partials/` |
| **II. WordPress Standards** | ✅ Pass | PHP 8.1+/WP 6.9+ match the header; PHPCS + PHPStan L8 + ESLint are DoD gates; multisite scoped per-site **with documented justification**, which the principle expressly permits |
| **III. Security First** | ✅ Pass | All six routes capability-checked via `permission_check`; no `__return_true`; `$wpdb->prepare()` throughout including the keyset comparison; **no credentials stored at all** (connector slug + client-reported name only), so the token-hashing rule is satisfied vacuously; consent-surface exception **not** invoked |
| **IV. User-Centric Design** | ✅ Pass | New screen uses DataViews; **no exception claimed** — neither the parent-menu nor the connector-card carve-out is relied upon. DEV5 would permit hand-rolled HTML in `McpTrackerTab::render_body()`; we are stricter and mount DataViews there |
| **V. Extensibility Without Core Modification** | ⚠️ **One finding** — see C below | Feature modifies 5 existing behavioural files |
| **VI. Reusability & DRY** | ✅ Pass | Redaction reuses `McpObservabilityHelperTrait`; recorder ignore-list a single shared constant per A9; `npm run validate-packages` is a DoD gate; **no new npm package**. *Correction:* an earlier draft cited reuse of an existing keyset-pagination helper as evidence here. Verified — none exists, and no REST controller in this plugin paginates at all. Keyset pagination is net-new work owned by this feature, with F097 its first consumer |
| **VII. Definition of Done** | ✅ Pass | All 11 gates carried into spec §Definition of Done, plus `npm run build` and the four pre-ship validate scripts |

### Finding A — the active-feature-area enumeration goes stale (advisory, needs a constitution PATCH)

Principle I's Rationale enumerates the active feature areas: *"MCP Server Management, CLI Auth,
Frontend Auth, Access Control, OAuth / AI Connectors"*, and requires each to map to exactly one
module. **F096 introduces a sixth: Activity Logging.** Nothing in the principle's normative
MUST is violated — the area maps to one module, exactly as the OAuth area maps to one area
across five directories — but the enumeration becomes incomplete the moment this ships.

The precedent is in this file's own Sync Impact Report: **v1.1.0 → v1.1.1 was a PATCH whose
entire content was correcting this same stale enumeration** when F095 reinstated OAuth. The
same bump is owed here.

*Recommended action*: a PATCH amendment adding Activity Logging to the enumeration, with its
modules listed as `includes/Database/ActivityLog/` and `includes/Observability/`. Not a blocker
for implementation; it is a documentation debt that compounds silently, as v1.1.1 demonstrates.

### Finding B — module ownership inversion in `includes/History/` (violation; fixed in this plan)

The spec places `CaptureContext.php` in **`includes/History/`**. That directory name describes
**Feature 097**'s domain (change history), not this feature's. The effect is that 096 would
create a directory named after a feature that does not exist yet, drop one file in it, and
leave 097 to fill the rest — so a reader finding `includes/History/CaptureContext.php` would
reasonably conclude it belongs to change history, when in fact it is the activity log's
attribution context and 097 is merely a consumer.

Principle I requires a module to have "a clear, singular purpose" and to be "independently
testable, extensible, and replaceable without affecting sibling modules". A directory jointly
owned by two features at different maturity levels satisfies none of those.

*Resolution applied in this plan*: `CaptureContext` lives at
**`includes/Observability/CaptureContext.php`**, inside the module 096 owns, alongside the
other three capture classes it collaborates with. 097 attaches to it there and creates
`includes/History/` for its own files when it ships.

*Cross-feature action*: `docs/planings-tasks/097-mcp-change-history.md` and its future spec
reference `includes/History/CaptureContext.php` in several places; those references must be
re-pointed. Recorded here rather than silently changed, because 097 is a separate feature with
its own planning doc.

### Finding C — Principle V vs five modified files (justified; see Complexity Tracking)

Principle V says new features MUST be implemented "via WordPress action/filter hooks, extension
points, or new self-contained modules — **never by modifying existing core plugin files**".
F096 modifies five behavioural files. Each is assessed in §Complexity Tracking; one is being
redesigned to comply, four are either mandated by the architecture or are the feature itself.

The one being redesigned: the spec has `OAuth\TokenValidator::authenticate()` call
`AuthContextHolder::set()` directly, which couples the OAuth module to the observability
module. **Replaced with a scoped `do_action()` that `AuthContextHolder` listens to**, wired in
`Main.php` per A1 — Principle V's own prescribed mechanism, and the pattern D19 already
establishes for this codebase. `TokenValidator` gains one fire-and-forget line and learns
nothing about the activity log.

### Accepted deviation — `McpTrackerTab` keeps `AbstractServerTab` (recorded 2026-10-21)

The refactor-generator's P1 item was to extend `AbstractReactMountServerTab`, the F037 primitive
`EmbedsTab` already consumes. Implementation found it does not fit, for a specific reason rather
than a stylistic one:

- `register_rest_routes()` is **`final`** and registers a `WP_REST_Server::CREATABLE` route
  unconditionally.
- `get_state_for_server()` / `set_state_for_server()` are abstract — the primitive assumes a tab
  that saves state.

The activity log is read-only. Extending the primitive would mean two no-op state methods **and a
mutating REST endpoint that exists but does nothing** — attack surface added for no benefit, on a
feature whose own security review flagged authorization surface twice.

`render_body()` therefore mounts the shared bundle through `ActivityLogPage::enqueue_bundle()`,
which keeps the one-component-two-mount-points property the primitive was wanted for. This is
exactly the escape hatch `architecture-migration-plan.md` Phase 2.4 anticipated.

*If the primitive ever gains a read-only mode — a `declares_save_route(): bool` hook, say — this
deviation should be revisited; the shared asset-enqueue logic is still worth inheriting.*

### Governance Gaps (flagged, not resolved here)

1. **No architecture constitution.** `.specify/memory/architecture_constitution.md` is absent;
   only `.specify/extensions/architecture-guard/templates/architecture_constitution.md` exists.
   Architecture gating therefore degrades to this constitution plus `docs/memory/`
   ARCHITECTURE.md. Run `/speckit-architecture-guard-init` to close it.
2. **No security constitution.** Same situation; the security review runs against Principle III
   and `docs/memory/` only.
3. **The constitution's directory-layout block is illustrative, not exhaustive.** It lists
   `includes/{Utilities,MCP,MCPClients,REST}` but the codebase also has `Database/`,
   `Abilities/`, `OAuth/`, `Connectors/`, `ConnectorProfiles/`, `Discovery/`. Adding
   `includes/Observability/` follows the established pattern; do not read the block as a
   closed set.

## Constitution Check — post-design re-evaluation

*Re-run after Phase 1, as the gate requires.*

| Principle | Pre-design | Post-design | What changed |
|---|---|---|---|
| I. Modular Architecture | ⚠️ A + B | ⚠️ A only | **B resolved**: `CaptureContext` moved to `includes/Observability/`; this feature no longer creates `includes/History/`. **A stands** — a constitution PATCH is owed, which is documentation debt outside this feature's changeset |
| II. WordPress Standards | ✅ | ✅ | Unchanged |
| III. Security First | ✅ | ✅ | Strengthened: the contract pins `permission_check` on all six routes, states `__return_true` appears nowhere, and routes previews to a single detail endpoint so list responses carry no payload |
| IV. User-Centric Design | ✅ | ✅ | Unchanged — no exception claimed |
| V. Extensibility | ⚠️ C | ⚠️ C, narrowed | `TokenValidator` now fires a scoped `do_action()` instead of calling the holder. The four remaining modifications are justified in Complexity Tracking; three are mandated by A1 and the project's own activation/uninstall structure |
| VI. Reusability & DRY | ✅ | ✅ | Strengthened: keyset helper reused, ignore list a single A9 constant, redaction via the existing trait, no new package |
| VII. Definition of Done | ✅ | ✅ | Unchanged |

**Gate verdict: PASS with two advisories.** Neither blocks Phase 2.

- **Finding A** is a constitution amendment, not a code change. It cannot be satisfied from
  inside this feature's diff and should be raised as its own PATCH proposal.
- **Finding C** is irreducible for `MCP\Controller` (the handler is a positional argument with
  no filter) and for the two `try`/`finally` sites (no hook can wrap a method body). The
  principle's intent — don't couple core files to optional integrations — is honoured: each
  edit delegates immediately and holds no logic.

### Phase 1 deviation from the template

The template's step 3 says to update the plan pointer in `CLAUDE.md`. No `CLAUDE.md` exists
here; `.specify/init-options.json` names `.github/copilot-instructions.md` as this project's
context file and that is where the `SPECKIT` markers live. Updated there instead — and the
pointer was **stale**, still naming `specs/091-schema-drift-reconciliation/plan.md` even though
095 has since shipped. Worth noticing: whichever feature ran `/speckit-plan` most recently did
not update it.

---

## Project Structure

### Documentation (this feature)

```text
specs/096-mcp-activity-log/
├── spec.md                  # Feature specification (42 FR, 20 SC)
├── plan.md                  # This file
├── memory-synthesis.md      # Durable-memory constraints (pre-existing)
├── research.md              # Phase 0 output
├── data-model.md            # Phase 1 output
├── quickstart.md            # Phase 1 output
├── contracts/
│   └── rest-activity.md     # Phase 1 output — the six routes
├── checklists/
│   └── requirements.md      # Spec quality checklist
└── tasks.md                 # Phase 2 — /speckit-tasks, NOT created here
```

### Source Code (repository root)

```text
includes/
├── Database/
│   └── ActivityLog/              # NEW — BerlinDB module, CliAuthLog five-file shape
│       ├── Schema.php            #   32 columns, 9 indexes, no FULLTEXT
│       ├── Table.php             #   acrossai_mcp_activity_log v1.0.0 + phantom-version guard
│       ├── Row.php
│       ├── Query.php             #   summary(), filter_options(), children(), bulk labelling
│       └── Recorder.php          #   A15: static-only, one add_item per method, try/catch
│                                 #   Query also owns prune_by_event_type() + enforce_ceiling()
├── Observability/                # NEW — this feature's module
│   ├── ActivityObservabilityHandler.php   # McpObservabilityHandlerInterface; mcp.request only
│   ├── ConnectionContext.php              # connection_type derivation + slug→id cache
│   ├── AuthContextHolder.php              # request-scoped; A17 three-stage lifecycle
│   ├── CaptureContext.php                 # request-scoped; A17; the seam 097 attaches to
│   ├── ResponseSizeProbe.php              # five adapter result filters, strlen only
│   └── PersonalData.php                   # WP exporter + de-identifying eraser (FR-037/038)
├── Abilities/
│   └── AbilityActivityRecorder.php        # NEW — core-hook quartet + bracket, re-entry guard
├── REST/
│   └── ActivityLogController.php          # NEW — 6 routes, offset + keyset pagination
└── Utilities/
    └── ActivityLogConstants.php           # NEW — A9: shared ignore-list + option keys

admin/
└── Partials/
    ├── ActivityLogPage.php                # NEW — submenu under the AcrossAI menu
    └── ServerTabs/McpTrackerTab.php       # MODIFIED — render_body() mounts the table

src/js/activity-log/                       # NEW — one app, two mount points
├── index.js        ├── fields.js      ├── StatTiles.js
├── DetailPanel.js  └── ManageLogs.js

tests/phpunit/ActivityLog/                 # NEW
├── SchemaContractTest.php   # B34: columns AND widths after install
├── RecorderTest.php         # round-trip; never throws
├── QueryTest.php            # summary(), children(), filter_options()
├── ObservabilityHandlerTest.php  # 5 connection_type branches; swallows; toggle off
├── AbilityRecorderTest.php  # WP_Error→error; denial→denied; exactly one row; finally
├── HolderLifecycleTest.php  # A17: shutdown clears; nothing survives a request
├── ResponseSizeProbeTest.php     # each filter returns $result byte-identical
├── RestActivityControllerTest.php  # caps, filters, cursor==offset, purge isolation
├── RetentionTest.php        # per-event_type cutoffs, ceiling, recorded removals
└── PersonalDataTest.php     # exporter returns; eraser de-identifies and deletes nothing
```

**Structure Decision**: the feature owns two new directories — `includes/Database/ActivityLog/`
(storage) and `includes/Observability/` (capture) — plus one controller, one admin partial, one
JS entry and one utilities class. This mirrors how the OAuth area is organised (storage modules
under `Database/`, behaviour in a named module) and satisfies Principle I's one-area-one-module
rule. `includes/History/` is **not** created by this feature; see Finding B.

### Principle V — the modified-file list, reconciled 2026-10-23

Violation detection compared the declaration against `git status`. **14 behavioural files are
modified; 8 are named here or in the migration plan; 6 were not.** Reconciled rather than
re-litigated:

| File | Status |
|---|---|
| `admin/Partials/SettingsMenu.php` | **Declared, misnamed.** This table said `Settings.php`; that file does not exist. +174 lines — the MCP settings tab. The substance was always declared. |
| `includes/Deactivator.php` | **Genuinely omitted.** +6 lines clearing the cleanup cron hook. Mandated for the same reason `Activator.php` is: this project's lifecycle requires the counterpart, and a feature that schedules an event must unschedule it. Should have been in the original four. |
| `includes/Loader.php` | Incidental (+19/−16). A docblock type widened to `object\|class-string`, which is what lets `Main` register a static callback. Retired 13 PHPStan baseline entries. No runtime change. |
| `includes/Abilities/AbilityHelpers.php`, `Toolset/AbilityGroup.php` | Incidental (+2/−1 each). Dead `is_array()` guards removed — `WP_Ability::get_meta()` declares `: array` in core, so the guards were unreachable. Found while fixing a flapping baseline entry. |
| `admin/Partials/Notices.php` | Incidental (+1/−1). Quote style, PHPCS. Not this feature's change in substance. |

**Assessment:** one real omission (`Deactivator.php`), one naming error (`Settings.php` →
`SettingsMenu.php`), and four incidental touches that are static-analysis hygiene rather than
feature work. Principle V's concern is behavioural modification of existing files; a docblock
and two unreachable guards do not meet that bar, and saying they do would make the principle
unfalsifiable. The declaration is corrected above rather than the count being argued down.

## Post-implementation architecture (recorded 2026-10-23)

Phases 1–7 completed with every gate green **while the log recorded nothing**: the feature had
never been exercised against a real MCP client. Phase 8 (tasks T095–T113) is the result. These
are architecture changes, not polish, so they belong in the plan rather than only in tasks.md.

### Schema 1.1.0 — the discriminator belongs in the key

One table holds two populations, so a composite key that omits `event_type` matches rows of both
and every single-kind query pays for the other kind's rows. Measured on a copy of the shipped
schema: 742 index entries examined under `(status, created_at)` against 239 under
`(event_type, status, created_at)` for the identical result. `ActivityLogController` defaults
`event_type` to `request`, so the predicate is on every list query and no caller wanted these
keys un-prefixed.

The four keys are **renamed** as well as re-keyed. The name is the only thing `index_exists()`
can see, and dbDelta never re-keys an index that already exists under its own name — so without
the rename an upgraded install keeps the old key while reporting the new version. B34 in index
form.

### Two silent-failure modes closed

Both had the same shape: the declared state and the physical state disagreed, while every check
that existed compared the declared state against itself.

1. **The table name did not resolve.** BerlinDB reads the real name from a `$wpdb` property set
   solely by the Table object's constructor, which ran only on `admin_init`. That fires on
   neither a front-end REST request (every MCP call) nor WP-Cron (the retention sweep), so the
   name resolved to `''`, every insert targeted `` `` `` and failed, and the A15 catch swallowed
   it. `Main::__construct()` now registers the table on every request — property assignment
   only, no queries.
2. **A missing version option skipped every migration.** `get_pending_upgrades()` returns nothing
   when `$db_version` is empty, so BerlinDB ran no callbacks and stamped the current version
   regardless. `Table::maybe_upgrade()` now seeds the oldest shipped version — the mirror of the
   phantom-version guard already there.

### Parent/child linkage is retroactive by necessity

An ability row is written while the request is in flight; the request's own row is written at the
end, once its duration is known. The parent therefore does not exist when its children are
stored, so `parent_id` cannot be set at insert time — this is a property of the ordering, not an
omission. `CaptureContext` collects the ids it wrote and `Query::adopt_children()` adopts them
once the request row lands, correcting `invoked_via` in the same pass. Row-by-row through
`update_item()`, because a bulk UPDATE bypasses BerlinDB's object cache (SC-C21's trap).

### Failure visibility is part of the architecture, not an add-on

FR-013 requires recording to swallow its failures so a client's call is never broken. That makes
failure invisible, which is a separate decision and not one the principle requires. Two new
Site Health surfaces close it: `Observability\RecordingHealthCheck` (table missing, table
unaddressable, writes failing, sweep overdue) and `Database\Support\SchemaFailureHealthCheck`
(listener + reporter for `acrossai_mcp_schema_upgrade_failed`, which every Table subclass fired
and nothing listened to). Both are read-side only; neither adds cost to the recording path.

### Defaults

Both halves record by default, bounded out of the box: 30 days for requests, 7 for ability runs,
50,000 events shared. Sound as a default rather than an opt-in **only because 096 has never
shipped** — no operator has declined it and would be switched on by an update. That reasoning
expires at release (FR-029b).

---

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| **P-V: modify `includes/MCP/Controller.php`** | The adapter takes the observability handler as the 9th argument to `create_server()`. There is no filter on that argument. | Hooking `rest_pre_dispatch` instead is exactly what the third-party mcp-tracker does, and it misses `initialize` (no session yet), fires on all REST traffic, and stores raw bodies. One argument swap, read behind the enable toggle, is strictly smaller and strictly more correct. |
| **P-V: modify `Base_Toolset_Ability::do_execute()` + `Abilities\Execute`** | A `try`/`finally` must be lexically inside the method whose exceptions it guards. No hook can wrap a method body. | Wrapping the ability callback via `CallbackReplacer` was rejected: WP core already instruments `WP_Ability::execute()` for every caller, so wrapping duplicates core's seam and misses non-MCP callers. These two files are the only MCP-originated choke points, and the edit delegates immediately to `CaptureContext` — no logic lands in them. |
| **P-V: modify `OAuth\TokenValidator.php`** | `authenticate()` resolves the OAuth client then discards it; nothing downstream can recover it. | **Redesigned to comply** — see Finding C. It now fires one scoped `do_action()` and `AuthContextHolder` listens, wired in `Main.php`. Direct `AuthContextHolder::set()` coupling was rejected as a true Principle V violation. |
| **P-V: modify `McpTrackerTab::render_body()`** | Replacing that body **is** the feature's per-server surface; the tab already owns slug `mcp-log` and label `Logs`. | A second, parallel tab would give the operator two "Logs" tabs, one advertising a third-party plugin. |
| **P-V: modify `Main.php`, `Activator.php`, `uninstall.php`, `Settings.php`** | Mandated: A1 requires every hook be wired in `Main.php`; the activator installs tables; uninstall drops them; settings host the toggles. | Not optional under this project's own architecture. Every feature touches these four; treating it as a Principle V violation would make the principle unsatisfiable. |
| **32 columns in one table, 16 populated on one event kind only** | Requests and abilities are the same shape; `event_type` + `parent_id` is the discriminator. | Two tables were specced first and rejected: nine identical columns, four renamed pairs, a redundant denormalised `tool_name`, a second REST controller and a second schema-contract suite. All four reference plugins that log anything use a single call-level table. |
