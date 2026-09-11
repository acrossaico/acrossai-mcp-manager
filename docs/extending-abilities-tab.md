# Extending the Abilities Tab

**Feature 017** ships the per-server Abilities tab (`?page=acrossai_mcp_manager&action=edit&server=<id>&tab=abilities`) as an extension point. Companion plugins can add columns, per-row actions, and richer data — using only WordPress-standard hooks (`wp.hooks` on the JS side, `apply_filters()` on the PHP side). No custom `window.acrossaiMcpAbilities.register(...)` API exists — every extension point is the same shape you'd use elsewhere in WordPress core.

**Stability marker**: every hook name below carries `@since 0.1.0 @experimental May change without notice before 1.0.0` on its docblock. Promotion to semver-stable happens at the plugin's 1.0.0 tag, with a deprecation cycle. This matches the F013 [`DEC-CLIENT-RENDERER-PUBLIC-API`] precedent.

---

## What you get out of the box

- **Columns** (`slug`, `label`, `type`, `category`, `description`, `is_exposed`) — cannot be removed or overwritten.
- **Bulk actions** — `Expose selected`, `Hide selected` — cannot be removed or overwritten.
- **Filter dropdowns** — category + type — populated from the built-in `elements` list.
- **Sorting** — every column except `is_exposed` sorts asc/desc on header click.
- **Live counter** — "N of M exposed" above the table.

## Client-side extensibility surface

All three filters run per-render. The filter *context* argument is `{ serverId, serverSlug }` so callbacks that need per-server logic can branch on it.

### `acrossaiMcpManager.abilities.fields` — add columns

```js
import { addFilter } from '@wordpress/hooks';
import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

addFilter(
    'acrossaiMcpManager.abilities.fields',
    'my-plugin/action-column',
    ( fields, { serverId } ) => [
        ...fields,
        {
            id: 'my_action',                        // MUST be unique
            label: __( 'Action', 'my-plugin' ),
            enableSorting: false,
            enableHiding: false,
            render: ( { item } ) => createElement(
                'button',
                {
                    className: 'button',
                    onClick: () => openEditModal( item.slug, serverId ),
                },
                __( 'Edit', 'my-plugin' )
            ),
        },
    ]
);
```

**Invariants**:
- Extensions MAY add new field entries. They MUST NOT redefine, remove, or overwrite any of the built-in ids (`slug`, `label`, `type`, `category`, `description`, `is_exposed`). Attempts to do so are silently dropped by the additive-only merge reducer.
- A callback that throws is caught by `safeApplyFilters`, logs one `console.error`, and falls back to the pre-filter fields array. Your column disappears; the built-ins keep rendering.

### `acrossaiMcpManager.abilities.actions` — add bulk actions

```js
addFilter(
    'acrossaiMcpManager.abilities.actions',
    'my-plugin/audit-action',
    ( actions ) => [
        ...actions,
        {
            id: 'audit',
            label: __( 'Audit selected', 'my-plugin' ),
            supportsBulk: true,
            callback: ( selectedItems ) => sendAudit( selectedItems.map( ( i ) => i.slug ) ),
        },
    ]
);
```

Built-in action ids `expose`, `hide` are reserved.

### `acrossaiMcpManager.abilities.row` — decorate rows

Useful when your `render` callback needs per-row data beyond the seven built-in keys. This filter runs once per row per render pass — keep it O(1).

```js
addFilter(
    'acrossaiMcpManager.abilities.row',
    'my-plugin/tag-editable',
    ( item ) => ( {
        ...item,
        my_editable: /^my-plugin\//.test( item.slug ),
    } )
);
```

The built-in seven keys (`slug`, `label`, `type`, `category`, `description`, `is_exposed`, `has_override`) survive any JS row filter — the core plugin re-asserts them server-side via `array_merge( $filtered, $row )`, and even a JS override affects only the current render.

## Server-side extensibility surface

### `acrossai_mcp_ability_row` — add row data server-side

Callbacks fire **once per registered ability on every GET** to the per-server abilities endpoint. Extensions add keys the client-side column `render` callbacks can read.

