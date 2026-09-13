# Planning: Searchable, paginated `discover-abilities` (Feature 089)

## In plain English

`mcp-adapter/discover-abilities` is the first tool an AI agent calls to learn what this
site can do. Before F089 it took **no parameters** and returned **every** tool-typed
ability in one lump. On a small site that is fine; on a large one it burns the model's
context and gives it no way to ask a narrower question like "what can you do with posts?".

F089 gives it `search`, `category`, `namespace`, `page` and `per_page`, defaulting to
**60** results per call, while keeping the **same ability name** so no client config
changes. The parameter descriptions are written for an LLM to read unaided — they are
literally what shows up in `tools/list`.

Novamira (a competitor) solves the same problem by unregistering the ability and
re-registering its own. We studied that approach but did not copy it: we already own a
cleaner seam, and their plugin is never modified.

---

## Context

The MCP adapter exposes **no** filter over `discover-abilities`' args, result or schema.
WordPress core does — `wp_register_ability_args`, fired inside
`WP_Abilities_Registry::register()`
(`wp-includes/abilities-api/class-wp-abilities-registry.php:139`).

We were already using it. `includes/Abilities/CallbackReplacer.php` hooks that filter and
rebinds the three vendor meta-tools to plugin-owned classes:

```php
self::DISCOVER_ABILITY => array( Discover::class, 'check_permission', 'execute' ),
```

So `Discover::execute()` was already ours. F089 widens that existing seam rather than
introducing a new mechanism: no vendor fork, survives a `wordpress/mcp-adapter` bump, and
the tool name is untouched.

### Two load-bearing core constraints

**1. The schema is mandatory, not cosmetic.** Core refuses input for an ability that
registers no `input_schema`, and never even passes `$input` to the callback:

```php
// WP_Ability::validate_input() — class-wp-ability.php:519-546
if ( empty( $input_schema ) ) {
    if ( null === $input ) { return true; }
    return new WP_Error( 'ability_missing_input_schema', … );
}

// WP_Ability::invoke_callback() — class-wp-ability.php:585-590
if ( ! empty( $this->get_input_schema() ) ) { $args[] = $input; }
```

