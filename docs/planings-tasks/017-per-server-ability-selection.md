# Planning: Per-server Ability Selection (Feature 017)

Add per-server ability exposure overrides to the plugin. A new BerlinDB
module — `MCPServerAbility` — stores one row per (`server_id`, `ability_slug`)
in `{prefix}acrossai_mcp_server_abilities`, and the read-only
`Abilities` tab on the Edit MCP Server screen is replaced with a
`@wordpress/dataviews`-driven React app (mockup Option 1A from
`Admin-ability-selection-UI.zip`) that lets admins choose which
registered abilities each server exposes to connected AI clients.

The change fills a gap in Feature 013's per-server-tabs refactor: the
current Abilities tab (`admin/Partials/ServerTabs/AbilitiesTab.php:84–147`)
partitions `wp_get_abilities()` on each ability's own `meta[mcp][public]`
flag, which is global to the ability — an admin cannot expose an
ability on one MCP server and hide it on another. Storing the toggle
per (server, ability) unblocks multi-server deployments and matches
the sibling plugin `acrossai-abilities-manager`'s
`Override_Applier::has_server_restriction()` intent from the read
side.

The migration is **backwards-compatible with existing data**: rows
are lazily created — the empty table IS the correct initial state. A
new shared resolver `ExposureResolver::resolve( $server_id, $slug, $meta )`
returns `(bool) $row->is_exposed` when a row exists, otherwise falls
back to `! empty( $meta['mcp']['public'] )`. Every current caller of
the F013 partition helpers stays green through this resolver, and no
data migration script is required. Data-preservation contract: table
name `acrossai_mcp_server_abilities` and option name
`acrossai_mcp_server_abilities_db_version` are frozen from this
merge forward per the F011 D-contract convention.

The React app uses **WordPress-provided packages only** —
`@wordpress/dataviews` for the table (search, category + type filters,
sortable columns, bulk actions, per-row `ToggleControl`),
`@wordpress/components` for controls, `@wordpress/element` +
`@wordpress/api-fetch` + `@wordpress/i18n` for glue, plus
`@wordpress/hooks` for the extensibility surface. No generic React
libraries (`react-query`, `redux`, `mobx`, `react-table`, MUI,
styled-components) are introduced. Mounted on the Abilities tab only,
via a new `Admin\Main::maybe_enqueue_abilities_app()` guard that
mirrors the F015 `maybe_enqueue_access_control_app()` shape. The tab
retains its existing `is_enabled` and `function_exists( 'wp_get_abilities' )`
guards so it degrades cleanly on servers without the Abilities API.

The tab is **extensible**: companion plugins (e.g. `acrossai-abilities-manager`)
can add columns and per-row actions by registering
`@wordpress/hooks` filters — three JS filter points
(`acrossaiMcpManager.abilities.{fields, actions, row}`) plus one
PHP row filter (`acrossai_mcp_ability_row`). This is a WordPress-standard
extensibility contract, not a custom API. A sibling plugin can add an
"Action" column with an "Edit" button in three touch points (one JS
`addFilter`, one PHP `add_filter`, and its own REST route the button
targets) without any change to Feature 017's source. The built-in
column set (`slug`, `label`, `type`, `category`, `description`,
`is_exposed`) is re-asserted after each filter fires so extensions
cannot remove or overwrite core columns.

---

## Speckit Workflow

```markdown
# 1. Branch
/speckit.git.feature "per-server-ability-selection"

# 2. Specify
/speckit.specify "Add a new BerlinDB module MCPServerAbility that stores
per-server ability exposure overrides in
{prefix}acrossai_mcp_server_abilities (columns: id bigint unsigned
auto_increment, server_id bigint unsigned, ability_slug varchar(191),
is_exposed tinyint(1) default 0, created_at datetime, updated_at
datetime; UNIQUE(server_id, ability_slug); KEY(server_id)). Reuse the
Feature 011 BerlinDB module shape verbatim — Schema/Table/Query/Row
extending \\BerlinDB\\Database\\Kern\\{Schema, Table, Query, Row},
phantom-version guard on Table::maybe_upgrade() copied from
MCPServer\\Table, singleton pattern on Query per A2/S6. Register the
Table in Activator::activate() after CliAuthLogTable::instance()->maybe_upgrade()
and in Main::bootstrap_database_tables() per DEC-BERLINDB-TABLE-REQUEST-BOOT.
Do NOT seed any default rows — the empty-table state is the correct
initial state (absence of row = inherit from the ability's own
meta[mcp][public] flag). Add REST controller Includes\\REST\\AbilitiesController
with two routes under namespace acrossai-mcp-manager/v1: GET
/servers/(?P<server_id>\\d+)/abilities returns the merged list from
wp_get_abilities() joined with the new table (shape: array of { slug,
label, type, category, description, is_exposed, has_override });
POST /servers/(?P<server_id>\\d+)/abilities upserts a batch of
{ slug, is_exposed } pairs and returns the refreshed merged list.
permission_callback = current_user_can('manage_options') on both. Add
stateless service Includes\\Database\\MCPServerAbility\\ExposureResolver
with static resolve( int $server_id, string $ability_slug, array
$meta ): bool that returns (bool) $row->is_exposed when a row exists,
else ! empty( $meta['mcp']['public'] ). Include a per-request static
cache keyed by \\\"{$server_id}:{$ability_slug}\\\". Replace
AbilitiesTab::render_body with a mount div (<div
id=\\\"acrossai-mcp-abilities-root\\\" data-server-id data-server-slug><p
class=\\\"description\\\">Loading abilities…</p></div>) after the
existing is_enabled + function_exists(wp_get_abilities) guards; delete
render_public_table(), render_private_table(), and
partition_abilities() — the React app + REST controller + resolver own
all of that now. Add a new webpack entry js/abilities pointing at
src/js/abilities.js. Build the React app on @wordpress/dataviews
(view type=table, per-row ToggleControl cell for is_exposed,
searchable slug/label/description, filters on category+type,
sortable slug/label/type/category/description, bulk actions Expose+Hide,
live \\\"N of M exposed\\\" count via @wordpress/i18n _n()),
@wordpress/components (ToggleControl, Notice, Spinner),
@wordpress/element (render, useState, useEffect, useMemo,
createElement), @wordpress/api-fetch (with createNonceMiddleware),
@wordpress/i18n (__/_n). Do NOT introduce generic React libraries
(react-query, redux, mobx, react-table, MUI, styled-components).
Enqueue on the Abilities tab only via a new
Admin\\Main::maybe_enqueue_abilities_app() mirroring
maybe_enqueue_access_control_app: guard on ?action=edit and
?tab=abilities via sanitize_key + wp_unslash, read
build/js/abilities.asset.php via read_asset_manifest,
wp_localize_script('acrossaiMcpAbilities', { serverId, serverSlug,
restApiRoot: untrailingslashit(rest_url()), nonce:
wp_create_nonce('wp_rest'), namespace: 'acrossai-mcp-manager/v1' }).
Add uninstall.php DROP TABLE for wp_acrossai_mcp_server_abilities
alongside the existing MCPServer + CliAuthLog drops. Register the
REST controller in Main::define_admin_hooks() on rest_api_init after
the existing MCP\\Controller wiring. Do NOT touch F015 AccessControl
code paths, F013 AbstractServerTab template method contract, or the
current MCPServer schema."
```

### Detailed Description for `/speckit.specify`

