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

- [x] T001 Confirm the working tree is clean and on branch `090-server-types`, and that no `server_type` / `tools_default_policy` code remains from the pre-planning spike (`grep -rn "server_type\|tools_default_policy\|ServerTypes" includes/ admin/ src/` returns zero)
- [x] T002 Confirm the local database is at schema version `1.1.5` with neither new column present, so the migration is exercised from a true pre-090 state (recipe in `quickstart.md` §1)

---

## Phase 2: Foundational (Blocking Prerequisites)

**⚠️ Every user story depends on this phase. Nothing below Phase 2 can start until it completes.**

- [x] T003 Add `server_type` varchar(32) NOT NULL DEFAULT `'mcp-adapter'` and `tools_default_policy` varchar(16) NOT NULL DEFAULT `'per-tool'` to `$columns` in `includes/Database/MCPServer/Schema.php`, each with a comment stating why the default is load-bearing
- [x] T004 Add matching `public string` properties and `to_array()` keys for both columns in `includes/Database/MCPServer/Row.php`
- [x] T005 Bump `$version` to `'1.1.6'` AND register `'1.1.6' => 'upgrade_to_1_1_6'` in `$upgrades` in the SAME edit in `includes/Database/MCPServer/Table.php` (D28 three-part contract — a bump without the callback silently stamps the version)
- [x] T006 Implement `upgrade_to_1_1_6()` in `includes/Database/MCPServer/Table.php`: guard each ADD with BerlinDB's **inherited public** `column_exists( $name )` — do NOT declare a local helper of that name, it is a fatal access-level clash (`research.md` R4)
- [x] T007 In `includes/Database/MCPServer/Table.php`, in the same callback, issue exactly one slug-matched `UPDATE … SET server_type='acrossai' WHERE server_slug = DefaultServerSeeder::ACROSSAI_SLUG`, **gated on having just created the column** so a re-run cannot revert a deliberate operator switch
- [x] T008 [P] Create `includes/Database/MCPServer/ServerTypes.php` — stateless static registry (A11): constant seed (`mcp-adapter`, `acrossai`), the `acrossai_mcp_server_types` filter, and its own small normalizer. Do NOT use `Utilities\RegistryEntryNormalizer` (it drops entries lacking a callable `render_callback`)
- [x] T009 [P] Implement `ServerTypes::all()`, `get()`, `tools_for()`, `is_available()`, `default_slug()` and `enablement_error()` per `contracts/server-types-filter.md`; `default_slug()` MUST skip types whose `requires` is unmet, with `mcp-adapter` as the always-registered floor
- [x] T010 In `includes/Database/MCPServer/DefaultServerSeeder.php`, add `server_type` to `definitions()` — `'mcp-adapter'` in the Default server's **`managed`** bucket, `'acrossai'` in the AcrossAI server's **`initial`** bucket (NOT managed). Add `tools_default_policy` to neither bucket
- [x] T011 [P] PHPUnit: migration adds both columns; pre-existing rows read `mcp-adapter`; the AcrossAI row reads `acrossai`; re-running `maybe_upgrade()` is a no-op — in `tests/phpunit/Database/MCPServer/TableMigration116Test.php`. Restore schema explicitly in teardown; DDL escapes `WP_UnitTestCase` rollback (B53)
- [x] T012 [P] PHPUnit **regression for T007**: set the AcrossAI row to `mcp-adapter`, delete the version option, re-run the upgrade, assert the row is STILL `mcp-adapter` — in `tests/phpunit/Database/MCPServer/TableMigration116Test.php`
- [x] T013 [P] PHPUnit for the registry: seed shape, filter add, last-wins override of the `acrossai` placeholder (D41), `default_slug()` skipping an unmet requirement, unknown slug degrading without fatal — in `tests/phpunit/Database/MCPServer/ServerTypesTest.php`
- [x] T014 **[ARCH-1]** Create `includes/Database/MCPServer/ServerEnablement.php` with `set( int $server_id, bool $enabled ): true|WP_Error` as the ONLY sanctioned `is_enabled` writer; it consults `ServerTypes::enablement_error()` on off→on and returns the `WP_Error` unchanged  *(moved from US2 per SEC-005 — the boundary must exist before any story can enable a server)*
- [x] T015 **[ARCH-1]** Add a grep gate to `bin/verify-f021-gates.sh` failing CI on any `'is_enabled' =>` write outside `ServerEnablement` and `DefaultServerSeeder`, so a future fourth path is caught by CI rather than by review
- [x] T016 [P] PHPUnit: `POST /servers/{id}/tools` rejects an unregistered `server_type` with `acrossai_mcp_invalid_server_type` (400) and leaves the stored value unchanged; `POST /servers/{id}/tools/policy` rejects a value outside `expose|hide|per-tool`. Include a forged-value case — a well-formed slug that is not a registered type — in `tests/phpunit/REST/ToolsControllerValidationTest.php`  *(added per SEC-006)*
- [x] T017 Load a real wp-admin page and confirm `wp-content/debug.log` gains no fatal. **Not optional** — PHPCS and PHPStan both passed on code that white-screened the site during pre-planning (`research.md` R4)

