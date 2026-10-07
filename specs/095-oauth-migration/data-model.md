# Phase 1 Data Model: OAuth + AI Connectors Migration

Four BerlinDB modules move from the companion. Column sets and index names are reproduced **verbatim** — the migration copies rows between structurally identical tables, so any drift here becomes data loss or a failed copy.

Each module is `includes/Database/<Module>/{Table,Schema,Query,Row}.php`, namespace `AcrossAI_MCP_Manager\Includes\Database\<Module>`.

---

## Naming transfer

| Module | Source table (companion, left intact) | Destination table | `db_version_key` | `$version` |
|---|---|---|---|---|
| `OAuthTokens` | `acrossai_pro_mcp_oauth_tokens` | `acrossai_mcp_oauth_tokens` | `acrossai_mcp_oauth_tokens_db_version` | `1.0.1` |
| `OAuthAuthCodes` | `acrossai_pro_mcp_oauth_auth_codes` | `acrossai_mcp_oauth_auth_codes` | `acrossai_mcp_oauth_auth_codes_db_version` | `1.0.1` |
| `OAuthClients` | `acrossai_pro_mcp_oauth_clients` | `acrossai_mcp_oauth_clients` | `acrossai_mcp_oauth_clients_db_version` | `1.0.1` |
| `ConnectorApprovedUsers` | `acrossai_pro_mcp_connector_approved_users` | `acrossai_mcp_connector_approved_users` | `acrossai_mcp_connector_approved_users_db_version` | `1.0.0` |

**Registration order is Tokens → AuthCodes → Clients** (`D31`). `ConnectorApprovedUsers` has no ordering dependency.

`$version` values carry over unchanged. These are new tables at the destination names, so they install rather than upgrade; the version simply records which schema generation the rows came from.

---

## Entities

### OAuth Client
A registered AI application. Self-registered via RFC 7591 dynamic client registration, or operator-created.

| Column | Role |
|---|---|
| `id` | surrogate key |
| `client_id` | the client's public identifier |
| `server_id` | owning MCP server — first-class, NOT NULL (`D31`) |
| `client_secret_hash` | hashed secret; **never plaintext** (§III, `B20`) |
| `client_name` | display attribution |
| `redirect_uris` | permitted redirect targets |
| `grant_types` | permitted grants |
| `token_endpoint_auth_method` | e.g. `client_secret_post`, `none` |
| `connector_slug` | which profile bucket this client matched |
| `metadata_fingerprint` | CIMD resolution cache key |
| `created_at` | — |

Indexes: `primary`, `client_id_server_id` (**composite UNIQUE** — the same connector registers on N servers as N rows, per `D31`), `connector_slug`, `metadata_fingerprint`.

### Token
An access or refresh credential. **Stored only as a digest.**

| Column | Role |
|---|---|
| `id` | surrogate key |
| `token_hash` | `char(64)` SHA-256 — the only record of the credential |
| `token_type` | access / refresh |
| `client_id`, `server_id`, `user_id` | binding triple |
| `scope`, `resource` | granted scope; RFC 8707 audience |
| `expires_at`, `revoked` | lifecycle |
| `token_family_id` | rotation family, for RFC 9700 §2.2.2 revocation |
| `created_at` | — |

Indexes: `primary`, `token_hash`, `client_id`, `user_id`, `expires_at`, `token_type`, `token_family_id`, `server_id_client_id`.

> **`token_hash` is the migration's critical column.** The raw token exists only on the AI client; this digest is the sole server-side record. Copy it byte-for-byte — no re-hashing, normalisation, re-encoding, or case change (FR-008). The `token_hash` index must exist before the first authenticated request, since validation is an indexed single-row lookup on the hot path.

### Authorization Code
Short-lived, single-use, exchanged for a token.