> **Before writing a single line of code, read and internalize all of
> these governing documents in full:**
>
> 1. `AGENTS.md` — this plugin's singleton pattern, hook registration
>    rules (no `add_action` inside constructors), Before Commit Checklist,
>    and the loader-only wiring contract enforced in `includes/Main.php`.
> 2. `docs/planings-tasks/011-berlindb-migration.md` — canonical
>    BerlinDB module shape (Schema/Table/Query/Row, phantom-version
>    guard on Table::maybe_upgrade(), request-time boot in
>    Main::bootstrap_database_tables per DEC-BERLINDB-TABLE-REQUEST-BOOT).
>    Every new class in this feature MUST mirror the F011 MCPServer
>    module layout at
>    `includes/Database/MCPServer/{Schema,Table,Query,Row}.php`.
> 3. `docs/planings-tasks/013-per-server-tabs-refactor.md` — the
>    `AbstractServerTab` template-method contract that `AbilitiesTab`
>    still honors. The `render()` final entry + `render_body()`
>    abstract remain the surface area; only the body content changes.
> 4. `docs/planings-tasks/015-access-control-v2-adoption.md` — the
>    canonical `src/js/<slug>.js` + `webpack.config.js` entry +
>    `admin/Main.php::maybe_enqueue_<slug>_app()` guard pattern this
>    feature mirrors. Also the vendor-package failure branch shape
>    (silent bail on missing asset manifest) applies here.
> 5. `vendor/berlindb/core/src/Database/Kern/{Table,Schema,Query,Row}.php`
>    — BerlinDB v3 base classes. Read `Table.php`, `Schema.php`,
>    `Query.php`, `Row.php` to understand which properties are
>    `protected` (must be declared in subclasses) vs inherited
>    defaults.
> 6. The current `admin/Partials/ServerTabs/AbilitiesTab.php` — read
>    the entire file BEFORE rewriting `render_body()` so the two
>    guard branches (server disabled; `wp_get_abilities()` absent) are
>    preserved verbatim in the new body.
> 7. `@wordpress/dataviews` package README + entry types (in
>    `node_modules/@wordpress/dataviews/`) — the `fields` / `actions` /
>    `view` contract that drives the mockup 1A UI. Follow the
>    documented shapes; do not reinvent.
>
> Every decision — schema column-def, index-name preservation, REST
> route shape, React field/action config — must be justified against
> the above. If a choice is not explicitly covered, default to the F011
> MCPServer or F015 access-control shape. Do not write code that would
> fail any Definition-of-Done gate: PHPStan level 8, PHPCS,
> security review, all `__()` calls using the correct text domain
> `'acrossai-mcp-manager'`.
>
> **New contract to preserve verbatim from this feature forward
> (grep-gate before + after):**
>
> - `\AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\Table`
> - `\AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\Schema`
> - `\AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\Query`
> - `\AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\Row`
> - `\AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\ExposureResolver`
> - `\AcrossAI_MCP_Manager\Includes\REST\AbilitiesController`
> - REST routes:
>   - `GET  /acrossai-mcp-manager/v1/servers/(?P<server_id>\d+)/abilities`
>   - `POST /acrossai-mcp-manager/v1/servers/(?P<server_id>\d+)/abilities`
> - DOM mount id: `acrossai-mcp-abilities-root`
> - JS localize handle: `acrossaiMcpAbilities`
> - Webpack entry: `js/abilities` → `build/js/abilities.js`
> - JS extension filter names (never renamed after merge):
>   - `acrossaiMcpManager.abilities.fields`
>   - `acrossaiMcpManager.abilities.actions`
>   - `acrossaiMcpManager.abilities.row`
> - PHP extension filter (never renamed after merge):
>   - `acrossai_mcp_ability_row` — `apply_filters( 'acrossai_mcp_ability_row', array $row, int $server_id, \WP_Ability $ability )`
> - PHP action (never renamed after merge):
>   - `acrossai_mcp_ability_exposure_changed` — `do_action( 'acrossai_mcp_ability_exposure_changed', int $server_id, string $ability_slug, bool $was, bool $now, int $user_id )` (per FR-024, fired only on effective change)
>
> Pre-flight grep (records the callers whose behavior must be
> unchanged after this feature):
> ```
> grep -rEn '(partition_abilities|render_public_table|render_private_table)' \
>     --include='*.php' \
>     includes/ admin/ public/ acrossai-mcp-manager.php
> ```
> Every hit outside `admin/Partials/ServerTabs/AbilitiesTab.php`
> MUST be reachable through `ExposureResolver::resolve()` (or its
> shared logic) after this feature. Any grep result that would break
> requires a caller-side follow-up out of Feature 017 scope.
>
> New table + option map (data-preservation contract):
>
> | Module | Table (with `$wpdb->prefix`) | `db_version_key` option | `$version` |
> | --- | --- | --- | --- |
> | MCPServerAbility | `acrossai_mcp_server_abilities` | `acrossai_mcp_server_abilities_db_version` | `1.0.0` |
>
> ---
>
> **TASK-1 — MCPServerAbility BerlinDB module (Schema/Table/Query/Row)**
>
> Files (all new under `includes/Database/MCPServerAbility/`):
> - `Schema.php` (new)
> - `Table.php` (new)
> - `Query.php` (new)
> - `Row.php` (new)
>
> Read the F011 MCPServer module files at
> `includes/Database/MCPServer/{Schema,Table,Query,Row}.php` in full
> BEFORE editing — the new classes are byte-for-byte structural
> analogs.
>
> Schema subclass — declare columns matching:
> ```php
> public $columns = array(
>     array( 'name' => 'id', 'type' => 'bigint', 'length' => '20',
>            'unsigned' => true, 'extra' => 'auto_increment', 'sortable' => true ),
>     array( 'name' => 'server_id', 'type' => 'bigint', 'length' => '20',
>            'unsigned' => true, 'searchable' => true ),
>     array( 'name' => 'ability_slug', 'type' => 'varchar', 'length' => '191',
>            'default' => '', 'searchable' => true ),
>     array( 'name' => 'is_exposed', 'type' => 'tinyint', 'length' => '1',
>            'default' => 0 ),
>     array( 'name' => 'created_at', 'type' => 'datetime',
>            'created' => true, 'date_query' => true, 'sortable' => true ),
>     array( 'name' => 'updated_at', 'type' => 'datetime',
>            'date_updated' => true ),
> );
> public $indexes = array(
>     array( 'name' => 'primary', 'type' => 'primary', 'columns' => array( 'id' ) ),
>     array( 'name' => 'server_ability', 'type' => 'unique',
>            'columns' => array( 'server_id', 'ability_slug' ) ),
>     array( 'name' => 'server_id', 'type' => 'key', 'columns' => array( 'server_id' ) ),
> );
> ```
> The 191-char varchar length is deliberate — it fits
> `UNIQUE(server_id, ability_slug)` under InnoDB's utf8mb4 767-byte
> key-length limit on MySQL 5.6+. Do not widen to 255.
>
> Table subclass — mirror `MCPServer\Table` exactly:
> ```php
> protected $name = 'acrossai_mcp_server_abilities';
> protected $version = '1.0.0';
> protected $db_version_key = 'acrossai_mcp_server_abilities_db_version';
> protected $schema = Schema::class;
> protected $global = false;
> ```
> Include the singleton `instance()`. Override `maybe_upgrade()`
> verbatim from `MCPServer\Table::maybe_upgrade()`:
> ```php
> public function maybe_upgrade(): void {
>     if ( ! $this->exists() ) {
>         delete_option( $this->db_version_key );
>     }
>     parent::maybe_upgrade();
> }
> ```
> This is the SILENT phantom-version guard per Clarification Q1 of
> F011 — no `error_log`, no admin notice, no transient.
>
> Query subclass — declare BerlinDB properties matching sibling
> pattern:
> ```php
> protected $table_name = 'acrossai_mcp_server_abilities';
> protected $table_alias = 'mcpsa';
> protected $table_schema = Schema::class;
> protected $item_name = 'mcp_server_ability';
> protected $item_name_plural = 'mcp_server_abilities';
> protected $item_shape = Row::class;
> ```
> Add singleton `instance()` (private ctor, matches `MCPServer\Query`).
> Add ONE bespoke helper:
> ```php
> public function upsert(
>     int $server_id,
>     string $ability_slug,
>     bool $is_exposed
> ): bool {
>     $existing = $this->query(
>         array(
>             'server_id'    => $server_id,
>             'ability_slug' => $ability_slug,
>             'number'       => 1,
>         )
>     );
>     if ( ! empty( $existing ) ) {
>         return (bool) $this->update_item(
>             $existing[0]->id,
>             array( 'is_exposed' => (int) $is_exposed )
>         );
>     }
>     return (bool) $this->add_item(
>         array(
>             'server_id'    => $server_id,
>             'ability_slug' => $ability_slug,
>             'is_exposed'   => (int) $is_exposed,
>         )
>     );
> }
> ```
> All other API (query, add_item, update_item, delete_item, etc.) is
> inherited from BerlinDB. Do not override any other method.
>
> Row subclass — extend `\BerlinDB\Database\Kern\Row`. Declare public
> properties for every column:
> ```php
> public $id           = 0;
> public $server_id    = 0;
> public $ability_slug = '';
> public $is_exposed   = 0;
> public $created_at   = '';
> public $updated_at   = '';
> ```
> Provide `to_array()` matching the MCPServer\Row shape.
>
> ---
>
> **TASK-2 — Register the new Table for install + request-time boot**
>
> Files:
> - `includes/Activator.php` (delta only — add one line)
> - `includes/Main.php` (delta only — add one line in
>   `bootstrap_database_tables()`)
> - `uninstall.php` (delta only — add one DROP TABLE)
>
> Activator delta — after `CliAuthLogTable::instance()->maybe_upgrade();`
> add:
> ```php
> use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\Table as MCPServerAbilityTable;
> ...
> MCPServerAbilityTable::instance()->maybe_upgrade();
> ```
> Do NOT add a seeder call — the empty-table state IS the correct
> backwards-compatible initial state (absence-of-row = inherit from
> meta).
>
> Main delta — in `bootstrap_database_tables()` add:
> ```php
> \AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\Table::instance();
> ```
> Same rationale as DEC-BERLINDB-TABLE-REQUEST-BOOT (F011 T044): the
> Table subclass MUST be instantiated at request time so its
> `sunrise()` boot registers `$wpdb->prefix . $name` with the DB
> interface. Without this, Query subclasses fall back to
> `$table_alias` as the FROM clause and produce `Table 'db.mcpsa'
> doesn't exist` errors.
>
> Uninstall delta — read `uninstall.php` for the existing pattern
> (should already include `wp_acrossai_mcp_servers`,
> `wp_acrossai_mcp_cli_auth_logs`), then add the new table name to
> the DROP list.
>
> ---
>
> **TASK-3 — Shared ExposureResolver service**
>
> Files (new): `includes/Database/MCPServerAbility/ExposureResolver.php`
>
> Stateless pure service (A11/A15 — no singleton, no ctor). Public
> API:
> ```php
> final class ExposureResolver {
>     private static array $cache = array();
>
>     public static function resolve(
>         int $server_id,
>         string $ability_slug,
>         array $meta
>     ): bool;
> }
> ```
>
> Contract:
> - Compute cache key `"{$server_id}:{$ability_slug}"`; return
>   `self::$cache[$key]` when present.
> - Look up the row via
>   `Query::instance()->query( array( 'server_id' => $server_id,
>   'ability_slug' => $ability_slug, 'number' => 1 ) )`.
> - If row found: `$result = (bool) $existing[0]->is_exposed`.
> - Else: `$result = ! empty( $meta['mcp']['public'] )`.
> - Cache `self::$cache[$key] = $result;` and return.
>
> Do NOT expose a public cache-clear method in Feature 017 — every
> resolver call happens within a single request. If a follow-up
> feature adds long-lived worker processes, add the cache-clear then.
>
> ---
>
> **TASK-4 — REST AbilitiesController**
>
> Files:
> - `includes/REST/AbilitiesController.php` (new)
> - `includes/Main.php::define_admin_hooks()` (delta — add wiring)
>
> Class shape:
> ```php
> final class AbilitiesController {
>     private const NAMESPACE = 'acrossai-mcp-manager/v1';
>     private static ?self $instance = null;
>     public static function instance(): self { ... }
>     private function __construct() {}
>     public function register_routes(): void { ... }
>     public function get_abilities( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error;
>     public function post_abilities( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error;
> }
> ```
>
> Routes registered in `register_routes()`:
>
> - `register_rest_route( self::NAMESPACE, '/servers/(?P<server_id>\d+)/abilities',
>   array( 'methods' => 'GET', 'callback' => [ $this, 'get_abilities' ],
>   'permission_callback' => [ $this, 'permission_check' ],
>   'args' => array( 'server_id' => array( 'type' => 'integer',
>   'required' => true, 'sanitize_callback' => 'absint' ) ) ) );`
>
> - `register_rest_route( self::NAMESPACE, '/servers/(?P<server_id>\d+)/abilities',
>   array( 'methods' => 'POST', 'callback' => [ $this, 'post_abilities' ],
>   'permission_callback' => [ $this, 'permission_check' ],
>   'args' => array(
>       'server_id' => array( 'type' => 'integer', 'required' => true,
>                              'sanitize_callback' => 'absint' ),
>       'abilities' => array( 'type' => 'array', 'required' => true,
>                             'items' => array( 'type' => 'object' ) ),
>   ) ) );`
>
> `permission_check()` returns `current_user_can( 'manage_options' )`
> — never `__return_true`, never a role-name check (Constitution key
> rule 8).
>
> `get_abilities()` handler:
> 1. `$server_id = (int) $req['server_id'];`
> 2. Server lookup via `MCPServer\Query::instance()->query( array(
>    'id' => $server_id, 'number' => 1 ) )`. Return `WP_Error(
>    'acrossai_mcp_server_not_found', ..., array( 'status' => 404 )
>    )` when empty.
> 3. If `! function_exists( 'wp_get_abilities' )` return
>    `array( 'has_abilities_api' => false, 'abilities' => array() )`.
> 4. For each ability from `\wp_get_abilities()`:
>    ```php
>    $slug = $ability->get_name();
>    $meta = $ability->get_meta();
>    $rows = MCPServerAbility\Query::instance()->query( array(
>        'server_id' => $server_id, 'ability_slug' => $slug, 'number' => 1,
>    ) );
>    $row = array(
>        'slug'         => $slug,
>        'label'        => $ability->get_label(),
>        'type'         => $meta['mcp']['type'] ?? 'tool',
>        'category'     => $ability->get_category(),
>        'description'  => $ability->get_description(),
>        'is_exposed'   => \AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\ExposureResolver::resolve(
>            $server_id, $slug, $meta
>        ),
>        'has_override' => ! empty( $rows ),
>    );
>    // FR-027: Let extensions add keys. Non-array returns are discarded.
>    $filtered = apply_filters( 'acrossai_mcp_ability_row', $row, $server_id, $ability );
>    if ( ! is_array( $filtered ) ) {
>        _doing_it_wrong(
>            'acrossai_mcp_ability_row',
>            'Filter callback must return an array; discarding non-array return.',
>            '0.1.0'
>        );
>        $filtered = $row;
>    }
>    // FR-027 invariant: re-assert built-in keys so extensions cannot overwrite them.
>    $data[] = array_merge( $filtered, $row );
>    ```
> 5. Return `array( 'has_abilities_api' => true, 'abilities' => $data )`.
>
> `post_abilities()` handler:
> 1. 404 lookup same as GET.
> 2. Validate `$req['abilities']` is a non-empty array; each entry has
>    a string `slug` + boolean `is_exposed`. Return
>    `WP_Error( 'acrossai_mcp_invalid_payload', ..., array( 'status'
>    => 400 ) )` on failure.
> 3. Compute `$valid_slugs = array_map( fn ( $a ) => $a->get_name(),
>    \wp_get_abilities() );` and reject any `slug` not in this set
>    (400) — prevents unbounded row growth if a plugin unregisters an
>    ability.
> 4. For each valid `{ slug, is_exposed }`:
>    `MCPServerAbility\Query::instance()->upsert( $server_id,
>    sanitize_text_field( $slug ), (bool) $is_exposed );`.
> 5. Return the SAME merged shape as GET so the client re-renders
>    without a follow-up request.
>
> Wire in `Main::define_admin_hooks()` immediately after the
> `MCP\Controller` block (currently line 405-406):
> ```php
> $abilities_rest = \AcrossAI_MCP_Manager\Includes\REST\AbilitiesController::instance();
> $this->loader->add_action( 'rest_api_init', $abilities_rest, 'register_routes' );
> ```
>
> ---
>
> **TASK-5 — AbilitiesTab rewrite (React mount + guards preserved)**
>
> Files: `admin/Partials/ServerTabs/AbilitiesTab.php`
>
> Preserve the class header, `slug()`, and `label()` methods verbatim.
> Rewrite `render_body()` to:
>
> ```php
> protected function render_body( array $server ): void {
>     $enabled = ! empty( $server['is_enabled'] );
>     echo '<div class="mcp-tab-panel">';
>     printf( '<h2>%s</h2>', esc_html__( 'WordPress Abilities', 'acrossai-mcp-manager' ) );
>
>     if ( ! $enabled ) {
>         printf(
>             '<div class="notice notice-warning inline"><p><strong>%1$s</strong> %2$s</p></div>',
>             esc_html__( 'Server is disabled.', 'acrossai-mcp-manager' ),
>             esc_html__( 'Enable the server on the Overview tab to expose these abilities to MCP clients.', 'acrossai-mcp-manager' )
>         );
>         echo '</div>';
>         return;
>     }
>
>     if ( ! function_exists( 'wp_get_abilities' ) ) {
>         printf(
>             '<div class="notice notice-warning inline"><p>%s</p></div>',
>             esc_html__( 'The WordPress Abilities API is not available on this installation.', 'acrossai-mcp-manager' )
>         );
>         echo '</div>';
>         return;
>     }
>
>     printf(
>         '<div id="acrossai-mcp-abilities-root" data-server-id="%1$d" data-server-slug="%2$s"><p class="description">%3$s</p></div>',
>         (int) $server['id'],
>         esc_attr( (string) ( $server['server_slug'] ?? '' ) ),
>         esc_html__( 'Loading abilities…', 'acrossai-mcp-manager' )
>     );
>     echo '</div>';
> }
> ```
>
> Delete `partition_abilities()`, `render_public_table()`, and
> `render_private_table()` in the same commit — the React app + REST
> controller + ExposureResolver own all of that now.
>
> ---
>
> **TASK-6 — Webpack entry + React source**
>
> Files:
> - `webpack.config.js` (delta only — one line under `entry:`)
> - `src/js/abilities.js` (new)
> - `src/scss/abilities.scss` (new, optional — omit if DataViews'
>   built-in layout is sufficient for the count header)
>
> Webpack delta — add ONE line under the existing `entry:` map, next
> to the F015 `js/access-control` line (currently
> `webpack.config.js:82-86`):
> ```js
> 'js/abilities': path.resolve( process.cwd(), 'src/js', 'abilities.js' ),
> ```
>
> `src/js/abilities.js` shape:
>
> ```js
> import { render, createElement, useState, useEffect, useMemo } from '@wordpress/element';
> import apiFetch from '@wordpress/api-fetch';
> import { __, _n, sprintf } from '@wordpress/i18n';
> import { DataViews } from '@wordpress/dataviews';
> import { ToggleControl, Notice, Spinner } from '@wordpress/components';
> import { applyFilters } from '@wordpress/hooks';
>
> // Optional SCSS — bundle the count header layout with this entry.
> // import '../scss/abilities.scss';
>
> ( function () {
>     const mount = document.getElementById( 'acrossai-mcp-abilities-root' );
>     if ( ! mount ) {
>         return;
>     }
>
>     const config = window.acrossaiMcpAbilities || {};
>     if ( ! config.serverId || ! config.namespace ) {
>         mount.textContent = __(
>             'Abilities app cannot boot — missing serverId or namespace.',
>             'acrossai-mcp-manager'
>         );
>         return;
>     }
>
>     if ( config.nonce ) {
>         apiFetch.use( apiFetch.createNonceMiddleware( config.nonce ) );
>     }
>
>     function App() {
>         const [ items, setItems ] = useState( [] );
>         const [ loading, setLoading ] = useState( true );
>         const [ error, setError ] = useState( null );
>         const [ view, setView ] = useState( {
>             type: 'table',
>             search: '',
>             filters: [],
>             sort: { field: 'label', direction: 'asc' },
>             perPage: 50,
>             page: 1,
>         } );
>
>         const path = `/${ config.namespace }/servers/${ config.serverId }/abilities`;
>
>         useEffect( () => {
>             setLoading( true );
>             apiFetch( { path } )
>                 .then( ( res ) => {
>                     setItems( res.abilities || [] );
>                     setError( null );
>                 } )
>                 .catch( ( e ) => setError( e.message ) )
>                 .finally( () => setLoading( false ) );
>         }, [ path ] );
>
>         const filterContext = { serverId: config.serverId, serverSlug: config.serverSlug };
>
>         // FR-029: wrap third-party filter callbacks in a try/catch so a
>         // buggy consumer never white-screens the core tab.
>         function safeApplyFilters( name, value ) {
>             try {
>                 const out = applyFilters( name, value, filterContext );
>                 if ( name.endsWith( '.fields' ) || name.endsWith( '.actions' ) ) {
>                     return Array.isArray( out ) ? out : value;
>                 }
>                 return out && typeof out === 'object' ? out : value;
>             } catch ( err ) {
>                 // eslint-disable-next-line no-console
>                 console.error( `[acrossai-mcp-manager] filter "${ name }" threw:`, err );
>                 return value;
>             }
>         }
>
>         // FR-026: per-row filter applied inside the DataViews render loop
>         // so extensions can inject extra keys the fields' render() reads.
>         function decorateRow( item ) {
>             return safeApplyFilters( 'acrossaiMcpManager.abilities.row', item );
>         }
>
>         const decoratedItems = useMemo(
>             () => items.map( decorateRow ),
>             [ items ]
>         );
>
>         const fields = useMemo( () => ( [
>             { id: 'slug', label: __( 'Ability Name', 'acrossai-mcp-manager' ),
>               enableGlobalSearch: true, render: ( { item } ) =>
>                 createElement( 'code', {}, item.slug ) },
>             { id: 'label', label: __( 'Label', 'acrossai-mcp-manager' ),
>               enableGlobalSearch: true },
>             { id: 'type', label: __( 'Type', 'acrossai-mcp-manager' ),
>               elements: [
>                 { value: 'tool', label: __( 'Tool', 'acrossai-mcp-manager' ) },
>                 { value: 'prompt', label: __( 'Prompt', 'acrossai-mcp-manager' ) },
>                 { value: 'resource', label: __( 'Resource', 'acrossai-mcp-manager' ) },
>               ], filterBy: { operators: [ 'is' ] } },
>             { id: 'category', label: __( 'Category', 'acrossai-mcp-manager' ),
>               getValue: ( { item } ) => item.category,
>               filterBy: { operators: [ 'is' ] } },
>             { id: 'description', label: __( 'Description', 'acrossai-mcp-manager' ),
>               enableGlobalSearch: true },
>             { id: 'is_exposed', label: __( 'Exposed', 'acrossai-mcp-manager' ),
>               enableSorting: false, enableHiding: false,
>               render: ( { item } ) => createElement( ToggleControl, {
>                 __nextHasNoMarginBottom: true,
>                 checked: !! item.is_exposed,
>                 onChange: ( next ) => saveOne( item.slug, next ),
>                 'aria-label': sprintf(
>                     /* translators: %s: ability slug */
>                     __( 'Toggle exposure for %s', 'acrossai-mcp-manager' ),
>                     item.slug
>                 ),
>               } ) },
>         ] ), [ items ] );
>
>         const actions = useMemo( () => ( [
>             { id: 'expose', label: __( 'Expose selected', 'acrossai-mcp-manager' ),
>               supportsBulk: true,
>               callback: ( selected ) => saveMany( selected, true ) },
>             { id: 'hide', label: __( 'Hide selected', 'acrossai-mcp-manager' ),
>               supportsBulk: true,
>               callback: ( selected ) => saveMany( selected, false ) },
>         ] ), [ items ] );
>
>         function saveMany( selectedItems, isExposed ) {
>             const abilities = selectedItems.map( ( it ) => ( {
>                 slug: it.slug, is_exposed: isExposed,
>             } ) );
>             return apiFetch( { path, method: 'POST', data: { abilities } } )
>                 .then( ( res ) => setItems( res.abilities || [] ) )
>                 .catch( ( e ) => setError( e.message ) );
>         }
>
>         function saveOne( slug, isExposed ) {
>             return saveMany( [ { slug } ], isExposed );
>         }
>
>         const exposedCount = items.filter( ( i ) => i.is_exposed ).length;
>         const header = createElement(
>             'p', { className: 'description' },
>             sprintf(
>                 /* translators: 1: exposed count, 2: total count */
>                 _n(
>                     '%1$d of %2$d ability exposed on this server.',
>                     '%1$d of %2$d abilities exposed on this server.',
>                     items.length,
>                     'acrossai-mcp-manager'
>                 ),
>                 exposedCount,
>                 items.length
>             )
>         );
>
>         if ( loading ) {
>             return createElement( Spinner );
>         }
>         if ( error ) {
>             return createElement( Notice,
>                 { status: 'error', isDismissible: false }, error );
>         }
>         // FR-026: extensions may append columns / actions. Built-in fields
>         // and actions are re-asserted from `builtinFields` / `builtinActions`
>         // — the safeApplyFilters return is discarded if it drops or renames
>         // any of the built-in ids (invariant enforced by shallow filter below).
>         const finalFields = useMemo( () => {
>             const extra = safeApplyFilters( 'acrossaiMcpManager.abilities.fields', fields );
>             const builtinIds = new Set( fields.map( ( f ) => f.id ) );
>             const additions = extra.filter( ( f ) => f && ! builtinIds.has( f.id ) );
>             return [ ...fields, ...additions ];
>         }, [ fields ] );
>
>         const finalActions = useMemo( () => {
>             const extra = safeApplyFilters( 'acrossaiMcpManager.abilities.actions', actions );
>             const builtinIds = new Set( actions.map( ( a ) => a.id ) );
>             const additions = extra.filter( ( a ) => a && ! builtinIds.has( a.id ) );
>             return [ ...actions, ...additions ];
>         }, [ actions ] );
>
>         return createElement( 'div', {},
>             header,
>             createElement( DataViews, {
>                 data: decoratedItems,
>                 fields: finalFields,
>                 view,
>                 onChangeView: setView,
>                 actions: finalActions,
>                 defaultLayouts: { table: {} },
>                 getItemId: ( item ) => item.slug,
>                 paginationInfo: { totalItems: decoratedItems.length, totalPages: 1 },
>             } )
>         );
>     }
>
>     render( createElement( App ), mount );
> } )();
> ```
>
> No generic React libraries: no `react-query`, `redux`, `mobx`,
> `react-table`, MUI, styled-components. Every import above is
> `@wordpress/*`.
>
> ---
>
> **TASK-7 — Admin\Main enqueue guard**
>
> File: `admin/Main.php`
>
> Add a private `maybe_enqueue_abilities_app()` method mirroring
> `maybe_enqueue_access_control_app()` (currently lines 136–199):
> - Guard `?action=edit` + `?tab=abilities` via
>   `sanitize_key( wp_unslash( $_GET[…] ) )` — same phpcs pragma
>   comments as the F015 method (read-only routing check).
> - Read `build/js/abilities.asset.php` via
>   `$this->read_asset_manifest( 'build/js/abilities.asset.php' )`;
>   bail silently on null (FR-019 pattern).
> - `$handle = $this->plugin_name . '-abilities';`
> - `wp_enqueue_script( $handle, ACROSSAI_MCP_MANAGER_PLUGIN_URL .
>   'build/js/abilities.js', $asset['dependencies'], $asset['version'],
>   true );`
> - If `build/js/abilities.css` exists (webpack emits when SCSS is
>   imported), enqueue with the same handle.
> - Look up the server row via `MCPServer\Query::instance()` (same
>   pattern as F015 `maybe_enqueue_access_control_app`) to get
>   `server_slug`.
> - `wp_localize_script( $handle, 'acrossaiMcpAbilities', array(
>     'serverId'    => $server_id,
>     'serverSlug'  => $server_slug,
>     'restApiRoot' => esc_url_raw( untrailingslashit( rest_url() ) ),
>     'nonce'       => wp_create_nonce( 'wp_rest' ),
>     'namespace'   => 'acrossai-mcp-manager/v1',
>   ) );`
> - Call from `enqueue_scripts()` immediately after
>   `$this->maybe_enqueue_access_control_app();` (currently
>   `admin/Main.php:127`).
>
> ---
>
> **TASK-8 — Package + docs + memory hygiene**
>
> Files:
> - `package.json`
> - `README.txt`
> - `docs/memory/DECISIONS.md`
> - `docs/memory/WORKLOG.md`
> - `docs/memory/INDEX.md`
> - `docs/planings-tasks/README.md`
>
> `package.json` — ensure the following are declared under
> `"dependencies"` (add if missing):
> - `@wordpress/dataviews`
> - `@wordpress/components`
> - `@wordpress/api-fetch`
> - `@wordpress/element`
> - `@wordpress/i18n`
> - `@wordpress/hooks` — powers the extensibility surface (`applyFilters` / `addFilter`) that companion plugins register against; declaring the import ensures the asset manifest lists `wp-hooks` so consumers can rely on it being enqueued.
>
> `@wordpress/scripts` externalizes these via the asset manifest so
> the runtime bundle stays small — the packages only need to be
> present at build time.
>
> `README.txt` — add an Unreleased changelog bullet:
> ```
> * New — Per-server ability selection. The Abilities tab on each
>   MCP server is now interactive: admins pick which registered
>   abilities the server exposes, with search, category + type
>   filters, sortable columns, bulk Expose / Hide actions, and a
>   per-row toggle. Backed by a new
>   `{prefix}acrossai_mcp_server_abilities` table (BerlinDB, with
>   the phantom-version self-heal guard). Backwards-compatible —
>   servers with no explicit selection continue to expose abilities
>   whose `meta[mcp][public]` is true.
> ```
>
> `docs/memory/DECISIONS.md` — add two Active entries:
>
> - **DEC-ABILITY-OVERRIDE-RESOLUTION (Active — Feature 017)**:
>   Effective ability exposure per (server, ability) is:
>   row-in-`acrossai_mcp_server_abilities`.`is_exposed` if a row
>   exists, else `meta[mcp][public]` from the ability's registered
>   metadata. The single-source-of-truth resolver is
>   `Includes\Database\MCPServerAbility\ExposureResolver::resolve()`;
>   every caller of the F013 partition helpers must be routed
>   through it.
>
>   **F082 forward-pointer (2026-09-05)**: F082 extends this decision.
>   The row-only method here (`resolve()`) is now the second tier of a
>   three-tier resolution: row → server-level policy → `meta.mcp.public`.
>   `ExposureResolver::resolve_effective()` (new sibling) implements
>   the full three-tier path and is what every advertisement-time and
>   call-time consumer uses. The row-only method is **renamed** to
>   `resolve_row_only()` per SEC-001 Option A — the row-only semantics
>   now live in the method name itself so the F030 permission-callback
>   bypass invariant is grep-visible and fail-loud on any future rename.
>   `resolve_row_only()` is the sole method F030's
>   `PermissionOverrideProcessor::should_bypass()` uses. See D51
>   `DEC-SERVER-DEFAULT-POLICY-OVER-SNAPSHOT-ENROLMENT` (to be captured
>   post-implementation) + `docs/planings-tasks/082-per-server-ability-policy-defaults.md`.
>
> - **DEC-WP-DATAVIEWS-OVER-REACT (Active — Feature 017)**: New
>   admin JS surfaces use `@wordpress/dataviews` +
>   `@wordpress/components` instead of custom React table libraries
>   or third-party grids (`react-query`, `redux`, `mobx`,
>   `react-table`, MUI, styled-components are all forbidden for new
>   admin entries). Rationale: `@wordpress/scripts` externalizes WP
>   packages, keeps bundle small, and matches core admin UI. Applies
>   to every future admin tabular surface this plugin adds.
>
> `docs/memory/WORKLOG.md` — add a Feature 017 milestone entry (Why
> durable / Future mistake prevented / Evidence / Where to look).
> Highlight the durable lesson: **per-resource overrides that fall
> back to a global default belong in a shared resolver, not
> duplicated across every consumer.**
>
> `docs/memory/INDEX.md` — new rows under Active Decisions for the
> two DEC-* above, plus a WORKLOG row for Feature 017.
>
> `docs/planings-tasks/README.md` — append a row for
> `017-per-server-ability-selection.md`.
>
> ---
>
> **TASK-9 — Extensibility surface + developer docs**
>
> Files:
> - `src/js/abilities.js` (delta only — three `safeApplyFilters()`
>   call sites + one row-decorator, per the TASK-6 snippet)
> - `includes/REST/AbilitiesController.php` (delta only — one
>   `apply_filters( 'acrossai_mcp_ability_row', ... )` inside the
>   per-ability loop of `get_abilities()`, per the TASK-4 snippet)
> - `docs/extending-abilities-tab.md` (new)
> - Optional example under `docs/examples/abilities-extension-plugin/`
>   (skip if the operator prefers a leaner doc — flagged in the
>   Manual Verification Checklist below).
>
> This task exists so the extensibility contract from FR-026..029 is
> reviewed as a first-class deliverable, not a side-effect of TASK-4
> and TASK-6. Grep-gate: every filter name listed in the contract
> table above MUST appear in exactly one source file (JS filter names
> in `src/js/abilities.js`; PHP filter name in
> `includes/REST/AbilitiesController.php`). Any drift is a defect.
>
> `docs/extending-abilities-tab.md` — a companion-plugin author's
> guide. Sections:
> - **What you get out of the box** — one sentence per built-in
>   column and per built-in bulk action.
> - **Client filters** — the three JS filter names + signatures + a
>   worked example of adding an "Action" column with an "Edit"
>   button, mirroring User Story 6. Include the required JS enqueue
>   pattern: declare `acrossai-mcp-manager-abilities` as a
>   dependency so the filter registration lands after the abilities
>   app boots, OR register on `wp.domReady` if the plugin enqueues
>   earlier.
> - **Server filter** — the `acrossai_mcp_ability_row` PHP filter
>   name + signature + a worked example.
> - **Invariants** — the built-in field/action id set that
>   extensions may NOT redefine; the safe-boundary rules (thrown
>   filters degrade to core rendering); the effective-change action
>   `acrossai_mcp_ability_exposure_changed`.
> - **Cross-plugin coordination** — link back to
>   `[[DEC-CLIENT-RENDERER-PUBLIC-API]]`'s `@experimental` policy so
>   companion plugins know these hook names are stable and covered
>   by the F013 public-API contract shape.
>
> ---
>
> **TASK-10 — Call-time enforcement at `mcp_adapter_pre_tool_call`**
>
> Files:
> - `includes/REST/AbilitiesController.php` OR a new class
>   `includes/MCP/AbilityExposureGate.php` (final decision at
>   implementation-time; put the callback where it best fits the A11
>   pure-service exception).
> - `includes/Main.php::define_admin_hooks()` (delta only — one
>   `$this->loader->add_filter( 'mcp_adapter_pre_tool_call', $gate,
>   'gate_tool_call_by_exposure', 20, 4 );` line, immediately after
>   the F015 wiring on line 373).
>
> Class shape (adopt the F015 `gate_mcp_tool_call` signature
> verbatim — same filter, same arg count):
>
> ```php
> public function gate_tool_call_by_exposure(
>     $result,       // WP_Error | mixed — already-set by earlier priorities
>     array $args,
>     string $tool_name,
>     $mcp_tool      // vendor tool object; server surfaces via ->get_server() or similar
> ) {
>     // If an earlier callback (F015) already denied, propagate the
>     // deny — never override with an allow.
>     if ( is_wp_error( $result ) ) {
>         return $result;
>     }
>
>     // Resolve the server slug from the tool's containing MCP server.
>     // Exact accessor depends on the vendor adapter — read
>     // vendor/wordpress/mcp-adapter for the current shape; F015 does
>     // the same lookup at gate_mcp_tool_call time.
>     $server_id = /* lookup via $mcp_tool->get_server() or $args */;
>     if ( ! $server_id ) {
>         // Cannot resolve server → fail-open (do NOT block) matches
>         // F015 fail-open observability (D19).
>         return $result;
>     }
>
>     // Ability slug is $tool_name in vendor mcp-adapter's contract.
>     $ability = wp_get_ability( $tool_name );
>     if ( ! $ability ) {
>         return $result; // Nothing to enforce.
>     }
>
>     $meta       = $ability->get_meta();
>     $is_exposed = \AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\ExposureResolver::resolve(
>         (int) $server_id, (string) $tool_name, $meta
>     );
>
>     if ( ! $is_exposed ) {
>         return new \WP_Error(
>             'acrossai_mcp_ability_not_exposed',
>             __( 'This ability is not exposed on this MCP server.', 'acrossai-mcp-manager' ),
>             array( 'status' => 403 )
>         );
>     }
>
>     return $result;
> }
> ```
>
> Priority contract: **F015 = 10, F017 = 20.** Do NOT wire at
> priority 10 — the call ordering with F015 must be deterministic.
>
> Do NOT wire on `wp_get_abilities` for list-time hiding in F017 —
> that's deferred to a follow-up feature. `wp_get_abilities` is a
> global getter without server context; filtering it here would
> either need a per-request context stash (fragile) or break other
> plugins that call it outside an MCP request. Confirm the vendor
> mcp-adapter's per-server ability-collection hook in the follow-up
> feature's research phase.
>
> Fail-open on ambiguity: if we cannot resolve `$server_id` from
> the vendor tool object, do NOT block the call — return the input
> `$result` unchanged. Matches D19 fail-open observability
> convention.
>
> ---
>
> **CONSTRAINTS**
>
> - **Do not rename `acrossai_mcp_server_abilities`.** The table
>   name is the backwards-compatibility contract with any prior
>   install's data starting from this merge.
> - **Do not rename `acrossai_mcp_server_abilities_db_version`.**
>   Same rationale — any rename triggers a phantom fresh install on
>   otherwise healthy sites.
> - **Do not introduce generic React libraries** — `react-query`,
>   `@tanstack/*`, `redux`, `mobx`, `react-table`, MUI, and
>   styled-components are FORBIDDEN for this entry. Every import
>   must be `@wordpress/*` (or a WP-scoped subpath).
> - **Do not skip the phantom-version guard.** Same silent
>   `if ( ! $this->exists() ) delete_option( ... );` shape as F011.
> - **Do not seed rows on activation.** The empty-table state IS
>   the correct backwards-compatible initial state.
> - **Do not accept ability slugs the current install doesn't
>   register** on POST — reject the whole batch with 400. This
>   prevents unbounded row growth if a plugin unregisters an
>   ability between the client's GET and its POST.
> - **Do not add any `add_action` inside class constructors.** All
>   wiring flows through `$this->loader->add_action()` in
>   `includes/Main.php` (A1).
> - **Do not `__return_true` on any REST permission_callback.**
>   Both routes gate on `current_user_can( 'manage_options' )`.
> - **REST namespace is `acrossai-mcp-manager/v1` — do not
>   shorten** (constitution key rule 9).
> - **Do not touch F015 Access Control code paths** — this feature
>   is orthogonal to access-control rules.
> - **Do not touch the AbstractServerTab template method
>   contract** — only AbilitiesTab's `render_body()` body changes.
> - **Do not exceed one webpack entry.** Everything ships as
>   `build/js/abilities.js` (+ optional `.css`). No secondary chunk
>   imports.
> - **PHPStan level 8 + PHPCS must remain green per task.**
>   Constitution §VII per-task gating applies.
> - **Grep after every task**: `grep -rEn
>   'react-query|@tanstack|redux|mobx|react-table' src/js/` MUST
>   return zero matches (WP-packages-only invariant).
> - **Do not rename the filter names** listed in the contract map
>   after this feature merges — `acrossaiMcpManager.abilities.fields`,
>   `acrossaiMcpManager.abilities.actions`,
>   `acrossaiMcpManager.abilities.row`, and
>   `acrossai_mcp_ability_row` are the extensibility contract with
>   every companion plugin. Renames require a deprecation window
>   and a migration note in `docs/extending-abilities-tab.md`.
> - **Do not let a third-party filter drop or overwrite built-in
>   fields / actions / row keys.** The core column set
>   (`slug`, `label`, `type`, `category`, `description`, `is_exposed`,
>   `has_override`) is re-asserted after every filter fires; a
>   filter that returns a shorter array or replaces a built-in
>   entry silently has its removals ignored.
> - **Do not white-screen the tab when a third-party filter
>   throws.** Every filter call site MUST be wrapped in a defensive
>   boundary that logs `console.error` and falls back to the
>   last-known-good value.
> - **Do not wire the enforcement callback at priority ≤ 10.**
>   F015's `gate_mcp_tool_call` runs at priority 10; F017 MUST run
>   LATER (priority 20) so a hidden-on-this-server decision
>   supersedes any AccessControl "allow." Wiring at ≤ 10 makes the
>   two features race and produces inconsistent enforcement.
> - **Do not override an F015 deny with an F017 allow.** The
>   enforcement callback MUST short-circuit with `return $result;`
>   when the incoming `$result` is already a `WP_Error`. F017 only
>   ADDS denials; it never removes them.
> - **Do not add list-time hiding in F017.** Filtering
>   `wp_get_abilities()` globally would break non-MCP consumers.
>   Deferred to a follow-up once the vendor `mcp-adapter` per-server
>   ability-collection hook is confirmed.

