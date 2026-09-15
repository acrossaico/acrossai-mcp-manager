# Contract: `acrossai_mcp_server_types`

The public extension point through which another plugin contributes or replaces a server
type. Shape follows `docs/extending-server-tools.md`.

## Signature

```php
apply_filters(
    'acrossai_mcp_server_types',
    array<string, array{
        label:       string,
        description?: string,
        tools?:      string[],
        requires?:   ?string,
        is_default?: bool
    }> $types
): array;
```

**Where it fires**: `ServerTypes::all()`, seeded with this plugin's two built-ins. Resolved
per request; no DB access; not memoised beyond the request.

**Where it does NOT fire**: never during `mcp_adapter_init` tool composition in a way that
could query the database — callbacks MUST be cheap and side-effect free.

## Entry contract

| Key | Type | Required | Default | Notes |
|---|---|---|---|---|
| `label` | `string` | **yes** | — | Missing → entry dropped with `_doing_it_wrong()` under `WP_DEBUG` |
| `description` | `string` | no | `''` | Shown beneath the selector |
| `tools` | `string[]` | no | `[]` | Ability slugs this type starts with |
| `requires` | `?string` | no | `null` | Plugin folder slug; `null` = always available |
| `is_default` | `bool` | no | `false` | Preselected for new servers, if available |

Array key = the type slug, passed through `sanitize_key()`; empty → dropped.

## Guarantees

- **LAST-WINS dedup (D41)**: re-registering an existing slug REPLACES it. This is how a
  companion overrides this plugin's `acrossai` placeholder.
- **Normalisation**: `tools` is coerced `strval` → drop empties → `array_unique` →
  `array_values`. A callback returning `null` or a scalar degrades to `[]`, never a fatal.
- **`mcp-adapter` is the floor** — always registered, always available. A callback removing
  it is ignored.
- **Throw safety**: throws propagate (standard WP filter behaviour). Callback authors own it.

## Worked example — a companion contributing its own type

```php
add_filter( 'acrossai_mcp_server_types', function ( array $types ): array {
    $types['mycorp'] = array(
        'label'       => __( 'MyCorp', 'mycorp' ),
        'description' => __( 'Servers that expose the MyCorp toolset.', 'mycorp' ),
        'tools'       => array( 'mycorp/dispatcher' ),
        'requires'    => 'mycorp-abilities',
    );
    return $types;
} );
```

Register the callback **unconditionally**, not behind a presence check on this plugin —
filtering a hook that may never fire costs nothing, and a presence probe evaluated at
attach time is unreliable because plugin load order is not guaranteed.

## Anti-patterns

- Do NOT query the database inside a callback — this runs on every admin request.
- Do NOT return an unkeyed list; the array key IS the slug.
- Do NOT rely on `is_default` winning: a type whose `requires` is unmet is never chosen as
  the default.
