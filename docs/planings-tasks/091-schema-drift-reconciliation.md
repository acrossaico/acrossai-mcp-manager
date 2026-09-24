# Planning: Tables that repair themselves (Feature 091)

## In plain English

A site running this plugin can end up with database tables that are missing columns the code
expects — and stay that way forever, silently.

When that happens nothing errors. The plugin reads the missing column, gets PHP's default value
instead of the stored one, and carries on confidently. On one live site this means the MCP
connector advertises **17 tools where it should advertise 14**, and the operator cannot fix it:
unchecking the three extras in the Tools tab appears to work and changes nothing, because the save
writes to columns that do not exist.

The site is not misconfigured. It updated the plugin the normal way. It is simply one of the sites
whose first BerlinDB-era page load happened at the wrong moment.

After this feature, every install converges on its declared schema on its own, and a missing
column can no longer survive an update.

## The problem, precisely

Two independent defects, both structural, both in the layer below our migrations.

### A — a version stamp written for migrations that never ran

BerlinDB decides whether to migrate by comparing one option against the declared `$version`. Two
lines in `vendor/berlindb/core/src/Database/Kern/Table.php` combine badly:

```php
if ( empty( $this->upgrades ) || empty( $this->db_version ) ) { return $upgrades; }  // :1021
if ( empty( $upgrades ) ) { $this->set_db_version(); return true; }                   // :982
```

"No version recorded" becomes "nothing pending" becomes "you are up to date". The version option
is stamped at the **currently declared** version having run **zero** callbacks.

Our own history walks into it. `wp_acrossai_mcp_servers` was created pre-BerlinDB by a dbDelta
installer keyed on `acrossai_mcp_manager_db_version`. F011 moved to BerlinDB under **new** option
keys and never migrated the old ones — `specs/011-berlindb-migration/plan.md:50` records that the
divergence was deliberate. So on a site where the table already existed, the new key was absent.

**The damage is a contiguous prefix, not everything.** The stamp takes the value of whatever
release the site happens to land on, and every later migration then runs normally from that false
baseline. Declared `$version` per release:

| Releases | `$version` | `$upgrades` | A pre-BerlinDB site landing here |
|---|---|---|---|
| v0.0.6 – v0.1.2 | 1.0.0 → 1.1.0 | **none** | stamped 1.0.0/1.1.0; every later migration runs. **Safe.** |
| v0.1.3 | 1.1.1 | 1 | loses 1.1.1 |
| v0.1.4 – v0.1.8 | 1.1.2 | 2 | loses 1.1.1–1.1.2 |
| v0.1.9 – v0.3.2 | 1.1.4 | 4 | loses 1.1.1–1.1.4 |
| v0.3.3 | 1.1.5 | 5 | loses 1.1.1–1.1.5 |
| v0.3.4 – v0.3.6 | **1.1.7** | 7 | **loses everything, `server_type` included** |

The last row is the one that matters commercially: a site making that jump **today** loses all
seven migrations. The affected population is not historical.

### B — Schema columns with no upgrade callback

`Table::create()` (`:601`) is a raw `CREATE TABLE`, run only from `install()` when the table is
**absent**. Nothing anywhere diffs a Schema against a live table. So a column added to a `Schema.php`
without a paired `$upgrades` entry reaches fresh installs only, forever.

This is D28's subject and B34's symptom. Both are already written down. What neither prevents is the
case where the coordinated three-part change is simply not made.

### What this actually looks like on a live site

Verified against production (`acrosswp.com`, v0.3.6) and against a healthy control:

| Table | Predates F011 | Live vs declared Schema |
|---|---|---|
| `acrossai_mcp_servers` | yes | **missing 4** — `tool_discover_abilities`, `tool_get_ability_info`, `tool_execute_ability` (1.1.1), `override_abilities_permission` (1.1.2); has `abilities_default_policy` (1.1.5) and `server_type` (1.1.6); 3 stale `claude_connector_*` remain |
| `acrossai_mcp_cli_auth_logs` | yes | **missing 4** — `redirect_uri`, `code_challenge`, `code_challenge_method`, `scope` (pure defect B — **no callback exists anywhere**; latent, nothing writes them today) |
| `acrossai_mcp_server_tools` | no | clean |
| `acrossai_mcp_server_abilities` | no | clean |
| `acrossai_mcp_servers_meta` | no | clean |

That column set reconstructs the history exactly: the stamp landed in [1.1.2, 1.1.4], so the site's
first BerlinDB-era admin request happened on a release in the v0.1.4–v0.3.2 range, and v0.3.3+ then
ran 1.1.5–1.1.7 normally. Every table BerlinDB created itself is clean; both tables that predate it
are frozen.