```markdown
# 3. Plan + guard + security
/speckit.memory-md.plan-with-memory
/speckit.architecture-guard.governed-plan
/speckit.security-review.plan

# 4. Tasks + guard
/speckit.tasks
/speckit.architecture-guard.governed-tasks

# 5. Implement + quality checks
/speckit.architecture-guard.governed-implement
composer dump-autoload
composer run phpcs
composer run phpstan
npm run build
npm run lint:js

# 6. Review + memory + commit
/speckit.analyze
/speckit.architecture-guard.architecture-review
/speckit.security-review.staged
/speckit.memory-md.capture-from-diff
/speckit.git.commit
```

---

## Manual Verification Checklist

### TASK-1 — MCPServerAbility BerlinDB module
- [ ] All four class files exist under `includes/Database/MCPServerAbility/`
      and extend the corresponding `\BerlinDB\Database\Kern\*` class.
- [ ] `Query::upsert()` inserts a row when absent, updates when
      present; returns `true` on success.
- [ ] `wp db query "SHOW CREATE TABLE wp_acrossai_mcp_server_abilities"`
      output preserves `PRIMARY KEY (id)`,
      `UNIQUE KEY server_ability (server_id, ability_slug)`, and
      `KEY server_id (server_id)`.
- [ ] `wp option get acrossai_mcp_server_abilities_db_version` returns
      `1.0.0` after fresh activation.