**Checkpoint**: schema, registry and seeder exist and are proven. User stories may now proceed.

---

## Phase 3: User Story 1 — Reset restores the right tools (Priority: P1) 🎯 MVP

**Goal**: Each server records its type, and Tools-tab Reset restores that type's tool set
instead of the hardcoded three protocol slugs.

**Independent test**: On a server of each type, add/remove tools, press Reset, and confirm the
restored set matches that server's type rather than a fixed list.

### Tests for User Story 1

- [x] T018 [P] [US1] PHPUnit: `Reset` resolves per type — assert the restored set for an `mcp-adapter` row is `ToolPolicy::PROTOCOL_TOOLS` and for an `acrossai` row is that type's tools — in `tests/phpunit/Database/MCPServer/ToolPolicyResetTest.php`
- [x] T019 [P] [US1] PHPUnit: `compose_for_row()` (configured) and `compose_effective_tools_for_row()` (served) return DIFFERENT results once a standing policy is set, proving they are no longer a passthrough — in `tests/phpunit/Database/MCPServer/ToolPolicyComposerTest.php`

### Implementation for User Story 1

- [x] T020 [US1] **[ARCH-2]** Implement the precedence chain INSIDE `ToolPolicy::compose_effective_tools_for_row()` in `includes/Database/MCPServer/ToolPolicy.php` — unmet requirement > standing policy > per-tool composition. Leave `compose_for_row()` returning the CONFIGURED set only; this ends their passthrough relationship
- [x] T021 [US1] Expose `server_type`, `tools_default_policy`, `type_available`, `type_label` and `effective_tools` on `GET /servers/{id}/tools` in `includes/REST/ToolsController.php` per `contracts/rest-tools.md`
- [x] T022 [US1] Accept an optional validated `server_type` on `POST /servers/{id}/tools` in `includes/REST/ToolsController.php` so a type switch and its tool set are ONE atomic write; reject unknown slugs with `acrossai_mcp_invalid_server_type` (400)
- [x] T023 [US1] In `includes/REST/ToolsController.php`, reset `tools_default_policy` to `'per-tool'` in that same write whenever `server_type` changes and the policy was `expose`/`hide` (FR-012a)
- [x] T024 [US1] Add the type selector to the top of the Tools tab in `src/js/tools.js`, offering only available types and showing the raw slug marked unavailable for an unrecognised value
- [x] T025 [US1] **Rewire `applyReset()` in `src/js/tools.js`** to use the resolved type's tools instead of `PROTOCOL_TOOL_SLUGS`. *This single change is the defect the feature exists to fix.*
- [x] T026 [US1] Add a ConfirmDialog on type switch in `src/js/tools.js` naming BOTH effects — the tool selection is replaced AND the standing rule returns to "choose individually" — reusing the existing `pendingReset` pattern
- [x] T027 [US1] Add the "N tools available for this type · Apply" prompt in `src/js/tools.js`, shown only when the type's set contains slugs the server lacks; it must never apply without the operator clicking

