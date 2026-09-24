# Implementation Plan: Tables That Repair Themselves

**Branch**: `091-schema-drift-reconciliation` | **Date**: 2026-09-24 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `/specs/091-schema-drift-reconciliation/spec.md`

## Summary

Five plugin tables can silently diverge from their declared schema and stay that way permanently.
Two independent defects cause it: BerlinDB stamps the declared version having run zero migration
callbacks whenever no version was previously recorded, and nothing anywhere diffs a declared schema
against a live table, so a column added without a paired callback reaches fresh installs only.

The fix is a reconciler that compares each table's declared columns against its live columns and
adds what is missing, writing no DDL of its own — the vendored library already exposes the declared
set, a live-table introspection that returns the same object type, and a public per-column DDL
fragment. It is additive only. A companion pass then sets values for columns it just created, because
a restored column carries a default that is right for one server type and wrong for another.
Prevention ships alongside: a frozen column manifest and a parity assertion make an un-healable
schema change fail the build instead of a customer's site.

## Technical Context

**Language/Version**: PHP 8.1+
**Primary Dependencies**: `berlindb/core` ^3.0 (vendored, already present); WordPress 7.0+
**Storage**: Five existing custom tables — `acrossai_mcp_servers`, `…_server_tools`,
`…_server_abilities`, `…_servers_meta`, `…_cli_auth_logs`. Two new non-autoloaded options
(a reconciliation fingerprint and a short-lived lock). No new table.
**Testing**: PHPUnit ^9.6 — `vendor/bin/phpunit --bootstrap tests/bootstrap-wp.php --testsuite database`
for the WP-integration suites, plus WP-free `TestCase` suites for schema-object introspection.
**Target Platform**: WordPress single-site, MySQL 5.7 / 8.x and MariaDB
**Project Type**: WordPress plugin (single project)
**Performance Goals**: Steady state must cost one non-autoloaded option read per administrative
page load. A full reconciliation — five `SHOW COLUMNS` reads — runs once per release, or whenever
the declared column set changes.
**Constraints**: No operator action. No UI. Silent on success, per the established self-healing
convention. Must not modify or remove anything, only add. Must not overwrite an operator's stored
choices. Must not block unrelated migrations behind a failure it cannot resolve.
**Scale/Scope**: Five tables, ~70 declared columns in total. Two new classes, two edited call sites,
two edited migration files, one edited gate script, six test files.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Assessment |
|---|---|
| **I. Modular Architecture** | PASS — new classes live under `includes/Database/`, depend only on BerlinDB and on already-public helpers in their own namespace. No sibling-module coupling introduced. |
| **II. WordPress Standards** | PASS — PHPCS zero errors/warnings and PHPStan level 8 are Definition-of-Done gates. Note the committed `phpstan.neon.dist` is level 5 while CI enforces 8; verify at 8 explicitly (see research.md R6). |
| **III. Security First** | PASS WITH DOCUMENTED EXCEPTION — the feature accepts no user input, renders no output, and adds no route or form. It does issue `ALTER TABLE`, which cannot be expressed through `$wpdb->prepare()` because identifiers are not preparable. See Complexity Tracking. |
| **IV. User-Centric Design** | N/A — no admin UI. DataViews/DataForm rules do not apply. |
| **V. Extensibility Without Core Modification** | PASS — reports through two new `do_action` extension points rather than hard-coding a surface. |
| **VI. Reusability & DRY** | PASS — reuses `ToolPolicy::split_payload()`, `ToolPolicy::COLUMN_MAP`, `ServerTypes::declared_tools()` and `ServerTypes::seeded_servers()` rather than re-deriving them. One knowingly deferred duplication is recorded in Complexity Tracking. |
| **VII. Definition of Done** | PASS — enumerated in spec.md; every gate is runnable locally. |
| **Module Contract (singleton rule)** | EXEMPT under A11 — both new classes are stateless, static-only, take no constructor arguments and are not wired into hooks themselves. This matches the three existing repair classes. The exemption must be cited in each class docblock, per the A11 rule. |
| **Boot Flow Rule** | PASS — no new hook registration. Both entry points are existing: the `admin_init` handler in `Main.php` and the activation routine. |
| **Database rule (custom tables justified)** | PASS — no new table is introduced; this feature only reconciles existing ones. |