### TASK-2 — Activation + request-time boot
- [ ] Fresh activation on a clean install creates
      `wp_acrossai_mcp_server_abilities`.
- [ ] Phantom-version test: drop the table with the option intact
      (`wp db query "DROP TABLE wp_acrossai_mcp_server_abilities"`;
      confirm `wp option get acrossai_mcp_server_abilities_db_version`
      still returns `1.0.0`), reactivate the plugin via WP admin, then
      confirm the table is back. `tail -50 wp-content/debug.log | grep
      -i acrossai` must be empty (silent guard invariant).
- [ ] Admin request-path check: visit
      `/wp-admin/admin.php?page=acrossai_mcp_manager` — no `Table …
      doesn't exist` error, MCP Server list renders normally.

### TASK-3 — ExposureResolver
- [ ] Given a server with an `is_exposed=1` row for slug X → resolver
      returns `true` regardless of `meta[mcp][public]`.
- [ ] Given a server with an `is_exposed=0` row for slug Y → resolver
      returns `false` regardless of `meta[mcp][public]`.
- [ ] Given a server with NO row for slug Z → resolver returns
      `(bool) meta[mcp][public]`.
- [ ] Second call in the same request returns the cached value (no
      duplicate `Query::instance()->query()` — verify via
      `wp shell` or Xdebug).

