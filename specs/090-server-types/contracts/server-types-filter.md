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
| `tools` | `string[]` | no | `[]` | Ability slugs this type starts with — **and claims exclusively**. See Exclusivity below |
| `requires` | `?string` | no | `null` | Plugin FOLDER slug; `null` = always available. Resolved by DIRECTORY — the main file need not be named after the folder |
| `is_default` | `bool` | no | `false` | Preselected for new servers, if available |

Array key = the type slug, passed through `sanitize_key()`; empty → dropped.

## Exclusivity — `tools` claims, it does not merely seed

Declaring a slug in `tools` does two things:

1. **Reset and Switch** write those slugs into the server's tool storage.
2. Those slugs are **removed from every other type's pool** — the set a server of that type
   may be offered, and the set its `expose` rule exposes.

The subtraction keeps an AcrossAI server from being offered the three `mcp-adapter/*`
protocol tools, and vice versa. A slug NO type claims belongs to every type, because nothing
has asserted where it goes.

**Claim only slugs your own plugin registers.** Naming another plugin's slug removes it from
every type but yours.

## API beyond the accessors

| Method | Answers |
|---|---|
| `pool_for( $slug )` | every tool a server of this type may offer (the subtraction above) |
| `registered_only( $slugs )` | narrows declared or curated slugs to abilities that EXIST here |
| `plugin_is_active( $slug )` | THE single resolver for "is this required plugin running" (B32) |

`registered_only()` returns its input unchanged when the ability registry is empty —
"not registered yet" is not "invalid", and returning `[]` would make Reset wipe the server.

## Guarantees

- **LAST-WINS dedup (D41)**: re-registering an existing slug REPLACES it. This is how a
  companion overrides this plugin's `acrossai` placeholder.
- **Normalisation**: `tools` is coerced `strval` → drop empties → `array_unique` →
  `array_values`. A callback returning `null` or a scalar degrades to `[]`, never a fatal.
- **`mcp-adapter` is the floor** — always registered, always available. A callback removing
  it is ignored.
- **Throw safety**: throws propagate (standard WP filter behaviour). Callback authors own it.
- **`requires` is matched by DIRECTORY, never by filename.** WordPress stores active plugins
  as `folder/file.php` and the file is frequently not named after the folder
  (`advanced-custom-fields/acf.php`, `wordpress-seo/wp-seo.php`, `sfwd-lms/sfwd_lms.php`).
  A `slug/slug.php` assumption held only for the two types this plugin ships and made every
  realistic third-party `requires` resolve as permanently unavailable.

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