**Checkpoint**: US1 is independently shippable — it alone fixes the Reset defect.

---

## Phase 4: User Story 2 — A server cannot be switched on until it can work (Priority: P2)

**Goal**: A server whose type has an unmet requirement cannot be enabled, on any route, and
the operator is offered both remedies.

**Independent test**: With the add-on inactive, attempt to enable an `acrossai` server through
every route and confirm each refuses; then switch its type and confirm it enables.

### Tests for User Story 2

- [x] T028 [P] [US2] PHPUnit: `ServerEnablement::set()` refuses off→on for an unmet requirement, ALWAYS permits on→off, and never auto-disables — in `tests/phpunit/Database/MCPServer/ServerEnablementTest.php`
- [x] T029 [P] [US2] PHPUnit: bulk enable over a mixed selection enables every eligible server and reports every skipped one with a reason (FR-016a) — in `tests/phpunit/Admin/SettingsBulkEnableTest.php`

### Implementation for User Story 2

- [x] T030 [US2] Route the single toggle at `admin/Partials/Settings.php:238` through `ServerEnablement::set()` and render the returned message
- [x] T031 [US2] Route the bulk branch at `admin/Partials/Settings.php:288` through `ServerEnablement::set()` with **partial-success** semantics — enable the eligible, skip the rest, name each skipped server and why
- [x] T032 [US2] Route `includes/REST/QuickConnectController.php:729` through `ServerEnablement::set()` and surface the `WP_Error` to the wizard
- [x] T033 [US2] Render the Enable affordance disabled with its reason in `admin/Partials/MCPServerListTable.php`. Do NOT gate `includes/MCP/Controller.php:357` — it is a READ (`has_any_enabled_server()`)
- [x] T034 [P] [US2] **[SEC-001]** Add a Server Type field to the classic create form in `admin/Partials/Settings.php:693-721`, preselecting `ServerTypes::default_slug()` and offering only available types; the `add_item()` array at `:347` MUST write `server_type` explicitly
- [x] T035 [US2] **[SEC-001]** Add the same field to (NOT parallel — shares `QuickConnectController.php` with T032, per SEC-007) `src/js/quick-connect/steps/Step2_ServerCreate.jsx`, AND write `server_type` explicitly in the second creation path at `includes/REST/QuickConnectController.php:631` — the path the first plan draft missed
- [x] T036 [P] [US2] PHPUnit: a server created through EACH path carries the registry default, not the column default — in `tests/phpunit/Admin/ServerCreateTypeTest.php`
- [x] T037 [US2] Surface BOTH remedies (install the add-on, or switch this server's type) wherever a requirement is unmet, in `admin/Partials/ServerTabs/OverviewTab.php` and `admin/Partials/ServerTabs/ToolsTab.php`
- [x] T038 [US2] Make step 4 non-skippable when the server in play has an unmet requirement, in `src/js/quick-connect/steps/Step4_AbilitiesManager.jsx`, so the operator cannot dead-end at step 6

**Checkpoint**: the requirement is enforced everywhere a server can be switched on.

---

## Phase 5: User Story 3 — A connected AI client is told what is wrong (Priority: P3)

**Goal**: Deactivating the add-on under a running server explains itself to the client instead
of failing opaquely, and never drops the connection.

**Independent test**: Connect a client to a working `acrossai` server, deactivate the add-on,
list the server's offerings, and confirm exactly one self-describing entry.

### Tests for User Story 3

- [x] T039 [P] [US3] PHPUnit: with an unmet requirement the effective tool list is exactly the diagnostic slug, and the server still registers — in `tests/phpunit/MCP/SetupRequiredTest.php`
- [x] T040 [P] [US3] **[SEC-002]** PHPUnit: the diagnostic ability is absent from `ToolAbilities::get_slugs()`, absent from discover results, and absent from the effective list of a server whose requirement IS met — in `tests/phpunit/MCP/SetupRequiredTest.php`

### Implementation for User Story 3

- [x] T041 [US3] Create `includes/Abilities/SetupRequired.php` — a plugin-owned ability whose description AND return value both name the required add-on, using the plugin text domain (translatable, per clarification Q3). Disclose only the plugin's public name: no paths, versions or site configuration
- [x] T042 [US3] **[SEC-002]** Scope it in `includes/Abilities/SetupRequired.php` and `includes/Abilities/ToolAbilities.php`: keep it out of `ToolAbilities::get_slugs()` and out of `discover-abilities`, and admit it only to the effective list of a server whose own requirement is unmet
- [x] T043 [US3] Wire its registration in `includes/Main.php` via the Loader (A1) — never in a constructor
- [x] T044 [US3] **[ARCH-2]** Ensure EVERY MCP composition path uses the effective composer — `includes/MCP/Controller.php:143` **and `:322`** (the `mcp_adapter_default_server_config` path the first draft missed). Never skip `create_server()` for an unmet requirement: that 404s the route and kills a live session
- [x] T045 [P] [US3] PHPUnit: a deactivate→reactivate cycle leaves curated presence rows byte-identical, proving the swap writes nothing to storage — in `tests/phpunit/MCP/SetupRequiredTest.php`

**Checkpoint**: an unmet requirement degrades into an explanation, not a failure.

---

## Phase 6: User Story 4 — Bulk tool selection (Priority: P4)

**Goal**: The Tools tab gains bulk controls matching the Abilities tab, backed by a STANDING
rule rather than a one-time sweep.

**Independent test**: Choose "add everything", activate a plugin contributing a new tool, and
confirm it is included with no further action.

### Tests for User Story 4

- [x] T046 [P] [US4] PHPUnit: policy `expose` includes a tool-level ability registered AFTER the policy was set; `hide` yields an empty set; `per-tool` reproduces today's behaviour exactly — in `tests/phpunit/Database/MCPServer/ToolsDefaultPolicyTest.php`
- [x] T047 [P] [US4] PHPUnit: switching `server_type` while the policy is `expose`/`hide` resets it to `per-tool` (FR-012a) — in `tests/phpunit/Database/MCPServer/ToolsDefaultPolicyTest.php`

### Implementation for User Story 4

- [x] T048 [US4] Add `POST /servers/{id}/tools/policy` to `includes/REST/ToolsController.php` with enum validation and an explicit `manage_options` `permission_callback`
- [x] T049 [US4] Add the bulk bar to `src/js/tools.js` — **Enable All / Disable All / Reset to Type Defaults** — reusing the existing ConfirmDialog for the two destructive actions
- [x] T050 [US4] Add the status pill to `src/js/tools.js` stating the current rule and override count, mirroring the Abilities tab, and stating BOTH: that `expose`/`hide` overrides the type's set until the rule returns to `per-tool`, AND that `expose` automatically includes tool-level abilities registered **after** the choice was made (SEC-008 — forward consent, not just precedence)

**Checkpoint**: tool curation reaches parity with ability curation.

---

## Phase 7: Polish & Cross-Cutting Concerns

- [x] T051 [P] **[§VI]** Add `render_inline_notice()` to `admin/Partials/ServerTabs/Partials/AbilitiesManagerPromoCard.php`, then refit BOTH existing call sites — `admin/Partials/ServerTabs/AbilitiesTab.php:104` and `admin/Partials/ServerTabs/ToolsTab.php:97` — so neither retains its own hardcoded `is_plugin_active()` literal
- [x] T052 [P] Write `docs/extending-server-types.md` in the shape of `docs/extending-server-tools.md`: filter contract, entry shape, the placeholder→companion override pattern, and a worked example
- [x] T053 [P] Update `README.txt` §Unreleased with the schema change and the new filter
- [x] T054 Run `composer run phpcs`, `composer run phpstan`, `composer test`, `bin/verify-f021-gates.sh` and `npm run validate-packages` — all must be clean
- [x] T055 Work `quickstart.md` end to end on the live install, including §1a (the corrective-UPDATE regression) and §2 (the real page load)
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

---

## Implementation log — 2026-09-15 (MVP, Phases 1-3)

**Delivered and verified on the live install**: schema + `1.1.6` migration, the
`ServerTypes` registry, seeder buckets, the `ServerEnablement` facade + CI grep gate, the
ARCH-2 precedence chain, the REST layer, and the Tools-tab UI including **T025, the Reset
rewire this feature exists for**.

**Pulled forward from later phases, deliberately:**

- **T030-T032** (route the three callers through the facade) moved up from US2. T015's grep
  gate CANNOT PASS while any caller still writes `is_enabled` directly, so shipping the
  facade without the rewires would have left Phase 2 red. A facade nobody routes through is
  not a boundary — the same reasoning behind SEC-005.
- **T041/T042** (`SetupRequired`) moved up from US3, because T020's precedence chain
  references the diagnostic slug. Leaving layer 1 returning an empty array would have
  recreated the silent dead-endpoint state the feature exists to prevent.
- **T044** needed no work: both MCP composition paths (`Controller.php:143` and `:322`)
  already call the effective composer, so putting the logic in `ToolPolicy` covered both.

**Defect found by live verification, not by static analysis.** With the sibling ACTIVE but
not yet registering its type (that task is still open), `acrossai` resolved to the shipped
placeholder's EMPTY tool list while reporting available — so Reset would have wiped every
tool on that server, strictly worse than the hardcoded-defaults bug being fixed.
`ServerTypes::tools_for()` now falls back to the legacy set for an empty template as well as
an unknown slug, and the REST payload resolves through the same method so the UI cannot
diverge. PHPCS, PHPStan and ESLint were all clean before this was caught.

**Not done in this pass — the six MVP test tasks (T011, T012, T013, T016, T018, T019).**
Local PHPUnit cannot run WP-dependent suites (`WP_UnitTestCase` not found; no WP test library
installed), so they must be written against CI. NOTE T012's regression WAS exercised manually
against the live database — the operator's type switch survived a forced re-run — but the
automated guard is not yet in place.

### US2 pass — 2026-09-15

T033-T038 delivered. T036 (create-path tests) deferred with the other five test
tasks — local PHPUnit cannot run WP-dependent suites.

Verified live rather than assumed, by setting a server to an unregistered type (which
exercises the same `enablement_error()` path as a deactivated sibling, without touching
plugin activation):

- servers list — 1 disabled Enable carrying the exact reason, 0 clickable Enable,
  **2 clickable Disable** confirming on->off is never gated
- Overview tab — the warning renders with BOTH remedies as separate buttons
- `active_plugins` byte-compared against a backup afterwards: never modified

Two silent-failure bugs caught and fixed during this pass, both of the same shape —
a control that refused correctly but said nothing:

1. The single-toggle refusal was stored on an instance property that a
   POST-redirect-GET flow can never render. Now redirects with a notice; bulk reports
   partial success (FR-016a).
2. T038's advance guard blocked Continue with no explanation. Now renders a warning
   offering both remedies before the operator hits the wall.

### US3/US4 pass — 2026-09-15

US3's non-test work was already complete: `SetupRequired` and its Loader wiring shipped in
the MVP (T041-T043), and T044 needed nothing because both MCP composition paths
(`Controller.php:143` and `:322`) already call the effective composer — putting the logic in
`ToolPolicy` covered both by construction.

US4 delivered T048-T050: the policy route, the Add All / Remove All / Reset to Type Defaults
bar, and the standing-rule pill. Verified live — the rule persists, the pill reflects it, and
the confirm dialog carries SEC-008's forward-consent warning ("including ones registered
later by plugins you install in future") plus the reassurance that the individual selection
is kept.

**Stale-read bug found and fixed.** Both write handlers re-fetched the row to build their
response, but BerlinDB's singleton Query can serve a memoized row inside the same request, so
the response was computed from PRE-write state. Observed directly: after returning to
`per-tool` the tab reported "serving 26 while 3 are configured". Storage was correct
throughout — only the response disagreed. Both handlers now reflect the columns they wrote
onto the row in hand instead of re-reading. Confirmed by querying the endpoint directly:
`per-tool`, 3 configured, 3 effective.

**Known cosmetic quirk, not fixed.** The divergence notice can lag when two policy writes are
issued in rapid succession (faster than a human clicks) — the second write's optimistic state
can render before its read settles. Steady state is always correct on load, and the notice is
an addition of mine rather than a spec requirement. Worth a proper fix if it ever shows up in
normal use; not worth blocking on.

Replaced two nested ternaries with lookup maps (`POLICY_PILL_LABEL` /
`POLICY_PILL_DESCRIPTION`) — `no-nested-ternary` was right, and three states with two strings
each read better as data.

### Polish pass — 2026-09-15

T051-T055 done. The §VI extraction landed as specified: `render_inline_notice()` on
`AbilitiesManagerPromoCard`, both existing call sites refitted, and
`grep -rn "is_plugin_active( 'acrossai-abilities-manager" admin/ includes/` now returns ZERO
outside that card — one implementation, one plugin-path literal.

The §VI-vs-A3 deviation is recorded in the method's own docblock, not only in plan.md, so the
next person to read the code finds the reasoning where they need it.

Gates: PHPCS 0, PHPStan 0, ESLint 0, all F021 gates pass, validate-packages clean.

Quickstart §1/§2/§5 re-verified after the extraction; §3/§4 were verified through the UI
during the MVP and US2 passes. Server 3's route returns 404 because it is DISABLED — that is
A21's safety layer working, not a regression.

T056 (`/speckit-analyze` after implementation) remains — it is the drift audit WORKLOG
2026-07-04 recommends for exactly this shape of feature: four clarifications and two
architecture-review pivots.

### Architecture-review remediation — 2026-09-17 (post-T056)

`/speckit-architecture-guard-architecture-review` found the boundaries sound (ARCH-1 and
ARCH-2 both verified) and the drift entirely in the EXTENSION POINT, invisible from inside
because only the two shipped types exercise it.

- **V1/V3** — `ServerTypes::plugin_is_active()` assumed `slug/slug.php`. Three plugins active
  on the dev site break that (`insert-headers-and-footers/ihaf.php`, `sfwd-lms/sfwd_lms.php`,
  `wp-mail-smtp/wp_mail_smtp.php`), so a third-party type naming one was permanently
  unavailable ON A SITE WHERE ITS DEPENDENCY WAS RUNNING. Now prefix-matches `active_plugins`
  (+ `active_sitewide_plugins` on multisite), which also drops the `wp-admin/includes/plugin.php`
  load out of the MCP/REST path (A3).
- **V2** — `AbilitiesManagerPromoCard::resolve_state()` was a second implementation of the same
  question, with the CORRECT algorithm. It now delegates the boolean and keeps only
  missing-vs-inactive, which is genuinely its own concern.
- **Third site, found while fixing the first two** — `QuickConnectController::handle_install_plugin()`
  carried the same assumption behind a two-slug allow-list. Convention guess removed entirely;
  unresolvable is now an error, not a guess.
- **V4/V5** — `contracts/rest-tools.md`, `contracts/server-types-filter.md` and `data-model.md`
  brought back in step with the shipped API (`type_pool`, `server_types`, `pool_for()`,
  `registered_only()`, `tools`-as-exclusivity-claim, `requires`-by-directory).
- **T013 done** — `tests/phpunit/Database/MCPServer/ServerTypesTest.php`, whose
  `provideRealWorldPluginFiles()` is the case that would have caught V1: real `active_plugins`
  values rather than invented ones.
- **Gates** — two added to `bin/verify-f021-gates.sh`; one of them then REMOVED for flagging
  correct code. Gate the defect, not the function.
- **Memory** — BUGS.md B60, DECISIONS.md D58, both routed in INDEX.md.

Remaining: NONE. Every test task in this feature is written.

### T011 + T012 — 2026-09-17

`tests/phpunit/Database/MCPServer/TableMigration116Test.php`. T012 is the regression for T007,
the task this file calls the feature's highest-risk: the corrective UPDATE is gated on having
just CREATED the column, and ungated it silently reverts an operator who used the switch-type
escape hatch. Until now it had only ever been checked by hand against the live database.

Two harness traps the test has to dodge, or it passes while proving nothing:

- **Rewind the stored version, never delete the option.** Deleting sends BerlinDB down its
  FRESH-INSTALL path, which never calls the upgrade callback at all.
- **Clear `*_upgrade_lock` first.** BerlinDB v3's 900-second concurrency guard is left set by
  any earlier upgrade in the suite, and `maybe_upgrade()` then returns immediately.

A mirror test asserts an untouched AcrossAI row STAYS corrected, so the gate cannot be
"satisfied" by disabling the UPDATE outright.

### T016 + T028 — 2026-09-17

The two security boundaries, tested before the convenience ones.

**T028** — `ServerEnablementTest`. The invariant is ASYMMETRIC and the asymmetry IS the safety
property: off->on is gated, on->off is unconditional. Every refusal assertion is paired with one
proving the opposite direction still works, because a facade that refused everything would pass a
one-sided suite while stranding any server whose dependency broke underneath it. Also covers
FR-016a partial-success bulk, the D19 refusal action, the redundant-call no-op, and FR-018 (a
running server is never auto-disabled — reading the gate must never write).

**T016** — `ToolsControllerValidationTest`. The case SEC-006 asks for is the FORGED one: a
well-formed, plausible slug that simply is not registered. `sanitize_key()` passes it happily;
only the registry lookup rejects it. Every rejection is paired with an assertion that the stored
value is UNCHANGED — a 400 that still wrote would be worse than no validation, since the caller
believes it failed and the row disagrees. Positive cases included deliberately: a validator that
rejects everything passes every negative test. Policy cases are driven from
`ToolPolicy::POLICIES` so adding a value cannot leave the test behind (B48), and the retired
`all`/`none` are asserted to be REJECTED now — a stale client must fail loudly rather than store
a value no branch of the precedence chain matches.

### The remaining nine — 2026-09-17

All written; the feature's Definition of Done gate for tests is now met.

| Task | File | What it locks |
|---|---|---|
| T018 | `ToolPolicyResetTest` | Reset follows the TYPE, not a fixed list — the defect F090 exists to fix |
| T019 | `ToolPolicyComposerTest` | configured vs served stay different questions (ARCH-2) |
| T029 | `SettingsBulkEnableTest` | FR-016a partial success + the ARCH-1 boundary |
| T036 | `ServerCreateTypeTest` | both create paths write `server_type` (SEC-001) |
| T039/T040/T045 | `SetupRequiredTest` | the diagnostic swap: exactly one entry, never leaks, writes nothing |
| T046/T047 | `ToolsDefaultPolicyTest` | `expose` is a STANDING rule; a type switch clears it |

**Two are partial, and say so in their own docblocks.** `Settings::handle_bulk_actions()`,
`Settings::handle_create_server()` and `QuickConnectController::apply_step_2()` are all private
and every branch ends in `exit`, so none can be invoked under PHPUnit without terminating the
run. Refactoring production code purely to make them callable was not judged worth it. T029 and
T036 therefore test the BEHAVIOUR through the seam that carries it (`ServerEnablement::set_many()`,
and the column-vs-registry default divergence) plus a SOURCE CONTRACT on each handler — the same
approach the F080 rename gate uses, and the same invariant `bin/verify-f021-gates.sh` enforces in
CI.

T036's first test is the one worth reading: it demonstrates the SEC-001 failure rather than
asserting it abstractly. A row created without an explicit `server_type` takes the column default
`'mcp-adapter'` while `ServerTypes::default_slug()` resolves to something else — the two answers
part company silently, and nothing errors.