## Project Structure

### Documentation (this feature)

```text
specs/091-schema-drift-reconciliation/
├── plan.md              # This file
├── research.md          # Phase 0 output — the eleven resolved decisions
├── data-model.md        # Phase 1 output — the five conceptual entities
├── quickstart.md        # Phase 1 output — reproduce the bug, verify the fix
├── contracts/
│   └── extension-points.md   # The two do_action contracts this feature publishes
├── checklists/
│   └── requirements.md  # Spec quality checklist (complete)
└── tasks.md             # Phase 2 output — created by /speckit-tasks, not here
```

### Source Code (repository root)

```text
includes/
├── Database/
│   ├── SchemaReconciler.php              # NEW — declared-vs-live diff, add-only
│   ├── LegacyOAuthCleanup.php            # existing sibling; the shape to mirror
│   ├── MCPServer/
│   │   ├── CreatedColumnBackfill.php     # NEW — values for just-created columns
│   │   ├── Table.php                     # EDIT — honest returns on 1.1.4 / 1.1.7
│   │   ├── Schema.php                    # unchanged (read as the declaration)
│   │   ├── ToolPolicy.php                # reused: COLUMN_MAP, split_payload()
│   │   ├── ServerTypes.php               # reused: declared_tools(), seeded_servers()
│   │   └── ServerGuideBackfill.php       # its done-flag is cleared by the backfill
│   ├── CliAuthLog/Table.php              # EDIT — honest return on 1.0.1
│   ├── MCPServerTool/Table.php           # unchanged (no $upgrades — now covered)
│   ├── MCPServerAbility/Table.php        # unchanged (no $upgrades — now covered)
│   └── MCPServerMeta/Table.php           # unchanged
├── Main.php                              # EDIT — sixth call in reconcile_database_schemas()
└── Activator.php                         # EDIT — reorder: reconcile before seed()

bin/verify-f021-gates.sh                  # EDIT — three new greppable gates

tests/phpunit/Database/
├── SchemaReconcilerTest.php              # NEW — WP integration, all five tables
├── SchemaParityTest.php                  # NEW — declared == live after activation
├── SchemaManifestTest.php                # NEW — WP-free, frozen column manifest
├── RowDefaultParityTest.php              # NEW — WP-free, Row defaults == Schema
├── UpgradeReturnHonestyTest.php          # NEW — failed DDL leaves version unstamped
└── MCPServer/CreatedColumnBackfillTest.php  # NEW — value pass, incl. ordering regression

docs/memory/DECISIONS.md                  # EDIT — amend D28
docs/memory/BUGS.md                       # EDIT — amend B34 with the phantom-stamp variant
readme.txt / README.md                    # EDIT — upgrade note incl. reconnect instruction
```

**Structure Decision**: Single WordPress plugin, existing layout. The reconciler is placed at
`includes/Database/` root rather than inside a module directory because it operates across all five
modules — the same reason `LegacyOAuthCleanup.php` sits there. The value backfill is
`MCPServer`-specific and lives in that module's directory, because the semantics it encodes (server
types, protocol flags) belong to that module alone and must not leak into the generic reconciler.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| `ALTER TABLE` issued without `$wpdb->prepare()` (Constitution III) | Identifiers and column definitions are not preparable by `$wpdb`. Both parts are plugin-owned: the table name is composed by BerlinDB from `$wpdb->prefix` plus a hardcoded stem, and the column definition comes from the Column object's own `get_create_string()`. No request data reaches the statement. | Preparing is not possible for DDL. The established precedent is D28, which already sanctions exactly this form with line-scoped `phpcs:ignore` and a stated justification — the seven existing migration callbacks all use it. |
| Two new classes rather than one | The generic reconciler must know nothing about servers, types or tools, or it becomes a second place where that vocabulary lives. The value pass must know nothing about schema introspection. | A single class would couple a table-agnostic mechanism to one module's semantics and would make the reconciler untestable against the other four tables. |
| Five duplicated `maybe_upgrade()` overrides left in place (Constitution VI) | Extracting them into a shared trait touches all five Table classes for no behavioural gain in this feature, and widens a release whose value is the repair itself. | **Deliberately deferred, not overlooked.** Recorded here so review does not read it as an oversight; it is a clean follow-up once this lands. |
