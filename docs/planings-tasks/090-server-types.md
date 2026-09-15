# Planning: Server types (Feature 090)

Give every MCP server row a **type** — a starting point plus a label — and make
`Reset` on the Tools tab restore that type's tool set instead of the three
hardcoded `mcp-adapter/*` protocol slugs it restores today for every server
regardless of purpose. That single hardcoded line (`applyReset()` in
`src/js/tools.js`) is the defect; everything else in this feature is the
scaffolding that lets Reset ask "what type am I?".

Two types ship. `mcp-adapter` is the legacy baseline — the three protocol tools —
and is the value every pre-090 server row is backfilled to. `acrossai` is the
AcrossAI-branded type whose tools are the `toolset/*` dispatchers published by the
sibling plugin `acrossai-abilities-manager`, and it **hard-requires** that plugin
to be installed and active.

This plugin never learns the sibling's vocabulary. `acrossai-mcp-manager` owns
servers; `acrossai-abilities-manager` owns abilities, and a toolset is an ability.
The entire contract between the two plugins is two WordPress filters
(`acrossai_mcp_manager_tool_abilities` inbound, `acrossai_toolset_member_visible`
outbound) with zero namespace imports in either direction — verified across the
whole plugin family. The `ServerTypes` registry preserves that: this plugin ships
an `acrossai` entry with an empty `tools` list and a `requires` key, and the
sibling overrides the same slug with its own dispatchers via the slug-keyed
LAST-WINS dedup already used by the tab and Connect-method registries (D41).

The requirement is enforced in three layers, deliberately. **Selection**: the
`acrossai` option is offered only when the sibling is active. **Enablement**: a
server whose type has an unmet `requires` cannot be switched on — gated at all
three write paths that flip `is_enabled`, because a UI-only guard is dodgeable and
Quick Connect never touches the list table. **Runtime**: a server that was enabled
*before* the sibling was deactivated keeps serving and keeps its client session
alive, advertising a single diagnostic tool that explains what is missing.

That last layer exists because the vendor gives us no other seam. In
`ToolsHandler::call_tool()` the adapter resolves the tool first and returns
`tool_not_found` before `mcp_adapter_pre_tool_call` ever fires, so a stale call to
a vanished `toolset/*` cannot be intercepted or rewritten. What we do control is
the tool list composed at `MCP\Controller::register_database_servers()`, so an
unmet requirement swaps that list for one honest entry rather than leaving it
empty.

The migration is a single `1.1.6` bump carrying **two** columns — `server_type`
and `tools_default_policy` — because they land in the same table for the same
feature and two migrations a week apart is two chances for a half-upgraded
install. `server_type`'s column default `'mcp-adapter'` is load-bearing: it
backfills every pre-existing row inside the `ALTER` itself, so there is no
backfill pass. Exactly one row is wrong afterwards — the F088 AcrossAI server —
corrected by a slug-matched `UPDATE` **gated on having just created the column**,
so a later re-run cannot revert an operator who deliberately switched that server
to `mcp-adapter`.

---

## Speckit Workflow

