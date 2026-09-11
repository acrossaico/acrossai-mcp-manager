# Planning: One tool-level ability list for both admin pickers (Feature 087)

Two admin screens were each showing the wrong set.

**Abilities tab** (`?tab=abilities`) listed the sibling `acrossai-abilities-manager` plugin's
thirteen **toolset** dispatchers — `toolset/appearance`, `toolset/blocks`, `toolset/cache`,
`toolset/configuration`, `toolset/content`, `toolset/cron`, `toolset/database`,
`toolset/diagnostics`, `toolset/files`, `toolset/updates`, `toolset/users`, plus
`toolset/elementor` and `toolset/rank-math` when those plugins are active — as ordinary rows with
their own **Exposed** toggle, beside ~350 genuine abilities. Each one is a router: it takes
`action=discover|info|execute` and dispatches to the abilities in its `meta.acrossai.tab_group`.
Plumbing, not choices. The three `mcp-adapter/*` protocol tools were already dropped there, but
through three string literals hardcoded in `src/js/abilities.js` that no other plugin could reach.

**Tools tab** (`?tab=tools`) had the opposite problem: its left pool offered all ~370 abilities.
But abilities were never advertised individually in `tools/list` — as of the 2026-07-15 revert
(§7 of `docs/extending-server-tools.md`) they reach clients *through* the tool-level entries. The
pool was offering a choice that isn't one.

**F087 is a single filter serving both.**

```
apply_filters( 'acrossai_mcp_manager_tool_abilities', string[] $slugs ): string[]
```

Seeded with `ToolPolicy::PROTOCOL_TOOLS`. The Abilities tab **hides** every slug in it; the Tools
tab's left pool shows **nothing else**. Companion plugins declare their own — this plugin never
hardcodes another's vocabulary.

## Scope

| Surface | Effect |
| --- | --- |
| Abilities tab table | Tool-level slugs dropped. The "N exposed" counter, the category/type dropdowns and bulk selection follow, since all derive from the same filtered `items` memo. |
| Tools tab left pool (relabelled **Available tools**) | Allow list: tool-level slugs ∪ already-curated slugs. |
| Tools tab "Added as tools" pane | Unfiltered. |
| Exposure, curation, `mcp_adapter_pre_tool_call` enforcement | Untouched. The list is presentational. |

## CONSTRAINTS

- **C1 — Presentation only.** No exposure, curation, or enforcement semantics move.
  `MCP\ToolExposureGate::EXCLUDED_SLUGS` is explicitly NOT this list: that constant means
  "always callable", and membership here gates nothing.
- **C2 — No silent data loss.** `src/js/tools.js` filters `poolAbilities`, never `abilities`.
  `addedRows` needs the unfiltered list for its byName metadata lookup, and a curated slug dropped
  from the client's `added` state would be deleted from `wp_acrossai_mcp_server_tools` on the next
  save. Hence `poolAbilities = toolSlugs ∪ added` — an install that curated individual abilities
  before F087 keeps seeing and managing them (they just can't be re-added once removed), and
  `totalPool` stays coherent with `added.size`. `visibleAvailable` drops added rows from the left
  pane anyway via its existing `added.has()` check.
- **C3 — PHP is the single source of truth.** The JS slug literals are boot-time fallbacks for a
  stale localized payload only. No parallel `@wordpress/hooks` filter — one seam, one code path,
  one list for both tabs.
- **C4 — Seed from `ToolPolicy::PROTOCOL_TOOLS`,** not a fourth copy of the three slugs. That
  constant's docblock already declares itself the canonical PHP source.
- **C5 — Normalization matches the existing precedent.** `ToolAbilities::get_slugs()` normalizes
  its filter return exactly the way `Controller::register_database_servers()` normalizes
  `acrossai_mcp_manager_server_tools`: `array_map( 'strval', (array) … )`, drop empties,
  `array_unique`, `array_values`. A callback returning `null` or a scalar degrades to `array()`.
- **C6 — No memoization.** A companion plugin may register its callback after the first call, and
  `get_slugs()` runs at most twice per admin request.
- **C7 — A1 hook discipline.** `ToolAbilities` is an all-static pure service (A11 exemption, same
  as `ToolPolicy` / `AbilityDiscovery` / `ExposureResolver`) — no singleton, no constructor, no
  `add_action`/`add_filter` of its own.
- **C8 — Accepted behaviour change.** On a site where nothing hooks the filter, the Tools pool
  holds exactly the three protocol tools; individual abilities are no longer addable as tools from
  that screen. Deliberate, per operator decision — it matches how abilities actually reach clients.
  `acrossai_mcp_manager_server_tools` remains the PHP escape hatch.

## Files

| File | Change |
| --- | --- |
| `includes/Abilities/ToolAbilities.php` | **new** — filter + normalization, seeded from `ToolPolicy::PROTOCOL_TOOLS` |
| `admin/Main.php` | `toolAbilities` key in the `acrossaiMcpAbilities` and `acrossaiMcpTools` payloads |
| `src/js/abilities.js` | `EXCLUDED_SLUGS` → `DEFAULT_TOOL_SLUGS` fallback + `config.toolAbilities`; table excludes them |
| `src/js/tools.js` | `toolSlugs` + allow-list `poolAbilities`; `visibleAvailable` / `totalPool` follow; pool copy relabelled tools-not-abilities |
| `build/js/{abilities,tools}.js` | regenerated |
| `tests/phpunit/Abilities/ToolAbilitiesTest.php` | **new** — 8 cases incl. a junk-return data provider |
| `docs/extending-abilities-tab.md`, `docs/extending-server-tools.md` | hook contract + cross-reference |

Deliberately untouched: `includes/MCP/ToolExposureGate.php`, `includes/REST/AbilitiesController.php`,
`includes/REST/ToolsController.php`, `includes/Database/MCPServer/ToolPolicy.php`.

## Manual Verification Checklist

### 1. Default state (nothing hooks the filter)
```
# ?tab=abilities item count (MUST be pre-F087 count minus 0 — the three were already hidden):
# ?tab=tools left pool row count (MUST be 3, all mcp-adapter/*, plus any already-curated slugs):
# ?tab=tools counter reads "N of M tools added to this server":
```

### 2. With the sibling's hook active
```php
add_filter( 'acrossai_mcp_manager_tool_abilities', function ( array $slugs ): array {
    foreach ( wp_get_abilities() as $a ) {
        if ( ! empty( $a->get_meta()['acrossai']['toolset'] ) ) {
            $slugs[] = $a->get_name();
        }
    }
    return $slugs;
} );
```
```
# ?tab=abilities search "toolset" -> row count (MUST be 0):
# banner count drop (MUST equal number of registered toolsets):
# ?tab=tools left pool -> 3 protocol + every toolset/* (MUST be nothing else):
```

### 3. Round-trip safety (C2)
```
# with a NON-tool-level ability already curated on server 1:
# renders in "Added as tools" with its real label? (y/n):
# add/remove an unrelated tool, save, re-check it is still curated? (y/n):
# tools/list still advertises it? (y/n):
```

### 4. Enforcement unchanged (C1)
```
# tools/list before vs after:
# toolset/content action=discover before vs after:
```

### 5. Quality gates
```
# phpcs:
# phpstan:
# lint:js:
# build:
# phpunit (abilities suite, CI):
```
