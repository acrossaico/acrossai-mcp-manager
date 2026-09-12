# Planning: Ship a second plugin-managed "AcrossAI" MCP server (Feature 088)

Add an AcrossAI-branded MCP server at route `acrossai/mcp-server` that every
install gets automatically — fresh activations **and** in-place plugin updates —
seeded Inactive, badged **Recommended**, pinned first in the servers list and in
the Quick Connect Step 1 picker, and impossible to edit or delete.

Unlike Features 011–087 this doc was written **after** implementation rather than
as a `/speckit.specify` driver. It is the record of what shipped and why, so the
next person touching `DefaultServerSeeder` or `ProtectedServers` inherits the
reasoning instead of rediscovering it.

---

## Plain-English summary

Before this feature the plugin shipped exactly one server out of the box:
**Default MCP Server** (`mcp/mcp-adapter-default-server`). It was created
automatically and treated as special — the admin UI hid its *Update Server* and
*Danger Zone* tabs so nobody could rename or delete it.

Feature 088 ships a **second** server the same way, called **AcrossAI**, and
makes it the recommended one: first in the list, with a green "Recommended" pill,
and locked against edits and deletion. Operators still control whether it is
switched on — it arrives Inactive, so an in-place plugin update never brings a
new MCP endpoint live without a click.

---

## Two facts that shaped the design

### 1. The seed-on-activate-AND-on-update machinery already existed

`DefaultServerSeeder::seed()` was already called from two places:

| Call site | Fires |
| --- | --- |
| `Activator::activate()` (`includes/Activator.php:41`) | Once, on plugin activation |
| `Settings::maybe_seed_default_server()` on `admin_init` priority 4 | Every admin page load |

The second is what makes "on update" work: activation hooks do **not** fire on an
in-place composer / wp-cli / manual-file-replace update, but the next wp-admin
request does. F088 reuses this path rather than inventing a `version_compare()`
migrator. There is still no plugin-level version option, by design.

Ordering matters and is already correct: `Main::reconcile_database_schemas()`
runs on `admin_init` **priority 3**, so every `Table::maybe_upgrade()` DDL is
applied before the priority-4 seeder writes a row.

### 2. The new row MUST be `registered_from = 'database'`, not `'plugin'`

`MCP\Controller::get_enabled_database_servers()` queries
`registered_from = 'database'` and only those rows reach
`$adapter->create_server()`. The single `'plugin'` row is registered by the
vendor's `DefaultServerFactory` under its own hard-coded slug, with this plugin
only filtering its config via `mcp_adapter_default_server_config`.

**A second `'plugin'` row would therefore be a dead endpoint.** That is the
load-bearing constraint of this feature: because the AcrossAI row has to be
`'database'`-sourced to work at all, "cannot be edited or deleted" can no longer
ride on `registered_from`, and needs its own predicate.

---

## Design

### A. `DefaultServerSeeder` becomes a declarative reconciler

Rewritten from a single hard-coded `$wpdb->insert()` into a `definitions()` table
keyed by slug. The class name is unchanged (it is referenced by
`MCP\Controller::filter_default_server_config()`, `Step1_ServerPick.jsx`, and
five PHPUnit files); since F088 it owns *all* plugin-managed rows.

Each definition splits its columns into two buckets, and that split is the whole
point — it is what lets a future column be added without clobbering operator
choices:

| Bucket | Written on INSERT | Re-asserted on every run | Example |
| --- | --- | --- | --- |
| `managed` | yes | **yes** — this is what backfills a newly added column on existing installs | `server_name`, `registered_from`, `server_route_namespace`, `server_route` |
| `initial` | yes | never | `is_enabled` |

`seed()` per definition: one `SELECT`; INSERT when absent; otherwise diff only the
`managed` keys and UPDATE the drifted ones. A converged install issues **two
SELECTs and nothing else**, regardless of how many servers exist.

Two supporting details keep "add a column later" a one-line change:

- **Formats are derived, not hand-maintained.** The old code hand-listed
  `array( '%s','%s','%s','%d','%s','%s','%s','%s' )`, which breaks silently the
  moment a key is inserted mid-array. `formats()` now maps each value by type.