| Column | Role |
|---|---|
| `id`, `code_hash` | digest, never the raw code |
| `client_id`, `server_id`, `user_id` | binding triple |
| `redirect_uri` | must match the request |
| `code_challenge`, `code_challenge_method` | PKCE S256 |
| `scope`, `resource` | — |
| `used`, `expires_at`, `created_at` | single-use enforcement |

Indexes: `primary`, `code_hash`, `expires_at`.

> Single-use redemption is a check-then-act hazard under concurrency. `B10` requires an atomic single-statement CAS (`UPDATE … WHERE id = :id AND used = 0`), never `SELECT` then `UPDATE`. The imported code already does this; preserve it.

### Connector Approval
A record that a given WordPress user is approved for a given connector on a given server.

| Column | Role |
|---|---|
| `id`, `server_id`, `connector_slug`, `user_id` | the approval tuple |
| `approved_by` | reviewer, or the user themselves on admin self-bypass |
| `approved_at` | — |

Indexes: `primary`, `server_connector_user` (unique tuple), `server_connector`, `user_id`.

> `approved_by === user_id` indicates an admin self-bypass. `B38` records that this is ambiguous without a discriminator, which is why the self-bypass path fires its own observability action — preserve that action (`D32`, SEC-L1 remediation).

---

## Migration state

Not a table — two option keys, following the house `DONE_OPTION` idiom.

| Key | Shape | Autoload |
|---|---|---|
| `acrossai_mcp_oauth_migration_done` | `1` once every source table is fully drained | no |
| `acrossai_mcp_oauth_migration_cursor` | map of destination table → last copied source `id` | no |

Semantics:

- Read the done-flag first as the early-bail guard.
- Per table, copy up to **200 rows** (filterable) ordered by source `id` ascending, starting after the stored cursor; advance the cursor after each batch.
- Set the done-flag only when **every** table is drained.
- Absent companion tables → skip silently, set done-flag, never create cursor state (FR-012).
- Repeated failure → Site Health critical naming table and cursor position, plus an error-log entry (FR-012).

Copying ordered by ascending `id` with a persisted cursor makes the migration idempotent without needing a uniqueness probe per row: a resumed run starts after the last row it confirmed.

---

## Options and meta carried over

Option keys keep their existing shapes verbatim (FR-014) — they are read by code on both sides during the dormant-companion period:

- `acrossai_mcp_connector_settings_%d_%s`
- `acrossai_mcp_connector_approved_users_%d_%s`
- `acrossai_mcp_connector_pending_approvals_%d_%s`

Per-server meta keys are re-attributed (FR-013):

| From | To |
|---|---|
| `_acrossai_pro_server_settings` | `_acrossai_mcp_server_settings` |
| `_acrossai_pro_pending_users` | `_acrossai_mcp_pending_users` |

`_acrossai_mcp_server_settings` holds `{enabled_slugs[], allow_other, require_admin_approval}` per server, read through `ConnectorSettings::get_server_settings()`. Its first-read seed enumerates every registered profile and collapses `require_admin_approval` by OR across legacy per-slug values — preserved exactly (FR-013).

---

## Constraints carried from memory

- **`A19`** — generic per-entity key-value storage uses the WP-canonical `{prefix}{entity}_meta` shape. The four tables here are purpose-built row sets, not key-value stores, so `A19` does not apply; justification for custom tables is in plan.md §Complexity Tracking.
- **`B7`** — Query writers must filter against `Schema::columns()` before persisting, to block mass-assignment via forged POST keys.
- **`B18`** — `$wpdb` returns TINYINT as string. `revoked` and `used` must not be compared with `1 === $col`; cast or use `! empty()`.
- **`B21`** — BerlinDB v3 spells the auto-update-on-write flag `modified`, not `date_updated`. An unrecognised flag is silently ignored.
- **`B34`** — if a live table's schema drifts from `Schema.php` while the stored `db_version` still matches, INSERTs of the missing column return `false` and callers casting to `(int)` read it as success. The migration must check affected-row counts, not just truthiness.
