# Phase 1 — Data Model: Server Types

## Entity: Server row (extended)

Table `{$wpdb->prefix}acrossai_mcp_servers`. Two columns added by migration `1.1.6`.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `server_type` | `varchar(32)` | NO | `'mcp-adapter'` | The type's slug. Default is load-bearing — it backfills existing rows during the ALTER. |
| `tools_default_policy` | `varchar(16)` | NO | `'per-tool'` | `all` \| `none` \| `per-tool`. Sibling of the existing `abilities_default_policy`. |

**Row.php**: both added as `public string` properties with matching defaults, and both added
to `to_array()`.

### Validation rules

- `server_type` accepted on write ONLY if present in `ServerTypes::all()`. Unknown → reject
  (REST `WP_Error` 400; admin form re-render with error). Never silently coerced.
- `server_type` on READ is permissive: an unrecognised stored slug MUST NOT fatal —
  `ServerTypes::get()` returns `null`, `tools_for()` falls back to `mcp-adapter`'s tools, and
  the UI renders the raw slug marked unavailable (FR-010).
- `tools_default_policy` accepted only from the three-value enum; unknown → reject.
- Neither column is ever written from unfiltered `$_POST`; writers filter against
  `Schema::columns()` (B7).

### State transitions

**`server_type`**

```
(pre-090 row)  --migration 1.1.6-->  'mcp-adapter'
                                          |
   AcrossAI row only, same callback,       |
   gated on column-just-created  ----------+--> 'acrossai'
                                          |
   operator switch (confirmed) -----------+--> any available type
                                          |
   sibling deactivated --------------------+--> UNCHANGED (never auto-switched)
```

The migration's corrective UPDATE runs ONLY in the pass that creates the column. A later
re-run must not revert an operator's deliberate switch.

**`tools_default_policy`**

```
(pre-090 row) --migration--> 'per-tool'  (today's behaviour preserved)
       'per-tool' <--> 'all' | 'none'    (operator, Tools tab)
       'all'|'none' --> 'per-tool'       (AUTOMATIC on a type change, FR-012a)
```

**`is_enabled`** — unchanged shape, new guard:

```
off --> on   GATED: refused when ServerTypes::is_available( row.server_type ) === false
on  --> off  ALWAYS permitted
on  --> on   never auto-flipped to off when a requirement later becomes unmet
```

**Enforcement location (architecture-review resolution).** Violation detection rated
call-site enforcement High: the plan's write-path list was built by grep, and the security
review then found a path it had missed. A list that cannot maintain itself is a convention,
not a boundary.

Resolution — **one write facade, plus a gate that fails when a new caller appears**:

- `ServerEnablement::set( int $server_id, bool $enabled ): true|WP_Error` becomes the ONLY
  sanctioned way to change `is_enabled`. It resolves the row, consults
  `ServerTypes::enablement_error()` on an off→on transition, and returns `WP_Error`
  unchanged to the caller so each surface can render its own message.
- All THREE existing writers route through it: `Settings.php:238` (toggle), `Settings.php:288`
  (bulk), `QuickConnectController.php:729`.
- **A grep gate in `bin/verify-f021-gates.sh`** fails CI on any `'is_enabled' =>` write
  outside `ServerEnablement` and `DefaultServerSeeder`. This is the part that makes the
  boundary self-maintaining — a future contributor adding a fourth path is stopped by CI
  rather than by someone remembering this review.

Rejected: enforcing inside `MCPServer\Query::update_item()`. It is a BerlinDB base-class
method also used by the seeder and migrations, so a guard there would fight legitimate
plugin-owned writes and couple schema plumbing to type policy.

### Seeder buckets (`DefaultServerSeeder`)

| Row | `server_type` bucket | Value | Why |
|---|---|---|---|
| `mcp-adapter-default-server` | `managed` | `'mcp-adapter'` | Plugin-owned identity; re-asserted every run. |
| `acrossai-mcp-server` | **`initial`** | `'acrossai'` | Operator must be able to switch it as the escape hatch; `managed` would revert them on the next `admin_init`. |
| either | *(neither bucket)* | — | `tools_default_policy` is operator-owned everywhere; reconciliation never writes it. |

Because `initial` only writes at INSERT, the AcrossAI row's correct value on an existing
install comes from the migration's one-row UPDATE, not the seeder.

---

## Entity: Server type (in-memory, not persisted)

Registry entries resolved per request from `ServerTypes::all()`. No table, no option.

| Field | Type | Required | Notes |
|---|---|---|---|
| *(key)* | `string` | yes | Slug. `sanitize_key()`; empty → entry dropped. |
| `label` | `string` | yes | Human name. Missing → entry dropped. |
| `description` | `string` | no | Defaults to `''`. |
| `tools` | `string[]` | no | Defaults to `[]`. Normalised: `strval` → drop empties → `array_unique` → `array_values`. |
| `requires` | `?string` | no | Plugin folder slug. `null`/absent = always available. |
| `is_default` | `bool` | no | Defaults `false`. |

**Seeded by this plugin**

```
'mcp-adapter' => label "MCP Adapter", tools = ToolPolicy::PROTOCOL_TOOLS
'acrossai'    => label "AcrossAI",    tools = [],
                 requires = 'acrossai-abilities-manager', is_default = true
```

### Registry rules

- **Dedup**: slug-keyed **LAST-WINS** (D41) — a companion re-registering `acrossai` replaces
  the placeholder. This is the override mechanism, not an accident.