```php
add_filter( 'acrossai_mcp_ability_row', function ( $row, $server_id, $ability ) {
    $row['my_editable'] = current_user_can( 'edit_abilities' );
    $row['my_owner']    = get_post_meta( ability_to_post_id( $ability ), 'owner', true );
    return $row;
}, 10, 3 );
```

**Signature**:
- `$row` — array with the seven built-in keys (`slug`, `label`, `type`, `category`, `description`, `is_exposed`, `has_override`) plus any keys prior callbacks added.
- `$server_id` — int, the MCP server id.
- `$ability` — `\WP_Ability` instance.

**Invariants**:
- Extensions MAY add new keys to `$row`. They MUST NOT overwrite any of the seven built-in keys — the controller re-asserts them via `array_merge( $filtered, $row )` after every callback fires.
- A callback that returns a non-array value has its return discarded and a `_doing_it_wrong()` notice is emitted. The unfiltered `$row` is used.
- A callback that throws — WordPress core surfaces the exception. Your extension is responsible for its own try/catch.

### `acrossai_mcp_manager_tool_abilities` — declare tool-level abilities

**Feature 087.** Not every registered ability is an operator-facing choice. A handful are *tool-level*: they are what an MCP server advertises in `tools/list`, and every other ability reaches a client through one of them. Two families exist today — the three `mcp-adapter/*` protocol tools, whose plugin-owned callbacks enumerate and run the ability catalogue, and the sibling `acrossai-abilities-manager` plugin's `toolset/*` dispatchers, each of which takes `action=discover|info|execute` and routes to the abilities in one `meta.acrossai.tab_group`.

One list drives two opposite behaviours:

| Surface | Effect |
| --- | --- |
| **Abilities tab** (`?tab=abilities`) | Hides every slug in the list. They're plumbing — a per-ability **Exposed** toggle on them either no-ops or breaks the protocol. The "N exposed" counter, the category/type dropdowns and bulk selection all follow, since they derive from the same filtered set. |
| **Tools tab** (`?tab=tools`) left "Available tools" pool | Shows **nothing but** these slugs, unioned with whatever is already curated on the server. |

The default is `ToolPolicy::PROTOCOL_TOOLS` — the three the vendored mcp-adapter registers. Nothing here hardcodes another plugin's vocabulary; a plugin declares its own:

```php
add_filter( 'acrossai_mcp_manager_tool_abilities', function ( array $slugs ): array {
    foreach ( wp_get_abilities() as $ability ) {
        $meta = $ability->get_meta();
        if ( ! empty( $meta['acrossai']['toolset'] ) ) {
            $slugs[] = $ability->get_name();
        }
    }
    return $slugs;
} );
```

**Signature**:
- `$slugs` — `string[]`, `ToolPolicy::PROTOCOL_TOOLS` plus whatever prior callbacks added.
- Return `string[]`. The resolver casts every entry with `strval()`, drops empty strings, de-duplicates, and re-indexes — a callback returning `null` or a scalar degrades to an empty list rather than fatalling.

**Invariants**:
- The filter **subtracts as well as adds**. Dropping one of the defaults puts it back on the Abilities tab and takes it out of the Tools pool.
- An ability **already curated** on a server keeps rendering in the Tools tab's "Added as tools" pane whether or not it's in this list, and stays removable. That union is deliberate: filtering it out of the client's state would make the next save delete it from `wp_acrossai_mcp_server_tools`. It just can't be re-added from the left pool once removed.
- On a site where nothing hooks the filter, the Tools pool holds exactly the three protocol tools. Individual abilities are not addable as tools from that screen unless a plugin declares them here — which matches how they actually reach clients (through the tool-level entries, not as direct `tools/list` entries; see [Extending per-server MCP tool lists §7](extending-server-tools.md)).
- **This is presentation only.** It changes neither exposure (F017/F082), curation (F020/F025), nor call-time enforcement at `mcp_adapter_pre_tool_call`. In particular it is *not* `MCP\ToolExposureGate::EXCLUDED_SLUGS`, which means "always callable" — an ability's membership here gates nothing.
- To put a tool-level ability on a server in PHP rather than by hand, use [`acrossai_mcp_manager_server_tools`](extending-server-tools.md).

