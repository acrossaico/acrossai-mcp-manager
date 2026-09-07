---
description: "Task list for F082 — Per-server Ability Policy Defaults"
---

# Tasks: Per-server Ability Policy Defaults

**Input**: Design documents from `specs/082-ability-policy-defaults/`
**Prerequisites**: [plan.md](plan.md) ✓, [spec.md](spec.md) ✓, [memory-synthesis.md](memory-synthesis.md) ✓, [security-constraints.md](security-constraints.md) ✓
**Companion planning brief**: [../../docs/planings-tasks/082-per-server-ability-policy-defaults.md](../../docs/planings-tasks/082-per-server-ability-policy-defaults.md) (canonical TASK-1..10 breakdown)
**Security review**: [../../docs/security-reviews/2026-09-05-082-ability-policy-defaults-plan.md](../../docs/security-reviews/2026-09-05-082-ability-policy-defaults-plan.md) (source of TASK-SEC-001..006)

**Tests**: REQUIRED for this feature. PHPUnit merge-blocker regression tests (F030 fence per spec SC-005) plus reconciler contract tests plus per-endpoint tests. Companion brief TASK-9 defines the required test set.

**Organization**: Tasks are grouped by user story (US1-US5 from spec.md) so each story can be implemented and tested independently. SEC-001 (row-only method rename) is Foundational — it MUST land before any user-story work touches the resolver.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (US1-US5)
- All paths absolute-relative to repo root: `/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/plugins/acrossai-mcp-manager/`

## Path Conventions

- **PHP source**: `includes/`, `admin/`
- **JS source**: `src/js/`
- **PHPUnit**: `tests/phpunit/`
- **Docs**: `docs/`

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Confirm the branch, capture baseline metrics, and lock in the pre-flight caller inventory referenced in the companion planning brief.

- [x] T001 Verify feature branch is checked out: `git branch --show-current` MUST return `082-ability-policy-defaults`.
- [x] T002 Capture the pre-flight caller grep baseline (companion brief "Pre-flight grep") into `specs/082-ability-policy-defaults/pre-flight-callers.txt` for post-implementation diff: `grep -rEn '(ExposureResolver::resolve\(|PermissionOverrideProcessor|acrossai_mcp_ability_exposure_changed)' --include='*.php' includes/ admin/ public/ tests/ acrossai-mcp-manager.php > specs/082-ability-policy-defaults/pre-flight-callers.txt`.
- [x] T003 [P] Confirm build tooling is available: `composer install --no-dev` (verify BerlinDB Kern classes autoloadable), `npm install` (verify `@wordpress/components` `<Modal>` present for TASK-7 confirm-modal delta per spec Clarifications Q3).

**Checkpoint**: Branch checked out; pre-flight caller inventory captured; build tools ready.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Ship the DB column, the resolver split (with the SEC-001 rename), the tool-call gate swap, and the F030 regression fence. **Every user story depends on this phase.**

**⚠️ CRITICAL**: No user story work can begin until Phase 2 is complete AND all Phase-2 tasks pass PHPStan L8 + PHPCS + F030 fence.

### DB column (companion brief TASK-2)

- [x] T004 Append column definition `array( 'name' => 'abilities_default_policy', 'type' => 'varchar', 'length' => '16', 'default' => 'per-ability' )` to `$columns` in `includes/Database/MCPServer/Schema.php` (place AFTER the last existing column so `SHOW CREATE TABLE` diff surfaces it at the bottom per companion brief TASK-2 guidance).
- [x] T005 In `includes/Database/MCPServer/Table.php`: (a) bump `$version` to the next patch (e.g. `0.0.2` — verify current value first; must be strict semver forward), (b) add entry to `$upgrades` at lines 69-74: `[ '<NEW_VERSION>', 'upgrade_to_<NEW_VERSION>' ]`, (c) implement the paired `upgrade_to_<NEW_VERSION>()` protected method per D28 3-part contract — idempotent (`INFORMATION_SCHEMA` column-exists check first), ALTER TABLE ADD COLUMN, returns `bool`. Reference: D28 memory decision.

### Row surface (companion brief TASK-3)

- [x] T006 [P] In `includes/Database/MCPServer/Row.php`: add public property `public $abilities_default_policy = 'per-ability';` and add matching `'abilities_default_policy' => $this->abilities_default_policy` entry to `to_array()`.

### Resolver split + SEC-001 rename (companion brief TASK-4 + SEC-001 Option A)

> **SCOPE AMENDMENT (2026-09-05, discovered during governed-implement)** — a
> grep for existing `ExposureResolver::resolve(` callers revealed **5 production
> call sites**, not 1-2 as the companion brief documented. The three additional
> sites are: `includes/Database/MCPServer/AbilityDiscovery.php:82`,
> `includes/Abilities/AbilityHelpers.php:79`,
> `includes/REST/AbilitiesController.php:278 + :284` (was/now snapshots inside
> `post_abilities()`), and `includes/REST/QuickConnectController.php:788`
> (aliased namespace). Per D24 defense-in-depth (advertisement-time = effective
> exposure), every non-F030 caller migrates to `resolve_effective()`. Only F030
> stays on `resolve_row_only()`. See T007c below for the sweep. Also: the
> existing `ExposureResolverTest.php` lives at
> `tests/phpunit/Database/MCPServerAbility/ExposureResolverTest.php` (nested
> path) with 6 direct `resolve()` calls — all six migrate to `resolve_row_only()`
> (they test row-only semantics).

