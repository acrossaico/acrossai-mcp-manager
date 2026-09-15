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
  "tools_default_policy": "per-tool",// NEW: all | none | per-tool
  "type_available": false,           // NEW: ServerTypes::is_available() for this row
  "type_label": "AcrossAI"           // NEW: or the raw slug when unrecognised
}
```

**`tools` vs `effective_tools` is the architecture-review fix.** `tools` comes from
`ToolPolicy::compose_for_row()` (configured); `effective_tools` from
`compose_effective_tools_for_row()` (configured, then standing policy, then the
unmet-requirement swap). Returning only one of them is what would let the Tools tab show a
list the server is not serving.

When the two differ, the UI MUST explain why — unmet requirement, or a standing `all`/`none`
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
- When `server_type` changes and `tools_default_policy` is `all` or `none`, the policy is
  reset to `per-tool` in the same write (FR-012a).

## `POST /servers/{server_id}/tools/policy`

New route. Sets the standing tool rule.

```jsonc
{ "policy": "all" }   // all | none | per-tool
```

- Enum-validated; unknown → `400`.
- Does NOT alter the curated presence rows — switching back to `per-tool` restores exactly
  the prior selection.

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