Resolved by `Includes\Abilities\ToolAbilities::get_slugs()` and delivered to both React apps as `config.toolAbilities` via `wp_localize_script()`. PHP is the single source of truth; the slug literals in `src/js/abilities.js` and `src/js/tools.js` are boot-time fallbacks for a stale localized payload only.

### `acrossai_mcp_ability_exposure_changed` — audit hook

Fires after the REST POST endpoint upserts a `(server, ability)` row **and** the effective exposure value changed. Writes that leave the effective value unchanged do NOT fire the action.

```php
add_action( 'acrossai_mcp_ability_exposure_changed', function ( $server_id, $ability_slug, $was, $now, $user_id ) {
    error_log( sprintf( '[audit] user %d flipped %s on server %d: %s → %s',
        $user_id, $ability_slug, $server_id,
        $was ? 'exposed' : 'hidden',
        $now ? 'exposed' : 'hidden'
    ) );
}, 10, 5 );
```

**Concurrency caveat (SEC-004)**: under concurrent writes to the same `(server, ability)` pair, `$was` reflects the value the resolver returned at the *beginning* of the writer's request — it may not match the actual pre-write DB state if another writer commits between our resolver read and our upsert. Subscribers building strict audit trails should consult the DB's `updated_at` column for authoritative ordering.

## Trust contract for extension data (SEC-002)

**Every value your extension adds to `$row` — or emits from a `render` callback — is untrusted at render time.** The core plugin does NOT sanitize your additions. Follow WordPress escaping conventions:

