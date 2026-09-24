# Tasks: Tables That Repair Themselves

**Input**: Design documents from `/specs/091-schema-drift-reconciliation/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/extension-points.md, quickstart.md

**Tests**: Test tasks ARE included. The specification requires them explicitly — User Story 3 is
itself a build-gate story, and the Definition of Done requires PHPUnit coverage for all new logic.

**Organization**: Grouped by user story so each can be implemented and verified independently.

## Format: `[ID] [P?] [Story] Description`

- **[P]** — parallelizable: different file, no dependency on an incomplete task
- **[US#]** — the user story this task serves (user-story phases only)

## Path Conventions

Single WordPress plugin, existing layout. Paths below are relative to the plugin root
(`acrossai-mcp-manager/`). PHP namespaces mirror directory paths under `AcrossAI_MCP_Manager`.

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Ensure the integration test harness can run, since every verification in this feature
issues real DDL.

- [X] T001 Verify the WordPress test scaffolding is installed and the database suite runs green before any change, via `bash bin/install-wp-tests.sh wordpress_test root root 127.0.0.1:3306 latest true` then `vendor/bin/phpunit --bootstrap tests/bootstrap-wp.php --testsuite database`
- [X] T002 Confirm static analysis runs at the level CI enforces, not the committed level, via `vendor/bin/phpstan analyse --level=8 --memory-limit=4G` — record any pre-existing failures so they are not attributed to this feature

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: The reconciliation mechanism itself. Every user story consumes it.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [X] T003 Create `includes/Database/SchemaReconciler.php` — `final` class, namespace `AcrossAI_MCP_Manager\Includes\Database`, `declare( strict_types = 1 )`, `defined( 'ABSPATH' ) || exit;`, static-only with the A11 pure-service exemption cited in the class docblock pointing at `docs/memory/ARCHITECTURE.md`
- [X] T004 Implement `SchemaReconciler::is_addable( Column $column ): bool` in `includes/Database/SchemaReconciler.php` — non-empty create string, no `auto_increment`, and never `not null` without `default`; case-insensitive matching; public so it is unit-testable without WordPress (research R5)
- [X] T005 Implement the declared-schema read in `includes/Database/SchemaReconciler.php` using the table's schema object, with a `/** @var Schema */` annotation for static analysis and a fallback that constructs the schema class by name if the object is not of the expected type (research R11)
- [X] T006 Implement the live-schema read in `includes/Database/SchemaReconciler.php` via `Schema::from_table()`, guarded on BOTH `$table->exists()` and a non-empty returned column list — an empty list means "table absent", never "every column is missing" (research R11, data-model)
- [X] T007 Implement `SchemaReconciler::reconcile( Table $table ): array` in `includes/Database/SchemaReconciler.php` — returns the names of columns actually added; emits `ALTER TABLE ... ADD COLUMN {$column->get_create_string()}` with no hand-written DDL (research R3)
- [X] T008 Implement the ALTER execution helper in `includes/Database/SchemaReconciler.php` — suppress `$wpdb` errors around the statement and restore afterwards; compare the result strictly against `false`; on failure re-check `column_exists()` so a lost race counts as success; single line-scoped `phpcs:ignore` carrying the justification that both identifier and definition are plugin-owned (research R11, plan Complexity Tracking)
- [X] T009 Implement `SchemaReconciler::fingerprint( array $tables ): string` in `includes/Database/SchemaReconciler.php` — plugin version plus, per table, the table name, the declared version read by reflection, and the sorted declared column definitions; memoised per request and keyed on the current site id (research R6, R11)
- [X] T010 Implement `SchemaReconciler::maybe_reconcile( array $tables ): array` in `includes/Database/SchemaReconciler.php` — short-circuit on a matching stored fingerprint; take a short transient lock released in a `finally`; write the fingerprint LAST and only when no ALTER failed (research R6, R11)
- [X] T011 Implement the drift partitioning in `includes/Database/SchemaReconciler.php` — collect unaddable-and-missing and type/width-divergent columns separately, comparing lengths only when both sides report one so modern MySQL does not report false drift (research R11, data-model)

**Checkpoint**: The reconciler can heal any table in isolation. User story work can begin.

---

## Phase 3: User Story 1 — A drifted site heals itself (Priority: P1) 🎯 MVP

**Goal**: A site with missing columns repairs itself on the next administrative page load, with no
operator action.

**Independent Test**: Drop declared columns, stamp the version option at the current version, load
any wp-admin page, and confirm the columns return with their declared definitions.

### Tests for User Story 1

- [X] T012 [P] [US1] Create `tests/phpunit/Database/SchemaReconcilerTest.php` with a `@dataProvider` over all five table classes; `public set_up()`/`tear_down()`; remove WordPress's temporary-table query filters so DDL is real; unconditionally self-heal in `tear_down()` including deleting the fingerprint option, the reconciler lock and the library upgrade lock
- [X] T013 [P] [US1] Add `test_restores_a_dropped_addable_column` to `tests/phpunit/Database/SchemaReconcilerTest.php` — select the target column by reflection over the declared schema rather than hardcoding a name; assert the restored type *starts with* the declared base type so modern MySQL's omitted display width does not fail the assertion
- [X] T014 [P] [US1] Add `test_second_run_creates_nothing` and `test_returns_empty_when_table_absent` to `tests/phpunit/Database/SchemaReconcilerTest.php` — the second asserts the table is still absent afterwards, proving no ALTER was attempted
- [X] T015 [P] [US1] Add `test_unsafe_columns_are_never_added` to `tests/phpunit/Database/SchemaReconcilerTest.php` — assert `is_addable()` is false for every primary key, for the authentication-log hash column, and for every creation-timestamp column
- [X] T016 [P] [US1] Add `test_no_library_getter_shadows_the_magic_properties` to `tests/phpunit/Database/SchemaReconcilerTest.php` — assert no `get_schema_object()` or `get_table_name()` exists on the library table class, so a future library upgrade fails loudly instead of silently returning the wrong value (research R11)

### Implementation for User Story 1

- [X] T017 [US1] Wire the reconciler into `includes/Main.php` `reconcile_database_schemas()` — a sixth call placed AFTER the five existing `maybe_upgrade()` calls so routine migrations run first and the reconciler only handles what they could not; update the method docblock, whose "7 cheap option reads" figure is already stale
- [X] T018 [US1] Reorder `includes/Activator.php` `activate()` so the reconciler runs AFTER all five `maybe_upgrade()` calls but BEFORE `DefaultServerSeeder::seed()` — on a drifted install the seeder writes columns that do not exist and silently drops them (research R7); rewrite the "ORDER IS LOAD-BEARING" comment to state this new reason

**Checkpoint**: A drifted site heals itself. This is the shippable MVP.

---

## Phase 4: User Story 2 — The repair respects what the operator chose (Priority: P1)

**Goal**: Values are corrected only where the operator could not have expressed a preference, and the
managed server stops offering tools nobody selected.

**Independent Test**: With columns already present and flags deliberately set, run the repair and
confirm the stored values are untouched.

### Tests for User Story 2

- [X] T019 [P] [US2] Create `tests/phpunit/Database/MCPServer/CreatedColumnBackfillTest.php` seeding one managed-type server and one default-type server, with the same DDL-safe harness conventions as T012
- [X] T020 [P] [US2] Add `test_flags_follow_declared_type_when_columns_were_just_created` — drop the three flag columns, reconcile, apply; assert the managed server lands on all-disabled and the default server on all-enabled
- [X] T021 [P] [US2] Add `test_pre_existing_columns_are_never_touched` — with columns present, set the managed server's flags deliberately, reconcile (creating nothing), apply; assert the values are unchanged. This is the doctrine assertion for FR-010
- [X] T022 [P] [US2] Add `test_server_type_is_corrected_before_flags_are_read` — drop the type column AND the three flag columns, reconcile, apply; assert the seeded managed server regained its correct type AND landed on all-disabled. This is the regression test for the highest-severity ordering trap (research R7)
- [X] T023 [P] [US2] Add `test_unknown_or_empty_type_leaves_flags_alone` — register a type declaring no tools via the server-types filter and assert no write occurs, guarding the legacy fallback (research R8)
- [X] T024 [P] [US2] Add `test_server_guide_repair_flag_is_cleared_when_type_column_is_created` (research R12)

### Implementation for User Story 2

- [X] T025 [US2] Create `includes/Database/MCPServer/CreatedColumnBackfill.php` — `final`, static-only, A11 exemption cited; docblock carries the seeded-tools doctrine verbatim: an operator cannot have expressed a preference about a column that did not exist
- [X] T026 [US2] Implement the seeded-server retype step in `includes/Database/MCPServer/CreatedColumnBackfill.php`, generalising the 1.1.6 slug-matched update over `ServerTypes::seeded_servers()` so a future seeded type needs no new hardcoded statement; MUST run before any type is read
- [X] T027 [US2] Clear the server-guide repair's completion flag in `includes/Database/MCPServer/CreatedColumnBackfill.php` whenever the type column was created, so a repair that already recorded false success can run (research R12)
- [X] T028 [US2] Implement the flag backfill in `includes/Database/MCPServer/CreatedColumnBackfill.php` — intersect created columns against `ToolPolicy::COLUMN_MAP`; per row skip when the type is unknown or declares no tools; derive values via `ToolPolicy::split_payload( ServerTypes::declared_tools( … ) )` and write ONLY the intersected columns; do NOT call `ToolPolicy::apply_type_defaults()`, whose row-replacing half would erase operator curation (research R7)
- [X] T029 [US2] Invalidate the server cache after writing in `includes/Database/MCPServer/CreatedColumnBackfill.php`, matching the seeder's existing invalidation
- [X] T030 [US2] Call the backfill from `includes/Main.php` and `includes/Activator.php` immediately after the reconciler, passing only the server table's created-column list

**Checkpoint**: The 17-tools symptom is gone and operator selections are provably intact.

---

## Phase 5: User Story 3 — A missing column can no longer ship (Priority: P2)

**Goal**: A declared column either reaches every site automatically or the build fails with an
actionable message. No third outcome.

**Independent Test**: Remove or narrow a declared column and confirm the suite fails naming the
change and the migration required.

### Tests for User Story 3

- [X] T031 [P] [US3] Create `tests/phpunit/Database/SchemaParityTest.php` — `@dataProvider` over all five tables; assert declared and live column names match as SETS, not sequences, because added columns are appended and a repaired table's order will never match a fresh install's (research R11, spec edge cases)
- [X] T032 [P] [US3] Create `tests/phpunit/Database/SchemaManifestTest.php` as a WordPress-free `TestCase` mirroring the existing column-width invariant test — a frozen manifest of every declared column as name → type, length, nullability
- [X] T033 [P] [US3] Implement the manifest assertions in `tests/phpunit/Database/SchemaManifestTest.php` — every manifest entry still declared, type unchanged case-insensitively, length never reduced, nullability never tightened; columns ABSENT from the manifest are ignored, because additions are legal now; failure message must name the required migration
- [X] T034 [P] [US3] Create `tests/phpunit/Database/RowDefaultParityTest.php` (WordPress-free) asserting each Row class property default equals its declared column default across all five modules — these defaults are the mechanism that hid this drift instead of erroring

### Implementation for User Story 3

- [X] T035 [US3] Add a gate to `bin/verify-f021-gates.sh` asserting `includes/Database/SchemaReconciler.php` contains no destructive DDL keyword — the additive-only property is the entire safety argument for relaxing the migration contract, so it must be mechanically enforced
- [X] T036 [US3] Add a gate to `bin/verify-f021-gates.sh` asserting destructive DDL appears only inside versioned `includes/Database/*/Table.php` callbacks
- [X] T037 [US3] Add a gate to `bin/verify-f021-gates.sh` asserting every `$upgrades` entry has a matching method in the same file and that the highest key equals the declared version
- [X] T038 [US3] Register the three new gates in the gate script's reporting and in `.github/workflows/verify-f021-gates.yml` step naming — do NOT rename the job, which is a required branch-protection check

**Checkpoint**: The authoring hole that orphaned four columns is closed.

---

## Phase 6: User Story 4 — What cannot be repaired is visible (Priority: P3)

**Goal**: Drift the feature will not touch, and statements that fail, are observable without a
database session.

**Independent Test**: Introduce a width difference, run the repair, confirm it is reported and the
column is unchanged.

### Tests for User Story 4

- [X] T039 [P] [US4] Add `test_divergent_and_unaddable_columns_are_reported_not_altered` to `tests/phpunit/Database/SchemaReconcilerTest.php`, subscribing to the drift action and asserting the column is untouched
- [X] T040 [P] [US4] Create `tests/phpunit/Database/UpgradeReturnHonestyTest.php` — rewrite the specific destructive statement into invalid SQL via a `query` filter, rewind the version option, clear the library upgrade lock, run the migration, and assert the version did NOT advance and the failure action fired; remove the filter and self-heal in `tear_down()`

### Implementation for User Story 4

- [X] T041 [US4] Fire `acrossai_mcp_schema_drift_detected` from `includes/Database/SchemaReconciler.php` per the published contract — only when the pass actually ran and found something it will not repair
- [X] T042 [P] [US4] Make `upgrade_to_1_1_4()` and `upgrade_to_1_1_7()` in `includes/Database/MCPServer/Table.php` return `false` when their statement fails, firing `acrossai_mcp_schema_upgrade_failed` first — the unstamped version is the only retry a destructive migration has (research R9)
- [X] T043 [P] [US4] Make `upgrade_to_1_0_1()` in `includes/Database/CliAuthLog/Table.php` return `false` on failure, accumulating per-column results, firing the same action
- [X] T044 [US4] Correct the docblocks of the add-column callbacks in `includes/Database/MCPServer/Table.php` — they currently promise a false-on-failure contract the code never had; state instead that they intentionally keep returning success because the reconciler is their retry and stalling the chain would block every later migration (research R9)

**Checkpoint**: All four stories independently functional.

---

## Phase 7: Polish & Cross-Cutting Concerns

- [X] T045 [P] Amend D28 in `docs/memory/DECISIONS.md` under its own Reconsider clause — the contract becomes conditional on the kind of change; record that D28 never covered the phantom stamp
- [X] T046 [P] Amend B34 in `docs/memory/BUGS.md` with the phantom-stamp variant and the verified evidence; mark its prevention-recipe grep-gate item as superseded by the manifest test
- [X] T047 [P] Add the upgrade notice to `readme.txt` and `README.md` — repair happens on the first admin page load; a connected client must be RECONNECTED to see the corrected tool list; deleting the fingerprint option forces a re-check; a site whose operator never opens wp-admin is not repaired
- [X] T048 Run the full gate set: `composer phpcs`, `vendor/bin/phpstan analyse --level=8`, the database suite, `bash bin/verify-f021-gates.sh`, `npm run validate-packages`
- [~] T049 Walk `specs/091-schema-drift-reconciliation/quickstart.md` end to end on a local site, including the harder case that also drops the type column — **PARTIALLY DONE.** The quickstart's reproduction and both repair paths are verified automatically: `tests/phpunit/Database/ReconcileWiringTest.php` drops the same four columns, stamps the version at the declared current value, drives `Main::reconcile_database_schemas()` (the real `admin_init@3` entry point) and asserts the columns return with the managed server on `0,0,0`; `CreatedColumnBackfillTest::test_server_type_is_corrected_before_flags_are_read` covers the harder case that also drops `server_type`. NOT done: a human clicking through wp-admin and reconnecting a live MCP client. That remains for the reviewer, and is the one step no test can stand in for.
- [X] T050 (filed as #150) File a follow-up issue for the save path that reports success on a failed write — `ToolsController::post_tools()` ignores the update return and catches only throwables, which is why this drift stayed invisible. Out of scope here by decision, not oversight

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)** — no dependencies
- **Foundational (Phase 2)** — depends on Setup; **blocks every user story**
- **US1 (Phase 3)** — depends on Foundational
- **US2 (Phase 4)** — depends on Foundational AND US1, because it consumes the created-column report
- **US3 (Phase 5)** — depends on Foundational only; can run parallel to US1/US2
- **US4 (Phase 6)** — depends on Foundational; T042–T044 are independent of everything else
- **Polish (Phase 7)** — depends on all stories

### User Story Dependencies

US2 is the only story that depends on another. US3 and US4 are independent and can be built by
separate people while US1/US2 are in progress.

### Within Each User Story

Tests are listed before implementation within each phase, but the ordering that actually matters is
inside the code: **T026 must execute before T028 at runtime**, and **T017/T018 must place the
reconciler before the seeder**. Both are correctness requirements, not preferences.

### Parallel Opportunities

- T012–T016 (US1 tests) are all in one new file — write together, not in parallel across agents
- T019–T024 (US2 tests) likewise
- T031, T032, T034 are three separate new files and genuinely parallel
- T042 and T043 touch different files and are parallel
- T045, T046, T047 are three different documents and are parallel

---

## Implementation Strategy

**MVP is Phase 1 + Phase 2 + Phase 3 (US1).** That alone makes every drifted site converge on its
declared schema, which is the bulk of the value and is independently shippable.

**But do not ship the MVP alone to the affected site.** Without US2 the managed server's restored
flags land on the wrong default and the site still offers three tools nobody selected — the visible
symptom would survive a technically successful repair. US1 and US2 are both P1 for this reason and
should ship together.

US3 is what stops the next occurrence and was explicitly requested in the same release. US4 is
genuinely deferrable if the release needs to be cut early.

---

## Task Summary

| Phase | Story | Tasks | Count |
|---|---|---|---|
| 1 | — | T001–T002 | 2 |
| 2 | — | T003–T011 | 9 |
| 3 | US1 (P1) | T012–T018 | 7 |
| 4 | US2 (P1) | T019–T030 | 12 |
| 5 | US3 (P2) | T031–T038 | 8 |
| 6 | US4 (P3) | T039–T044 | 6 |
| 7 | — | T045–T050 | 6 |
| **Total** | | | **50** |
