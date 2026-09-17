# Implementation Plan: Server Types

**Branch**: `090-server-types` | **Date**: 2026-09-15 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `/specs/090-server-types/spec.md`

## Summary

Give every MCP server row a **type** and make the Tools-tab Reset restore that type's tool
set instead of the three hardcoded protocol slugs it restores today for every server. Two
types ship: `mcp-adapter` (the three protocol tools) and `acrossai` (tools supplied by the
sibling `acrossai-abilities-manager`, empty here). The `acrossai` type hard-requires that
sibling, enforced in three independent layers — selection, enablement, runtime.

Technical approach: one `1.1.6` BerlinDB migration adds both `server_type` and
`tools_default_policy` (D28 three-part contract); a stateless `ServerTypes` registry mirrors
`ToolAbilities` (constant seed → one filter → own normalizer, last-wins per D41); the
enablement gate becomes a second condition on the plugin-owned `is_enabled` safety layer
(A21); and an unmet requirement swaps the composed tool list for a single diagnostic ability
at `mcp_adapter_init` — because the vendor resolves tools before
`mcp_adapter_pre_tool_call` fires, that hook cannot be used here.

## Technical Context

**Language/Version**: PHP 8.1+ · JavaScript (ES2020, `@wordpress/*` packages)
**Primary Dependencies**: `wordpress/mcp-adapter` ^0.6.1, `berlindb/core` ^3.0, WP Abilities
API (WP 7.1), `automattic/jetpack-autoloader` ^5.0
**Storage**: Custom BerlinDB table `{prefix}acrossai_mcp_servers` — two new columns.
Companion presence rows in `{prefix}acrossai_mcp_server_tools` (unchanged).
**Testing**: PHPUnit **^9.6** (pinned — use `@dataProvider`, NOT `#[DataProvider]`; see
Conflict Warnings in memory-synthesis.md), PHPCS, PHPStan L8, ESLint
**Target Platform**: WordPress 6.9+ single-site, wp-admin + REST + MCP transport
**Project Type**: WordPress plugin (PHP backend + React admin surfaces)
**Performance Goals**: No new per-request cost beyond one registry resolution per admin
request and one per MCP server registration. `ServerTypes` is resolved from a filter with no
DB access; it MUST NOT query.
**Constraints**: Zero behaviour change for pre-090 servers (SC-002). The MCP route MUST keep
answering when a requirement is unmet (FR-021). No toolset vocabulary in this plugin.
**Scale/Scope**: Two shipped types, extensible by filter. ~15 tool-level abilities on a
populated site. Servers per site: single digits typical.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-checked after Phase 1 design.*

| Principle | Gate | Status |
|---|---|---|
| **I. Modular Architecture** | New logic in a self-contained module; no god-classes | **PASS** — `ServerTypes` is one new stateless class; `SetupRequired` is one new ability class |
| **II. WordPress Standards** | PHPCS/PHPStan clean, WP APIs, prefixed hooks | **PASS** — one new filter `acrossai_mcp_server_types` |
| **III. Security First** (NON-NEG) | nonce, cap, sanitize, escape, prepare | **PASS** — see Security gates below |
| **IV. User-Centric Design** (NON-NEG) | DataViews/DataForm for new screens | **PASS (no new screen)** — extends existing surfaces only; servers list keeps its pre-ratified `WP_List_Table` exception |
| **V. Extensibility Without Core Modification** | filters, optional integrations degrade | **PASS** — sibling contributes via filter; plugin fully functional without it |
| **VI. Reusability & DRY** | extract before second use, to `includes/Utilities/` | **PASS with documented deviation** — see Complexity Tracking |
| **VII. Definition of Done** | gates in spec | **PASS** — plus a non-standard live-page-load gate |

**Architecture & UI Standards**

