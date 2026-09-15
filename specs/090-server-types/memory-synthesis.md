# Memory Synthesis

## Current Scope

Feature 090 — server types. Two columns (`server_type`, `tools_default_policy`) in one
`1.1.6` migration; a `ServerTypes` registry; enablement gated on an unmet type requirement
across three write paths; a one-entry diagnostic tool list when unmet; a type field on both
create forms; Tools-tab Reset rewired; a Tools bulk bar.

Affected: `includes/Database/MCPServer/*`, `includes/MCP/Controller`,
`includes/REST/{Tools,QuickConnect}Controller`, `includes/Abilities/`, `admin/Partials/*`,
`src/js/tools.js`, `src/js/quick-connect/steps/`.

## Relevant Decisions

- **D28 / DEC-BERLINDB-SCHEMA-DRIFT-RECONCILIATION** — every Schema change on a live table
  ships as 3 coordinated edits: bump `$version`, register the paired `$upgrades` callback,
  ensure `maybe_upgrade()` fires on `admin_init`. A bare bump silently stamps the version and
  touches nothing. (Governs the migration. Active (F029). DECISIONS.md)
- **D41 / DEC-SERVER-TAB-REGISTRY-DEDUP-LAST-WINS** — slug-keyed last-wins dedup; later
  filter contributions REPLACE earlier ones including built-ins. (The mechanism by which the
  sibling overrides our `acrossai` placeholder. Active (F040). DECISIONS.md)
- **D55 / DEC-SIBLING-REGISTRY-NAME-SEMANTICS** — when adding a parallel registry, mirror the
  sibling's SHAPE but treat every accessor NAME as carrying the sibling's semantics; prefer
  removing an unsafe accessor over documenting it. (Reason: `ServerTypes` mirrors
  `ToolAbilities`; `all()`/`get()` must not quietly mean something different. Status: Active
  (F084). Source: INDEX.md only — see Conflict Warnings)
- **DEC-F025-HYBRID-TOOL-STORAGE-PROTOCOL-VS-CURATED** — tool state is boolean columns plus
  presence rows, unioned by a single canonical composer. (`tools_default_policy` must sit ABOVE
  that composer, never replace or fork it. Active (F025). DECISIONS.md)
- **D24 / DEC-F026-ADVERTISEMENT-VS-CALL-TIME-DEFENSE-IN-DEPTH** — advertisement-time and
  call-time enforcement are independent layers; never drop either. (The diagnostic swap is
  advertisement-time only and must not be read as replacing a call-time gate. Active
  (F026 v3). DECISIONS.md)

## Active Architecture Constraints

- **A21 / A-MCP-ADAPTER-NO-ENABLED-CONCEPT-PLUGIN-ADDS-LAYER** — the adapter has NO
  `is_enabled` concept; the column, its default `0`, and the request-time gate are a safety
  layer owned by THIS plugin. Boundary rule: new wizards/flows touching server creation MUST
  preserve disabled-by-default, and any `add_item()` with `'is_enabled' => 1` needs explicit
  justification. (Reason: 090's enablement gate is a second condition on that same
  plugin-owned layer. Source: ARCHITECTURE.md)
- **A1** — hook registration lives only in `includes/Main.php`; the diagnostic ability and
  any gate filters must be Loader-wired, not constructor-wired.
- **A11** — pure stateless services are exempt from the singleton rule; `ServerTypes` is
  static-only, matching `ToolAbilities`.
- **A6** — `Includes` classes MUST use `use` imports or leading-`\` FQN; bare relative names
  silently fail. `Table` references `DefaultServerSeeder::ACROSSAI_SLUG`.
- **A9** — constants read by ≥2 modules belong in `includes/Utilities/`; the type slugs are
  read by the admin, REST and MCP layers.

## Accepted Deviations

- None recorded for this scope. The WP_List_Table and AI Connectors carve-outs are adjacent
  but NOT invoked: 090 adds no new admin screen, so neither widens.

## Relevant Security Constraints

- **B32** — filter defaults that gate security or per-context decisions MUST be the canonical
  resolver's output, never a partial re-derivation. (Reason: `is_available()` must be the one
  resolver for "requirement met", used identically by selection, enablement and runtime.)
- **B7** — Query writers MUST filter POSTed keys against `Schema::columns()` before
  persisting. (Reason: two new writable columns reachable from REST.)
- **D24 corollary** — exposure ≠ authorization. (Reason: the diagnostic entry must not
  become a path that skips any ability's own permission check.)

## Related Historical Lessons

- **B34** — silent write-loss when a live schema drifts while `db_version` still matches:
  INSERT of a missing column returns `false` and callers treat `int(0)` as success.
- **B53** — DDL implicitly COMMITs, so schema tests escape `WP_UnitTestCase` rollback; a
  mid-flight failure leaves the column dropped and the version option poisoned.
- **B18** — `$wpdb` returns TINYINT as string, so `1 === $row->col` is always false —
  relevant to every `is_enabled` comparison the gate adds.
- **Worklog 2026-07-02** — BerlinDB Table subclasses override `maybe_upgrade()` with a
  phantom-version guard; `PhantomVersionGuardTest` reflection-reads `$version`, so the 1.1.6
  bump must not break it.
- **Worklog 2026-07-04** — run `/speckit-analyze` AFTER implement, not only before. 090
  already has a 4-question Clarifications session, which is exactly the drift trigger.

## Conflict Warnings

- **B9 is STALE (soft conflict).** It says "PHPUnit 13+ silently ignores `@dataProvider` — use
  `#[DataProvider]`". `composer.json` pins `phpunit/phpunit: ^9.6`, where attributes are inert
  and annotations are correct — the opposite instruction. No live breakage: all 5 files
  carrying `#[DataProvider]` also carry `@dataProvider`. **Follow the pin: `@dataProvider`.**
  Correct B9 at capture time.
- **D55 has no source entry (integrity gap).** INDEX.md row only, no section in
  `DECISIONS.md`, so it cannot be de-referenced for detail. The row is self-contained enough
  to apply; flagged so it is not silently lost.
- **D18 / DEC-F020 gate-priority map (soft).** Those establish `mcp_adapter_pre_tool_call` as
  the canonical MCP-boundary hook. 090 deliberately does NOT use it for the unmet-requirement
  case, because the vendor returns `tool_not_found` before that filter fires. This is not a
  violation — 090 enforces no authorization there — but a reviewer applying D18 mechanically
  will flag it, so the plan must state the vendor ordering explicitly.

**No hard conflicts.** Nothing violates a constitution rule, architecture boundary, or
active decision. Safe to proceed to `/speckit-plan`.

## Retrieval Notes

- 20 index entries considered (cap); selected 5 decisions, 5 architecture, 3 security,
  3 bugs, 2 worklog — all within budget.
- Read in full: `ARCHITECTURE.md` A21 only. All others via `INDEX.md` rows. `DECISIONS.md`
  (2,926 lines) and `BUGS.md` (2,610 lines) NOT read whole, per
  `full_memory_read_allowed: false`.
- B9 conflict verified against ground truth, not assumed: read the `composer.json` pin and
  counted annotation vs attribute usage across `tests/`.
- `optimizer.enabled: false` → markdown-only retrieval. No feature `memory.md` existed.
