# Phase 0 — Research: Server Types

The spec carries **zero** `[NEEDS CLARIFICATION]` markers, so this file records decisions
already resolved, with the evidence behind them. Several were verified against vendor source
or a live install during pre-planning rather than reasoned from documentation.

---

## R1 — Where the `acrossai` type's tools come from

**Decision**: This plugin ships the `acrossai` type with an EMPTY `tools` array and a
`requires` key. The sibling `acrossai-abilities-manager` registers the same slug through
`acrossai_mcp_server_types` and replaces it (last-wins, D41). This plugin never names a
`toolset/*` slug.

**Rationale**: `ToolAbilities`' own docblock states the rule — *"Companion plugins declare
their own via the filter; nothing here hardcodes another plugin's vocabulary."* The two
plugins already communicate purely through filters with zero namespace imports in either
direction, a boundary `ToolsetExposureBridge` documents explicitly: *"this plugin owns
per-server exposure, the sibling owns the Toolsets."*

**Alternatives considered**:
- *Hardcode the 13 `toolset/*` slugs here* — rejected: names another plugin's vocabulary and
  goes stale silently when the sibling adds a toolset.
- *Derive the list from `ToolAbilities::get_slugs()` minus protocol tools* — rejected after
  the requirement became hard: it would make `acrossai` silently degrade to an empty set
  rather than declare an unmet dependency.
- *Move the toolset machinery into this plugin or a shared package* — investigated in depth
  (5-agent sweep) and rejected. A toolset is a ~2,000-line index over a 69,237-line ability
  library in 382 files; 413 files declare the `tab_group` keys the dispatchers hardcode.
  Extraction would also CREATE two defects that do not exist today: duplicate ability-category
  registration, and `toolset/content` appearing twice in `tools/list`.

---

## R2 — Why the runtime layer cannot use `mcp_adapter_pre_tool_call`

**Decision**: Swap the composed tool list at registration time
(`MCP\Controller::register_database_servers()`), NOT at call time.

**Rationale**: verified by reading vendor source. In
`vendor/wordpress/mcp-adapter/includes/Handlers/Tools/ToolsHandler.php::call_tool()` the
order is:

```
get_mcp_tool( $name )  →  not found  →  McpErrorFactory::tool_not_found(), RETURNS
                                          ↓ never reached
check_permission()  →  apply_filters( 'mcp_adapter_pre_tool_call', … )  →  execute()
```

The filter fires at line 183, *after* the lookup at ~line 136. When the sibling is
deactivated its `toolset/*` abilities no longer exist, so a stale call is rejected two steps
before any filter of ours can see it.

**Consequence (accepted)**: a client that listed the toolsets before deactivation and calls
one immediately still receives a generic `tool_not_found`. Most agents re-list after an error
and then see the diagnostic. Recorded in the spec as a known residual gap.

**Alternatives considered**:
- *Register stand-in abilities under the `toolset/*` names while the sibling is inactive* —
  rejected: this plugin would be squatting another plugin's namespace to improve one error
  message.
- *Unregister the server when the requirement is unmet* — rejected and made an explicit
  prohibition: it 404s the route and kills a live client session instead of explaining.

**Governance note**: D18 / DEC-F020 establish `mcp_adapter_pre_tool_call` as *the* canonical
MCP-boundary hook. This is not a violation — 090 enforces no authorization there — but a
reviewer applying D18 mechanically will flag it, hence this entry.

---

## R3 — Migration shape: one version, two columns, one conditional backfill

**Decision**: `Table::$version` `1.1.5 → 1.1.6` with a paired `upgrade_to_1_1_6()` that ADDs
both columns and then issues ONE slug-matched `UPDATE`, gated on having just created
`server_type`.

**Rationale**: `server_type`'s column default `'mcp-adapter'` backfills every pre-existing
row inside the `ALTER` — the correct legacy value for all of them, so no backfill pass is
needed. Exactly one row is then wrong: the F088 AcrossAI server. It cannot be fixed by the
seeder, because 090 moves `server_type` out of that row's `managed` bucket into `initial`
(so the operator can switch type as the escape hatch), and `initial` only writes at INSERT —
the row already exists on any site with F088.

**The gate is the load-bearing part.** An unconditional `UPDATE` re-runs on any later
invocation and silently reverts an operator who deliberately switched that server to
`mcp-adapter` — destroying the very affordance the ownership change exists to provide.

**Verified on a live install during pre-planning**: version `1.1.5 → 1.1.6`, default server
backfilled to `mcp-adapter`, AcrossAI row corrected to `acrossai`, both policies `per-tool`.
Then the regression was exercised directly: the row was switched to `mcp-adapter`, the
version option wiped to force a re-run, and the operator's choice survived.