| Rule | Application | Status |
|---|---|---|
| **A1** hooks only in `Main.php` | `SetupRequired` registration + any gate filters Loader-wired | **PASS** |
| **A3** `includes/` context-neutral | `ServerTypes` and `SetupRequired` contain no admin logic | **PASS** |
| **A6** `use`/leading-`\` FQN | `Table` references `DefaultServerSeeder::ACROSSAI_SLUG` (same namespace — no import needed) | **PASS** |
| **A9** shared constants in `Utilities/` | type slugs are registry-owned, exposed via `ServerTypes` accessors, never re-literalled | **PASS** |
| **A11** pure-service singleton exemption | `ServerTypes` static-only, matching `ToolAbilities` | **PASS** |
| **A21** adapter has no `is_enabled`; gate is plugin-owned | 090 adds a second condition to that same layer; disabled-by-default preserved; no `add_item()` with `is_enabled => 1` | **PASS** |

**Security gates (III)**

- Both new columns are written only after validation against `ServerTypes::all()`; unknown
  slugs rejected (**B7** mass-assignment — writers filter against `Schema::columns()`).
- `ServerTypes::is_available()` is the single resolver for "requirement met"; selection,
  enablement and runtime all call it — no partial re-derivation (**B32**).
- The enablement gate is enforced server-side on all three write paths, never in the UI
  alone.
- The diagnostic ability discloses only the required plugin's public name — no paths,
  versions, or site configuration.
- The diagnostic ability MUST NOT become a path that skips any other ability's
  `permission_callback` (**D24** corollary: exposure ≠ authorization).

**Result: PASS.** One deviation documented in Complexity Tracking. No P0 violations.

### Post-Design Re-check (after Phase 1)

Re-evaluated against `data-model.md` and `contracts/`. Phase 1 introduced one new REST route
(`POST /servers/{id}/tools/policy`) and two new response fields; both carry explicit
`manage_options` `permission_callback`s and enum validation, so **III** still passes. The
registry remained DB-free and admin-free, so **A3/A11** still pass. `SetupRequired` carries no
privilege and cannot bypass another ability's `permission_callback`, so **D24**'s
exposure ≠ authorization corollary holds.

**No new violations. The §VI deviation is unchanged and remains the only one.**

> **Post-implementation addendum (2026-09-17, `/speckit-analyze` T056)**: a SECOND deviation
> was added to Complexity Tracking after this gate ran — FR-027's separate "no visible effect"
> notice, built and then removed on live-verification evidence. It is a UX deviation, not a
> constitution one; the Phase-1 result above stands as recorded.

## Project Structure

### Documentation (this feature)

```text
specs/090-server-types/
├── plan.md              # This file
├── spec.md              # Feature specification (4 clarifications integrated)
├── memory-synthesis.md  # Durable-memory synthesis (Step 2)
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output
├── quickstart.md        # Phase 1 output
├── contracts/           # Phase 1 output
│   ├── server-types-filter.md
│   └── rest-tools.md
├── checklists/
│   └── requirements.md
└── tasks.md             # Phase 2 (/speckit.tasks — NOT created here)
```

### Source Code (repository root)

```text
includes/
├── Database/MCPServer/
│   ├── ServerEnablement.php       # NEW — sole sanctioned is_enabled writer  [ARCH-1]
│   ├── Schema.php                 # + server_type, + tools_default_policy
│   ├── Row.php                    # + two properties, + to_array() keys
│   ├── Table.php                  # $version 1.1.6 + upgrade_to_1_1_6()
│   ├── DefaultServerSeeder.php    # server_type into managed/initial buckets
│   ├── ServerTypes.php            # NEW — the registry
│   └── ToolPolicy.php             # BOTH new layers live here: policy + unmet-req
│                                  #   swap, inside compose_effective_tools_for_row  [ARCH-2]
├── Abilities/
│   └── SetupRequired.php          # NEW — the diagnostic ability
├── MCP/Controller.php             # uses the effective composer at :143 AND :322
├── REST/
│   ├── ToolsController.php        # expose/accept type + policy
│   └── QuickConnectController.php # SECOND create path (:631) writes server_type;
│                                  #   enablement gate (:729)   [SEC-001]
└── Main.php                       # Loader wiring for the above

admin/
├── Partials/
│   ├── Settings.php               # type field on create form; enablement gate (toggle+bulk)
│   ├── MCPServerListTable.php     # Enable rendered disabled + reason
│   └── ServerTabs/
│       ├── OverviewTab.php        # requirement notice + both remedies
│       ├── ToolsTab.php           # onto shared notice
│       ├── AbilitiesTab.php       # onto shared notice
│       └── Partials/AbilitiesManagerPromoCard.php  # + render_inline_notice()

src/js/
├── tools.js                       # type selector, Reset rewire, bulk bar
└── quick-connect/steps/
    ├── Step2_ServerCreate.jsx     # type field
    └── Step4_AbilitiesManager.jsx # advance guard when requirement unmet