The 17-tool symptom follows mechanically: `Row`'s class-property defaults are `= 1` for the three
flags, so `ToolPolicy::compose_for_row()` unions all three protocol slugs into `tools/list`. And
`ToolsController::post_tools()` → `update_item()` → `$wpdb->update()` against absent columns returns
`false` without throwing, so the catch block never fires and the save reports success.

### The landmines still ahead

`MCPServerTool` and `MCPServerAbility` declare **no `$upgrades` property at all**. The first column
anyone adds to either Schema reaches fresh installs only, with no callback scaffolding to hang a fix
on. Same defect, not yet triggered.

## What changes

### A reconciler that adds what is missing

A new `SchemaReconciler` compares each table's declared columns against its live ones and adds
whatever is absent. **It writes no DDL of its own** — the vendored library already has everything:

| Need | Already exists |
|---|---|
| declared columns | `$table->schema_object->get_columns()` → `Column[]` |
| live columns | `Schema::from_table( $table->table_name )` — introspects via `SHOW COLUMNS`, returns the same `Column` type |
| the DDL fragment | `Column::get_create_string()` — **public**, emits exactly what follows `ADD COLUMN` |

**Additive only, deliberately.** It never drops: a live-minus-declared column is indistinguishable
from one another plugin or the operator added, which is why the stale `claude_connector_*` columns
are left alone. It never modifies: adding a missing column cannot lose data, narrowing an existing
one can. Width, type and index drift are **detected and reported** through an action, in the
`LegacyOAuthCleanup::…_skipped` idiom — never silently changed.

**Not every declared column is safely addable**, and the rule must be derived from the create string
rather than from a hand-kept list:

> A column is addable iff its `get_create_string()` is non-empty, does **not** contain
> `auto_increment`, and does **not** contain `not null` without also containing `default`.

That single rule excludes `id` (auto_increment without PRIMARY KEY is invalid DDL, errno 1075),
`CliAuthLog`'s `auth_code_hash` (`char(64)` not-null under a UNIQUE index — the second existing row
would violate it), and every `created_at` (not-null `datetime` whose synthesised default is
`'0000-00-00 00:00:00'`, which fails under `NO_ZERO_DATE` on any host that does not let WordPress
strip the strict modes). Anything excluded is **reported**, not added.

### Restoring the columns is not enough — and the order is load-bearing

`MCPServer/Schema.php:108-110` declares all three flags `'default' => 1`, and says why:

> Default 1 means "enabled" and, on the ALTER for existing installs, backfills every pre-F025 row
> with all three protocol tools enabled.

That was correct when every server was an `mcp-adapter` server. It is wrong for an `acrossai` one,
so a schema-only repair restores the columns at `1/1/1` and the site **still serves 17 tools**.

A second pass therefore sets the flags from `ServerTypes::declared_tools( $row->server_type )`,
reusing `ToolPolicy::split_payload()` for the mapping. Three constraints on it, in order of severity:

**`server_type` must be repaired first, in the same pass.** On a site stamped at 1.1.7 — the
population making the jump today — `server_type` is itself missing. The reconciler creates it, every
row lands on the column default `'mcp-adapter'`, and `upgrade_to_1_1_6()`'s corrective UPDATE is
gated inside a callback that will never run again. The flag pass then reads `'mcp-adapter'`, resolves
its declared tools to the three protocol slugs, and writes **1, 1, 1** — reproducing the exact
symptom it exists to fix, and mistyping the server permanently. So: retype the seeded rows first,
then read the type.

**`ServerTypes::declared_tools()` falls back to the legacy type when a type declares no tools**
(`ServerTypes.php:211-220`) — and the legacy tool list *is* the three protocol slugs. A third-party
type registered with an empty `tools` array would therefore have its flags set to `1,1,1`. Guard on
a non-null `ServerTypes::get()` with a non-empty `tools` array, and skip the row otherwise.

**Do not call `ToolPolicy::apply_type_defaults()`**, which already does almost exactly this — its
`replace_set()` half would wipe every operator-curated tool row on every affected server. Use the
columns half only; `DefaultServerSeeder::write_declared_tools()` is the precedent for that shape.

It runs **only on columns the reconciler just created in this same request**. That narrowness is the
safety argument, and it is `SeededToolsBackfill`'s own: an operator cannot have expressed a
preference about a column that did not exist, so this cannot fight them.

### Migrations that report failure honestly — but not all of them

Every `upgrade_to_*()` returns `true` regardless of what `$wpdb->query()` returned, so a failed
ALTER still advances the stamp. Their docblocks already promise the opposite — *"`false` on failure
(BerlinDB aborts and leaves the version unstamped…)"* — so this is closing a documented-versus-actual
gap, not changing a design.