That is why `Discover::execute()` opened with `unset( $input );` — it could never have
received anything. (Same "unused parameter" idiom that produced the F017 bug fixed in
#124; here it would have silently discarded every parameter.)

**2. Zero-argument calls must keep working.** Once a schema exists,
`validate_input( null )` would fail it. `WP_Ability::normalize_input()` applies only the
**top-level** `default`, so the schema root carries `'default' => array()`. Without that,
every existing caller breaks the moment the schema registers. Per-property `default`s are
**not** applied by core — `Discover::execute()` applies its own, and the schema declares
them purely so the model can see them.

### Parameter design and its sources

| Parameter | Type | Default | Convention borrowed from |
|---|---|---|---|
| `search` | string | `''` | Novamira Visual `matches_search()` — substring over name/label/description/category |
| `category` | string | `''` | exact; Novamira Visual + core `wp_get_abilities( category )` |
| `namespace` | string | `''` | prefix, e.g. `acrossai`; core `wp_get_abilities( namespace )` arg name |
| `page` | integer, min 1 | `1` | `acrossai-abilities-manager` `List_Posts` |
| `per_page` | integer, 1–200 | **60** | same, with the requested 60 |

`additionalProperties => false`, matching `List_Posts`, so a typo'd parameter fails loudly
instead of being ignored.

**Deliberately excluded:** `include_schemas` (which Novamira Visual has). Inlining every
ability's input schema is exactly the context blow-up this feature exists to prevent, and
`mcp-adapter/get-ability-info` already covers the per-ability case.

`page`/`per_page` rather than `limit`/`offset` because that is the established shape in
`acrossai-abilities-manager` — an agent that has seen one list tool in this ecosystem
already knows this one.

### Response shape

Existing key first; everything else additive.

```php
array(
    'abilities' => array( /* name, label, description, category */ ),
    'total'     => 137,   // matches BEFORE pagination
    'returned'  => 60,
    'page'      => 1,
    'per_page'  => 60,
    'has_more'  => true,
)
```

`has_more` is the important one — without it a model cannot distinguish a truncated list
from a complete one.

---

## Tasks

> **TASK-1 — Schema + description contribution (`CallbackReplacer`)**
> **TASK-2 — Parameter handling in `Discover::execute()`**
> **TASK-3 — `category` on each returned entry**
> **TASK-4 — PHPUnit coverage**
> **TASK-5 — This planning doc**
> **TASK-6 — Memory capture + commit**

### TASK-1 — Schema + description contribution

**File:** `includes/Abilities/CallbackReplacer.php`

- `public const DISCOVER_ABILITY = 'mcp-adapter/discover-abilities'`, reused as the
  `VENDOR_ABILITIES` map key so the slug is written once.
- `replace_callbacks()` — after the existing callback rebinding, and only for
  `DISCOVER_ABILITY`, sets `input_schema`, `output_schema` and `description`.
- `discover_input_schema(): array` — root `type`/`default`/`additionalProperties` plus the
  five properties, each with an LLM-facing `description`. Reads
  `Discover::PER_PAGE_DEFAULT` / `PER_PAGE_MAXIMUM` so the advertised numbers cannot drift
  from the enforced ones.
- `discover_output_schema( array $existing ): array` — **merges** into whatever is already
  registered rather than replacing, so vendor keys (and any other filter's contribution)
  survive; also adds `category` to the per-item schema.
- `discover_description(): string`.
- **Idempotency guard:** returns `$args` untouched when
  `isset( $args['input_schema']['properties']['per_page'] )`. The filter fires on every
  registration of the slug and third parties re-register this ability wholesale.

The other two `VENDOR_ABILITIES` entries keep callback-only replacement.

### TASK-2 — Parameter handling

**File:** `includes/Abilities/Discover.php`

- `public const PER_PAGE_DEFAULT = 60`, `public const PER_PAGE_MAXIMUM = 200`.
- `execute( $input = array() )` — `unset( $input );` removed; reads the criteria.
- `collect_visible_abilities()` — the original loop extracted verbatim, including both
  gates: `'tool' !== self::mcp_type()` and `! self::apply_exposure_filter( …, 'discover' )`.
- `filter_list( array $entries, array $criteria )` — `search` / `category` / `namespace`,
  ANDed; an empty criterion is a no-op.
- `matches_search( array $entry, string $search )` — case-insensitive substring over
  name, label, description, category. Uses `Compat::str_contains()` /
  `Compat::str_starts_with()` because phpcs pins `testVersion` at `7.4-`.
- `resolve_pagination( array $criteria )` — applies defaults and clamps, honouring
  `acrossai_mcp_discover_abilities_default_per_page` and
  `acrossai_mcp_discover_abilities_max_per_page`.

**Order is load-bearing:** collect → exposure gate → filter → `$total = count()` → slice.
Filtering *after* the gate is what stops `search` surfacing an ability the per-server
F017/F020 policy hides. `$total` counts matches before the slice so `has_more` is honest.

`wp_get_abilities()` stays a bare call — core only accepts `$args` on WP 7.1+, and
filtering in PHP keeps WP 6.9 installs identical.

### TASK-3 — `category` per entry

`collect_visible_abilities()` adds `'category' => $ability->get_category()`. Needed because
`search` matches category, and it lets a model see which category values exist before using
the `category` filter. Additive — no existing key changes.

### TASK-4 — PHPUnit coverage

- `tests/phpunit/Abilities/DiscoverTest.php` — behaviour: zero-argument back-compat,
  the 60 default with `has_more`, `total` counted before the slice, page 2 and past-the-end,
  `search` across all four fields case-insensitively, `category` exact vs `namespace`
  prefix (including no match across the slash boundary), criteria ANDing, `per_page`
  clamping, both filters, and the security invariant that a gate-hidden ability is
  unreachable through *any* criterion.
- `tests/phpunit/Abilities/DiscoverSchemaTest.php` (new) — the contributed schema: all five
  properties present and described, the root `default` that keeps zero-argument calls
  valid, advertised default/maximum matching `Discover`'s constants, output-schema merge
  preserving vendor keys, the description stating the paging contract, callbacks still
  rebound, idempotency, and that the other two vendor abilities get callbacks only.

Fixtures register through `acrossai_test_register_ability()`
(`tests/bootstrap-wp.php`) — WP 6.9 refuses `wp_register_ability()` outside
`wp_abilities_api_init`.

---

## Files

| File | Change |
|---|---|
| `includes/Abilities/CallbackReplacer.php` | `DISCOVER_ABILITY`; `discover_input_schema()`, `discover_output_schema()`, `discover_description()`; idempotency guard |
| `includes/Abilities/Discover.php` | constants; `collect_visible_abilities()`, `filter_list()`, `matches_search()`, `resolve_pagination()`; `category` per entry |
| `tests/phpunit/Abilities/DiscoverTest.php` | extended |
| `tests/phpunit/Abilities/DiscoverSchemaTest.php` | new |

No DB change, no new ability, no vendor edit, no change to `novamira`.

---

## Manual Verification Checklist

Run live on `wordpress-7-0.local` against the connected
`wordpress-7-0-mcp-adapter-default-server` MCP client, 2026-09-13.

### 1. Quality gates
```
# composer run phpcs:                 pass
# composer run phpstan:               pass
# phpunit abilities suite (CI):       OK (98 tests, 214 assertions) — was 78 pre-F089
# all 8 checks on PR #125:            pass
```

### 2. Schema reaches the client
Read back from the MCP client's own tool definition, not from our source:
```
# mcp-adapter-discover-abilities advertises inputSchema?   yes
# all 5 properties present WITH descriptions?              yes
# additionalProperties:false present?                      yes
# per_page default 60 / maximum 200?                       yes
# description mentions the 60 default and has_more?        yes
```

### 3. Behaviour
```
# no arguments -> total 439, returned 60, has_more true    yes
#   (439 abilities on this site — before F089 every call
#    returned all 439 in one response)
# {"search":"permalink"} -> total 10, has_more false       yes
#   total is the FILTERED count, not the global one;
#   matched descriptions too (content/get-post)
# {"namespace":"cache","per_page":3,"page":2}
#   -> total 7, returned 3, items 4-6, has_more true       yes
# same at page 3 -> returned 1, has_more false             yes
# {"category":"acrossai-themes"} -> total 7, exact match   yes
# {"per_page":500} -> rejected by core:                    yes
#   'input[per_page] must be between 1 (inclusive) and 200
#    (inclusive)' — an error an LLM can self-correct from.
#   Same validator enforces additionalProperties:false.
```

### 4. Exposure gate still wins
Covered by CI rather than by hand — `DiscoverTest::
test_hidden_ability_is_unreachable_through_any_criterion` asserts a
gate-hidden ability is absent for `search`, `category`, `namespace` and the
unfiltered call. Doing it live needs a per-server Abilities-tab toggle, and the
default server used above is not the surface that carries those overrides.