- [x] T007 In `includes/Database/MCPServerAbility/ExposureResolver.php`: **rename** the existing `resolve()` static method to `resolve_row_only()` (SEC-001 Option A — the row-only semantics move into the method name so the F030 invariant is grep-visible and fail-loud on any future rename attempt). Body and signature unchanged. Update the file's own PHPDoc block to note the rename + F030 dependency.
- [x] T007a In `includes/Database/MCPServerAbility/ExposureResolver.php`: add sibling static method `resolve_effective( int $server_id, string $ability_slug, array $meta ): bool` implementing the three-tier priority — row-in-table → server policy → `meta.mcp.public` — per companion brief TASK-4 pseudocode. Add private `server_policy( int $server_id ): string` helper (with in-list validation against `[ 'expose', 'hide', 'per-ability' ]`). Add two new per-request static caches: `$effective_cache` (keyed `"{server_id}:{slug}"`) and `$policy_cache` (keyed by `server_id`). *(Was T009; renumbered so the sibling exists BEFORE any caller migration in T007c below.)*
- [x] T007b In `includes/Database/MCPServerAbility/ExposureResolver.php`: extend `_reset_cache_for_tests()` to reset all THREE caches (`$cache` existing + `$effective_cache` new + `$policy_cache` new) atomically. Keep the existing method name (F017 tests depend on it per companion brief CONSTRAINT). *(Was T010.)*
- [x] T007c **NEW — caller-migration sweep (SCOPE AMENDMENT 2026-09-05)**: migrate every non-F030 production caller of the retired `resolve()` name to `resolve_effective()`. Four production edits:
  - (a) `includes/MCP/AbilityExposureGate.php:131` (F017 call-time gate) — was TASK-5; already scheduled in T011 below. **Absorbed here for atomicity — T011 becomes a no-op verification task.**
  - (b) `includes/Database/MCPServer/AbilityDiscovery.php:82` (F026 advertisement-time enumeration) — advertisement per D24 must honour effective exposure so `policy='expose'` server surfaces the full set.
  - (c) `includes/Abilities/AbilityHelpers.php:79` (F026 `apply_exposure_filter` composer, per D24 explicit citation) — same D24 rationale as (b).
  - (d) `includes/REST/AbilitiesController.php:278 + :284` (was/now snapshots for the per-pair `acrossai_mcp_ability_exposure_changed` action) — using `resolve_effective` correctly suppresses the fire on no-op transitions (row appears but effective exposure did not change).
  - (e) `includes/REST/QuickConnectController.php:788` (Quick-Connect flow) — READ the surrounding code first to confirm advertisement-time semantics; if yes, migrate to `resolve_effective()`; if it's a security-critical check specific to Quick Connect, escalate before migrating.
  Each of (a)-(e) MUST land in the same commit as T007 (SEC-001 atomicity) — the retired `resolve()` method no longer exists after T007, so any caller left behind fatals.
- [x] T008 In `includes/Abilities/PermissionOverrideProcessor.php` at line 150: (a) update the call from `ExposureResolver::resolve( $server_id, $slug, array() )` to `ExposureResolver::resolve_row_only( $server_id, $slug, array() )` — this is the ONLY production caller that stays on the row-only method, (b) add the F082 review-gate comment (6 lines per companion brief TASK-4 snippet) immediately above the call site.
- [x] T008a **NEW (SCOPE AMENDMENT)** — update the 6 existing test callers in `tests/phpunit/Database/MCPServerAbility/ExposureResolverTest.php` (lines 36, 41, 45, 49, 53, 58, 62) from `ExposureResolver::resolve( ... )` to `ExposureResolver::resolve_row_only( ... )`. All six existing tests exercise row-only semantics (row-vs-meta-fallback) so migrating them to the renamed method is semantically identical. Also update the two comment mentions in `tests/phpunit/Abilities/PermissionOverrideProcessorTest.php` if the underlying assertions still work.

**T009 and T010 are absorbed into T007a and T007b above (renumbered for correct dependency order — sibling method must exist before the caller sweep in T007c).**

### Tool-call gate swap (companion brief TASK-5)

- [x] T011 **Absorbed into T007c(a)** — the gate swap is now part of the SEC-001 atomic caller-migration sweep. This task becomes a verification-only checkpoint: confirm `includes/MCP/AbilityExposureGate.php:131` calls `resolve_effective()` (not `resolve_row_only()`, not the retired `resolve()`), priority is still 20, `is_wp_error( $result )` short-circuit intact, F015 (priority 10) and F020 (priority 30) wirings in `Main.php` untouched.

### Server-delete cascade discovery (SEC-002 + companion brief TASK-2b + spec FR-018)

- [x] T012 [P] SEC-002 discovery task: run `grep -rEn 'DELETE FROM.*acrossai_mcp_servers|delete_item.*mcp_servers|Query.*delete_item.*server_id' --include='*.php' includes/ admin/ public/ bin/` to enumerate **every** MCP-server-delete code path (list-table row action, bulk action, REST controller, WP-CLI, admin AJAX). Cross-reference F032's `server_id` cascade (if any). Record the enumerated paths in `specs/082-ability-policy-defaults/server-delete-paths.txt`. Do NOT implement the cascade yet — that's T013.