- **PHP side (`acrossai_mcp_ability_row`)**: Run any HTML your extension adds through `wp_kses_post()` or a more restrictive `wp_kses` allowlist BEFORE adding to `$row`. Cast numbers to `(int)` / `(float)`. Never store or pass secrets through the filter payload.
- **JS side (`render` callbacks)**: Prefer `createElement( 'span', {}, value )` (React's default child escaping is safe) over `dangerouslySetInnerHTML`. If you MUST use `dangerouslySetInnerHTML`, run the value through a whitelist sanitizer client-side.

Failure to escape leaves stored XSS on the operator screen. This is your extension's responsibility, not the core plugin's.

## Performance contract (SEC-005)

`acrossai_mcp_ability_row` fires **once per registered ability on every GET**. On a site with 100 registered abilities, that's 100 callbacks per admin page load. Callbacks MUST be O(1) with respect to network / disk / DB work.

If your extension needs external data, prefetch it once outside the callback (e.g. on `admin_init` or via a cached transient) and read from cache inside the callback:

```php
add_action( 'admin_init', function () {
    if ( ! ( isset( $_GET['tab'] ) && 'abilities' === $_GET['tab'] ) ) return;
    // Prefetch once per request; cache lookup is O(1).
    MyPlugin::warm_ability_metadata_cache();
} );

add_filter( 'acrossai_mcp_ability_row', function ( $row, $server_id, $ability ) {
    $row['my_owner'] = MyPlugin::get_cached_owner( $ability->get_name() );
    return $row;
}, 10, 3 );
```

## Error-message contract (SEC-006)

The client-side `safeApplyFilters` boundary catches every thrown callback and logs `[acrossai-mcp-manager] filter "<name>" threw: <raw error>` to the browser console. **Do not include secrets, API keys, PII, or internal implementation details** in errors your callbacks throw — they surface to anyone with a devtools console open, screenshots, or a screen-share.

Prefer opaque error identifiers:

```js
// GOOD
throw new Error( 'my-plugin: metadata fetch failed (code=E_META_042)' );

// BAD
throw new Error( `my-plugin: metadata fetch failed for token ${ apiKey }` );
```

## Cross-plugin coordination

Companion plugins that want to register filters MUST enqueue their JS after the abilities bundle. Two supported patterns:

### Pattern A — Declare a script dependency

```php
add_action( 'admin_enqueue_scripts', function () {
    if ( ! ( isset( $_GET['action'] ) && 'edit' === $_GET['action'] )
        || ! ( isset( $_GET['tab'] ) && 'abilities' === $_GET['tab'] ) ) {
        return;
    }
    wp_enqueue_script(
        'my-plugin-abilities-extension',
        plugins_url( 'assets/abilities-extension.js', __FILE__ ),
        array( 'acrossai-mcp-manager-abilities' ), // <-- KEY: depend on the core bundle
        MY_PLUGIN_VERSION,
        true
    );
} );
```

### Pattern B — Register on `wp.domReady`

```js
import { addFilter } from '@wordpress/hooks';
import domReady from '@wordpress/dom-ready';

domReady( () => {
    addFilter(
        'acrossaiMcpManager.abilities.fields',
        'my-plugin/columns',
        ( fields ) => [ ...fields, /* your columns */ ]
    );
} );
```

## Frozen public contract

Once Feature 017 merges, these identifiers become part of the plugin's public contract with companion plugins. Renames require a deprecation cycle documented in this doc.

| Identifier | Type | Notes |
|---|---|---|
| `acrossaiMcpManager.abilities.fields` | JS filter name | Additive-only |
| `acrossaiMcpManager.abilities.actions` | JS filter name | Additive-only |
| `acrossaiMcpManager.abilities.row` | JS filter name | Additive-only |
| `acrossai_mcp_ability_row` | PHP filter name | Additive-only; built-in keys re-asserted |
| `acrossai_mcp_ability_exposure_changed` | PHP action name | Fires only on effective change |
| `slug`, `label`, `type`, `category`, `description`, `is_exposed`, `has_override`, `has_abilities_api` | Row keys | Reserved — cannot be redefined |
| `expose`, `hide` | Action ids | Reserved |
| `acrossai-mcp-manager-abilities` | Script handle | Declare as dep from companion plugins |
| `window.acrossaiMcpAbilities` | Localized global | `{ serverId, serverSlug, restApiRoot, nonce, namespace }` |

## Verify your integration

### Smoke test — "Action" column with "Edit" button

Save this as a tiny helper plugin (`/wp-content/plugins/hello-abilities-ext/hello-abilities-ext.php`) alongside a matching `ext.js`, activate it, then reload the Abilities tab. You should see a new "Action" column with an Edit button on every row.

```php
<?php
/** Plugin Name: Hello Abilities Extension */

add_action( 'admin_enqueue_scripts', function () {
    if ( ! ( isset( $_GET['tab'] ) && 'abilities' === $_GET['tab'] ) ) return;
    wp_enqueue_script(
        'hello-abilities-ext',
        plugins_url( 'ext.js', __FILE__ ),
        array( 'acrossai-mcp-manager-abilities' ),
        '0.1.0',
        true
    );
} );

add_filter( 'acrossai_mcp_ability_row', function ( $row, $server_id, $ability ) {
    $row['hello_extra'] = 'from-php';
    return $row;
}, 10, 3 );
```

```js
// ext.js
wp.hooks.addFilter(
    'acrossaiMcpManager.abilities.fields',
    'hello/extra-column',
    ( fields ) => [
        ...fields,
        {
            id: 'hello_action',
            label: 'Action',
            enableSorting: false,
            render: ( { item } ) => wp.element.createElement(
                'button',
                { onClick: () => alert( 'Edit ' + item.slug + ' — ' + item.hello_extra ) },
                'Edit'
            ),
        },
    ]
);
```

### Smoke test — throwing filter degrades gracefully

Replace `ext.js` with:

```js
wp.hooks.addFilter(
    'acrossaiMcpManager.abilities.fields',
    'hello/broken',
    () => { throw new Error( 'boom' ); }
);
```

Reload the tab. Expected:
- ✅ Built-in columns still render.
- ✅ Browser console shows exactly one `[acrossai-mcp-manager] filter "acrossaiMcpManager.abilities.fields" threw:` line.
- ✅ No white-screen.
- ✅ No PHP errors in `wp-content/debug.log`.
