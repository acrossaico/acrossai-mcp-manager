# Implementation Plan: Per-server Ability Policy Defaults

**Branch**: `082-ability-policy-defaults` | **Date**: 2026-09-05 | **Spec**: [spec.md](spec.md)
**Input**: Feature specification from `specs/082-ability-policy-defaults/spec.md`
**Memory context**: [memory-synthesis.md](memory-synthesis.md)
**Companion planning brief**: [docs/planings-tasks/082-per-server-ability-policy-defaults.md](../../docs/planings-tasks/082-per-server-ability-policy-defaults.md) (tracks issue #95)

## Summary

Add a tri-state `abilities_default_policy` column (`per-ability` | `expose` | `hide`) to `{prefix}acrossai_mcp_servers` so an operator's Enable All / Disable All gesture persists as a server-level policy, not a snapshot of ability rows. Split `ExposureResolver` into `resolve_row_only()` (renamed from `resolve()` per **SEC-001 Option A** — row-only semantics move into the method name itself so the F030 invariant is grep-visible and fail-loud on rename) and a new sibling `resolve_effective()` (three-tier: row → server policy → `meta.mcp.public`). Wire `AbilityExposureGate` (priority 20), the augmented `GET /abilities` response, the new `POST /abilities/policy` handler, the F026 advertisement-time helpers (`AbilityDiscovery`, `AbilityHelpers`), the was/now snapshots in `post_abilities()`, and the Quick-Connect flow to `resolve_effective()`; F030's `PermissionOverrideProcessor` is the sole caller of the renamed `resolve_row_only()`. F015 (priority 10) and F020 (priority 30) gates are byte-for-byte untouched.

> **SCOPE AMENDMENT (2026-09-05)**: implementation-time grep revealed 5 production callers of `resolve()`, not 1-2 as the initial companion brief assumed. Per D24 defense-in-depth (advertisement-time uses effective exposure), 4 of the 5 migrate to `resolve_effective()`; only F030 stays on `resolve_row_only()`. See tasks.md T007c for the caller-migration sweep. All 6 existing test callers in `tests/phpunit/Database/MCPServerAbility/ExposureResolverTest.php` migrate to `resolve_row_only()` (they test row-only semantics).

## Technical Context

**Language/Version**: PHP 8.1+ (constitution §II); JavaScript targeting `@wordpress/scripts` webpack build.
**Primary Dependencies**: `berlindb/core: ^3.0.0` (F010, already installed); `wordpress/mcp-adapter`; `@wordpress/dataviews` + `@wordpress/components` + `@wordpress/element` + `@wordpress/api-fetch` + `@wordpress/i18n` + `@wordpress/hooks` (F017 stack, all in place).
**Storage**: `{$wpdb->prefix}acrossai_mcp_servers` (existing BerlinDB table; F082 adds one column via D28 3-part contract). No new table. Existing `{$wpdb->prefix}acrossai_mcp_server_abilities` (F017) unchanged.
**Testing**: PHPUnit (extend existing `ExposureResolverTest` + `AbilitiesControllerTest`; add new `PolicyReconcilerTest`); manual MCP-client end-to-end via Claude Desktop / MCP Inspector; PHPStan L8 per task; PHPCS.
**Target Platform**: WordPress 6.9+, single-site (matches F017 scope; multisite explicitly out of scope per spec Assumptions).
**Project Type**: WordPress plugin — single project, plugin-tree layout (constitution §Architecture & UI Standards).
**Performance Goals**: Per-request static caches on both `resolve()` and `resolve_effective()` — one DB round-trip per unique `(server_id, slug)` pair per request; one additional round-trip per unique `server_id` for policy lookup. Cache reset in `_reset_cache_for_tests()` and after each policy POST.
**Constraints**: Zero behaviour change on pre-F082 installs (SC-004); F030 row-only bypass MUST return the same answer before/after (SC-005); no rename of any public REST route, filter, hook, column, or method signature; no touch to F015 or F020 gates.
**Scale/Scope**: Typical install: 1–5 MCP servers, 20–200 registered abilities, 0–20 override rows per server. Policy transitions rare (operator gesture). No hot-path concern beyond the two per-request caches.

## Constitution Check

*GATE: Must pass before implementation. Re-checked after Phase 1 design.*

| Principle | Status | Justification |
|---|---|---|
| **I. Modular Architecture** | ✅ Pass | F082 changes are localised to `includes/Database/MCPServer/*`, `includes/Database/MCPServerAbility/ExposureResolver.php`, `includes/REST/AbilitiesController.php`, `includes/MCP/AbilityExposureGate.php`, and `src/js/abilities.js`. No cross-module coupling introduced. Extends the F017 module in place. |
| **II. WordPress Standards Compliance** | ✅ Pass | PHPStan L8 required per TASK (spec DoD gates + memory D24 layered enforcement invariant); PHPCS/WPCS strict; JS ESLint. All queries use `$wpdb->prepare()` or BerlinDB parameterised surfaces (S4). Single-site scope justified in spec Assumptions (matches F017). |
| **III. Security First (NON-NEGOTIABLE)** | ✅ Pass | New `POST /abilities/policy` gated on `current_user_can( 'manage_options' )` via shared `permission_check()` — no `__return_true` (S2). Column addition additive; no plaintext secrets (S3 n/a). `$wpdb->prepare()` for the overrides-clearing DELETE (S4). **F030 hazard defended in depth** via (1) rename of the row-only method to `resolve_row_only()` so the invariant is grep-visible in the name itself (SEC-001 Option A), (2) FR-007 + SC-005 (spec), (3) merge-blocker regression fence, (4) review-gate comment above the F030 call site. This is the single most important security invariant of the feature. |
| **IV. User-Centric Design (NON-NEGOTIABLE)** | ✅ Pass | F082 extends the F017 React tab (DataViews + `@wordpress/components`). New confirm modal uses `@wordpress/components` `<Modal>` (spec Clarifications Q3), not `window.confirm` — inherits focus-trap + Esc semantics. New header pill uses `role="status"` + `aria-live="polite"` (FR-019 — accessibility). No hand-rolled tables; DEV5 does not apply. Reinforces D37 React-first pattern. |
| **V. Extensibility Without Core Modification** | ✅ Pass | F082 adds one new hook (`acrossai_mcp_server_policy_changed`) — additive. Existing `acrossai_mcp_ability_exposure_changed` per-pair action unchanged (FR-012). No modification to F015 / F020 / F030 code paths. React tab still uses F017's extensibility hooks (`acrossaiMcpManager.abilities.{fields,actions,row}`). |
| **VI. Reusability & DRY Principle** | ✅ Pass | Resolver split adds a **sibling** (`resolve_effective()`) not a copy — D23 sibling-composer-extension pattern. Server-policy lookup centralised in `ExposureResolver::server_policy()` (single source of truth). Cross-surface parity via the augmented `GET /abilities` DTO (D43). |
| **VII. Definition of Done** | ✅ Pass | Spec DoD Gates section enumerates all 10 constitution DoD items plus F082-specific: F030 regression fence test, three grep audits (`ExposureResolver::resolve(` count, `resolve_effective(` scope, client-side merge removed). |

**No constitution violations. No Complexity Tracking entries required.**

## Project Structure

### Documentation (this feature)

```text
specs/082-ability-policy-defaults/
├── spec.md                              # Feature specification (5 user stories, 19 FRs, 7 SCs)
├── plan.md                              # THIS FILE
├── memory-synthesis.md                  # Retrieval-budgeted memory synthesis (880 words)
├── checklists/
│   └── requirements.md                  # /speckit-specify quality checklist (all pass)
└── tasks.md                             # (Phase 2 output — /speckit-tasks)

docs/planings-tasks/
└── 082-per-server-ability-policy-defaults.md  # Companion planning brief (1201 lines, TASK-1..10)
```

### Source Code (repository root)

```text
includes/
├── Database/
│   ├── MCPServer/
│   │   ├── Schema.php                   # TASK-2: append abilities_default_policy column def
│   │   ├── Table.php                    # TASK-2: bump $version; add $upgrades entry; add upgrade callback (D28 3-part)
│   │   └── Row.php                      # TASK-3: add public $abilities_default_policy + to_array() entry
│   └── MCPServerAbility/
│       └── ExposureResolver.php         # TASK-4: RENAME resolve() → resolve_row_only() (SEC-001 Option A);
│                                        #         add sibling resolve_effective() + server_policy() + two new caches;
│                                        #         extend _reset_cache_for_tests() to reset all three caches.
├── REST/
│   └── AbilitiesController.php          # TASK-6: new post_policy() handler; augment get_abilities() response;
│                                        #         calls resolve_effective(); post_abilities() UNCHANGED.
├── MCP/
│   └── AbilityExposureGate.php          # TASK-5: one-line swap resolve() → resolve_effective() at line 131.
│                                        #         NOT resolve_row_only() — the gate needs the three-tier resolution.
├── Abilities/
│   └── PermissionOverrideProcessor.php  # TASK-4: two-line delta at line 150 —
│                                        #         (a) resolve() → resolve_row_only() (rename),
│                                        #         (b) add F082 review-gate comment above it.
└── Main.php                             # No change (existing reconcile_database_schemas @ admin_init@3 picks up new upgrade)

admin/
└── Partials/
    └── ServerTabs/
        └── AbilitiesTab.php             # NOT TOUCHED (React owns the tab body from F017)

# Server-delete cascade (spec Clarifications Q1 + FR-018)
# TASK-2b: identify existing MCP-server-delete code path and add the cascade DELETE.
# Location TBD — probably admin/Partials/MCPServerListTable.php or a controller under includes/REST/;
# tasks.md must resolve the exact file(s) via a preflight grep before implementation.

src/
└── js/
    └── abilities.js                     # TASK-7: 6 deltas (client-merge deletion; Enable/Disable All → policy endpoint;
                                         #          counter rewrite; overridden filter; header pill; Modal confirm)

tests/
└── phpunit/
    ├── Database/
    │   ├── MCPServerAbility/ExposureResolverTest.php  # TASK-9: extend — resolve_effective cases + F030 regression fence (nested path corrected 2026-09-06)
    │   ├── MCPServerAbility/QueryCascadeTest.php      # TASK-2b/SEC-002: NEW — server-delete cascade coverage
    │   └── MCPServer/PolicyReconcilerTest.php         # TASK-9: NEW — D28 3-part contract exercised for the new column (nested path corrected 2026-09-06)
    └── REST/
        └── AbilitiesControllerTest.php  # TASK-9: extend (123 LOC) — policy endpoint cases

# Docs + memory (TASK-10)
README.txt                               # = Unreleased = bullet
docs/memory/DECISIONS.md                 # D51 + D52 Active entries
docs/memory/WORKLOG.md                   # Feature 082 milestone
docs/memory/INDEX.md                     # D51 + D52 rows + WORKLOG row
docs/planings-tasks/README.md            # Append F082 row
docs/planings-tasks/017-per-server-ability-selection.md  # Forward-pointer annotation on DEC-ABILITY-OVERRIDE-RESOLUTION
```

**Structure Decision**: WordPress plugin tree — single project, existing layout. F082 is entirely a delta on the existing F017 subsystem; no new modules or directories. All new files are additive (one PHPUnit file, plus the D51/D52 memory entries). New TASK-2b (server-delete cascade) is discovered by tasks-time preflight grep; see spec FR-018.

## Phase Plan

**Phase 0 — Preflight (done)**: `/speckit-specify` → `/speckit-clarify` (5 questions resolved) → `/speckit-memory-md-plan-with-memory` (synthesis complete). All prior-art file paths + line numbers verified against current code during planning-doc authoring (see companion brief §Verified against current code). No research.md deliverable needed — the planning doc + memory synthesis + spec Clarifications cover the research space.

**Phase 1 — Data model & contracts (this plan)**: See "Data model changes" and "REST API contract" below. No separate data-model.md / quickstart.md / contracts/ artefacts needed — the spec's Requirements, Key Entities, REST API Contract, and Storage sections are self-contained.

**Phase 2 — Tasks generation**: `/speckit-tasks` will materialise TASK-1..10 from the companion planning brief plus a new TASK-2b for the server-delete cascade (spec FR-018). TASK ordering per companion brief; dependency graph unchanged.

**Phase 3 — Implementation**: `/speckit-implement`. Each TASK runs to PHPStan L8 + PHPCS green before the next starts (constitution §VII per-task gating; memory D24 layered defence).

**Phase 4 — Review**: `/speckit-analyze` → `/speckit-security-review-staged` → `/speckit-architecture-guard-architecture-review`.

**Phase 5 — Memory capture + commit**: `/speckit-memory-md-capture-from-diff` produces D51 + D52 as Active decisions; `/speckit-git-commit`.

## Data model changes

| Table | Column | Change | Migration |
|---|---|---|---|
| `{$wpdb->prefix}acrossai_mcp_servers` | `abilities_default_policy` | **ADD** `VARCHAR(16) NOT NULL DEFAULT 'per-ability'` | BerlinDB `$upgrades` (D28 3-part contract). `$version` bumps to next patch; `upgrade_to_<v>()` idempotent via `INFORMATION_SCHEMA` column-exists check; fires on `admin_init@3` via existing `Main::reconcile_database_schemas()`. No manual ALTER, no dbDelta, no seed. |
| `{$wpdb->prefix}acrossai_mcp_server_abilities` | *(no change)* | — | Existing table; F082 does not touch its Schema. F082 DELETEs rows for a server via `post_policy()` and via the new server-delete cascade (FR-018). |

Existing servers get `abilities_default_policy = 'per-ability'` via the column default — byte-for-byte behavioural parity (SC-004). Existing override rows continue to win via `resolve_effective()`'s priority-1 tier.

## REST API contract

| Method | Route | Auth | Change |
|---|---|---|---|
| `GET` | `/wp-json/acrossai-mcp-manager/v1/servers/(?P<server_id>\d+)/abilities` | `manage_options` | **Augmented** — top-level `abilities_default_policy`; per-item `has_override: bool` and `is_exposed` now via `resolve_effective()`. Additive only. |
| `POST` | `/wp-json/acrossai-mcp-manager/v1/servers/(?P<server_id>\d+)/abilities` | `manage_options` | **Unchanged.** Per-pair override upsert. `acrossai_mcp_ability_exposure_changed` still fires per changed pair (FR-012). |
| `POST` | `/wp-json/acrossai-mcp-manager/v1/servers/(?P<server_id>\d+)/abilities/policy` | `manage_options` | **New.** Body `{ "policy": "per-ability" \| "expose" \| "hide" }`. Validated via `enum`. Clears overrides for the server (last-write-wins per spec Clarifications Q5). Fires `acrossai_mcp_server_policy_changed( $server_id, $old, $new, $affected_slugs, $user_id )` — `$affected_slugs` is a map `[ slug => [ 'was' => bool, 'now' => bool ] ]` (spec Clarifications Q2). No-op transitions suppressed (FR-015). |

## Hooks introduced

- `acrossai_mcp_server_policy_changed` — new server-scoped action, additive only. Signature frozen from merge forward (companion brief CONSTRAINTS).

## Hooks/routes NOT touched

- `acrossai_mcp_ability_exposure_changed` — verbatim per-pair fire at `AbilitiesController.php:306`.
- `mcp_adapter_pre_tool_call` priority 10 (F015) and priority 30 (F020) — no code change (memory `DEC-F020-TOOL-ENFORCEMENT-PRIORITY`).
- `wp_register_ability_args` (F030's priority-999999 hook) — no code change; only a comment is added above the resolver call inside `PermissionOverrideProcessor.php`.

## Complexity Tracking

*No entries — Constitution Check has zero violations.*

## Risks & mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| **F030 row-only bypass silently widened by a future "cleanup" of the row-only method** | Security regression (permission-callback bypass extended to every ability on `policy='expose'` servers) | (1) **Row-only method renamed `resolve()` → `resolve_row_only()`** so the invariant is grep-visible in the method name itself and any widening happens inside a method whose name broadcasts its semantics (SEC-001 Option A — recommended by the plan-phase security review 2026-09-05). (2) 6-line review-gate comment above `PermissionOverrideProcessor.php:150`. (3) Merge-blocker regression fence test `test_resolve_row_only_still_row_only_for_f030()` with a hazard-naming assertion message. (4) FR-007 + SC-005 + CONSTRAINTS block reinforce in the spec. Four independent gates — process + runtime + name-level. |
| **BerlinDB `$version` bumped but `$upgrades` empty → silent no-op** (B34) | New column never created on live installs; policy write fails silently | Ship the D28 3-part contract complete; verify with `SHOW COLUMNS FROM` in the spec Evidence Collation Template §1 (companion brief). |
| **Client-side merge left in place → tab lies on `policy='expose'` servers** | UI shows wrong `is_exposed` values; operator confusion | TASK-6 (GET augment) and TASK-7 (merge removal) MUST ship in the same commit (companion brief CONSTRAINT). Grep-audit added to Evidence Collation. |
| **Server-delete cascade omitted → orphan override rows accumulate** (spec Clarifications Q1) | Data hygiene; broken `has_override` counters on server-id reuse | New TASK-2b added; server-delete code path identified via preflight grep in `/speckit-tasks`; PHPUnit test asserts `SELECT COUNT(*) WHERE server_id = <deleted_id> = 0`. |
| **Concurrent policy POST + per-pair POST → silent override wipe** (spec Clarifications Q5) | Accepted last-write-wins; observable via `acrossai_mcp_server_policy_changed`'s `affected_slugs` map | No optimistic-concurrency layer; documented in spec Edge Cases + Assumptions; deferred to a follow-up feature if usage produces friction. |

## Governance references

- Memory synthesis: [memory-synthesis.md](memory-synthesis.md) — 5 decisions, 5 arch, 3 bugs, 3 deviations, 3 security, 2 worklog.
- Constitution: `.specify/memory/constitution.md` v1.1.0.
- Companion planning brief: [../docs/planings-tasks/082-per-server-ability-policy-defaults.md](../../docs/planings-tasks/082-per-server-ability-policy-defaults.md).
- Prior-art docs referenced: `docs/planings-tasks/{011,017,030}-*.md` (BerlinDB reconciler contract, F017 per-server ability selection, F030 permission override).

## Next command

`/speckit-tasks` — will materialise TASK-1..10 + TASK-2b (server-delete cascade) into `specs/082-ability-policy-defaults/tasks.md`.