```markdown
# 1. Branch
/speckit.git.feature "server-types"

# 2. Specify
/speckit.specify "Add a server_type column to wp_acrossai_mcp_servers so each MCP
server row records which kind of server it is, and make the Tools tab's Reset
button restore that type's tool set instead of the hardcoded three mcp-adapter
protocol slugs it restores today for every server. Ship two types: 'mcp-adapter'
(tools = ToolPolicy::PROTOCOL_TOOLS) and 'acrossai' (tools supplied by the sibling
plugin acrossai-abilities-manager, empty in this plugin). Add a ServerTypes
registry in includes/Database/MCPServer/ in the light ToolAbilities shape — a
constant seed, one filter 'acrossai_mcp_server_types', and its own small
normalizer. Do NOT use Utilities\\RegistryEntryNormalizer: it drops any
non-built-in entry lacking a callable render_callback and a server type is pure
data. Entry shape: label, description, tools (string[]), requires (string|null),
is_default (bool). Dedup slug-keyed LAST-WINS so a companion plugin overrides this
plugin's placeholder entry, matching D41. API: all(), get(), default_slug(),
tools_for(), is_available(). default_slug() must resolve to the last is_default
entry WHOSE requires IS SATISFIED, with 'mcp-adapter' as the always-registered
floor, so a site without the sibling never defaults to a type it cannot use. An
unknown stored type slug must never fatal: get() returns null, callers fall back
to mcp-adapter's tools, and the UI shows the raw slug marked unavailable.
Add a second column tools_default_policy varchar(16) NOT NULL DEFAULT 'per-tool'
in the SAME 1.1.6 migration, mirroring the existing abilities_default_policy
column: values 'all' | 'none' | 'per-tool', where 'all' and 'none' are STANDING
rules that win over the per-tool composition exactly as abilities_default_policy
wins over per-ability override rows. 'all' must cover tool-level abilities
registered later, not just those present when the button was clicked. Use it to
give the Tools tab an Add All / Remove All / Reset to Type Defaults bar matching
the Abilities tab's Enable All / Disable All / Reset to Ability Defaults bar.
Migration: Table::\$version 1.1.5 -> 1.1.6 with a paired upgrade_to_1_1_6()
callback per the D28 three-part contract. ADD both columns, each guarded by
BerlinDB's INHERITED PUBLIC column_exists() — do NOT declare a private
column_exists() helper, that is a fatal 'Access level must be public' clash with
BerlinDB\\Database\\Kern\\Table. server_type's column default 'mcp-adapter'
backfills every pre-existing row inside the ALTER, so write NO backfill pass; then
issue exactly one slug-matched UPDATE setting server_type='acrossai' WHERE
server_slug = DefaultServerSeeder::ACROSSAI_SLUG, GATED on having just created the
column so a re-run cannot revert a deliberate operator change.
Seeder: add server_type to the Default MCP Server definition's 'managed' bucket as
'mcp-adapter'. For the AcrossAI definition put server_type in the 'initial' bucket,
NOT 'managed', so the operator can switch that server's type as an escape hatch;
its name, route and description stay managed. Add tools_default_policy to neither
bucket.
Requirement enforcement, three layers. (1) Selection: offer the acrossai type only
when its requires is satisfied. (2) Enablement: a server whose type has an unmet
requires cannot be ENABLED — gate the off->on transition at all three write paths
that flip is_enabled (admin/Partials/Settings.php single toggle, the same file's
bulk action, and includes/REST/QuickConnectController.php) via
ServerTypes::enablement_error(): ?WP_Error. Disabling must ALWAYS remain allowed so
a server stranded by a deactivated sibling can still be switched off. Do NOT
auto-disable a running server when the sibling is deactivated. (3) Runtime: in
MCP\\Controller::register_database_servers(), when the row's type has an unmet
requires, replace the composed tool list with a single plugin-owned diagnostic
ability acrossai/setup-required whose description AND return value both name the
required plugin. The server MUST still register — never skip create_server() for
an unmet requirement, that would 404 the route and kill a live client session. Do
NOT attempt this through mcp_adapter_pre_tool_call: ToolsHandler::call_tool()
returns tool_not_found before that filter fires, so a stale toolset/* call cannot
be intercepted; accept that as a known residual gap rather than registering
stand-in abilities in the sibling's namespace.
UI: add a Server Type field to BOTH create forms — the classic Add New MCP Server
form in admin/Partials/Settings.php and Quick Connect step 2 in
src/js/quick-connect/steps/Step2_ServerCreate.jsx — preselecting
ServerTypes::default_slug() and offering only available types; the add_item() call
must then write server_type explicitly or the row silently inherits the column
default instead of the registry default. Add a type selector to the top of the
Tools tab with a ConfirmDialog on switch (destructive: replaces the tool set),
reusing the existing pendingReset pattern in src/js/tools.js. Rewire applyReset()
to the resolved type's tools. Surface BOTH remedies whenever a requirement is
unmet — install the add-on, or switch this server's type — on the Overview and
Tools tabs. In Quick Connect, make step 4 (Step4_AbilitiesManager.jsx) non-skippable
when the server in play has an unmet requires, so the operator cannot reach a
blocked step 6 and dead-end five steps in.
Extract, do not copy, the sibling-missing notice: the 'notice notice-info inline'
block is already duplicated at AbilitiesTab.php and ToolsTab.php with a hardcoded
is_plugin_active() path in each, while AbilitiesManagerPromoCard already owns
SIBLING_SLUG and a three-state resolve_state(). Add
AbilitiesManagerPromoCard::render_inline_notice(), refit both existing call sites,
then use it for the new one. Constitution VI: extract before the second use.
Do NOT move any toolset code out of acrossai-abilities-manager, and do NOT
reference toolset/* slugs anywhere in this plugin. Do NOT change what a server
serves at registration time except for the diagnostic swap above. Do not touch
ToolsetExposureBridge. Ship docs/extending-server-types.md in the shape of
docs/extending-server-tools.md."
```

