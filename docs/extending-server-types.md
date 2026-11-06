# Extending MCP server types

**Introduced**: Feature 090
**Companions**: `docs/extending-server-tools.md` (Feature 025), `docs/extending-per-server-tabs.md` (Feature 019)

A **server type** is a starting point plus a label. It decides what **Switch** and **Reset**
WRITE into a server's tool storage — and nothing more. It is never consulted when the server
registers, so what a server actually serves stays exactly what its Tools tab says.

This document describes the filter contract, the placeholder→companion override pattern, and
a worked example.

## 1. What a type is (and is not)

| A type IS | A type is NOT |
|---|---|
| A named template: "start this server with these tools" | A runtime filter over what the server serves |
| The answer to "what should Reset restore?" | A permission or capability boundary |
| A label shown in the admin | A guarantee that those tools exist |

The one place a type acts at runtime is the requirement gate: a server whose type declares a
`requires` that is unmet cannot be **enabled**, and if it was already enabled it advertises a
single diagnostic entry instead of tools. That is deliberately the *only* runtime behaviour —
see §5.

## 2. Filter contract

```php
apply_filters(
    'acrossai_mcp_server_types',
    array<string, array{
        label:        string,
        description?: string,
        tools?:       string[],
        requires?:    ?string,
        is_default?:  bool
    }> $types
): array;
```

**Where it fires**: `ServerTypes::all()`, seeded with the two built-in types. Resolved on
demand during admin requests and at MCP server registration.

**Performance contract**: callbacks MUST be cheap and side-effect free. `all()` performs **no
database access** and is not memoised across the request — a callback that queries turns every
admin page load into extra round-trips.

### Entry shape

| Key | Type | Required | Default | Notes |
|---|---|---|---|---|
| `label` | `string` | **yes** | — | Missing → entry dropped, with `_doing_it_wrong()` under `WP_DEBUG` |
| `description` | `string` | no | `''` | Shown beneath the selector |
| `tools` | `string[]` | no | `[]` | Ability slugs this type starts with — what **Reset** writes. See §2.1 |
| `requires` | `?string` | no | `null` | Plugin folder slug; `null` = always available |
| `is_default` | `bool` | no | `false` | Preselected for new servers, **if available** |

The array KEY is the slug, passed through `sanitize_key()`. An empty key drops the entry.

### Guarantees

- **LAST-WINS dedup (D41)** — re-registering an existing slug REPLACES it. This is the
  override mechanism, not an accident; see §3.
- **Normalisation** — `tools` is coerced `strval` → drop empties → `array_unique` →
  `array_values`. A callback returning `null` or a scalar degrades to `[]`, never a fatal.
- **`mcp-adapter` is the floor** — always registered, always available. A callback that
  removes it gets it back.
- **A type that contributes no tools falls back to the legacy set.** `tools_for()` never
  returns an empty template, because an empty template would make **Reset wipe the server**.
- **Throw safety** — throws propagate. Standard WordPress filter behaviour; callback authors
  own it.

### 2.1 `tools` is a template, not a claim

`tools` decides one thing: what **Reset** and **Switch** write into a server's tool storage.

It does **not** restrict what the operator may add by hand.

```
Reset on an `acrossai` server      -> that type's toolsets only
Reset on an `mcp-adapter` server   -> the three protocol tools only
The picker, on either              -> this type's tools, plus every
                                      registered tool no type has claimed
```

**The pool is scoped to the type (since 0.3.6).** An earlier iteration offered every
tool-level ability on the site regardless of type. That was reversed: an AcrossAI server was
being offered the four `mcp-adapter/*` tools — another server's entire vocabulary, under a
heading naming this one — and the header counted the configured set against a pool that
excluded it, reading "15 of 4".

What is withheld is only vocabulary **another type has claimed**. A tool-level ability that no
type claims is still offered everywhere, so a plugin contributing a tool of its own via
`acrossai_mcp_manager_tool_abilities` keeps working unchanged.