### TASK-4 — REST AbilitiesController
- [ ] `curl -H 'X-WP-Nonce: <nonce>'
      http://wordpress-7-0.local/wp-json/acrossai-mcp-manager/v1/servers/1/abilities`
      returns 200 with `{ has_abilities_api: true, abilities: [ ... ] }`.
- [ ] Unauthenticated GET → 403.
- [ ] `POST` with an invalid slug → 400 with an
      `acrossai_mcp_invalid_payload` code.
- [ ] `POST` with a valid batch → 200 + refreshed list; matching
      rows appear in `wp_acrossai_mcp_server_abilities`.
- [ ] Non-existent `server_id` → 404 with an
      `acrossai_mcp_server_not_found` code.

### TASK-5 — AbilitiesTab guards preserved
- [ ] Disabled server → warning notice text unchanged from pre-017.
- [ ] `wp_get_abilities()` absent (Abilities API not installed) →
      warning notice text unchanged.
- [ ] Enabled + API available → mount div `<div
      id="acrossai-mcp-abilities-root">` renders and the React app
      boots.
- [ ] `grep -rEn 'partition_abilities|render_public_table|render_private_table'
      admin/Partials/ServerTabs/AbilitiesTab.php` returns zero
      matches.

### TASK-6 — React app + webpack entry
- [ ] `npm run build` succeeds; `build/js/abilities.js` and
      `build/js/abilities.asset.php` exist.