### Detailed Description for `/speckit.specify`

> **Before writing a single line of code, read and internalize these governing
> documents in full:**
>
> 1. `AGENTS.md` — singleton pattern (A11 pure-service exemption), hook
>    registration rules, the `includes/` context-neutrality rule (A3).
> 2. `.specify/memory/constitution.md` — especially §VI (extract before the
>    second use, never copy) which TASK-7 turns on.
> 3. `docs/memory/DECISIONS.md` — D28 (BerlinDB three-part migration contract),
>    D41 (slug-keyed LAST-WINS dedup, the placeholder→companion override).
> 4. `docs/memory/BUGS.md` — and note PHPUnit is pinned `^9.6`, so use
>    `@dataProvider` annotations, never `#[DataProvider]` attributes.
> 5. `docs/extending-server-tools.md` — the filter-contract house style the new
>    `docs/extending-server-types.md` must match.

**Non-negotiables, each learned the hard way:**

- **BerlinDB already provides `column_exists()` and it is `public`.** Declaring a
  `private` one in the Table subclass is a fatal `Access level ... must be public`
  that neither PHPCS nor PHPStan catches — it only surfaces as a white-screen on a
  real page load. Use the inherited method.
- **The `server_type` column default must be `'mcp-adapter'`, not `'acrossai'`.**
  The default is what backfills existing rows during the `ALTER`; `'acrossai'`
  would stamp every pre-090 server as an AcrossAI server.
- **The corrective `UPDATE` must be conditional.** Gate it on having just created
  the column. Unconditional, it re-runs later and silently reverts an operator who
  used the switch-type escape hatch — destroying the very affordance the
  `managed`→`initial` ownership change exists to provide.
- **`mcp_adapter_pre_tool_call` is not a usable seam for missing tools.** The
  vendor returns `tool_not_found` two steps earlier. Do not design around it.
- **Never skip `create_server()` for an unmet requirement.** The route must keep
  answering; an in-flight AI client session must be told what is wrong, not
  disconnected.

**Explicitly out of scope** — do not let the workflow widen into these:

- Moving toolsets, the toolset registry, or `AcrossAI_Ability_Group` out of
  `acrossai-abilities-manager`, or into a shared Composer package. Investigated in
  depth and rejected: a toolset is a ~2,000-line index over a 69,237-line ability
  library that stays put, and extraction would create two new bugs (duplicate
  ability-category registration, and duplicated `toolset/*` slugs in `tools/list`).