**Practical consequence for contributors**: naming a slug in your `tools` affects what Reset
writes for servers of *your* type, and removes that slug from the unclaimed pool other types
are offered. To put a tool on a type you do not own, use the filter in §2.2 rather than
re-registering their entry.

### 2.2 Adding tools to a type you do not own — `acrossai_mcp_server_type_tools`

`acrossai_mcp_server_types` registers a type, and its dedup is slug-keyed **last-wins**. That
is the wrong tool for *adding one toolset to someone else's type*: you would have to
re-declare the whole entry — clobbering its label, description and `requires` — and whichever
plugin ran last would win, silently dropping the other's contribution.

`acrossai_mcp_server_type_tools` receives the **resolved** list and hands it back, so
contributions compose:

```php
add_filter(
	'acrossai_mcp_server_type_tools',
	function ( array $tools, string $slug ): array {
		if ( 'acrossai-fleet' !== $slug ) {
			return $tools;
		}

		$tools[] = 'toolset/my-plugin';

		return $tools;
	},
	10,
	2
);
```

**Where it lands.** The filter runs inside `ServerTypes::declared_tools()`, the single
writer-side source of truth, so one callback reaches every writer at once:

| Surface | Effect |
|---|---|
| **Reset to Type Defaults** | restores your toolset along with the type's own |
| Server creation | a new server of that type is created with it |
| Type switch | switching to that type writes it |
| The Tools tab picker | `pool()` builds on `declared_tools()`, so it is **offered**, not only restored |

That last row matters: without it, an added toolset would appear only after someone pressed
Reset, and the picker would disagree with the list beside it.

#### Guarantees

- **Runs on the legacy fallback too.** A server whose type was removed with its plugin still
  passes through the filter — that is exactly when a contributor still wants its toolset.
- **Coercion** — non-string members are dropped, values are trimmed, blanks removed, then
  `array_unique` → `array_values`. A non-array return is ignored.
- **Returning `[]` is ignored.** This list is *written over* a server's curated tools, so an
  empty result would wipe it. Same guard the declaration and result checks already enforce. To
  strip a type down, return the slugs you do want — not nothing.
- **Not narrowed to registered abilities**, matching the rest of `declared_tools()`. Declaring
  a slug whose plugin is not installed yet is deliberate; it lights up when the ability
  registers. `registered_only()` narrows wherever that matters.

#### Which filter do I want?

| You want to… | Use |
|---|---|
| ship a whole new server type | `acrossai_mcp_server_types` (§2) |
| add a tool to an existing type | `acrossai_mcp_server_type_tools` (this section) |
| register a tool-level ability at all | `acrossai_mcp_manager_tool_abilities` |
| change one particular server's tools | `acrossai_mcp_manager_server_tools` |

## 3. The placeholder → companion override pattern

This plugin must never hardcode another plugin's vocabulary. `ToolAbilities` states the rule:

> Companion plugins declare their own via the filter; nothing here hardcodes another plugin's
> vocabulary.

So the `acrossai` type ships here as a **placeholder** — a label, a `requires`, and an empty
tool list — and the sibling `acrossai-abilities-manager` re-registers the same slug with its
real toolsets. Last-wins dedup makes the replacement automatic.

```
this plugin ships:   'acrossai' => label, requires, tools: []
sibling registers:   'acrossai' => label, tools: [ 'toolset/content', … ]
                     ────────────────────────────────────────────────────
result:              the sibling's entry, because it registered last
```

The same pattern is used by the level-1 tab registry and the level-2 Connect-method registry.

## 4. Worked example — a companion contributing its own type

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

Register the callback **unconditionally** — do not guard it on this plugin being active.
Filtering a hook that may never fire costs nothing, and a presence probe evaluated at
attach time is unreliable because plugin load order is not guaranteed.

## 5. Requirement enforcement, in three layers

A `requires` that is unmet is enforced in three independent places. Each exists because the
others cannot cover its case:

