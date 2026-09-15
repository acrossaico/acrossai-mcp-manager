---
description: "Task list for Feature 090 — Server Types"
---

# Tasks: Server Types

**Input**: Design documents from `/specs/090-server-types/`
**Prerequisites**: `plan.md`, `spec.md`, `research.md`, `data-model.md`, `contracts/`, `quickstart.md`, `security-constraints.md`

**Tests**: INCLUDED. The spec's Definition of Done requires "PHPUnit tests written and
passing for all new PHP logic", so test tasks are generated per story.

**Organization**: Tasks are grouped by user story so each story is independently
implementable and testable.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: parallelizable — different files, no dependency on an incomplete task
- **[USn]**: the user story this task serves (story phases only)

## Path Conventions

Single WordPress plugin at repo root: `includes/`, `admin/`, `src/js/`, `tests/phpunit/`,
`docs/`. Paths below are exact and repo-relative.

**PHPUnit is pinned `^9.6`** — use `@dataProvider` annotations, NEVER `#[DataProvider]`
attributes (they are inert under 9.6). See `research.md` R7.

---

## Phase 1: Setup (Shared Infrastructure)

- [ ] T001 Confirm the working tree is clean and on branch `090-server-types`, and that no `server_type` / `tools_default_policy` code remains from the pre-planning spike (`grep -rn "server_type\|tools_default_policy\|ServerTypes" includes/ admin/ src/` returns zero)
- [ ] T002 Confirm the local database is at schema version `1.1.5` with neither new column present, so the migration is exercised from a true pre-090 state (recipe in `quickstart.md` §1)

---

## Phase 2: Foundational (Blocking Prerequisites)

**⚠️ Every user story depends on this phase. Nothing below Phase 2 can start until it completes.**

- [ ] T003 Add `server_type` varchar(32) NOT NULL DEFAULT `'mcp-adapter'` and `tools_default_policy` varchar(16) NOT NULL DEFAULT `'per-tool'` to `$columns` in `includes/Database/MCPServer/Schema.php`, each with a comment stating why the default is load-bearing
- [ ] T004 Add matching `public string` properties and `to_array()` keys for both columns in `includes/Database/MCPServer/Row.php`
- [ ] T005 Bump `$version` to `'1.1.6'` AND register `'1.1.6' => 'upgrade_to_1_1_6'` in `$upgrades` in the SAME edit in `includes/Database/MCPServer/Table.php` (D28 three-part contract — a bump without the callback silently stamps the version)
- [ ] T006 Implement `upgrade_to_1_1_6()` in `includes/Database/MCPServer/Table.php`: guard each ADD with BerlinDB's **inherited public** `column_exists( $name )` — do NOT declare a local helper of that name, it is a fatal access-level clash (`research.md` R4)
- [ ] T007 In `includes/Database/MCPServer/Table.php`, in the same callback, issue exactly one slug-matched `UPDATE … SET server_type='acrossai' WHERE server_slug = DefaultServerSeeder::ACROSSAI_SLUG`, **gated on having just created the column** so a re-run cannot revert a deliberate operator switch
- [ ] T008 [P] Create `includes/Database/MCPServer/ServerTypes.php` — stateless static registry (A11): constant seed (`mcp-adapter`, `acrossai`), the `acrossai_mcp_server_types` filter, and its own small normalizer. Do NOT use `Utilities\RegistryEntryNormalizer` (it drops entries lacking a callable `render_callback`)
- [ ] T009 [P] Implement `ServerTypes::all()`, `get()`, `tools_for()`, `is_available()`, `default_slug()` and `enablement_error()` per `contracts/server-types-filter.md`; `default_slug()` MUST skip types whose `requires` is unmet, with `mcp-adapter` as the always-registered floor
- [ ] T010 In `includes/Database/MCPServer/DefaultServerSeeder.php`, add `server_type` to `definitions()` — `'mcp-adapter'` in the Default server's **`managed`** bucket, `'acrossai'` in the AcrossAI server's **`initial`** bucket (NOT managed). Add `tools_default_policy` to neither bucket
- [ ] T011 [P] PHPUnit: migration adds both columns; pre-existing rows read `mcp-adapter`; the AcrossAI row reads `acrossai`; re-running `maybe_upgrade()` is a no-op — in `tests/phpunit/Database/MCPServer/TableMigration116Test.php`. Restore schema explicitly in teardown; DDL escapes `WP_UnitTestCase` rollback (B53)
- [ ] T012 [P] PHPUnit **regression for T007**: set the AcrossAI row to `mcp-adapter`, delete the version option, re-run the upgrade, assert the row is STILL `mcp-adapter` — in `tests/phpunit/Database/MCPServer/TableMigration116Test.php`
- [ ] T013 [P] PHPUnit for the registry: seed shape, filter add, last-wins override of the `acrossai` placeholder (D41), `default_slug()` skipping an unmet requirement, unknown slug degrading without fatal — in `tests/phpunit/Database/MCPServer/ServerTypesTest.php`
- [ ] T014 **[ARCH-1]** Create `includes/Database/MCPServer/ServerEnablement.php` with `set( int $server_id, bool $enabled ): true|WP_Error` as the ONLY sanctioned `is_enabled` writer; it consults `ServerTypes::enablement_error()` on off→on and returns the `WP_Error` unchanged  *(moved from US2 per SEC-005 — the boundary must exist before any story can enable a server)*
- [ ] T015 **[ARCH-1]** Add a grep gate to `bin/verify-f021-gates.sh` failing CI on any `'is_enabled' =>` write outside `ServerEnablement` and `DefaultServerSeeder`, so a future fourth path is caught by CI rather than by review
- [ ] T016 [P] PHPUnit: `POST /servers/{id}/tools` rejects an unregistered `server_type` with `acrossai_mcp_invalid_server_type` (400) and leaves the stored value unchanged; `POST /servers/{id}/tools/policy` rejects a value outside `all|none|per-tool`. Include a forged-value case — a well-formed slug that is not a registered type — in `tests/phpunit/REST/ToolsControllerValidationTest.php`  *(added per SEC-006)*
- [ ] T017 Load a real wp-admin page and confirm `wp-content/debug.log` gains no fatal. **Not optional** — PHPCS and PHPStan both passed on code that white-screened the site during pre-planning (`research.md` R4)

