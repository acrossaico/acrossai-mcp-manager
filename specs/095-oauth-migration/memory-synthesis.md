# Memory Synthesis

## Current Scope

F095 migrates the OAuth 2.1 server and AI Connectors stack (~15,700 LOC) from `acrossai-pro` into this plugin, making one-click connection free. Affected modules: `includes/OAuth/` (new), four new BerlinDB modules + `Database/Support/`, `includes/Connectors/`, `includes/Discovery/`, `admin/Partials/ServerTabs/`, `includes/Main.php` wiring, `uninstall.php`, `webpack.config.js`, and the Quick Connect wizard. Adds a one-shot data migration — the first in this plugin where late execution breaks production rather than delaying a repair.

Phase: **Plan**. Retrieval prioritised boundary definitions, module ownership, and drift risk.

## Relevant Decisions

- **D28 / DEC-BERLINDB-SCHEMA-DRIFT-RECONCILIATION** — every Schema change on a live table ships as 3 coordinated edits (bump `$version`, register `$upgrades` callback, ensure `maybe_upgrade()` fires on `admin_init` via `Main::reconcile_database_schemas()` pri 3). A bare `$version` bump with empty `$upgrades` silently stamps and touches nothing; dbDelta is NOT invoked. (Reason: governs all four incoming tables; independently confirms the Q1 clarification. Status: Active. Source: DECISIONS.md)
- **DEC-BERLINDB-TABLE-REQUEST-BOOT** — Table subclasses MUST be instantiated at request time via `Main::load_hooks()`; activation-time `Table::instance()` alone leaves BerlinDB's DB interface empty on later requests, and Query silently falls back to `$table_alias` as FROM. (Reason: the four new tables must join `bootstrap_database_tables()`, not just the Activator. Status: Active F011. Source: DECISIONS.md)
- **D31 / DEC-F032-OAUTH-SERVER-ID-FIRST-CLASS** — `server_id BIGINT UNSIGNED NOT NULL` is first-class on the three OAuth tables; `UNIQUE(client_id, server_id)` composite; every mutating REST endpoint validates `server_id`; registration order in `reconcile_database_schemas` is **Tokens → AuthCodes → Clients**. (Reason: defines the exact shape and ordering of what we are importing. Status: Active F032. Source: DECISIONS.md)
- **D41 / DEC-SERVER-TAB-REGISTRY-DEDUP-LAST-WINS** — Registry dedup is last-wins specifically to enable the "built-in placeholder → companion override" pattern; first consumer was `AIConnectorsPromoTab` → companion's `AIConnectorsTab` at priority 35. (Reason: F095 reverses this exact mechanism; the slot must stay slug `ai-connectors` / priority 10. Status: Active F040. Source: DECISIONS.md)
- **DEC-UNINSTALL-OPT-IN-GATE** — `uninstall.php` short-circuits unless `acrossai_mcp_uninstall_delete_data === 1`; every destructive statement lives after the gate. (Reason: F095 edits the drop list twice. Status: Active F012. Source: DECISIONS.md)

## Active Architecture Constraints