- Feature 091 — retiring `mcp-adapter-default-server` for new installs, and
  preselecting AcrossAI in every picker. 090 leaves seeding otherwise unchanged.
  (The "should it ship enabled?" half of 091 is already answered by the enablement
  gate: it cannot.)
- The `acrossai-co/main-menu` version skew (`acrossai-log-manager` pins `0.0.23`
  while this plugin and the abilities manager pin `0.0.33`; highest wins at
  runtime under jetpack-autoloader). Real, but a separate change in another repo.

```markdown
# 3. Plan + guard + security
/speckit.memory-md.plan-with-memory
/speckit.architecture-guard.governed-plan
/speckit.security-review.plan

# 4. Tasks + guard
/speckit.tasks

# 5. Implement + quality checks
/speckit.architecture-guard.governed-implement
composer dump-autoload
composer run phpcs
composer run phpstan

# 6. Review + memory + commit
/speckit.analyze
/speckit.architecture-guard.architecture-review
/speckit.security-review.staged
/speckit.memory-md.capture-from-diff
/speckit.git.commit
```

---

## Manual Verification Checklist

### TASK-1 — Schema + Row
- [ ] `Schema.php` declares `server_type` varchar(32) NOT NULL DEFAULT
      `'mcp-adapter'` and `tools_default_policy` varchar(16) NOT NULL DEFAULT
      `'per-tool'`.
- [ ] `Row.php` declares both public properties with matching defaults and both
      appear in `to_array()`.
- [ ] Outside the T119 F021 gate's scope (OAuth schemas only) — `bin/verify-f021-gates.sh`
      still passes.

### TASK-2 — Table migration `1.1.6`
- [ ] `Table::$version` is `'1.1.6'` AND `$upgrades` maps `'1.1.6' =>
      'upgrade_to_1_1_6'` in the same commit (D28).
- [ ] Callback uses the INHERITED `column_exists( $name )`, not a local helper.
- [ ] On a pre-090 install: version goes `1.1.5` → `1.1.6`, both columns appear,
      every existing row reads `server_type = 'mcp-adapter'` and
      `tools_default_policy = 'per-tool'`.
- [ ] The F088 AcrossAI row reads `server_type = 'acrossai'` afterwards.
- [ ] **Regression:** manually set that row to `'mcp-adapter'`, delete the
      `acrossai_mcp_servers_db_version` option, reload wp-admin. The row must
      STILL read `'mcp-adapter'` — the corrective UPDATE must not re-run.
- [ ] Re-running `maybe_upgrade()` on an already-upgraded install is a no-op.
- [ ] No PHP fatal on any wp-admin page load (check `wp-content/debug.log`, not
      just PHPCS/PHPStan).

### TASK-3 — `ServerTypes` registry
- [ ] Seeded with `mcp-adapter` (PROTOCOL_TOOLS) and `acrossai` (empty tools +
      `requires`).
- [ ] A filter callback adding a type appears in `all()`.
- [ ] A filter callback re-registering `acrossai` REPLACES the placeholder
      (LAST-WINS, D41).
- [ ] `default_slug()` skips a type whose `requires` is unmet and falls to
      `mcp-adapter`.
- [ ] An unknown stored slug degrades: no fatal, tools fall back, UI marks it
      unavailable.

### TASK-4 — Seeder
- [ ] Default MCP Server row asserts `server_type = 'mcp-adapter'` from `managed`.
- [ ] AcrossAI row's `server_type` is in `initial`, NOT `managed` — switching it
      in the UI survives the next `admin_init` reconcile.
- [ ] AcrossAI row's name, route and description are still force-corrected.

### TASK-5 — Write paths
- [ ] Both create forms render a Server Type field, preselecting
      `ServerTypes::default_slug()`, offering only available types.
- [ ] A server created from EACH form gets the registry default, not the column
      default.
- [ ] `ToolsController` GET exposes `server_type`; POST accepts and validates it,
      rejecting unknown slugs.