**Checkpoint**: schema, registry and seeder exist and are proven. User stories may now proceed.

---

## Phase 3: User Story 1 — Reset restores the right tools (Priority: P1) 🎯 MVP

**Goal**: Each server records its type, and Tools-tab Reset restores that type's tool set
instead of the hardcoded three protocol slugs.

**Independent test**: On a server of each type, add/remove tools, press Reset, and confirm the
restored set matches that server's type rather than a fixed list.

### Tests for User Story 1

- [ ] T018 [P] [US1] PHPUnit: `Reset` resolves per type — assert the restored set for an `mcp-adapter` row is `ToolPolicy::PROTOCOL_TOOLS` and for an `acrossai` row is that type's tools — in `tests/phpunit/Database/MCPServer/ToolPolicyResetTest.php`
- [ ] T019 [P] [US1] PHPUnit: `compose_for_row()` (configured) and `compose_effective_tools_for_row()` (served) return DIFFERENT results once a standing policy is set, proving they are no longer a passthrough — in `tests/phpunit/Database/MCPServer/ToolPolicyComposerTest.php`

### Implementation for User Story 1

- [ ] T020 [US1] **[ARCH-2]** Implement the precedence chain INSIDE `ToolPolicy::compose_effective_tools_for_row()` in `includes/Database/MCPServer/ToolPolicy.php` — unmet requirement > standing policy > per-tool composition. Leave `compose_for_row()` returning the CONFIGURED set only; this ends their passthrough relationship
- [ ] T021 [US1] Expose `server_type`, `tools_default_policy`, `type_available`, `type_label` and `effective_tools` on `GET /servers/{id}/tools` in `includes/REST/ToolsController.php` per `contracts/rest-tools.md`
- [ ] T022 [US1] Accept an optional validated `server_type` on `POST /servers/{id}/tools` in `includes/REST/ToolsController.php` so a type switch and its tool set are ONE atomic write; reject unknown slugs with `acrossai_mcp_invalid_server_type` (400)
- [ ] T023 [US1] In `includes/REST/ToolsController.php`, reset `tools_default_policy` to `'per-tool'` in that same write whenever `server_type` changes and the policy was `all`/`none` (FR-012a)
- [ ] T024 [US1] Add the type selector to the top of the Tools tab in `src/js/tools.js`, offering only available types and showing the raw slug marked unavailable for an unrecognised value
- [ ] T025 [US1] **Rewire `applyReset()` in `src/js/tools.js`** to use the resolved type's tools instead of `PROTOCOL_TOOL_SLUGS`. *This single change is the defect the feature exists to fix.*
- [ ] T026 [US1] Add a ConfirmDialog on type switch in `src/js/tools.js` naming BOTH effects — the tool selection is replaced AND the standing rule returns to "choose individually" — reusing the existing `pendingReset` pattern
- [ ] T027 [US1] Add the "N tools available for this type · Apply" prompt in `src/js/tools.js`, shown only when the type's set contains slugs the server lacks; it must never apply without the operator clicking