- **`default_slug()`**: last `is_default` entry **whose `requires` is satisfied**; otherwise
  `mcp-adapter`, which is always registered and always available (the floor). A site without
  the sibling therefore never defaults to a type it cannot use.
- **`is_available( $slug )`**: the SINGLE resolver for "requirement met". Selection,
  enablement and runtime all call it; none re-derives the rule (B32).
- **`tools_for( $slug )`**: the type's tools, or `mcp-adapter`'s when the slug is unknown.
- **Not memoised across the request boundary**; resolved from a filter with **no DB access**.
- Uses its own small normalizer, NOT `Utilities\RegistryEntryNormalizer` — that helper drops
  any non-built-in entry lacking a callable `render_callback`, and a type is pure data.
- Naming mirrors `ToolAbilities`' shape; per **D55**, accessor names must not mean something
  different here than they do there.

---

## Derived set: the server's tool POOL

Not persisted, resolved per request by `ServerTypes::pool_for( $server_type )`:

```
pool(T) = every tool-level ability registered on this site
          MINUS every slug claimed by a type OTHER than T
```

A slug no type claims belongs to every type — nothing has asserted where it goes — so a
third-party tool-level ability is offered on every server until some type claims it.

The pool is the definition `expose` uses, which is what makes that rule STANDING rather than
a snapshot: an ability registered tomorrow by a plugin installed tomorrow lands in the pool
and is exposed with no admin action. Scoping `expose` to the type's own declared list would
silently exclude everything the type does not already name.

It is also what the picker renders (returned as `type_pool`), so the UI cannot offer a tool
the write path would reject. The pool is TYPE-DEPENDENT: it must be recomputed on every type
change, which is why both write responses return it.

## Derived set: registered-only narrowing

`ServerTypes::registered_only( $slugs )` narrows any declared or curated list to abilities
that actually exist here. A type declares what it WANTS; the site decides what EXISTS.

Applied to a type's `tools`, to a server's curated rows, and to the pool. It **filters, never
deletes** — a presence row naming an ability whose plugin was deactivated is hidden, not
removed, so the operator's selection returns intact on reactivation.

Returns its input unchanged when the ability registry is empty: "not registered yet" is not
"invalid", and returning `[]` there would make Reset wipe the server.

## Entity: Diagnostic ability (`SetupRequired`)

Registered only while a server's type has an unmet requirement. Not persisted.

| Aspect | Value |
|---|---|
| Purpose | Tell a connected AI client why the server has no tools |
| Content | Description AND return value both name the required plugin |
| Localisation | Translatable like every other string (clarification Q3) |
| Disclosure | Public plugin name only — no paths, versions, or site config |
| Authorization | Carries no privilege; must not become a path that skips another ability's `permission_callback` (D24) |
| **Scope (SEC-002)** | Registered site-globally by `wp_register_ability()`, so it MUST be explicitly excluded from the general abilities surface: kept out of `ToolAbilities::get_slugs()`, hidden from `mcp-adapter/discover-abilities`, and admitted ONLY to the effective tool list of a server whose own requirement is unmet. Without this it would surface on healthy, unrelated servers. |

---

## Relationships

```
Server row ──(server_type slug)──> Server type   [many-to-one, soft FK]
                                        │
                              requires  └──> Plugin (active?) ──> is_available()
                                                                      │
Server row ──(is_enabled off→on)────────── GATED BY ──────────────────┘
                                                                      │
Server row ──(tool_* cols + presence rows)──> composed tool list      │
                       │                                              │
              tools_default_policy ('all'/'none') overrides           │
                       │                                              │
              unmet requirement REPLACES the whole list ──────────────┘
                       with the single diagnostic entry
```

**Precedence when several rules apply** (highest first):

1. Unmet requirement → single diagnostic entry (a server cannot advertise tools that are not
   registered).
2. `tools_default_policy` = `all` / `none`.
3. `per-tool` → existing composer: `tool_*` columns ∪ curated presence rows.

The server type's tool set is NOT in this chain — it is a *template* that writes into layer 3
when Reset or a type switch runs, never a runtime filter.

### Where the chain is implemented (architecture-review resolution)

Violation detection flagged the chain being split across `ToolPolicy` and `MCP\Controller`,
which would let REST and MCP disagree. Resolution — **two methods with genuinely different
semantics, one implementation of each rule**:

| Method | Answers | Layers applied |
|---|---|---|
| `ToolPolicy::compose_for_row()` | *"What has the operator CONFIGURED?"* | layer 3 only |
| `ToolPolicy::compose_effective_tools_for_row()` | *"What does this server ACTUALLY SERVE?"* | 1 → 2 → 3 |

This deliberately ENDS the "straight passthrough" relationship the two methods have today
(`ToolPolicy.php:163-164`). They were identical only because no rule had yet distinguished
them; layers 1 and 2 are exactly that distinction.

Rules:

- **Both new layers live inside `compose_effective_tools_for_row()`.** Neither
  `MCP\Controller` nor any REST handler re-derives them. This is the
  DEC-ABILITY-OVERRIDE-RESOLUTION single-resolver rule applied to tool composition.
- **Every MCP registration path uses the effective method** — including
  `Controller.php:322` (the `mcp_adapter_default_server_config` path), which the first draft
  of this plan missed.
- **The Tools tab edits the CONFIGURED set**, so an operator can still see and change their
  curated picks while the requirement is unmet. Collapsing both questions into one method
  would leave them editing a list they cannot see.
- REST returns both, so the UI can say "you configured X; the server is currently serving Y
  because Z" rather than silently showing one and serving the other.
