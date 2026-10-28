# Phase 1 Data Model — Generate-Prompt connect path

**No persistent storage is added.** No table, no option, no post meta. Everything below is either
an in-memory value produced per request, or a record WordPress core already owns.

---

## MCP client (existing — gains one member)

A pure service object describing one AI client. Lives in `includes/MCPClients/`, enumerated only
through `AbstractMCPClient::get_all_registered_clients()`.

| Member | Type | Status | Notes |
|---|---|---|---|
| `get_client_slug()` | `string` | existing, abstract | `/\A[a-z0-9-]{1,64}\z/` |
| `get_client_name()` | `string` | existing, abstract | display name |
| `get_config_snippet( $url, $token )` | `string\|array` | existing, abstract | union by contract; all sixteen built-ins return arrays |
| `get_config_files()` | `array<string,string>` | existing, virtual | OS slug → path; deliberately partial |
| `get_config_file()` | `string` | existing, virtual | single legacy path; fallback only |
| `get_top_level_key()` | `string` | existing, virtual | key the server entry nests under |
| `get_restart_step_text()` | `string` | existing, virtual | how to make the client reload |
| **`get_setup_prompt( $url, $token )`** | **`string`** | **NEW, virtual** | defaulted; zero per-client overrides required |

**Validation rules**

- `get_setup_prompt()` MUST be non-abstract. An abstract member breaks every third-party subclass
  registered through `acrossai_mcp_client_classes` (FR-021).
- Its default MUST compose only the accessors above. No new per-client data is introduced.
- It MAY call WordPress functions that the WP-free bootstrap stubs — `__()` and `esc_html__()` are
  already stubbed under `function_exists()` guards, and `get_restart_step_text()` already relies on
  this. Translating the prose (FR-026) is permitted on that basis. It MUST NOT reach for an
  unstubbed function: `tests/phpunit/MCPClients/` must keep running without a WordPress bootstrap
  (A18 / SC-003).
- It MUST honour the `string|array` contract of `get_config_snippet()` via `is_array()`, even though
  no in-tree client returns a string today.

---

## Connection instructions (new — transient value, no identity)

The assembled prompt. Produced per request, never stored, never cached beyond the DTO producer's
existing per-request memoisation.

| Field | Source | Constraint |
|---|---|---|
| prose | translated via `acrossai-mcp-manager` text domain | FR-026 |
| config file paths | `get_config_files()` → fallback `get_config_file()` | every declared OS, labelled, no UA sniffing (FR-002, FR-013) |
| top-level key | `get_top_level_key()` | never translated |
| configuration block | `get_config_snippet()` encoded `JSON_PRETTY_PRINT \| JSON_UNESCAPED_SLASHES` | byte-identical to the manual path, including under translation (FR-003, FR-026) |
| username | `current_username()` | required alongside the password (B19 / FR-005) |
| password | `EMPTY_TOKEN_PLACEHOLDER` at build time | real value substituted client-side only (FR-007, FR-008) |
| reload instruction | `get_restart_step_text()` | FR-006 |

**State**: none. The prompt is a pure function of (client, server). Two calls with the same inputs
MUST produce identical output.

---

## Client DTO (existing — gains one field)

Produced by `ConnectionMethodRegistry::get_clients( ?array $server = null )`. The single shape both
the PHP renderer and the React wizard read (D43).

```
[
  'category'     => 'client',
  'slug'         => string,
  'name'         => string,
  'description'  => string,
  'icon'         => string,
  'instructions' => string,
  'restartStep'  => string,
  'meta'         => [ 'config_file', 'config_files', 'top_level_key', 'class' ],

  // present ONLY when $server !== null:
  'config'       => string,   // existing
  'setupPrompt'  => string,   // NEW
]
```

**Validation rules**

- `setupPrompt` is **additive and present-or-absent** — never renames or removes an existing key
  (D43). Consumers that do not know it are unaffected.
- Present only when a server is resolved, matching `config`'s existing condition (FR-012).
- Built with an **empty** token, so it carries the placeholder and never a credential (FR-008).
- String fields remain unescaped by deliberate contract (SEC-035-002); consumers escape at the
  render boundary.

---

## Issued credential (existing — WordPress core owns it)

A WordPress Application Password belonging to the requesting user.

| Attribute | Owner | Notes |
|---|---|---|
| password value | WP core | hashed by core; never persisted by this plugin (S3) |
| `uuid` | WP core, retained by this feature | enables revoking one credential without touching the user's others (FR-018, B72) |
| name | this feature | MUST carry `APP_NAME_PREFIX` so it appears in the Tokens tab and `/list-app-passwords` (FR-019) |
| user | `get_current_user_id()` only | never a request-supplied id (FR-016, SEC-013-002) |

**Lifecycle**: create-only. Generating again creates another and **leaves every earlier credential
working** (FR-025). Accumulation is intended; the interface is responsible for showing the count and
linking to per-credential revocation. There is no replace, expiry or automatic cleanup in scope.

---

## Activity record (existing table — one new row kind)

Issuance is recorded in `acrossai_mcp_activity_log` (FR-027), reusing the existing schema.

| Column | Value |
|---|---|
| `event_type` | `ActivityLogConstants::EVENT_REQUEST` |
| `event_source` | `ActivityLogConstants::SOURCE_AUTH` — declared, previously unused |
| `result_status` | the outcome; **not** a smuggled event name (see the constant's own docblock) |

No schema change, so no table-version bump and no `$upgrades` callback — which is precisely the
point, since `D28`/`B34` record that getting that pairing wrong silently loses writes.
