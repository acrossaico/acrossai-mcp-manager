# Contract: REST — tools, type and policy

All routes are admin-only and mutating or admin-scoped; every one has an explicit
`permission_callback` checking `manage_options`. No `__return_true`.

## `GET /servers/{server_id}/tools`

Existing route. Response gains two fields.

```jsonc
{
  "tools": ["…"],                    // CONFIGURED set — what the operator curated
  "effective_tools": ["…"],          // NEW: what the server ACTUALLY serves right now
  "server_type": "acrossai",         // NEW
  "tools_default_policy": "per-tool",// NEW: expose | hide | per-tool
  "type_available": false,           // NEW: ServerTypes::is_available() for this row
  "type_label": "AcrossAI",          // NEW: or the raw slug when unrecognised
  "type_pool": ["…"],                // NEW: ServerTypes::pool_for() — every tool THIS
                                     //      server may offer; drives the picker
  "server_types": [                  // NEW: the registry, for the type selector
    { "slug": "acrossai", "label": "AcrossAI", "available": true }
  ]
}
```

**`type_pool` is not optional for a correct client.** The pool is type-dependent, so a
client that keeps its own copy across a type switch renders the previous type's tools and
counts against the wrong denominator. Both WRITE responses return it for the same reason —
see below.

**`tools` vs `effective_tools` is the architecture-review fix.** `tools` comes from
`ToolPolicy::compose_for_row()` (configured); `effective_tools` from
`compose_effective_tools_for_row()` (configured, then standing policy, then the
unmet-requirement swap). Returning only one of them is what would let the Tools tab show a
list the server is not serving.

When the two differ, the UI MUST explain why — unmet requirement, or a standing `expose`/`hide`
rule. `type_available: false` drives the two remedies (install the add-on, or switch type).

## `POST /servers/{server_id}/tools`

Existing route. Accepts an OPTIONAL `server_type` so that a type switch and its resulting
tool set are **one atomic write** — the two can never end up disagreeing.

```jsonc
{
  "tools": ["…"],
  "server_type": "mcp-adapter"   // optional
}
```

- `server_type` validated against `ServerTypes::all()`; unknown → `400` with
  `acrossai_mcp_invalid_server_type`.
- The response repeats `type_pool` and `server_types` **recomputed for the type actually
  written**, so the client never has to infer the new pool. Omitting them left the tab
  showing "3 of 17" with the previous type's tools still listed until a page reload.
- When `server_type` changes and `tools_default_policy` is `expose` or `hide`, the policy is
  reset to `per-tool` in the same write (FR-012a).

## `POST /servers/{server_id}/tools/policy`

New route. Sets the standing tool rule.

```jsonc
{ "policy": "expose" }   // expose | hide | per-tool
```

- Enum-validated; unknown → `400`.
- Does NOT alter the curated presence rows — switching back to `per-tool` restores exactly
  the prior selection.
- Returns the same reconciliation payload as `POST /tools` (`tools`, `effective_tools`,
  `type_pool`, `server_types`), so both write paths leave the client in a consistent state.

### Write responses are computed from the WRITTEN state, never re-read

Both handlers reflect the columns they just wrote onto the row in hand instead of
re-fetching. BerlinDB's singleton `Query` can serve a memoized PRE-write row inside the same
request, which made a correct write report a stale result ("serving 26 while 3 are
configured"). Storage was right throughout; only the response disagreed.

## Enablement refusal (not a REST route of this feature)

The gate surfaces through existing enable paths rather than a new endpoint:

| Path | File | Behaviour when requirement unmet |
|---|---|---|
| Single toggle | `admin/Partials/Settings.php:238` | Refuse; admin notice names the missing plugin |
| Bulk enable | `admin/Partials/Settings.php:288` | **Partial success** — enable eligible, skip ineligible, name each skipped row and why |
| Quick Connect | `includes/REST/QuickConnectController.php:729` | Refuse with `WP_Error`; the wizard surfaces it |

Disabling is permitted on all three paths unconditionally.

`includes/MCP/Controller.php:357` matches an `is_enabled` grep but is a READ
(`has_any_enabled_server()`) — do NOT gate it.