**Checkpoint**: US1 is independently shippable — it alone fixes the Reset defect.

---

## Phase 4: User Story 2 — A server cannot be switched on until it can work (Priority: P2)

**Goal**: A server whose type has an unmet requirement cannot be enabled, on any route, and
the operator is offered both remedies.

**Independent test**: With the add-on inactive, attempt to enable an `acrossai` server through
every route and confirm each refuses; then switch its type and confirm it enables.

### Tests for User Story 2

- [ ] T028 [P] [US2] PHPUnit: `ServerEnablement::set()` refuses off→on for an unmet requirement, ALWAYS permits on→off, and never auto-disables — in `tests/phpunit/Database/MCPServer/ServerEnablementTest.php`
- [ ] T029 [P] [US2] PHPUnit: bulk enable over a mixed selection enables every eligible server and reports every skipped one with a reason (FR-016a) — in `tests/phpunit/Admin/SettingsBulkEnableTest.php`

### Implementation for User Story 2

- [ ] T030 [US2] Route the single toggle at `admin/Partials/Settings.php:238` through `ServerEnablement::set()` and render the returned message
- [ ] T031 [US2] Route the bulk branch at `admin/Partials/Settings.php:288` through `ServerEnablement::set()` with **partial-success** semantics — enable the eligible, skip the rest, name each skipped server and why
- [ ] T032 [US2] Route `includes/REST/QuickConnectController.php:729` through `ServerEnablement::set()` and surface the `WP_Error` to the wizard
- [ ] T033 [US2] Render the Enable affordance disabled with its reason in `admin/Partials/MCPServerListTable.php`. Do NOT gate `includes/MCP/Controller.php:357` — it is a READ (`has_any_enabled_server()`)
- [ ] T034 [P] [US2] **[SEC-001]** Add a Server Type field to the classic create form in `admin/Partials/Settings.php:693-721`, preselecting `ServerTypes::default_slug()` and offering only available types; the `add_item()` array at `:347` MUST write `server_type` explicitly
- [ ] T035 [US2] **[SEC-001]** Add the same field to (NOT parallel — shares `QuickConnectController.php` with T032, per SEC-007) `src/js/quick-connect/steps/Step2_ServerCreate.jsx`, AND write `server_type` explicitly in the second creation path at `includes/REST/QuickConnectController.php:631` — the path the first plan draft missed
- [ ] T036 [P] [US2] PHPUnit: a server created through EACH path carries the registry default, not the column default — in `tests/phpunit/Admin/ServerCreateTypeTest.php`
- [ ] T037 [US2] Surface BOTH remedies (install the add-on, or switch this server's type) wherever a requirement is unmet, in `admin/Partials/ServerTabs/OverviewTab.php` and `admin/Partials/ServerTabs/ToolsTab.php`
- [ ] T038 [US2] Make step 4 non-skippable when the server in play has an unmet requirement, in `src/js/quick-connect/steps/Step4_AbilitiesManager.jsx`, so the operator cannot dead-end at step 6

**Checkpoint**: the requirement is enforced everywhere a server can be switched on.

---

## Phase 5: User Story 3 — A connected AI client is told what is wrong (Priority: P3)

**Goal**: Deactivating the add-on under a running server explains itself to the client instead
of failing opaquely, and never drops the connection.

**Independent test**: Connect a client to a working `acrossai` server, deactivate the add-on,
list the server's offerings, and confirm exactly one self-describing entry.

### Tests for User Story 3

- [ ] T039 [P] [US3] PHPUnit: with an unmet requirement the effective tool list is exactly the diagnostic slug, and the server still registers — in `tests/phpunit/MCP/SetupRequiredTest.php`
- [ ] T040 [P] [US3] **[SEC-002]** PHPUnit: the diagnostic ability is absent from `ToolAbilities::get_slugs()`, absent from discover results, and absent from the effective list of a server whose requirement IS met — in `tests/phpunit/MCP/SetupRequiredTest.php`

### Implementation for User Story 3

- [ ] T041 [US3] Create `includes/Abilities/SetupRequired.php` — a plugin-owned ability whose description AND return value both name the required add-on, using the plugin text domain (translatable, per clarification Q3). Disclose only the plugin's public name: no paths, versions or site configuration
- [ ] T042 [US3] **[SEC-002]** Scope it in `includes/Abilities/SetupRequired.php` and `includes/Abilities/ToolAbilities.php`: keep it out of `ToolAbilities::get_slugs()` and out of `discover-abilities`, and admit it only to the effective list of a server whose own requirement is unmet
- [ ] T043 [US3] Wire its registration in `includes/Main.php` via the Loader (A1) — never in a constructor
- [ ] T044 [US3] **[ARCH-2]** Ensure EVERY MCP composition path uses the effective composer — `includes/MCP/Controller.php:143` **and `:322`** (the `mcp_adapter_default_server_config` path the first draft missed). Never skip `create_server()` for an unmet requirement: that 404s the route and kills a live session
- [ ] T045 [P] [US3] PHPUnit: a deactivate→reactivate cycle leaves curated presence rows byte-identical, proving the swap writes nothing to storage — in `tests/phpunit/MCP/SetupRequiredTest.php`

**Checkpoint**: an unmet requirement degrades into an explanation, not a failure.

---

## Phase 6: User Story 4 — Bulk tool selection (Priority: P4)

**Goal**: The Tools tab gains bulk controls matching the Abilities tab, backed by a STANDING
rule rather than a one-time sweep.

**Independent test**: Choose "add everything", activate a plugin contributing a new tool, and
confirm it is included with no further action.

### Tests for User Story 4

- [ ] T046 [P] [US4] PHPUnit: policy `all` includes a tool-level ability registered AFTER the policy was set; `none` yields an empty set; `per-tool` reproduces today's behaviour exactly — in `tests/phpunit/Database/MCPServer/ToolsDefaultPolicyTest.php`
- [ ] T047 [P] [US4] PHPUnit: switching `server_type` while the policy is `all`/`none` resets it to `per-tool` (FR-012a) — in `tests/phpunit/Database/MCPServer/ToolsDefaultPolicyTest.php`

### Implementation for User Story 4

- [ ] T048 [US4] Add `POST /servers/{id}/tools/policy` to `includes/REST/ToolsController.php` with enum validation and an explicit `manage_options` `permission_callback`
- [ ] T049 [US4] Add the bulk bar to `src/js/tools.js` — **Add All / Remove All / Reset to Type Defaults** — reusing the existing ConfirmDialog for the two destructive actions
- [ ] T050 [US4] Add the status pill to `src/js/tools.js` stating the current rule and override count, mirroring the Abilities tab, and stating BOTH: that `all`/`none` overrides the type's set until the rule returns to `per-tool`, AND that `all` automatically includes tool-level abilities registered **after** the choice was made (SEC-008 — forward consent, not just precedence)

**Checkpoint**: tool curation reaches parity with ability curation.

---

## Phase 7: Polish & Cross-Cutting Concerns

- [ ] T051 [P] **[§VI]** Add `render_inline_notice()` to `admin/Partials/ServerTabs/Partials/AbilitiesManagerPromoCard.php`, then refit BOTH existing call sites — `admin/Partials/ServerTabs/AbilitiesTab.php:104` and `admin/Partials/ServerTabs/ToolsTab.php:97` — so neither retains its own hardcoded `is_plugin_active()` literal
- [ ] T052 [P] Write `docs/extending-server-types.md` in the shape of `docs/extending-server-tools.md`: filter contract, entry shape, the placeholder→companion override pattern, and a worked example
- [ ] T053 [P] Update `README.txt` §Unreleased with the schema change and the new filter
- [ ] T054 Run `composer run phpcs`, `composer run phpstan`, `composer test`, `bin/verify-f021-gates.sh` and `npm run validate-packages` — all must be clean
- [ ] T055 Work `quickstart.md` end to end on the live install, including §1a (the corrective-UPDATE regression) and §2 (the real page load)
- [ ] T056 Re-run `/speckit-analyze` AFTER implementation — this feature had a 4-question clarification session and two architecture-review pivots, which is exactly the drift trigger recorded in WORKLOG 2026-07-04

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 (Setup)** → no dependencies
- **Phase 2 (Foundational)** → blocks EVERYTHING; the schema, registry and seeder are prerequisites for all four stories
- **Phase 3 (US1)** → needs Phase 2
- **Phase 4 (US2)** → needs Phase 2; independent of US1
- **Phase 5 (US3)** → needs Phase 2; T044 shares `ToolPolicy` with T020, so sequence them
- **Phase 6 (US4)** → needs Phase 2; T046/T047 assume T020's chain exists
- **Phase 7 (Polish)** → after all desired stories

### User Story Dependencies

- **US1 (P1)** — independent. Ships alone as the MVP.
- **US2 (P2)** — independent of US1; both need only Phase 2.
- **US3 (P3)** — soft dependency on US1's T020 (same method).
- **US4 (P4)** — soft dependency on US1's T020 (same precedence chain).

### Within Each User Story

Tests → composer/registry changes → REST → admin PHP → JS → checkpoint verification.

### Parallel Opportunities

- T008 + T009 (registry) run alongside T003–T007 (schema/migration) — different files.
- T011, T012, T013 are all `[P]`: different test files, no shared state.
- T034 + T035 (the two create forms) are `[P]` — different files, same contract.
- T039, T040, T045 are `[P]` within the same test file only if split by method; otherwise sequence.
- T051, T052, T053 are fully `[P]`.

---

## Parallel Example: Foundational Phase

```
# After T003–T007 land, launch the registry and its tests together:
T008  Create ServerTypes.php
T009  Implement its accessors
T013  ServerTypesTest.php

# And the migration tests in parallel with them:
T011  migration applies both columns
T012  corrective-UPDATE regression
```

---

## Implementation Strategy

### MVP First (User Story 1 only)

Phases 1 → 2 → 3, then stop and validate. That alone fixes the Reset defect and is
independently shippable. Everything after it adds enforcement and convenience.

### Incremental Delivery

1. **Phase 2** — schema + registry + seeder (no user-visible change yet)
2. **US1** — the defect fix 🎯
3. **US2** — the requirement gate
4. **US3** — the runtime explanation
5. **US4** — bulk parity
6. **Polish** — extraction, docs, gates

### Risk Notes

- **T007 is the highest-risk task.** An ungated corrective UPDATE silently reverts operators
  who used the switch-type escape hatch. T012 exists solely to catch that.
- **T017 is not ceremonial.** The pre-planning spike passed PHPCS and PHPStan while fatally
  white-screening the site.
- **T020 and T044 must agree.** They are the two halves of ARCH-2; if the precedence chain
  lands anywhere other than the effective composer, REST and MCP will report different tool
  lists.