- **A1** — all hook registration lives exclusively in `Main.php` via `define_admin_hooks()` / `define_public_hooks()`. (Spec FR-020 already encodes this.)
- **A20** — cross-plugin option-namespace exclusion on uninstall: when the LIKE-sweep would catch a companion's options, it MUST explicitly exclude them. (Directly governs FR-025's `acrossai_mcp_connector_%` retention.)
- **A6** — classes in `Includes` MUST use `use` imports or leading-`\` FQN; bare relative names silently fail. (Critical during the `AcrossAI_Pro\` → `AcrossAI_MCP_Manager\Includes\` rewrite.)
- **A2** — every feature class uses the singleton `instance()` pattern.
- **A22** — `public/Renderers/` MUST NOT depend on admin-only CSS (`.nav-tab`, `.wp-list-table`, `.notice`) or `backend.scss`. (Connectors touch `public/Discovery/`.)

## Accepted Deviations

- **DEV5** — per-server tab `render_body()` MAY use hand-rolled admin form HTML instead of DataForm when ≤3 configurable fields. (Status: Accepted-Deviation)
- **Constitution §IV v1.1.0 connector-picker card exception** — the AI Connectors tab and its Level 2/3 panels MAY use hand-rolled cards, `.nav-tab-wrapper`, and a `widefat striped` table. The incoming tab sits inside this carve-out, so no DataViews rewrite. (Status: Accepted-Deviation)
- **DEV1** — MCP Manager parent menu uses `WP_List_Table`. (Status: Accepted-Deviation)

## Relevant Security Constraints

- **S3** — OAuth tokens MUST be stored hashed, SHA-256 minimum, never plaintext. (FR-007's byte-for-byte digest transfer depends on this invariant holding unchanged.) Source: CONSTITUTION.md §III
- **S9** — consent-surface displayed state MUST come from a server-side authoritative store, not URL params (confused-deputy defence). Source: CONSTITUTION.md §III
- **S2 / S7 / S8** — explicit `permission_callback` on all routes; `__return_true` only on the documented protocol endpoints. Source: CONSTITUTION.md §III

## Related Historical Lessons

- **B44** — adding a `Database/<Module>/Table.php` subclass does NOT automatically add the table to `uninstall.php`'s `$tables` DROP array; nothing compiles or fails. Four new tables land in F095.
- **B20** — plaintext OAuth secret in `varchar(255)` is a §III violation; secrets/tokens MUST be `char(64)` SHA-256. Grep gate on `Schema.php` files.
- **B42** — `add_rewrite_rule()` before the `init` action fatals on `add_rule() on null`. F095 imports five rewrite rules.
- **F040 worklog (2026-07-31)** — the migration this reverses was explicitly **"zero data migration"**. F095 inverts that premise; none of F040's safety reasoning about data transfers.

## Conflict Warnings

1. **~~HARD~~ — RESOLVED during this synthesis. B44 vs FR-004.** FR-004 originally removed the four table names from *both* the one-shot sweeper and `uninstall.php`'s drop list, with nothing restoring the latter once tables were recreated under those same names — while verification step 7 still expected them dropped. As written, F095 would have leaked four tables on uninstall, which is exactly the B44 failure mode. Resolved by narrowing rather than re-adding: only the sweeper is a live hazard (it drops empty tables during normal `admin_init`); `uninstall.php` runs only at uninstall, so its entries were never dangerous and become *correct* once the plugin owns the tables. FR-004 now deletes only the sweeper; **new FR-005** requires the uninstall entries to remain, with their comment rewritten so a future reader does not mistake them for stale orphans and delete them. FR set renumbered to FR-001…FR-028.
2. **SOFT — S7 and A13 are marked "Post-F016: NO active consumers — retired".** F095 revives both (token-endpoint `__return_true`; RFC-prescribed consent form exempt from A4). Both entries need un-retiring at capture time.
3. **SOFT — D51 and D52 lose their premise.** D51 mandates duplicating connector CSS across the plugin boundary rather than sharing a partial; D52 mandates a defensive union DTO read in `Step10_ConnectorsDetail.jsx`. Once OAuth lives here the boundary is gone: D50's intra-plugin rule (10+ rules → shared SCSS partial) applies instead, and the union read becomes dead weight. Decide explicitly at plan time or it rots silently.

## Retrieval Notes

Config read: `optimizer.enabled: false` → markdown-only, index-first. 20 index entries considered against a 263-line INDEX.md; source sections read selectively by grep, never whole files (`full_memory_read_allowed: false` honoured — DECISIONS.md 2,986 lines, BUGS.md 3,021 lines, ARCHITECTURE.md 767 lines all left unread). Budget: 5/5 decisions, 5/5 architecture, 3/3 deviations, 3/3 security, 4 lessons (3 bug patterns + 1 worklog). No `security-constraints.md` exists in `memory_root`; S-entries sourced from CONSTITUTION.md §III via INDEX. Feature-local `memory.md` absent. Near-miss entries not included for budget: B10 (atomic CAS on one-shot credentials — relevant to auth codes), B34 (silent write-loss on schema drift), B53/B63 (DDL in unit tests escapes rollback — relevant to migration tests), D27/D32/D33/D34 (OAuth behaviours imported unchanged), D37 (React-first admin UI).