- [ ] Mockup 1A: search filters slug / label / description live.
- [ ] Category + type filter dropdowns filter rows.
- [ ] Column headers toggle sort asc/desc and re-order rows.
- [ ] Per-row `ToggleControl` flips exposure and persists across a
      full-page reload.
- [ ] Bulk actions Expose / Hide selected update all selected rows
      in one request.
- [ ] Live "N of M exposed" header updates as toggles fire.
- [ ] `_n()` pluralization renders correctly (test with 1 exposed
      and with ≥2 exposed).
- [ ] `wp-content/debug.log` and browser console are clean during
      interaction.

### TASK-7 — Enqueue scope
- [ ] Only the Abilities tab enqueues `build/js/abilities.js` —
      verified via DOM/Network on Overview + Access Control tabs
      (script tag absent).
- [ ] `window.acrossaiMcpAbilities` is populated on the Abilities
      tab; `undefined` on other tabs.

### TASK-8 — Docs + memory hygiene
- [ ] `README.txt` Unreleased bullet present.
- [ ] Both `DEC-ABILITY-OVERRIDE-RESOLUTION` and
      `DEC-WP-DATAVIEWS-OVER-REACT` rows appear in
      `docs/memory/DECISIONS.md` and are indexed in
      `docs/memory/INDEX.md` under Active Decisions.