But making **all** of them honest is a regression once the reconciler ships. BerlinDB releases the
upgrade lock in a `finally`, so a permanently-failing ALTER retries every admin request forever and
**blocks every later migration in the chain behind it**. A site without ALTER privilege would freeze
at 1.1.0 permanently. So split the rule by what the reconciler can heal:

- **ADD-COLUMN callbacks** (1.1.1, 1.1.2, 1.1.3, 1.1.5, 1.1.6) — keep `return true`. The reconciler
  is now their retry. Fix the docblocks to say so.
- **DROP/MODIFY callbacks** (1.1.4, 1.1.7, `CliAuthLog::upgrade_to_1_0_1`) — return `false` on
  failure. The reconciler deliberately never performs these, so the unstamped version is their
  *only* retry. Fire `acrossai_mcp_schema_upgrade_failed` before returning, per D19 fail-open
  observability.

### Prevention, so this cannot recur

- A **guard test** across all five tables: drop an addable column, reconcile, assert it returns
  correctly and that a second run is a no-op.
- A **parity test** asserting declared columns == live columns after activation, comparing **sets,
  not sequences** (`ADD COLUMN` appends, so a repaired table's column order will never match a fresh
  install's — an explicit non-goal). This is what would have caught the four orphaned PKCE columns.
- A **frozen manifest test**, *not* a diff-based grep gate. The gate originally wanted — "fire when a
  `Schema.php` diff removes or narrows a column" — is a diff predicate, and `verify-f021-gates.sh`
  runs whole-repo content greps against a `fetch-depth: 1` checkout with no merge base, and also
  fires on `push: main` where "the diff" is undefined. A manifest of every column as
  `name => [type, length, allow_null]`, asserting no removal, no narrowing and no type change while
  **ignoring additions**, gives the identical guarantee with no diff, and a far better failure
  message.
- Three genuinely greppable gates for `verify-f021-gates.sh`: the reconciler contains no destructive
  DDL keyword (this is the whole safety argument for relaxing D28, so it deserves a gate);
  destructive DDL appears only in versioned `Table.php` callbacks; and every `$upgrades` entry has a
  matching method with the highest key equal to the declared `$version`.

## Where the work lives

All of it in **`acrossai-mcp-manager`**. The sibling plugin owns no tables and is untouched.

| Path | Change |
|---|---|
| `includes/Database/SchemaReconciler.php` | new — A11 pure service, sibling to `LegacyOAuthCleanup` |
| `includes/Database/MCPServer/CreatedColumnBackfill.php` | new — the value pass for just-created columns |
| `includes/Main.php` | a sixth call in `reconcile_database_schemas()`, after the five `maybe_upgrade()` calls |
| `includes/Activator.php` | **reorder** — the reconciler must precede `DefaultServerSeeder::seed()` |
| `includes/Database/MCPServer/Table.php`, `includes/Database/CliAuthLog/Table.php` | honest returns on DROP/MODIFY callbacks; docblock fixes on the ADDs |
| `bin/verify-f021-gates.sh` | three new greppable gates |
| `tests/phpunit/Database/` | reconciler, parity, manifest, backfill, upgrade-honesty, Row-default tests |

**The `Activator` reorder is not cosmetic.** Today `seed()` runs before anything could have repaired
the schema, and on a drifted install its `insert()`/`update()` reference columns that do not exist
and silently drop them — B34 verbatim, and the same class of bug the 0.3.6 activation-order fix
already caught once. The "ORDER IS LOAD-BEARING" comment at `Activator.php:38-56` becomes stale and
must be rewritten.

**`ServerGuideBackfill` has already burned its one shot** on any install missing `server_type`: its
query filters `WHERE s.server_type = %s`, which errors on a table without that column, returns
empty, does nothing, and sets its `DONE_OPTION` anyway. So whenever the reconciler creates
`server_type`, the backfill's done-flag must be deleted so it can actually run.

## Traps, all confirmed in the vendored source

- `$table->version` returns the **installed** version, not the declared property — the Magic `__get`
  trait prefers `get_version()`. Read the declared one by reflection, as
  `PhantomVersionGuardTest.php:46` already does. `schema_object` and `table_name` are safe today
  only because no such getter exists; a test should assert that.
- `Schema::from_table()` suppresses errors and returns an **empty Schema** when the table is absent.
  Unguarded, the reconciler would then emit one `ALTER` per declared column against a table that
  does not exist. Guard on both `exists()` and a non-empty live column list.
- **MySQL 8.0.19+ no longer reports integer display width**, so `SHOW COLUMNS` returns `tinyint`
  not `tinyint(1)` and the parsed length is `0`. Every `tinyint(1)` / `bigint(20)` column would look
  drifted. Compare lengths only when both sides are non-zero.
  `PermissionOverrideColumnUpgradeTest.php:35-39` already documents this.
- BerlinDB's `{$db_version_key}_upgrade_lock` does **not** protect the reconciler. Take a short
  transient lock of its own, released in a `finally`. Do **not** reach for `ADD COLUMN IF NOT
  EXISTS` — that is MariaDB-only and MySQL 8 rejects it.
- `$wpdb->query()` returns `true` for DDL, not a row count — test `false === $result`, never
  `! $result`. And suppress wpdb errors around the ALTER: `phpunit.xml.dist` sets
  `beStrictAboutOutputDuringTests` and `failOnWarning`, so a stray error message fails the suite.
- There are **no hooks or filters anywhere in BerlinDB's upgrade path**.
- **PHPStan is level 5 in `phpstan.neon.dist` but level 8 in CI**, so local runs pass code CI
  rejects. And `phpcs` is piped through `cs2pr`, which fails only on errors — warnings annotate but
  do not block. Write to the stricter standard; do not rely on the local gate to catch it.

## Amending D28

D28 (`docs/memory/DECISIONS.md:1755`) mandates the three-part Schema-change contract. Its own
Reconsider clause reads:

> If BerlinDB Core adds an auto-diff mode … this DEC's manual-callback requirement can relax.

Core did not; this plugin grows one instead. So this lands as an amendment, and the contract becomes
conditional on the kind of change:

- **Pure column addition** — declare it in `Schema.php` and stop. No version bump, no callback.
  Because the DDL comes from the Column object itself, the physical column can never disagree with
  the declaration, which a hand-written `ALTER` string always could. **Exception:** a column that is
  `NOT NULL` without a default, carries `auto_increment`, or is the primary key still needs the full
  contract — `SchemaReconciler::is_addable()` is the authority.
- **A new column that also needs a value on existing rows** — declare it, and add a value pass keyed
  on the reconciler's created-column report, acting only on columns created in that same request.
- **Removal, narrowing, type change, index change, rename, drop** — the three-part contract is
  unchanged and mandatory, and these callbacks must return `false` on failure.

D28 as written addressed only "version bumped without a callback". It did not cover the phantom
stamp, which is the second root cause and the one no version bump can ever reach. The amendment
should say so, and B34 should gain the phantom-stamp variant alongside its existing symptom.

## Open questions

1. **Should width/type drift ever be repaired, not just reported?** `CliAuthLog`'s
   `upgrade_to_1_0_1` exists precisely because widths drifted. Reporting is the safe v1; repairing
   is the complete one.
2. **Is the action hook enough of an operator surface?** The plugin has no Site Health test and no
   status screen. `Notices::register_shared_notices()` on the `acrossai_notices` filter is the one
   persistent surface that exists, if unrepaired drift should be visible rather than only
   observable.
3. **Should `post_tools()` treat a `false` from `update_item()` as a 500?** Today it returns 200 with
   a success payload — which is why the drift was invisible for so long. Out of scope here, but it
   should become an issue.

## What this is not

**It is not gated by a one-shot flag, nor run on every request.** A `DONE_OPTION` would burn its
single chance on a site that happens to be mid-upgrade, and drift can arrive at any future release.
Instead, short-circuit on a stored fingerprint — but fingerprint the **declared column set**, not the
declared versions. A version-only fingerprint misses exactly defect B, whose whole shape is "column
added with no version bump". Include the plugin version too, so every release forces exactly one
full scan. Write the fingerprint last and only on a pass where no ALTER failed, so a transient
failure retries and a clean pass costs one non-autoloaded option read.

**It is not a REST-reachable repair.** It runs on `admin_init` and activation, matching the existing
backfills. `admin_init` does not fire on REST, front-end, WP-CLI or cron — so a site whose operator
never opens wp-admin stays drifted. That is precisely the profile of an affected site, and the
plugin has no WP-CLI surface to offer as an escape hatch. Accepted; it must be stated in the spec
rather than left implicit.

**It is not multisite-aware.** All five tables are `$global = false`, `register_activation_hook`
ignores `$network_wide`, and there is no `get_sites()` loop anywhere — so subsite N is reached only
when someone visits that subsite's wp-admin. The existing repairs are silently single-site too.

**It does not clean up the mess it can prove is harmless.** The stale `claude_connector_*` columns
and the orphaned `acrossai_mcp_manager_db_version` / `acrossai_mcp_cli_auth_log_db_version` options
stay. Both are inert and `uninstall.php`'s sweep already catches the options.

**It will not look fixed to an already-connected client.** A connected MCP client's tool list is
fixed at connect time and cannot be refreshed — the plugin's own server instructions say so. After
the repair the site serves 14, but the client keeps showing 17 until the connector is reconnected.
The upgrade notice must say this, or operators will report the fix as not working.
