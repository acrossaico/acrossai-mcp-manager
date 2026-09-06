# Memory Synthesis

## Current Scope

F082 (per-server ability policy defaults) adds a tri-state `abilities_default_policy` column to `{prefix}acrossai_mcp_servers` and splits `ExposureResolver` into `resolve()` (frozen; row-only) plus a new sibling `resolve_effective()` (three-tier: row → server policy → `meta.mcp.public`). Affected modules: `includes/Database/MCPServer/{Schema,Row,Table}.php`, `includes/Database/MCPServerAbility/ExposureResolver.php`, `includes/REST/AbilitiesController.php`, `includes/MCP/AbilityExposureGate.php`, `includes/Abilities/PermissionOverrideProcessor.php` (comment-only), `src/js/abilities.js`, plus a new PHPUnit suite. Merge-gated on preserving F030's row-only permission-callback bypass verbatim.

## Relevant Decisions

- **D30 / DEC-F030-EXPLICIT-EXPOSURE-ONLY** — F030 calls the row-only method with empty `$meta` as a row-existence probe. (Reason Included: F082's spec locks this via FR-007 + SC-005 + a merge-blocker regression fence + the SEC-001 rename `resolve()` → `resolve_row_only()`. Widening the row-only method to honour server policy would silently widen the F030 permission-callback bypass to every ability on any `policy='expose'` server. Post-F082 the row-only method's name broadcasts the invariant. Status: Active. Source: DECISIONS.md; F082 plan review 2026-09-05 SEC-001.)
- **DEC-ABILITY-OVERRIDE-RESOLUTION (F017)** — Every consumer of effective ability exposure MUST route through `ExposureResolver`. (Reason Included: F082 legitimately EXTENDS this — renames `resolve()` → `resolve_row_only()` (the row-only method F030 depends on) and adds sibling `resolve_effective()` for the three-tier path. Two named entry points on the same resolver; the invariant is preserved. See Conflict Warnings below. Status: Active. Source: DECISIONS.md.)
- **DEC-F020-TOOL-ENFORCEMENT-PRIORITY** — `mcp_adapter_pre_tool_call` slot map: **F015 = 10, F017 = 20, F020 = 30**. (Reason Included: F082 changes exactly the F017 gate at priority 20 — F015 and F020 stay byte-for-byte untouched (they don't call the resolver). Deny-precedence via `is_wp_error( $result )` short-circuit preserved. Status: Active. Source: DECISIONS.md.)
- **D24 / DEC-F026-ADVERTISEMENT-VS-CALL-TIME-DEFENSE-IN-DEPTH** — Advertisement-time and call-time enforcement are independent layers; "never route ability-exposure decisions through a path that skips ExposureResolver." (Reason Included: F082's `AbilityExposureGate` swap and the augmented `GET /abilities` both go through `resolve_effective()`, keeping the invariant intact for both layers. Status: Active. Source: DECISIONS.md.)
- **D28 / DEC-BERLINDB-SCHEMA-DRIFT-RECONCILIATION** — Every Schema change ships as a 3-part contract: bump `$version`, register `$upgrades = [ '<v>' => 'upgrade_to_<v>' ]`, ensure `maybe_upgrade()` fires on `admin_init@3` via `Main::reconcile_database_schemas()`. (Reason Included: F082's new column MUST land via this contract; a bare `$version` bump with empty `$upgrades` silently stamps the version and touches nothing — see B34. Status: Active. Source: DECISIONS.md.)

## Active Architecture Constraints

- **A1** — Hook registration lives exclusively in `includes/Main.php`. (Reason Included: F082's new REST route is registered inside the existing `AbilitiesController::register_routes()` which is already wired on `rest_api_init` in `Main.php` — no new constructor-side registration. Source: ARCHITECTURE.md.)
- **A6** — `AcrossAI_MCP_Manager\Includes` code MUST use `use` imports or leading-`\` FQN. (Reason Included: F082's `resolve_effective()` cross-namespace queries touch `MCPServerAbility\Query` and `MCPServer\Query` — silent failure if bare relative names slip through. Source: ARCHITECTURE.md.)
- **A11** — Pure service classes (stateless value producers) exempted from the singleton rule. (Reason Included: `ExposureResolver` is exactly this pattern; F082's new `resolve_effective()` + private `server_policy()` remain static-only, matching A11. Source: ARCHITECTURE.md.)
- **A17** — Request-scoped WP REST context capture pattern. (Reason Included: `AbilityExposureGate::gate_tool_call_by_exposure()` runs during REST dispatch; F082's one-line swap does not disturb the existing context capture. Source: ARCHITECTURE.md.)
- **A21 / A-MCP-ADAPTER-NO-ENABLED-CONCEPT** — MCP Adapter has no `is_enabled` server concept; the plugin adds its own layer. (Reason Included: F082's policy column sits on the same plugin-owned layer as the F011 `is_enabled` column, so the policy layer inherits the same enforcement locus. Source: ARCHITECTURE.md.)

## Accepted Deviations

- **DEV5** — Per-server-edit tab sub-forms MAY use hand-rolled admin form HTML instead of DataForm. (Reason Included: F082's Abilities tab is React + DataViews (D37 canonical path), not a hand-rolled form — DEV5 does NOT apply. Clarified confirm-modal uses `@wordpress/components` `<Modal>` (spec Clarifications), reinforcing D37. Status: Accepted-Deviation for other surfaces, non-applicable here.)

## Relevant Security Constraints

- **S2** — All REST routes MUST have explicit `permission_callback`; `__return_true` only on public read routes. (Reason Included: F082's new `POST /abilities/policy` uses `permission_check()` gating on `current_user_can( 'manage_options' )` — no `__return_true`. Source: security-constraints.md.)
- **S4** — All DB queries MUST use `$wpdb->prepare()`. (Reason Included: F082's overrides-clearing DELETE inside `post_policy()` uses either BerlinDB `delete_where` or `$wpdb->prepare( "DELETE FROM %i WHERE server_id = %d", ... )` fallback. Source: security-constraints.md.)
- **S6** — Singleton `__construct()` MUST be private. (Reason Included: `ExposureResolver` is A11 pure-service (no singleton) so S6 doesn't apply directly, but every controller/query touched keeps its existing private ctor. Source: security-constraints.md.)

## Related Historical Lessons

- **B32** — Filter defaults that gate security decisions MUST re-verify (Reason Included: F082's `resolve_row_only()` freeze + rename is the exact defense against B32 — any future consumer widening the row-only method must do so inside a method whose name says "row_only"; the merge-blocker regression fence at `test_resolve_row_only_still_row_only_for_f030()` codifies this at runtime.)
- **B34** — Silent write-loss when BerlinDB schema drifts and `db_version` still matches (Reason Included: F082 MUST ship the D28 3-part contract completely — bare `$version` bump is a silent no-op that leaves the new column uncreated on live installs.)

## Conflict Warnings

- **Soft conflict — DEC-ABILITY-OVERRIDE-RESOLUTION (F017) vs. F082 resolver split**: F017 decreed every consumer routes through `ExposureResolver::resolve()`. F082 adds a sibling `resolve_effective()` and moves `AbilityExposureGate` to it while F030 stays on `resolve()`. Legitimate extension — F017's single-tier resolver did not anticipate the F030 hazard nor a server-level policy. Resolution: F082's TASK-10 appends a forward-pointer annotation to F017's decision; the spec's Clarifications, FR-007, and CONSTRAINTS block document the split. Ratified during `/speckit-clarify` — no user clarification needed.

## Retrieval Notes

- ~76 index entries scanned; selected within budget (5 decisions, 5 arch, 3 bugs, 3 deviations, 3 security, 2 worklog).
- Read: `docs/memory/INDEX.md` lines 1–232. No full body reads of `DECISIONS.md`, `ARCHITECTURE.md`, or `BUGS.md` (budget respected; `full_memory_read_allowed: false`).
- Optimizer: disabled — markdown-only, index-first flow.
- Feature-scoped `memory.md`: absent; not created (first synthesis pass).
