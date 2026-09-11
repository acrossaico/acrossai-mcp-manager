# Extending per-server MCP tool lists

**Introduced**: Feature 025 (2026-07-14)
**Companion**: `docs/extending-per-server-tabs.md` (Feature 019)

Feature 025 opens the per-server tool list to third-party plugins via two filter seams — one for the default server, one for plugin-created (database) servers. This document describes the runtime contract, the storage model behind the composed tool list, and three worked examples covering the common extension patterns.

## 1. Storage model

Each MCP server exposes two orthogonal tool storage layers on the plugin side:

- **Protocol tools** — the three MCP-adapter default tools (`mcp-adapter/discover-abilities`, `mcp-adapter/get-ability-info`, `mcp-adapter/execute-ability`) are enabled per-server via three `tinyint(1) NOT NULL DEFAULT 1` columns on `wp_acrossai_mcp_servers`: `tool_discover_abilities`, `tool_get_ability_info`, `tool_execute_ability`. `1` = enabled, `0` = the operator explicitly removed it via the Tools tab.
- **Curated abilities** — any additional WordPress ability the operator picked in the Tools tab lives as a presence row in `wp_acrossai_mcp_server_tools` (Feature 020 storage).

At MCP-adapter server-registration time (`mcp_adapter_init` action), `ToolPolicy::compose_effective_tools_for_row( $row )` returns the two layers deduped and array_values-normalized. That array is what the tool filter sees.

**Scope note (2026-07-15 revert)**: F026 v1's tools-widening (which added `meta.mcp.public = true` abilities to this list at registration time) was reverted. `tools/list` now reflects only the operator's Tools-tab picks. Abilities with `mcp.public = true` (or `is_exposed = 1` per-server override) are accessible to AI clients through the three built-in meta tools — `mcp-adapter/discover-abilities`, `mcp-adapter/get-ability-info`, `mcp-adapter/execute-ability` — whose callbacks were swapped to plugin-owned versions (commit `070ffe2`) that honor the Abilities-tab per-server visibility. Resources and prompts still widen — see the `..._server_resources` and `..._server_prompts` filter sections below.

The REST GET `/tools` endpoint continues to call `ToolPolicy::compose_for_row()` — it and `compose_effective_tools_for_row()` are now functionally identical.

## 2. Filter contract

### `acrossai_mcp_manager_server_tools` (plugin filter — database servers only)

```php
apply_filters(
    'acrossai_mcp_manager_server_tools',
    string[] $tools,
    \AcrossAI_MCP_Manager\Includes\Database\MCPServer\Row $server
): string[];
```