### TASK-6 — Tools tab: selector, switch, Reset fix
- [ ] Selector present; switching opens a ConfirmDialog.
- [ ] **`applyReset()` no longer references `PROTOCOL_TOOL_SLUGS`** — it uses the
      resolved type's tools. (This is the defect the feature exists to fix.)
- [ ] "N tools available for this type · Apply" appears when the preset contains
      slugs the server lacks.

### TASK-7 — Extract the sibling notice
- [ ] `AbilitiesManagerPromoCard::render_inline_notice()` exists.
- [ ] `AbilitiesTab.php` and `ToolsTab.php` both call it; neither retains its own
      `is_plugin_active( 'acrossai-abilities-manager/...' )` literal.
- [ ] `grep -rn "is_plugin_active( 'acrossai-abilities-manager" admin/ includes/`
      returns only the promo card.

### TASK-8 — Sibling registers the `acrossai` type
- [ ] With the sibling active, `acrossai` resolves to its toolsets.
- [ ] The sibling builds its slug list the way `Base_Toolset_Ability` already
      does; no registry walk (the list is built before `wp_abilities_api_init`).

### TASK-9 — PHPUnit coverage
- [ ] Migration, registry, seeder, and Reset-per-type all covered.
- [ ] `PhantomVersionGuardTest` still passes (it reflection-reads `$version`, so
      the bump must not break it).
- [ ] `@dataProvider` annotations only — PHPUnit is pinned `^9.6`.

### TASK-10 — Runtime diagnostic
- [ ] Sibling deactivated, server previously enabled: `/wp-json/acrossai/mcp`
      still answers; `tools/list` contains exactly `acrossai/setup-required`; its
      description names the required plugin; calling it returns the same message.
- [ ] Sibling reactivated: diagnostic gone, toolsets present.
- [ ] Curated tool rows survive a deactivate/reactivate cycle untouched.

### TASK-11 — Enablement gate
- [ ] Enable blocked on ALL THREE paths (single toggle, bulk, Quick Connect).
- [ ] Disable still works on all three.
- [ ] A server enabled before deactivation is never auto-disabled.
- [ ] `includes/MCP/Controller.php` `has_any_enabled_server()` is NOT gated — it
      is a read.

### TASK-11b — Quick Connect flow
- [ ] Creating an `acrossai`-type server and skipping step 4 does not dead-end at
      step 6; step 4 explains and offers both remedies.

### TASK-12 — Tools bulk actions
- [ ] Add All / Remove All / Reset to Type Defaults present.
- [ ] Policy `'all'` includes a tool-level ability registered AFTER the click.
- [ ] Pill states the policy and override count, like the Abilities tab.
- [ ] UI states that with policy `'all'`, switching type has no visible effect
      until the policy returns to `'per-tool'`.

### TASK-13 — Docs
- [ ] `docs/extending-server-types.md` matches the house style of
      `docs/extending-server-tools.md`: filter contract, entry shape, the
      placeholder→companion override pattern, a worked example.

### Quality gates (all must be green before commit)
- [ ] PHPStan — zero errors.
- [ ] PHPCS — zero errors.
- [ ] `composer test` — PHPUnit all suites pass.
- [ ] `bin/verify-f021-gates.sh` — passes.
- [ ] **A real wp-admin page load produces no fatal** — PHPCS and PHPStan both
      passed on code that white-screened the site during pre-planning.
- [ ] `SELECT id, server_slug, server_type, tools_default_policy FROM
      wp_acrossai_mcp_servers` returns sane values for every row.

---

## Pre-flight note

During pre-planning (2026-09-15) TASK-1 and TASK-2 were implemented, verified
against the live install, and then **reverted** so this feature could run through
the spec-kit workflow from a clean tree. The database was rolled back to `1.1.5`
with both columns dropped. Nothing from that spike remains in the working tree;
its findings are folded into the non-negotiables above.