**Alternatives considered**:
- *Column default `'acrossai'`* — rejected: the default is what backfills existing rows, so
  it would stamp every pre-090 server as an AcrossAI server.
- *ADD then unconditional backfill of all rows* — rejected per the gate rationale above.
- *Two migrations (`1.1.6` + `1.1.7`)* — rejected; see Complexity Tracking in plan.md.

---

## R4 — `column_exists()` must be the inherited one

**Decision**: use BerlinDB's inherited **public** `column_exists( $name )` in the upgrade
callback. Do NOT declare a local helper of that name.

**Rationale**: `BerlinDB\Database\Kern\Table::column_exists()` is public
(`vendor/berlindb/core/src/Database/Kern/Table.php:818`). Declaring a `private` override is a
fatal `Access level ... must be public`.

**Evidence — this actually happened during pre-planning.** The fatal white-screened the local
install, and **both PHPCS and PHPStan passed clean on the broken code**. It surfaced only on a
real wp-admin page load. This is why the spec carries a non-standard Definition-of-Done gate:
*a real wp-admin page load completes with no fatal error*.

**Side benefit**: migrations `1.1.1`–`1.1.5` each hand-rolled the same `INFORMATION_SCHEMA`
query. Using the inherited method stops that copy-paste lineage at five.

---

## R5 — `tools_default_policy` mirrors `abilities_default_policy`

**Decision**: `varchar(16) NOT NULL DEFAULT 'per-tool'`, values `all | none | per-tool`,
resolved ABOVE the existing `ToolPolicy::compose_effective_tools_for_row()` composer.

**Rationale**: the Abilities tab's bulk bar is not three buttons that stamp rows — it is a
STANDING rule (`abilities_default_policy`, values `expose | hide | per-ability`) resolved per
request by `ExposureResolver`, with per-ability rows overriding it. That is why "Enable All"
also covers abilities registered tomorrow. Tools today is a pure snapshot, so a snapshot
"Add All" would fail the exact case this feature exists for: reactivate the sibling, new
toolsets appear, and nothing picks them up.

Mirroring the model — not just the buttons — keeps the two sibling screens semantically
consistent (**D55**: mirror the SHAPE, and do not let an accessor name mean something
different one level down).

**Alternatives considered**:
- *Snapshot Add All / Remove All with no column* — rejected per the standing-rule rationale.
- *Reuse `abilities_default_policy` for both* — rejected: different domains, and the value
  vocabularies differ (`expose|hide` vs `all|none`).

---

## R6 — Enablement gate placement

**Decision**: `ServerTypes::enablement_error(): ?WP_Error`, consulted at all three write
paths that flip `is_enabled` — `admin/Partials/Settings.php` single toggle (`:238`), the same
file's bulk branch (`:288`), and `includes/REST/QuickConnectController.php` (`:729`). Gate the
off→on transition ONLY.

**Rationale**: a UI-only guard is dodgeable, and Quick Connect never touches the list table.
`includes/MCP/Controller.php:357` matches an `is_enabled` grep but is a READ
(`has_any_enabled_server()`) and must not be gated.

**A21 alignment**: the adapter has NO `is_enabled` concept — that column, its default `0`, and
the request-time gate are a safety layer this plugin owns. 090 adds a second condition to the
same layer rather than inventing a parallel one, and preserves disabled-by-default.

**Bulk semantics** (clarification Q2): partial success — enable the eligible, skip the rest,
name each skipped server and why. Wholesale failure punishes the operator for one bad row;
silent skipping breaks FR-015's rule that every refusal names what is missing.

---

## R7 — Testing constraints

**Decision**: PHPUnit `^9.6` with `@dataProvider` annotations.

**Rationale**: `composer.json` pins `phpunit/phpunit: ^9.6`; PHP attributes arrived in PHPUnit
10 and are inert under 9.6. **This contradicts `BUGS.md` B9**, which says "PHPUnit 13+ ignores
`@dataProvider` — use `#[DataProvider]`". B9 is stale.

**Verified, not assumed**: 5 test files carry `#[DataProvider]`, but all 5 also carry
`@dataProvider`, so nothing is silently skipped today. No live breakage; stale guidance only.
B9 should be corrected at capture time.

**Also relevant**: **B53** — DDL implicitly COMMITs, so schema tests escape
`WP_UnitTestCase`'s per-test rollback; a mid-flight failure leaves the column dropped and the
version option poisoned for the rest of the run. Migration tests must restore explicitly
rather than relying on rollback. **B34** — a live schema that drifts from `Schema.php` while
`db_version` still matches causes silent write-loss, which is what the paired-callback
contract prevents.