- **Unknown columns are dropped, not fatal.** Definition keys are intersected
  against `Schema::$columns` (read via reflection on the declared default, so
  BerlinDB's `Boot` trait never normalises them into `Column` objects). On the
  UPDATE path the fetched `SELECT *` row provides a second, physical guard.

#### Checklist for the upcoming column-adding version

All in one commit; no extra plumbing needed after F088:

1. Add the column to `MCPServer/Schema.php::$columns`.
2. Add the typed public prop + `to_array()` key in `MCPServer/Row.php`.
3. Bump `MCPServer/Table.php::$version`, add the `$upgrades` entry and an
   idempotent `upgrade_to_1_1_N()` `ALTER TABLE` callback (the existing D28
   three-part contract).
4. Add the key to the relevant `managed` or `initial` bucket.

Existing installs backfill on the next admin request; fresh installs get it at
activation.

### B. `ProtectedServers` — the new predicate

`includes/Database/MCPServer/ProtectedServers.php`, stateless static helper in
the same style as the seeder:

- `slugs()` / `is_protected( string $slug )`
- `is_protected_server( array $server )` — accepts both key shapes in
  circulation: `server_slug` (`Row::to_array()`, used by the edit screen and tab
  Registry) and `slug` (`MCPServerListTable::prepare_items()`'s remapped item)
- `is_protected_id( int $id )` — for the action handlers, which only get an id
- `is_recommended( string $slug )` + `recommended_badge()`

**Anything gating on "is this a built-in server" must call these — never
`registered_from === 'plugin'`.**

### C. Lockdown

| Layer | Change |
| --- | --- |
| `UpdateServerTab::visible_for()` / `DangerZoneTab::visible_for()` | additionally require `! ProtectedServers::is_protected_server()` |
| `MCPServerListTable::column_cb()` | returns `''` — protected rows carry no bulk checkbox |
| `MCPServerListTable::column_name()` | no Delete row action for protected rows |
| `Settings::handle_actions()` `delete` branch | refuses, redirects with the new `server_protected` notice |
| `Settings::handle_bulk_actions()` | `continue` on delete for protected ids (Enable/Disable stay allowed) |
| `Settings::handle_update_server()` | refuses — the tab is hidden but the POST target is reachable |

The bulk-delete guard also closes a **pre-existing hole**: before F088 "Default
MCP Server" could be swept into a bulk delete (it only self-healed on the next
admin request).

`Registry::visible_tabs()` already filters on `visible_for()`, and
`render_edit_page()` already falls back to `overview` when the requested tab is
not visible, so a bookmarked `&tab=danger-zone` URL degrades safely.

### D. Recommended first + badge

- `MCPServerListTable::prepare_items()` keeps the `orderby id ASC` query, then
  `usort()`s on a two-key rank (recommended → 0, else 1; tiebreak ascending id).
  Every other row keeps today's order.
- Pill rendered in the list Name column and on the Overview tab's Server Name row
  (`render_row()` already runs `wp_kses_post()`).
- `.acrossai-recommended-badge` added to `src/scss/backend.scss` beside
  `.acrossai-source-badge`; `.qs-card__badge--recommended` in
  `src/scss/quick-connect.scss`. Both require `npm run build`.

### E. Quick Connect wizard

`Step1_ServerPick.jsx`'s single `DEFAULT_SERVER_SLUG` constant becomes
`PREFERRED_SERVER_SLUGS` (AcrossAI, then default, then `servers[0]`), the picker
sorts the recommended server first, and it carries the same badge. Auto-select
still only fires when nothing is already picked, so a user mid-wizard is never
hijacked.

---

## Accepted trade-offs (deliberate, documented in the seeder docblock)

### Ownership is decided by slug alone

There is no marker recording which rows the seeder inserted. A row that already
carries a managed slug is **adopted**: its managed columns are rewritten and it
becomes non-editable/non-deletable. On an install that happened to create its own
`acrossai-mcp-server` before upgrading, that silently replaces the operator's
server configuration, with no UI path to undo it.

Accepted in favour of keeping one code path. The escape hatch if the trade ever
stops being worth it: record inserted row ids in an option and reconcile only
those, leaving foreign rows untouched.

Note this only exposes *pre-existing* rows. After the seeded row exists, neither
`Settings::handle_create_server()` nor `QuickConnectController::apply_step_2()`
will let anyone create a clashing `server_slug`.

### `namespace` + `route` uniqueness is still unchecked

Pre-F088 behaviour, unchanged. Nothing in the plugin enforces it; the vendor
adapter dedupes on `server_id` (our slug) only (`McpAdapter.php:242`); and
`register_rest_route()` **appends** handlers rather than replacing them. So two
servers sharing a route both register and whichever dispatches first wins, with
the other silently shadowed. Explicitly out of scope for F088 — worth a future
ticket (warning badge in the list + a namespace/route check in the create and
update forms, mirroring the existing `slug_exists` check).

---

## Files touched

| File | Change |
| --- | --- |
| `includes/Database/MCPServer/DefaultServerSeeder.php` | declarative definitions + reconcile + `ACROSSAI_SLUG` |
| `includes/Database/MCPServer/ProtectedServers.php` | **new** predicate helper |
| `admin/Partials/ServerTabs/{UpdateServerTab,DangerZoneTab}.php` | `visible_for()` guard |
| `admin/Partials/ServerTabs/OverviewTab.php` | Recommended pill on the Server Name row |
| `admin/Partials/MCPServerListTable.php` | `column_cb`, `column_name`, `prepare_items` ordering |
| `admin/Partials/Settings.php` | delete / bulk-delete / update guards |
| `admin/Partials/Notices.php` | `server_protected` notice |
| `src/scss/{backend,quick-connect}.scss` + `build/css/*`, `build/js/*` | badges |
| `src/js/quick-connect/steps/Step1_ServerPick.jsx` | preferred-server list + pinning |

**No schema change and no `MCPServer\Table::$version` bump in this feature** —
the new row uses only existing columns, and the F025/F030/F082 columns fall to
their DDL defaults exactly as the Default MCP Server row already does. The
declarative seeder is what carries the next version's added columns.

---

## Tests

New PHPUnit files (WP-dependent; the `database` and `admin` suites):

- `tests/phpunit/Database/MCPServer/DefaultServerSeederTest.php` — both rows
  seeded on an empty table; exact AcrossAI identity including
  `registered_from = 'database'` and `is_enabled = 0`; no duplicates on re-seed;
  restores only the deleted row. Plus the **reconcile contract**: a tampered
  `managed` column is restored, an operator-flipped `is_enabled` is **not**, and
  a converged run issues exactly one read per definition.
- `tests/phpunit/Database/MCPServer/ProtectedServersTest.php` — predicate matrix,
  both key shapes, missing/invalid ids, recommended-is-exactly-one.
- `tests/phpunit/Admin/ServerTabs/ManagedServerTabVisibilityTest.php` — both tabs
  hidden for both managed rows, visible for an operator row, end-to-end through
  `Registry::visible_tabs()`.
- `tests/phpunit/Admin/Partials/MCPServerListTableManagedRowsTest.php` — no
  checkbox, no delete row action, badge only on the recommended row, and pinning
  that holds regardless of id.

Local `phpunit` cannot run these (no WP test lib installed); `phpunit.yml` in CI
is the oracle, as for every other WP-dependent suite in this repo.

---

## Verification performed

1. **Seeded row correct** — `wp_acrossai_mcp_servers` row: `AcrossAI` /
   `acrossai-mcp-server` / `acrossai` / `mcp-server` / `database` / `is_enabled 0`.
2. **List page** — AcrossAI first with the `RECOMMENDED` pill, no bulk checkbox,
   no Delete row action; Default MCP Server second, also no checkbox.
3. **Edit page** — Overview / Connect / Tools / Abilities / Access Control / Logs
   only; no Update Server, no Danger Zone. An operator-created server still shows
   both tabs.
4. **Endpoint live when enabled** — `GET`/`POST /wp-json/acrossai/mcp-server`
   returns **401** (registered, auth-required) while a bogus sibling route under
   the same namespace returns **404**, proving the `'database'` choice registers
   it via `MCP\Controller::register_database_servers()`.
5. **Wizard** — AcrossAI first, pre-selected, badged.
6. `composer run phpcs` and `composer run phpstan` clean; `npm run lint:js`
   clean; pure `mcpclients` (100 tests) and `rename-gate` (12 tests) suites green.

A protected row mints no delete nonce anywhere in the UI, so the server-side
delete guard has no reachable hand-crafted-URL path to exercise manually; it is
covered by the PHPUnit tests above.