### TASK-9 — Extensibility surface
- [ ] `grep -rEn 'acrossaiMcpManager\.abilities\.(fields|actions|row)' src/js/`
      returns three call sites — one per filter name.
- [ ] `grep -rEn 'acrossai_mcp_ability_row' includes/REST/`
      returns exactly one `apply_filters(...)` call.
- [ ] Companion-plugin smoke test — with a small helper plugin that
      adds an "Action" column with an "Edit" button per User Story 6
      §Independent Test, the new column appears in the tab and the
      built-in columns are unchanged. Removing the helper plugin
      restores the original column set.
- [ ] Failure smoke test — with a helper plugin whose JS filter
      throws (`addFilter( 'acrossaiMcpManager.abilities.fields',
      'test/throws', () => { throw new Error( 'boom' ); } )`), the
      tab still renders the built-in columns and the browser console
      shows exactly one `[acrossai-mcp-manager] filter "..." threw:`
      line. No white-screen.
- [ ] Invariant test — with a helper plugin whose JS filter tries to
      remove or overwrite a built-in field (`return fields.filter(
      ( f ) => f.id !== 'is_exposed' );`), the built-in `is_exposed`
      column is still visible after the tab renders. Same for
      built-in actions and built-in row keys.
- [ ] `docs/extending-abilities-tab.md` renders correctly on GitHub
      and contains a worked "Edit button" example whose code is
      copy-paste-runnable.