**Where it fires**: `\AcrossAI_MCP_Manager\Includes\MCP\Controller::register_database_servers()`, on the `mcp_adapter_init` action at priority 11 (immediately after the vendor's `DefaultServerFactory` at priority 10). Fires exactly once per enabled DB-registered server per adapter boot.

**Where it does NOT fire**:
- Not fired for the default server (`registered_from = 'plugin'`, slug `mcp-adapter-default-server`). Hook the vendor filter `mcp_adapter_default_server_config` for that path.
- Not fired for disabled server rows.
- Not fired when the MCP adapter package is absent.

**Arguments**:

- `$tools`: the composed union of TWO sources, deduped and `array_values()`-normalized:
  1. Enabled protocol columns (in `ToolPolicy::COLUMN_MAP` key order) — the three vendor built-in slugs.
  2. Curated slugs (`MCPServerToolQuery::get_added_slugs()` insertion order) — operator's Tools-tab picks.
  Abilities with `mcp.public = true` are NOT in this list — they're accessible via the three built-in meta tools whose callbacks respect per-server Abilities-tab visibility (see the scope note in §1).
- `$server`: the BerlinDB `Row` object for the server being registered. Callbacks may gate on `$server->id`, `$server->server_slug`, `$server->server_name`, or read the enablement columns directly.

### `acrossai_mcp_manager_server_resources` and `acrossai_mcp_manager_server_prompts` (plugin filters — database servers only) — Feature 026

Two sibling filters, same signature as `..._server_tools`. Fire alongside `acrossai_mcp_manager_server_tools` inside `Controller::register_database_servers()`, once per enabled DB-registered server.

```php
apply_filters(
    'acrossai_mcp_manager_server_resources',
    string[] $resources,  // F017-effective, mcp.type === 'resource' set
    \AcrossAI_MCP_Manager\Includes\Database\MCPServer\Row $server
): string[];

apply_filters(
    'acrossai_mcp_manager_server_prompts',
    string[] $prompts,  // F017-effective, mcp.type === 'prompt' set
    \AcrossAI_MCP_Manager\Includes\Database\MCPServer\Row $server
): string[];
```

The pre-filter list for each is exclusively the F017-effective, type-filtered slug set — there is no equivalent to F025's protocol-column or curated-storage layers for resources/prompts. Companion plugins may add or remove any slug freely. Same defensive re-normalization (`array_values( array_unique( array_map( 'strval', ... ) ) )`) applies. NOT fired for the default server — hook `mcp_adapter_default_server_config` (which sets `$config['resources']` and `$config['prompts']`) for that path.

**Return contract**: array (or coercible). The plugin re-normalizes with `array_values( array_unique( array_map( 'strval', (array) $return ) ) )` before passing to `$adapter->create_server()`, so non-array / non-string / duplicate returns don't corrupt the adapter call. `null` / `false` degrade to `[]` — server registers with an empty tool list.

**Throw safety**: throws propagate. Standard WordPress filter behavior — companion authors own their throw safety.

**Confused-deputy caveat**: a callback that re-adds a protocol slug the operator has explicitly removed via the Tools tab (column value `0`) will silently override the operator's UI-facing decision. Filter authors SHOULD log or documentation-cite any such override so operators can trace unexpected tool advertisements back to the responsible plugin.

### `mcp_adapter_default_server_config` (vendor filter — default server only)

Consumed by the plugin's `Controller::filter_default_server_config()` at default priority 10. Hook this filter at priority > 10 to run AFTER the plugin's callback, or < 10 to run before (in which case the plugin's REPLACE step will overwrite the `tools` / `resources` / `prompts` keys). The plugin REPLACES `$config['tools']` with the protocol columns + F020 curated slugs, and `$config['resources']` / `$config['prompts']` with F017-effective sets, when the default server row exists in `wp_acrossai_mcp_servers`.

Signature (vendor-owned): `apply_filters( 'mcp_adapter_default_server_config', array $config ): array`. See `vendor/wordpress/mcp-adapter/includes/Servers/DefaultServerFactory.php:89` for the source.

## 3. Two-hook model

| Server source | Row's `registered_from` | Filter(s) to hook | Priority guidance |
|---|---|---|---|
| Default server | `'plugin'` (slug `mcp-adapter-default-server`) | `mcp_adapter_default_server_config` (vendor) — modifies `$config['tools']`, `$config['resources']`, `$config['prompts']` | Priority `>10` to modify AFTER the plugin's composed sets land |
| Plugin-created / operator-created | `'database'` | Three plugin filters: `acrossai_mcp_manager_server_tools`, `acrossai_mcp_manager_server_resources`, `acrossai_mcp_manager_server_prompts` | Default `10` is fine; earlier priorities see the pre-normalize state |

A companion plugin that wants to touch every server hooks BOTH paths. The two seams never double-fire for the same server row.

## 4. Worked example 1 — Add a Notes ability to every server named "Marketing"

```php
<?php
add_filter(
    'acrossai_mcp_manager_server_tools',
    static function ( array $tools, $server ): array {
        if ( 'Marketing' === $server->server_name && ! in_array( 'my-plugin/notes', $tools, true ) ) {
            $tools[] = 'my-plugin/notes';
        }
        return $tools;
    },
    10,
    2
);
```

## 5. Worked example 2 — Strip execute-ability from audit-only database servers

```php
<?php
add_filter(
    'acrossai_mcp_manager_server_tools',
    static function ( array $tools, $server ): array {
        if ( str_contains( $server->server_slug, 'audit' ) ) {
            $tools = array_values( array_diff( $tools, array( 'mcp-adapter/execute-ability' ) ) );
        }
        return $tools;
    },
    10,
    2
);
```

## 6. Worked example 3 — Same idea for the default server via the vendor filter

```php
<?php
add_filter(
    'mcp_adapter_default_server_config',
    static function ( array $config ): array {
        if ( is_array( $config['tools'] ?? null ) ) {
            $config['tools'] = array_values( array_diff(
                $config['tools'],
                array( 'mcp-adapter/execute-ability' )
            ) );
        }
        return $config;
    },
    20 // priority > 10 to run AFTER the plugin's Controller::filter_default_server_config().
);
```

## 7. Interaction with the Abilities tab

The Abilities tab (`?tab=abilities`) controls per-server ability visibility via `wp_acrossai_mcp_server_abilities`. The precedence resolved by `ExposureResolver::resolve()` is:

1. Row in `wp_acrossai_mcp_server_abilities` for this `(server_id, ability_slug)` → its `is_exposed` value wins.
2. Else fall back to the ability's `meta.mcp.public` — `true` includes, missing / `false` excludes.

**Where this decision applies now:**

- **The three built-in meta tools** (`mcp-adapter/discover-abilities`, `.../get-ability-info`, `.../execute-ability`) — plugin-owned callbacks (commit `070ffe2`) call `ExposureResolver::resolve()` inside their `check_permission()` / `execute()`. Toggling an ability OFF on the Abilities tab hides it from `discover-abilities`' output and blocks `execute-ability` from running it. See `includes/Abilities/*.php`.
- **`resources/list` and `prompts/list`** — the resources/prompts filters below still widen with F017-effective abilities of the matching type.
- **F017's `AbilityExposureGate`** at `mcp_adapter_pre_tool_call` priority 20 — direct calls to `tools/call` on an ability's own slug are still gated per-server.

**Where this decision does NOT apply (as of 2026-07-15 revert):**

- **The `tools/list` advertisement** — abilities are no longer widened into `tools/list` at registration time. Only the F025 protocol columns + F020 curated slugs appear. Rationale: abilities are accessible through the three meta tools whose plugin-owned callbacks already respect Abilities-tab visibility; advertising them again as direct tools would duplicate the surface and confuse the "what's a tool vs. what's an ability" mental model.

The Tools tab UI is deliberately unchanged. It reflects the operator's Tools-tab picks (protocol + curated) only. The Abilities tab is the sole surface for F017 override management.

## 7a. Tool-level abilities and this filter — Feature 087

`acrossai_mcp_manager_tool_abilities` (documented in [Extending the Abilities Tab](extending-abilities-tab.md)) decides what the Tools tab's left "Available tools" pool offers. It is an allow list: only tool-level slugs appear there, seeded with `ToolPolicy::PROTOCOL_TOOLS` and extended by companion plugins. An ability that isn't in it can't be put on a server with **+ Add** — this filter is the supported way to do it in PHP:

```php
add_filter( 'acrossai_mcp_manager_server_tools', static function ( array $tools, $server ): array {
    // Advertise the sibling's cron dispatcher on every database server,
    // whether or not an operator picked it on the Tools tab.
    $tools[] = 'toolset/cron';
    return $tools;
}, 10, 2 );
```

Two things to know:

- The allow list is cosmetic. It does not remove anything from `wp_acrossai_mcp_server_tools` and does not affect `compose_for_row()` — a slug this filter adds is advertised regardless of what the pool shows.
- Abilities already curated on a server render in the "Added as tools" pane whether or not they're tool-level, stay removable, and round-trip through save untouched. Nothing is dropped silently.

## 8. Throw safety note

Neither filter is wrapped in try/catch on the plugin side. This matches standard WordPress behavior: throws propagate up the call stack. If your callback may throw:

- Wrap the risky work in your own `try { ... } catch ( \Throwable $e ) { ... }` block.
- On catch, either return the input `$tools` (or `$config`) untouched, or return a safe default.
- Log via `error_log()` — do not swallow silently.

Example:

```php
add_filter( 'acrossai_mcp_manager_server_tools', static function ( array $tools, $server ) {
    try {
        return your_risky_transform( $tools, $server );
    } catch ( \Throwable $e ) {
        error_log( sprintf(
            '[my-plugin] server_tools filter for server_id=%d threw: %s',
            (int) $server->id,
            $e->getMessage()
        ) );
        return $tools; // safe fallback — leave the composed set unchanged.
    }
}, 10, 2 );
```