docs/extending-server-types.md     # NEW
tests/phpunit/…                    # migration, registry, seeder, gate, reset
```

> **SEC-001 correction (security review, 2026-09-15)**: an earlier draft of this plan listed
> only ONE server-creation path (`Settings.php:347`). A second exists at
> `QuickConnectController.php:631`. Both MUST write `server_type` explicitly — otherwise a
> Quick Connect server silently takes the column default, the stored type disagrees with the
> operator's selection, and the enablement gate evaluates a type they did not choose. See
> `security-constraints.md` SEC-001.

> **Architecture-review resolutions (2026-09-15)** — two High violations, both about WHERE a
> rule is enforced rather than whether it exists:
>
> - **ARCH-1 (Isolation Breach)**: the enablement invariant was enforced at each entry-layer
>   call site from a grep-built list that had already proven incomplete. Resolved with a
>   single `ServerEnablement::set()` facade that all three writers route through, plus a grep
>   gate in `bin/verify-f021-gates.sh` that fails CI on any `is_enabled` write outside it and
>   the seeder. A guard inside `MCPServer\Query::update_item()` was rejected: it is a
>   BerlinDB base method also used by the seeder and migrations.
> - **ARCH-2 (Hidden Coordination)**: the precedence chain was split between `ToolPolicy` and
>   `MCP\Controller`, which would let REST and MCP disagree about what a server serves. Both
>   new layers now live inside `ToolPolicy::compose_effective_tools_for_row()`, ending its
>   "straight passthrough" relationship with `compose_for_row()` — the two now answer
>   genuinely different questions (*served* vs *configured*), and REST returns both.
>
> Also captured: `Controller.php:322` (the default-server config path) is a second MCP
> composition site the first draft missed, and `QuickConnectController.php:631` is a second
> creation path (SEC-001).

**Structure decision**: No new top-level directories. `ServerTypes` sits beside the other
`MCPServer` module classes because it is per-server-row domain data, not a cross-cutting
utility — consistent with `ToolPolicy` and `ProtectedServers` living there.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| **§VI says shared logic goes to `includes/Utilities/`; the notice extraction targets `admin/Partials/ServerTabs/Partials/AbilitiesManagerPromoCard`** | The extracted unit renders admin HTML (`notice notice-info inline`), links to `admin.php?page=acrossai-addons`, and reuses `resolve_state()`'s three-state detection which already lives on that card. It is admin-only by nature. | Moving it to `includes/Utilities/` would violate **A3** ("classes in `includes/` are context-neutral and MUST NOT contain admin-specific logic") — a harder rule than §VI's location preference. §VI's *intent* (one source of truth, no duplication) is fully satisfied: three call sites, one implementation, one plugin-path literal. Splitting detection into `includes/` and rendering into `admin/` was considered and rejected as two classes where the card already owns both halves. |
| **A new `ServerEnablement` facade plus a CI grep gate, rather than gating the existing writers in place** | The invariant must survive a contributor who adds a fourth write path without reading this plan. A facade makes the boundary real; the grep gate makes it self-maintaining. | Enforcing inside `MCPServer\Query::update_item()` was rejected — it is a BerlinDB base-class method also used by the seeder and by migrations, so a type-policy guard there would block legitimate plugin-owned writes and couple schema plumbing to feature policy. Leaving enforcement at call sites was rejected because the grep-built inventory had already missed a path. |
| **FR-027's "the type's set has no visible effect" disclosure ships as the standing-rule pill, not a separate notice** | Under `expose`/`hide` the divergence between the configured set and the served set *is the rule working*, not a fault. A dedicated "serving X while Y configured" notice was built and then removed during live verification: it made a correct state read as broken. The pill states which rule is in force and what it does, which is the requirement's intent. | Disabling the per-row controls instead was rejected — presence-based storage (DEC-TOOL-SELECTION-PRESENCE-MODEL) has no third state, so a single tool cannot be excepted from a coarse rule, and the materialise-on-first-edit path (`src/js/tools.js:696`) is a better affordance than dead controls. Keeping the notice was rejected on the evidence above. FR-027 amended to match; rationale also carried in the code at `src/js/tools.js:900`. |
| **A second column (`tools_default_policy`) ships in the same migration as `server_type`** | Both are per-server attributes of the same row, introduced by the same feature, and the Tools bulk bar is meaningless without the policy. | Two separate version bumps (`1.1.6` + `1.1.7`) a week apart doubles the migration surface on the same table and creates a window where an install is half-upgraded. D28's contract is per-version, not per-column, and explicitly permits multiple ALTERs in one callback. |