### TASK-10 — Call-time enforcement
- [ ] `includes/Main.php` shows the F017 `mcp_adapter_pre_tool_call`
      wiring at priority 20, immediately after the F015 wiring at
      priority 10.
- [ ] Manual smoke test — with an enabled server, an admin who toggles
      an ability's `is_exposed=0` and then attempts to invoke that
      ability from a connected AI client (e.g. Claude Desktop with
      Application-Password auth) sees a **403** MCP error surfaced by
      the vendor adapter with the message from
      `acrossai_mcp_ability_not_exposed`.
- [ ] Pre-toggle behavior — with NO rows in `wp_acrossai_mcp_server_abilities`,
      the same client invocation of an ability whose `meta[mcp][public]`
      is truthy MUST succeed (fallback still returns `true` from the
      resolver). This is the FR-007 "zero visible change" invariant.
- [ ] Deny-precedence test — with an F015 AccessControl rule that
      DENIES access to `core/get-user-info` AND an F017 row that says
      `is_exposed=1`, the client MUST receive F015's deny (F017's
      later-priority callback preserves the earlier deny).
- [ ] Fail-open test — if the callback cannot resolve `$server_id`
      from the vendor tool object (simulate by passing an unrecognized
      `$mcp_tool`), the callback MUST return the input `$result`
      unchanged. `wp-content/debug.log` shows no fatal.
- [ ] `grep -rEn 'mcp_adapter_pre_tool_call' includes/`  returns TWO
      matches — one for F015's callback (priority 10) and one for
      F017's (priority 20).
- [ ] `docs/memory/WORKLOG.md`: Feature 017 milestone entry
      present.
- [ ] `docs/planings-tasks/README.md` Feature Specs table lists row
      017.

### Final full-repo audit (blocker before merge)

```bash
grep -rEn 'react-query|@tanstack|redux|mobx|react-table|styled-components|@mui/' \
    --include='*.js' \
    --include='*.jsx' \
    --include='*.ts' \
    --include='*.tsx' \
    src/js/
```

- [ ] Grep returns **zero matches** — WP-packages-only invariant
      holds.

```bash
grep -rEn 'partition_abilities|render_public_table|render_private_table' \
    --include='*.php' \
    includes/ admin/ public/
```

- [ ] Grep returns **zero matches** — legacy PHP partition helpers
      fully retired.

### Quality gates (all must be green before commit)
- [ ] PHPStan level 8 — zero errors on
      `includes/Database/MCPServerAbility/**`,
      `includes/REST/AbilitiesController.php`,
      `admin/Partials/ServerTabs/AbilitiesTab.php`, and
      `admin/Main.php`.
- [ ] PHPCS — zero errors on the same files.
- [ ] `npm run build` — succeeds with `build/js/abilities.js` +
      `build/js/abilities.asset.php` emitted (and
      `build/js/abilities.css` if SCSS is imported).
- [ ] `npm run lint:js` — zero errors on `src/js/abilities.js`.
- [ ] Composer autoload refresh — succeeds with zero warnings.
- [ ] `SHOW TABLES LIKE 'wp_acrossai_mcp_%'` on a clean install
      returns exactly three rows: `wp_acrossai_mcp_servers`,
      `wp_acrossai_mcp_cli_auth_logs`,
      `wp_acrossai_mcp_server_abilities`.
- [ ] `SELECT option_name, option_value FROM wp_options WHERE
      option_name LIKE 'acrossai_mcp%_db_version'` returns the
      Feature 017 row `acrossai_mcp_server_abilities_db_version →
      1.0.0` alongside the existing F011 rows.