| Layer | Where | Why it alone is not enough |
|---|---|---|
| **Selection** | Admin pickers offer only available types | Does not cover a server whose type became unavailable later |
| **Enablement** | `ServerEnablement::set()` refuses off→on | Does not cover a server enabled *before* the requirement broke |
| **Runtime** | `ToolPolicy::compose_effective_tools_for_row()` swaps the tool list for one diagnostic entry | Explains the state to a connected AI client that cannot see the admin |

Three rules worth knowing if you build on this:

- **Disabling is never gated.** A server stranded by a deactivated dependency must always be
  switchable off.
- **A running server is never auto-disabled.** That would 404 its route mid-session instead of
  explaining itself.
- **The runtime swap writes nothing.** Curated tool rows survive a deactivate/reactivate cycle
  byte-identical.

### A vendor limitation you cannot design around

The MCP Adapter resolves a tool *before* firing `mcp_adapter_pre_tool_call`
(`ToolsHandler::call_tool()`). A client holding a stale tool list that calls a vanished tool
receives the vendor's generic `tool_not_found` — no filter this plugin owns can intercept it.
The diagnostic entry is what a client sees when it **re-lists**, which is what agents do after
an error. Do not attempt to fix this by registering stand-in abilities under another plugin's
namespace.

## 6. Reading a type

```php
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ServerTypes;

ServerTypes::all();                     // every registered type, normalised
ServerTypes::get( 'acrossai' );         // one entry, or null when unregistered
ServerTypes::tools_for( 'acrossai' );   // its tools, with the legacy fallback
ServerTypes::is_available( 'acrossai' );// THE single resolver for "requirement met"
ServerTypes::default_slug();            // preselected type for new servers
ServerTypes::enablement_error( $slug ); // ?WP_Error — why it may not be enabled
ServerTypes::pool();                    // every tool the picker offers — see §2.1
ServerTypes::registered_only( $slugs ); // narrow declared slugs to abilities that exist here
```

`is_available()` is the single source of truth for whether a requirement is satisfied.
Selection, the enablement gate and the runtime composer all call it; **do not re-derive the
rule**, or the three layers will disagree.

## 7. Storage

One column on `{$wpdb->prefix}acrossai_mcp_servers`, added by migration `1.1.6`:

| Column | Values | Notes |
|---|---|---|
| `server_type` | a registered slug | Default `'mcp-adapter'` — the value every pre-090 row was backfilled to |

A second column, `tools_default_policy`, was added alongside it and DROPped again by
migration `1.1.7`. It backed a coarse `expose`/`hide` rule above the operator's curation;
`MCP\ToolExposureGate` gates `tools/call` on curated rows alone and never read it, so an
`expose` server advertised tools that it then refused. What a server serves is now decided
by its curated selection and nothing else. (`abilities_default_policy` is F082, a different
feature on the same table, and is unaffected.)

An unrecognised stored `server_type` **must never fatal**: `get()` returns `null`, tool
resolution falls back to the legacy set, and the admin shows the raw slug marked unavailable.
A server whose type came from a plugin that has since been deleted stays manageable.

## 8. Declared vs. existing — `registered_only()`

A type declares what it WANTS; the site decides what EXISTS. A companion typically registers
one dispatcher per area it covers, but a dispatcher whose group has no members never registers
an ability — so a site without, say, GeoDirectory still sees `toolset/geodirectory` declared.

`registered_only()` narrows any declared or curated slug list to abilities actually registered
here. It runs on a type's tools, on a server's curated rows, and on the `expose` pool. Left
unfiltered, the Tools tab's write is refused with "One or more submitted ability slugs are not
registered on this site" and `expose` advertises tools that do not exist.

It **returns the input unchanged when the ability registry is empty**, rather than returning
nothing. An empty registry means abilities have not been registered *yet* — not that every
declared slug is invalid — and returning `[]` there would make Reset wipe the server.

Filtering belongs here rather than in the contributing plugin: a contributor declares its
slugs while abilities are still being assembled and cannot know which will survive, whereas by
the time anything ASKS for a type's tools the registry is populated.

Note this **filters, never deletes**. A curated presence row naming an ability whose plugin is
deactivated is hidden, not removed, so the operator's selection returns intact on
reactivation.