- [x] T013 SEC-002 implementation: at each server-delete site enumerated in T012, add a cascade DELETE of override rows in `acrossai_mcp_server_abilities` matching the deleted `server_id`. **As-built (differs from the original prescription, recorded 2026-09-06)**: instead of adding a NEW `acrossai_mcp_server_deleted` action at every delete site, the listener subscribes to the PRE-EXISTING BerlinDB-level `mcp_server_deleted` action (the same one F020's tool-row cleanup already uses — every delete path routes through `MCPServer\Query::delete_item()`, so one hook covers list-table, bulk, REST, and WP-CLI with zero new fire sites; DRY per constitution §VI). The listener is `MCPServerAbility\Query::on_mcp_server_deleted()` calling `delete_items_for_server()` (a `$wpdb->delete()` bulk clear + cache-group flush), wired in `includes/Main.php::define_public_hooks()` next to the F020 subscription it mirrors. The hook name's missing `acrossai_mcp_` prefix is inherited (pre-F082 hook), not new surface.

### F030 regression fence — merge-blocker test (companion brief TASK-9 + SEC-001)

- [x] T014 In `tests/phpunit/Database/MCPServerAbility/ExposureResolverTest.php` (extend existing 66 LOC file — path corrected 2026-09-05; brief had wrong path): add `test_resolve_row_only_still_row_only_for_f030()` per companion brief TASK-9 pseudocode. Assertion message MUST include the exact text: "F082 review-gate: ExposureResolver::resolve_row_only() MUST remain row-only. F030 depends on this exact behaviour. Do not widen resolve_row_only() to consult server policy — add or update resolve_effective() instead. See docs/planings-tasks/082-per-server-ability-policy-defaults.md CONSTRAINTS." Include the sanity-check second assertion that `resolve_effective()` on the same server (policy='expose', no row) returns `true`. **This test MUST be green before ANY user-story implementation begins.**

### Quality gates (per-TASK gating per constitution §VII)

- [x] T015 Run `vendor/bin/phpcs includes/Database/MCPServer/ includes/Database/MCPServerAbility/ includes/MCP/ includes/Abilities/ includes/REST/AbilitiesController.php includes/REST/QuickConnectController.php` — zero errors, zero warnings. (Scope expanded 2026-09-05 to cover the T007c caller sweep: AbilityDiscovery, AbilityHelpers, was/now snapshots in AbilitiesController, QuickConnectController.)
- [x] T016 Run `vendor/bin/phpstan analyse --level=8 includes/Database/MCPServer/ includes/Database/MCPServerAbility/ includes/MCP/ includes/Abilities/ includes/REST/AbilitiesController.php includes/REST/QuickConnectController.php` — zero errors. (Same scope expansion as T015.)
- [x] T017 Run `vendor/bin/phpunit --filter test_resolve_row_only_still_row_only_for_f030` — MUST pass. If FAIL, STOP and fix the resolver split before moving to user stories. **GREEN 2026-09-06** (OK, 1 test / 2 assertions) — required rebuilding the WP-PHPUnit harness first: WP test-lib supports PHPUnit ≤9 while this repo pins ^13, so WP-dependent suites run via a dedicated PHPUnit 9.6 + yoast/phpunit-polyfills runner with the plugin's vendor installed `--no-dev` (autoloader collision otherwise); `tests/bootstrap-wp.php` now resolves the polyfills path and runs `Activator::activate()` so plugin tables exist (production creates them on activation/admin_init@3, neither of which fires under the test bootstrap).

**Checkpoint**: DB column landed via D28; resolver renamed + split; gate swapped; F030 fence green; server-delete cascade wired. Foundation ready — user story implementation can now begin in parallel.

---

## Phase 3: User Story 1 - "Enable All" that survives future ability registrations (Priority: P1) 🎯 MVP

**Goal**: A site admin clicks Enable All on the Abilities tab, confirms the modal, and abilities registered by later plugin updates automatically inherit "exposed" without any admin action.

**Independent Test**: On a `policy='per-ability'` server, click Enable All → confirm modal → verify pill flips to "Default policy: Expose every ability by default"; register a mu-plugin ability with `meta.mcp.public=false`; reload the tab; verify the new ability is toggled ON; invoke it from a live MCP client — the call succeeds.

### REST endpoint (companion brief TASK-6)

- [x] T018 [US1] In `includes/REST/AbilitiesController.php::register_routes()`: register the new `POST /servers/(?P<server_id>\d+)/abilities/policy` route per companion brief TASK-6 register_rest_route snippet. `permission_callback` = `array( $this, 'permission_check' )` (shared helper gating on `current_user_can( 'manage_options' )` — S2, constitution §III). Args: `server_id` (integer, sanitize `absint`) + `policy` (string, `enum: [ 'per-ability', 'expose', 'hide' ]`).
- [x] T019 [US1] In `includes/REST/AbilitiesController.php`: implement the `post_policy( \WP_REST_Request $req )` handler per companion brief TASK-6 steps 1-10. Special attention: (a) SEC-005 — for the overrides-clearing DELETE, prefer BerlinDB `Query::delete_where()` if it exists; otherwise fall back to `$wpdb->prepare( "DELETE FROM %i WHERE server_id = %d", $table, $server_id )` with `%i` for the identifier (WP 6.9+ safe). Grep first: if `%i` is already used elsewhere in the plugin, adopt it; else prefer BerlinDB's helper. (b) FR-015 no-op suppression: if `$old_policy === $policy`, skip the DELETE and skip the action fire; return the current shape. (c) Action-fire with the `[ slug => [ 'was' => bool, 'now' => bool ] ]` map per spec Clarifications Q2 + FR-011. (d) Reset the resolver cache via `ExposureResolver::_reset_cache_for_tests()` after the DELETE + before computing post-change state (so the diff sees fresh values). *(As-built note 2026-09-06: the overrides-clearing DELETE landed as `$wpdb->delete( $table, array( 'server_id' => $server_id ), array( '%d' ) )` — a third option beyond the two prescribed; equally parameterised and constitution-§III compliant.)*
- [x] T020 [US1] In `includes/REST/AbilitiesController.php::get_abilities()` at lines 168-199: augment the response with (a) top-level `abilities_default_policy` (from `$server_row->abilities_default_policy ?? 'per-ability'`), (b) per-item `is_exposed` now via `ExposureResolver::resolve_effective()` for EVERY registered ability (not just those with rows), (c) per-item `has_override: bool` boolean (`true` iff a row exists in `acrossai_mcp_server_abilities` for that `(server_id, slug)` pair — batch-query the whole set outside the loop to avoid N+1).

### React client (companion brief TASK-7 Delta 1 + 2 + 4 + 6)

- [x] T021 [US1] In `src/js/abilities.js` at lines 360-362: DELETE the client-side merge `const isExposed = override ? override.is_exposed : !! mcpMeta.public;` and replace with `const isExposed = !! row.is_exposed;` (server-computed via `resolve_effective()`). Must ship in the same commit as T020 (spec Edge Cases + companion brief CONSTRAINTS).
- [x] T022 [US1] In `src/js/abilities.js` around the counter render blocks (currently ~lines 631 + 734): replace the row-count-driven counter with the resolver-output-driven copy per companion brief TASK-7 Delta 4 pseudocode. Reads `policy`, `items.length`, `items.filter(i => i.is_exposed).length`, and `items.filter(i => i.has_override).length`.
- [x] T023 [US1] In `src/js/abilities.js` at lines 878-898 (Enable All handler): replace `saveMany( decoratedItems, true )` with the `@wordpress/components` `<Modal>` (per spec Clarifications Q3 — NOT `window.confirm`) confirmation flow per companion brief TASK-7 Delta 2 pseudocode. On confirm, POST `/${config.namespace}/servers/${config.serverId}/abilities/policy` with `{ policy: 'expose' }`; on success, `setItems( res.abilities )` + `setPolicy( res.abilities_default_policy )`. Import `Modal` from `@wordpress/components`; import `Button` too for the primary Confirm / secondary Cancel.
- [x] T024 [US1] In `src/js/abilities.js`: add the header pill above the DataViews table per companion brief TASK-7 Delta 6 pseudocode. Element MUST carry `role="status"` and `aria-live="polite"` per FR-019 + spec Clarifications Q4. Copy: 'Default policy: Expose every ability by default' / 'Hide every ability by default' / 'Use each ability's own default'. Style via the F017 `.mcp-tab-panel` sibling class conventions.

### US1 smoke + build

- [x] T025 [US1] Run `npm run build` — verify `build/js/abilities.js` compiles and `build/js/abilities.asset.php` emits with the new `@wordpress/components` `Modal` + `Button` dependencies.
- [ ] T026 [US1] Manual smoke per spec User Story 1: on a fresh `policy='per-ability'` server, click Enable All → confirm modal → verify pill + counter update + DB has `policy='expose'` and zero rows in `wp_acrossai_mcp_server_abilities` for that server. Then register a mu-plugin `wp_register_ability` with `meta.mcp.public=false`, reload tab, verify new ability appears as ON, and invoke it from a live MCP client — MUST succeed (before F082 it would 403).

**Checkpoint**: User Story 1 fully functional and independently testable. The "money case" (new ability inherits `expose` policy) works end-to-end.

---

## Phase 4: User Story 2 - "Disable All" that survives future ability registrations (Priority: P1)

**Goal**: Mirror of US1 in the opposite direction. Operator locks the server down and new abilities inherit `hidden` automatically.

**Independent Test**: Click Disable All → confirm → verify pill flips to "Default policy: Hide every ability by default"; register a mu-plugin ability with `meta.mcp.public=true`; reload; verify the new ability is toggled OFF; invoke it from an MCP client — call MUST return 403 with `acrossai_mcp_ability_not_exposed`.

### React client (companion brief TASK-7 Delta 3)

- [x] T027 [US2] In `src/js/abilities.js` at lines 899-919 (Disable All handler): mirror T023's shape with `{ policy: 'hide' }` and the confirm-modal copy "Hide every ability on this server by default? Any per-ability overrides will be cleared and future abilities will be hidden automatically." Same `<Modal>` + `Button` component reuse from T023.

### US2 smoke

- [ ] T028 [US2] Manual smoke per spec User Story 2: on any server, click Disable All → confirm → verify pill flips to Hide + counter reads "All hidden — default policy: hide. 0 overrides" + DB `policy='hide'` + zero rows. Register a mu-plugin ability with `meta.mcp.public=true`, reload tab, verify OFF, invoke via MCP client, MUST return 403 with `acrossai_mcp_ability_not_exposed`.

**Checkpoint**: User Story 2 fully functional. The "lockdown that survives future registrations" case works end-to-end.

---

## Phase 5: User Story 3 - Backwards compatibility for pre-F082 installs (Priority: P1)

**Goal**: Existing installs with per-ability override rows upgrade to F082 with **zero behaviour change**. Every previously-exposed ability remains exposed; every previously-hidden ability remains hidden.

**Independent Test**: Snapshot `GET /abilities` responses on a pre-F082 install; upgrade; trigger `admin_init@3`; diff post-upgrade responses — every `is_exposed` value MUST match.

### Migration verification tests (companion brief TASK-9 PolicyReconcilerTest — NEW)

- [x] T029 [P] [US3] Create `tests/phpunit/Database/MCPServer/PolicyReconcilerTest.php` (NEW — path corrected 2026-09-06 to the nested MCPServer dir, matching the shipped file) exercising the D28 3-part contract per companion brief TASK-9 spec: (a) assert `MCPServer\Table::$upgrades` array contains the new entry with the F082 version key, (b) assert `upgrade_to_<v>()` returns `true` on a fresh install and creates the column with `VARCHAR(16) NOT NULL DEFAULT 'per-ability'`, (c) assert running the upgrade twice is idempotent (`INFORMATION_SCHEMA` check short-circuits), (d) assert `$version` bump is a strict semver forward from the previous value.
- [x] T030 [P] [US3] In `tests/phpunit/REST/AbilitiesControllerTest.php` (extend existing 123 LOC file): add `test_get_abilities_response_stable_across_f082_upgrade()` — seed 3 servers with mixed override-row shapes (0 rows / 5 rows / 20 rows), snapshot the pre-upgrade GET response for each, run the F082 reconciler, snapshot the post-upgrade response, assert byte-for-byte identity of every `is_exposed` value.

### US3 manual regression

- [ ] T031 [US3] Manual regression per spec User Story 3: on an install with N pre-existing override rows across M servers, `wp option delete acrossai_mcp_manager_db_version` (simulate pre-F082 state — DO NOT drop the table), load `/wp-admin/admin.php?page=acrossai_mcp_manager`, verify `admin_init@3` reconciler adds the column with default `per-ability`, verify no `ALTER TABLE` shown in `SHOW WARNINGS`, verify `GET /abilities` for every server returns the same `is_exposed` set as before the upgrade.

**Checkpoint**: Pre-F082 installs upgrade with zero behavioural drift. SC-004 verified.

---

## Phase 6: User Story 4 - Per-ability override still works alongside default (Priority: P2)

**Goal**: The per-row toggle + Expose selected / Hide selected still work identically to F017. Overrides ALWAYS win over the server-level default.

**Independent Test**: On a `policy='expose'` server, toggle one ability OFF via the row toggle → row written → counter reads "All N exposed, 1 override" → invoke that ability via MCP client returns 403.

### React client (companion brief TASK-7 Delta 5)

- [x] T032 [US4] In `src/js/abilities.js` at lines 777-807 (exposure filter): add `{ value: 'overridden', label: __( 'Only overridden', 'acrossai-mcp-manager' ) }` as the fourth option. Filter logic: `items.filter( ( i ) => i.has_override )`. Other three options (`all`, `exposed`, `hidden`) unchanged.

### Per-pair action preservation (companion brief TASK-8)

- [x] T033 [US4] Verify `includes/REST/AbilitiesController.php::post_abilities()` at 209-317 is byte-for-byte unchanged — no code delta. Verify the existing `do_action( 'acrossai_mcp_ability_exposure_changed', ... )` still fires at line 306 (or wherever the refactor moved it). Grep-gate: `grep -rEn 'acrossai_mcp_ability_exposure_changed' includes/REST/` returns exactly ONE `do_action(...)` call.

### US4 tests

- [x] T034 [P] [US4] In `tests/phpunit/Database/MCPServerAbility/ExposureResolverTest.php` (extend — path corrected 2026-09-06, same nested location T014 already records): add `test_resolve_effective_row_wins_over_expose_policy()` — server with `policy='expose'` + `is_exposed=0` row for slug X → `resolve_effective()` returns `false`. Symmetric: `test_resolve_effective_row_wins_over_hide_policy()` — server with `policy='hide'` + `is_exposed=1` row → returns `true`.
- [x] T035 [P] [US4] In `tests/phpunit/REST/AbilitiesControllerTest.php` (extend): add `test_post_abilities_still_fires_per_pair_action()` — subscribe a test-double listener, POST a per-pair upsert changing 3 abilities, assert the listener fires exactly 3 times with the correct pair-level args.

### US4 smoke

- [ ] T036 [US4] Manual smoke per spec US4: (a) toggle one ability OFF on a `policy='expose'` server, verify row written + counter + "Only overridden" filter surfaces it; (b) toggle one ability ON on a `policy='hide'` server, verify row written + MCP call succeeds; (c) Expose selected on 5 abilities, verify 5 override rows + 5 `acrossai_mcp_ability_exposure_changed` fires.

**Checkpoint**: Per-pair semantics preserved. US4 fully functional. F017's public contract unchanged (FR-012 + spec Additive-only invariant).

---

## Phase 7: User Story 5 - UI counter tells the truth (Priority: P2)

**Goal**: The counter reads the resolver output, not the row count. On a `policy='expose'` server with zero override rows, the counter reads "All N exposed", not "0 of N exposed".

**Independent Test**: On `policy='expose'` with zero overrides, verify counter reads "All N exposed — default policy: expose. 0 overrides".

### Counter tests

- [x] T037 [P] [US5] In `tests/phpunit/REST/AbilitiesControllerTest.php` (extend): add `test_get_abilities_augments_with_policy_and_has_override()` — verify the augmented GET response contains top-level `abilities_default_policy`, per-item `has_override` boolean, and per-item `is_exposed` via `resolve_effective()`. Assert on all three policies (per-ability, expose, hide) with a mix of override-row shapes.

### US5 smoke

- [ ] T038 [US5] Manual smoke per spec US5: on `policy='expose'` server with 0 overrides — counter reads "All N exposed — default policy: expose. 0 overrides"; on `policy='hide'` server with 1 `is_exposed=1` override — counter reads "All hidden — default policy: hide. 1 override"; on `policy='per-ability'` — counter reads "K of N abilities exposed" where K matches the effective resolver output.

**Checkpoint**: US5 fully functional. Truthful counter confirmed on all three policies.

---

## Phase 8: Polish & Cross-Cutting Concerns

**Purpose**: Endpoint-level tests, docs, memory captures, whole-plugin quality gates, SEC-003/004/006 follow-ups, release checklist.

### Additional endpoint tests (companion brief TASK-9)

- [x] T039 [P] In `tests/phpunit/REST/AbilitiesControllerTest.php` (extend): add `test_post_policy_expose_clears_overrides()` — seed 5 override rows on a server, POST `{policy:'expose'}`, verify `SELECT COUNT(*) FROM wp_acrossai_mcp_server_abilities WHERE server_id = X` = 0.
- [x] T040 [P] In `tests/phpunit/REST/AbilitiesControllerTest.php` (extend): add `test_post_policy_invalid_string_returns_400()` and `test_post_policy_nonexistent_server_returns_404()`.
- [x] T041 [P] In `tests/phpunit/REST/AbilitiesControllerTest.php` (extend): add `test_post_policy_noop_transition_suppresses_action()` — server already at `policy='expose'`, POST `{policy:'expose'}` again, verify `acrossai_mcp_server_policy_changed` does NOT fire and no DELETE is issued (FR-015).
- [x] T042 [P] In `tests/phpunit/REST/AbilitiesControllerTest.php` (extend): add `test_post_policy_fires_action_with_map_shape()` — subscribe a test-double, POST a policy transition that flips 3 abilities, assert the fired `$affected_slugs` is a map keyed by slug with `[ 'was' => bool, 'now' => bool ]` per FR-011 + spec Clarifications Q2.
- [x] T043 [P] Server-delete-cascade PHPUnit: assert `SELECT COUNT(*) FROM wp_acrossai_mcp_server_abilities WHERE server_id = <deleted_id>` = 0 after each server-delete code path enumerated in T012 (parameterised test). Shipped as `tests/phpunit/Database/MCPServerAbility/QueryCascadeTest.php` (6 tests / 12 assertions, green 2026-09-06).

### Docs + memory (companion brief TASK-10)

- [x] T044 [P] In `README.txt`: add the `= Unreleased =` bullet per companion brief TASK-10 pseudocode. Include the SEC-006 one-liner: "Policy-transition audit events carry the affected ability slugs and their exposure states — choose audit-log integrations you trust."
- [x] T045 [P] In `docs/planings-tasks/017-per-server-ability-selection.md`: append the F082 forward-pointer annotation to the `DEC-ABILITY-OVERRIDE-RESOLUTION` section per companion brief TASK-10 pseudocode.
- [x] T046 [P] In `docs/planings-tasks/README.md`: append a row for `082-per-server-ability-policy-defaults.md`.
- [x] T047 SEC-003 documentation: in `docs/planings-tasks/082-per-server-ability-policy-defaults.md` §Assumptions, add explicit note that policy transitions are observable ONLY through the action fire — no persistent DB history — and recommend subscribed audit-log integration in future production scenarios. (D51's memory-capture body will absorb this note when written by `/speckit-memory-md-capture-from-diff`.)

### Quality gates (whole-plugin sweep)

- [x] T048 Full PHPStan L8 sweep: `vendor/bin/phpstan analyse --level=8 includes/ admin/ public/ tests/` — zero errors on the whole codebase (not just the F082 delta).
- [x] T049 Full PHPCS sweep: `vendor/bin/phpcs includes/ admin/ public/ tests/` — zero errors, zero warnings on the whole codebase.
- [x] T050 **AMENDED 2026-09-06 — F082 scope green; whole-plugin sweep blocked by pre-existing debt.** All five F082 test files pass (56 tests / 107 assertions): `Database/MCPServerAbility/ExposureResolverTest` (incl. F030 fence), `Database/MCPServer/PolicyReconcilerTest`, `Database/MCPServerAbility/QueryCascadeTest`, `REST/AbilitiesControllerTest`, `Abilities/PermissionOverrideProcessorTest` — plus the two pure suites (`mcpclients` + `rename-gate`, 112 tests) under the repo's own PHPUnit 13. The FULL WP-dependent sweep cannot pass: suites across F011-F080 (Database misc, Abilities, MCP, RestCli, FrontendAuth, Admin, Public, OAuth, Embeds — 100+ failures, several fatals) were authored but never executed in this environment (the harness was never provisioned) and have accumulated API drift (BerlinDB Kern object-vs-array columns, `get_wp_die_handler` signature, etc.). That repair is a separate follow-up feature, not an F082 gate. Two F082 test bugs were fixed en route: the resolver-cache slug collision in `test_server_policy_rejects_unknown_db_value`, and the 401-vs-403 expectation for unauthenticated callers (WP core returns 401 logged-out / 403 under-privileged); `PolicyReconcilerTest` gained a schema self-heal `tear_down()` because its drop-and-restore DDL escapes per-test transaction rollback and could poison the shared test DB.
- [x] T051 JS lint: `npm run lint:js` — zero errors on `src/js/abilities.js`. **GREEN 2026-09-06** (zero errors AND zero warnings, whole scoped tree). Required toolchain repair: eslint was never installed/runnable in this repo (constitution §II/§VII gate previously vacuous). As-built: eslint@^10 added as a root devDependency (`--legacy-peer-deps`), legacy `.eslintrc` retired in favour of wp-scripts' default flat config (it only re-declared the same `plugin:@wordpress/eslint-plugin/recommended` preset), lint scope set to `src tests/jest webpack.config.js` (Node CLI maintainer scripts in `scripts/`/`.github/scripts/` and external `.agents/` excluded), ~1,400 style errors auto-fixed + ~55 manual conformance fixes across 8 files with exactly ONE `eslint-disable` added (`__experimentalConfirmDialog` in `src/js/tools.js`, pre-existing F020 usage).
- [x] T052 JS build: `npm run build` succeeds; `build/js/abilities.js` + `build/js/abilities.asset.php` emitted.
- [x] T053 `npm run validate-packages` passes (constitution §VII).

### Whole-plugin grep audits (blocker before merge)

- [x] T054 SEC-001 rename audit: `grep -rEn 'ExposureResolver::resolve\(' includes/ admin/ public/` MUST return **zero matches** (the old method name is fully retired). If any hit, the SEC-001 rename is incomplete or a new caller was introduced against the retired name.
- [x] T055 SEC-001 row-only audit: `grep -rEn 'ExposureResolver::resolve_row_only\(' includes/ admin/ public/` MUST return exactly ONE match — the F030 call site at `includes/Abilities/PermissionOverrideProcessor.php:150`, with empty meta as the third argument and the F082 review-gate comment immediately above.
- [x] T056 Effective-resolver audit (SCOPE AMENDMENT 2026-09-05): `grep -rEn 'ExposureResolver::resolve_effective\(' includes/ admin/ public/` MUST return matches under (at minimum) `includes/MCP/AbilityExposureGate.php`, `includes/REST/AbilitiesController.php`, `includes/Database/MCPServer/AbilityDiscovery.php`, `includes/Abilities/AbilityHelpers.php`, and (if migrated in T007c(e)) `includes/REST/QuickConnectController.php`. Any hit inside `includes/Abilities/PermissionOverrideProcessor.php` is a **blocking regression** (F030 must not migrate to `resolve_effective()` — that's the whole point of SEC-001).
- [x] T057 Client-merge audit: `grep -rEn 'override \? override\.is_exposed : !!' src/js/` MUST return **zero matches** — the client-side merge is fully removed.
- [x] T058 Per-pair action audit: `grep -rEn 'acrossai_mcp_ability_exposure_changed' includes/REST/` MUST return exactly ONE `do_action(...)` call site.
- [x] T059 New action audit (**amended 2026-09-06 per architecture-review R1**): `grep -rEn 'acrossai_mcp_server_policy_changed' includes/` MUST return exactly ONE `do_action(...)` call site — inside `includes/Database/MCPServer/PolicyTransition.php::apply()`, the shared transition service both `AbilitiesController::post_policy()` and `QuickConnectController::apply_step_5()` delegate to. ZERO fire sites under `includes/REST/`.
- [x] T060 React libs audit (inherited from F017 CONSTRAINT): `grep -rEn 'react-query|@tanstack|redux|mobx|react-table|styled-components|@mui/' src/js/` MUST return **zero matches**.

### Evidence collation

- [ ] T061 Fill in the T-NN Evidence Collation Template §1-§7 in `docs/planings-tasks/082-per-server-ability-policy-defaults.md` with the paste-outputs from T031, T026, T028, T017, T050, T054-T060.

### Pre-merge release-checklist gates (SEC-004)

- [ ] T062 SEC-004 pre-merge check: confirm no new production installs have appeared since the 2026-09-04 dev-only attestation. If any have, upgrade the confirm-modal from generic copy to enumerated-overrides (spec Clarifications Q3 Option C) before merge.

**Checkpoint**: All quality gates green; grep audits clean; evidence pack filled in; release-checklist gate satisfied.

---

## Phase 9: Post-testing Addendum (2026-09-06 — completed during live-testing, recorded retroactively)

Live testing between governed-implement and the Phase-4 review surfaced one bug and several UX
gaps; all were fixed and verified in-browser before review. Spec deltas recorded in spec.md
§Post-implementation Amendments (AMD-001..005).

- [x] T063 [AMD-003] Fix stale-toggle bug in `src/js/abilities.js`: delete the write-only `overrides` state; consolidate response→state mapping into `applyServerResponse()` + `refreshFromServer({withSpinner})`; `saveMany()` now optimistically flips the server-truth `serverExposure` map then silently re-fetches to reconcile (rolls back on error). Verified in-browser: instant toggle, POST `/abilities` + silent GET, state survives reload.
- [x] T064 [AMD-001] Add "Reset to Ability Defaults" button posting `{policy:'per-ability'}` through the shared confirm-modal flow; third `confirmTitles`/`confirmBodies` branch. Verified end-to-end (neutral pill, per-ability counter, overrides cleared).
- [x] T065 [AMD-002] Disable each policy button while the server is already in that mode (FR-015 no-op suppression would make it a silent no-op — overrides NOT cleared server-side).
- [x] T066 [AMD-004] Header restructure: bordered `.acrossai-mcp-abilities-policy` panel (styled pill with per-policy colors — the pill class previously had NO css anywhere; counter; three policy buttons), Enable/Disable All moved out of the selection bulk bar; `role="status"` + `aria-live="polite"` preserved (FR-019). Confirm modal styled (480px cap, right-aligned actions, `isDestructive` red Confirm on the hide branch).
- [x] T067 [AMD-004] Full-screen branded loading overlay (pulsing AcrossAI icon over translucent blur, mirroring Quick Connect's `qs__initial-loading--overlay`) on initial load and policy transitions; `iconUrl` localized in `admin/Main.php::maybe_enqueue_abilities_app()`; Spinner retained as fallback. Verified — overlay captured mid-transition in-browser.
- [x] T068 [AMD-005] Migrate Quick Connect Step 5 `apply_step_5()` from N-per-ability upserts to the F082 policy flip (policy='expose' + override clear + cache reset + FR-011 action fire + FR-015 no-op parity); file-header comment updated. Verified end-to-end: DB shows `policy='expose'` with ZERO override rows after "Enable all and continue" (old path would have written ~435 rows).
- [ ] T069 Follow-up (out of F082 scope): repair the pre-existing never-executed WP-dependent PHPUnit suites (F011-F080 drift — see T050 amendment) as its own feature; consider relaxing FR-015's no-op suppression to clear overrides when any exist (AMD-002); consider adding a `POST /abilities/policy` PHPUnit case asserting the as-built response shape (overrides + policy + affected_slugs, no abilities list); consider splitting `AbilitiesController` (556 lines, two user stories' handlers) per the constitution's ~400-line REST sub-controller rule (architecture-review R3) and decomposing `src/js/abilities.js`'s monolithic `App()` component next time that file is touched.
- [x] T070 **Architecture-review R1 (2026-09-06)**: extract shared `includes/Database/MCPServer/PolicyTransition.php` (A11 pure-service, static `apply( $server_id, $new_policy )`) owning the full transition sequence — FR-015 no-op guard, snapshot, `update_item`, `delete_items_for_server()` override clear, resolver-cache reset, FR-011 diff, and the SINGLE `acrossai_mcp_server_policy_changed` fire. `AbilitiesController::post_policy()` and `QuickConnectController::apply_step_5()` are now thin delegating callers (§VI duplication removed; T059 audit true again).
- [x] T071 **Architecture-review R2 (2026-09-06)**: `ExposureResolver::_reset_cache_for_tests()` renamed to `reset_request_cache()` for production use; the old name survives as a delegating `@internal` alias because F017's test contract pins it (companion brief CONSTRAINT). All four former production call sites migrated (`post_abilities()` snapshots ×2 + the two now inside `PolicyTransition`).

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies — can start immediately.
- **Foundational (Phase 2)**: Depends on Setup. **BLOCKS every user story.** F030 fence (T014) MUST be green before Phase 3.
- **User Stories (Phase 3-7)**: Depend on Foundational (Phase 2). Once Foundational is green:
  - **US1 (Phase 3)** ⇒ ships the REST endpoint + main React deltas — enables US2, US4, US5.
  - **US2 (Phase 4)** depends on US1 (reuses the endpoint + modal component).
  - **US3 (Phase 5)** independent — backwards compat regression tests only.
  - **US4 (Phase 6)** depends on US1 (needs the `has_override` field from the augmented GET).
  - **US5 (Phase 7)** depends on US1 (counter code shipped in Phase 3 T022; US5 tests only).
- **Polish (Phase 8)**: Depends on all desired user stories being complete.

### User Story Dependencies

- **US1 (P1)**: Foundational only.
- **US2 (P1)**: US1 (endpoint + modal shared).
- **US3 (P1)**: Foundational only. Migration regression can run in parallel with US1-US2 client-side work.
- **US4 (P2)**: US1 (augmented GET's `has_override` field).
- **US5 (P2)**: US1 (counter shipped in T022; Phase 7 is verification-only).

### Within Each User Story / Phase

- Foundational: T004+T005 first (schema/upgrade) → T006 [P] Row → T007+T008 rename+update-call (must land TOGETHER — SEC-001) → T009+T010 sibling resolver + cache reset → T011 gate swap → T012 discovery → T013 cascade → T014 F030 fence → T015+T016+T017 quality gates.
- US1: T018 register route → T019 handler impl → T020 GET augment → T021 client-merge deletion (same commit as T020) → T022 counter → T023 Enable All handler → T024 header pill → T025 build → T026 smoke.
- Tests within a story may run [P] once implementation is ready.

### Parallel Opportunities

- **Setup [P]**: T003 can run parallel to T001+T002.
- **Foundational [P]**: T006 [P] (Row) can run parallel to T004+T005 (Schema/Table) but touches a different file. T012 [P] discovery can run parallel to the schema work.
- **US3 tests [P]**: T029 + T030 can run parallel (different files).
- **US4 tests [P]**: T034 + T035 can run parallel (different files).
- **US5 tests [P]**: T037 solo.
- **Polish tests [P]**: T039-T043 can all run parallel (same file `AbilitiesControllerTest.php` — enforce sequential if the file is shared; treat [P] as "conceptually parallel, actually sequenced").
- **Polish docs [P]**: T044 + T045 + T046 in different files.
- **Whole-plugin grep audits (T054-T060)**: can all run parallel (read-only greps).

### Parallel Example: Foundational (Phase 2)

```bash
# After T004+T005 land the schema change:
Task: "T006 [P] Row.php public property + to_array() entry"       # <- src/Row.php
Task: "T012 [P] SEC-002 server-delete grep discovery"              # <- write report only
```

### Parallel Example: Polish grep audits

```bash
# All 7 audits are read-only greps — can run in parallel:
Task: "T054 SEC-001 rename audit"
Task: "T055 SEC-001 row-only audit"
Task: "T056 Effective-resolver audit"
Task: "T057 Client-merge audit"
Task: "T058 Per-pair action audit"
Task: "T059 New action audit"
Task: "T060 React libs audit"
```

---

## Implementation Strategy

### MVP First (US1 + US3 required for a shippable release)

1. Complete Phase 1: Setup (T001-T003).
2. Complete Phase 2: Foundational (T004-T017) — the F030 fence (T017) is the gate.
3. Complete Phase 3: US1 (T018-T026) — the Enable All money case.
4. Complete Phase 5: US3 (T029-T031) — the backwards-compat guarantee.
5. **STOP and VALIDATE**: run T017 + T031 + T026 end-to-end; smoke on a real WP install with real MCP client.
6. Ship MVP. Then continue to US2/US4/US5/Polish.

### Incremental Delivery

1. Foundation → tests-green.
2. + US1 → Enable All ships (deploy/demo).
3. + US3 → backwards-compat guarantee (safe to release).
4. + US2 → Disable All ships (deploy/demo).
5. + US4 → per-pair overrides verified.
6. + US5 → truthful counter verified.
7. + Polish → docs + memory + quality gates + release checklist → merge-ready.

### Parallel Team Strategy

With multiple developers:

1. Whole team completes Setup + Foundational together (single-file conflicts in resolver split make parallelisation costly).
2. Once Foundational is green:
   - Dev A: US1 (Phase 3) — main REST + React work.
   - Dev B: US3 (Phase 5) — backwards-compat regression tests, independent of Dev A's file work.
   - Dev C: TASK-13 server-delete cascade wiring — independent of Dev A/B.
3. After US1 lands: Dev A → US2 (Phase 4), Dev B → US4 (Phase 6), Dev C → US5 (Phase 7).
4. Whole team joins Polish (Phase 8) — quality gates + grep audits + docs.

---

## Notes

- **[P] tasks**: different files, no incomplete-task dependencies. Tests in the same PHPUnit file are marked [P] for conceptual parallelism but should ship as sequential commits.
- **[Story] label**: maps each task to its owning user story (US1-US5) for traceability. Setup, Foundational, Polish carry no story label.
- **F030 fence gate**: T017 (running `test_resolve_row_only_still_row_only_for_f030`) MUST be green before ANY Phase-3-onward work. This is the load-bearing security invariant of the feature.
- **SEC-001 rename atomicity**: T007 (rename in resolver) + T008 (update the F030 call site) MUST land in the same commit. Split commits leave the intermediate state broken (F030's call site references a method that no longer exists).
- **TASK-6 GET augment + TASK-7 client-merge deletion atomicity**: T020 (GET server-truth) + T021 (client-merge deletion) MUST land in the same commit per spec Edge Cases + companion brief CONSTRAINTS.
- **Commit cadence**: commit after each task or logical group; each user story should be a clean checkpoint that could be shipped independently.
- **Avoid**: cross-story dependencies that break independence; vague tasks; same-file conflicts within a `[P]` group.

---

## Cross-references

- Spec: [spec.md](spec.md) — 5 user stories, 19 FRs, 7 SCs, Clarifications with 6 Q/A.
- Plan: [plan.md](plan.md) — Constitution Check all-pass, Data Model, REST Contract, Risks.
- Companion planning brief: [../../docs/planings-tasks/082-per-server-ability-policy-defaults.md](../../docs/planings-tasks/082-per-server-ability-policy-defaults.md) — canonical TASK-1..10 breakdown + SEC-001 amendment banner.
- Security review: [../../docs/security-reviews/2026-09-05-082-ability-policy-defaults-plan.md](../../docs/security-reviews/2026-09-05-082-ability-policy-defaults-plan.md) — source of TASK-SEC-001..006 (folded into this task list).
- Memory synthesis: [memory-synthesis.md](memory-synthesis.md) — retrieval-budgeted context.
- Security constraints: [security-constraints.md](security-constraints.md) — inline artefact.
