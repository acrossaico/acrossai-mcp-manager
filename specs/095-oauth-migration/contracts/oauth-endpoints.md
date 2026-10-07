# Contract: OAuth + Connector Endpoints

**Every identifier in this document is a live contract with already-connected AI clients.** They are reproduced byte-identical from the companion (FR-014). Drift does not fail loudly — it silently breaks production connections, because clients hold tokens and endpoint URLs issued under the old names.

Grep-gate this file's contents before and after the move.

---

## 1. Non-REST protocol endpoints (rewrite rules)

Registered via `OAuthRouter` at `top` priority, dispatched through `parse_request`. **Not** REST routes.

| Pattern | Purpose |
|---|---|
| `^\.well-known/oauth-authorization-server/?$` | RFC 8414 authorization-server metadata |
| `^\.well-known/oauth-protected-resource/?$` | RFC 9728 protected-resource metadata |
| `^\.well-known/oauth-protected-resource/(.+?)/?$` | RFC 9728, per-server |
| `^authorize/?$` | authorization endpoint (consent surface) |
| `^token/?$` | token endpoint (code exchange + refresh) |

Query vars: `acrossai_mcp_oauth`, `acrossai_mcp_oauth_resource`.

**Timing**: registration must occur on a hook wired from `Main::define_public_hooks()`, never earlier — `add_rewrite_rule()` before `init` fatals (`B42`, R5). Flush on activation only.

---

## 2. REST routes — namespace `acrossai-mcp-manager/v1`

The namespace is **unchanged**. The companion already registered under this plugin's namespace, which is what makes the handover invisible to clients.

### Public protocol routes

| Method | Route | `permission_callback` |
|---|---|---|
| `POST` | `/oauth/register` | public — RFC 7591 DCR; authenticates by protocol, not capability |

`__return_true` is permitted here, on the token endpoint, and on discovery metadata **only**. §III and `S2`/`S7`/`S8` govern; every other route checks `manage_options`.

### Administrative routes — all `manage_options`

| Method | Route | Purpose |
|---|---|---|
| `POST` | `/oauth/generate-client` | operator-created client credentials |
| `GET`/`POST` | `/oauth/connector-settings` | per-server connector configuration |
| `GET`/`POST` | `/oauth/server-settings` | per-server OAuth settings |
| `POST` | `/oauth/revoke-client-tokens` | revoke one client's tokens on one server |
| `POST` | `/oauth/revoke-client-tokens-all-servers` | **site-wide** nuclear revoke |
| `POST` | `/oauth/revoke-grant` | revoke one grant |
| `POST` | `/oauth/revoke-connector-tokens` | revoke by connector |
| `POST` | `/oauth/revoke-server-tokens` | revoke all tokens on a server |
| `POST` | `/oauth/revoke-server-approval` | revoke a server-level approval |
| `POST` | `/oauth/revoke-user-approval` | revoke one user's approval |
| `POST` | `/oauth/delete-client` | delete a registered client |
| `POST` | `/oauth/approve-pending-consent` | approve a pending user |
| `POST` | `/oauth/deny-pending-consent` | deny a pending user |
| `POST` | `/oauth/approve-server-pending` | approve at server level |
| `POST` | `/oauth/deny-server-pending` | deny at server level |
| ~~`POST`~~ | ~~`/servers/{server_id}/n8n/bearer/token`~~ | **NOT served by this plugin.** Withdrawn 2026-10-07 (T086) — n8n stayed with the companion, which serves this route from the same `acrossai-mcp-manager/v1` namespace. Listed here so the namespace collision is visible, not as a contract of ours. |

**Tenant-binding invariant (`D31`, `B37`)**: every per-server route requires *and validates* `server_id`. A mismatch returns `WP_Error` `acrossai_mcp_oauth_cross_server` with status 403 and fires a 4-arg `acrossai_mcp_oauth_cross_server_attempted` — which **must never include the owning `server_id`** (SEC-032-001). Accepting only a tenant-scoped identifier without validating the binding is the `B37` cross-tenant escalation pattern.

**Documented carve-out (`D34`)**: `/oauth/revoke-client-tokens-all-servers` deliberately takes only `client_id` and acts site-wide. It fires `acrossai_mcp_oauth_client_revoked_across_all_servers` exactly once and **must not** fire `acrossai_mcp_oauth_cross_server_attempted` — that action is reserved for genuine bypass attempts, keeping the forensic streams separate.

---

## 3. Actions and filters

### Actions fired

| Hook | When |
|---|---|
| `acrossai_mcp_manager_oauth_token_issued` | token issued (initial grant and refresh) |
| `acrossai_mcp_manager_oauth_token_revoked` | token revoked; carries a reason |
| `acrossai_mcp_manager_oauth_authorization_denied` | authorization denied |
| `acrossai_mcp_connector_user_approval_revoked` | 4-arg; triggers the token cascade (`D32`) |
| `acrossai_mcp_connector_admin_self_bypassed` | admin self-approved at `/authorize` (SEC-L1, `B38`) |
| `acrossai_mcp_oauth_cross_server_attempted` | genuine tenant-binding violation |
| `acrossai_mcp_oauth_client_revoked_across_all_servers` | site-wide revoke (`D34`) |
| `acrossai_mcp_access_control_denied` | AC gate denied at `/authorize` (`D33`) |

### Filters consumed

| Hook | Purpose |
|---|---|
| `acrossai_mcp_manager_trusted_proxies` | rate-limiter client-IP resolution |
| `acrossai_mcp_manager_connector_profiles` | third-party connector profile registration |
| `acrossai_mcp_connector_revoke_tokens_on_approval_revoked` | opt out of the revoke cascade (`D32`) |
| `acrossai_mcp_manager_discovery_ai_connectors` | existing seam at `public/Discovery/ConnectionMethodRegistry.php:306` |

The companion's `acrossai_pro_profiles` alias is **dropped** — it existed only to bridge the two plugins.

### Scheduled event

`acrossai_mcp_manager_oauth_cleanup` — daily expiry sweep. Scheduled on activation, cleared on deactivation. Name unchanged.

---

## 4. Behavioural invariants

Imported unchanged; each has a recorded decision behind it. Do not "tidy" any of these during the move.

- **`D27`** — a `client_secret_post` client submitting **no** secret (header and body both empty) falls through to PKCE-only verification rather than `invalid_client`. Applies symmetrically to both grant handlers. Clients that *do* submit a secret are still verified.
- **`D33`** — the Access Control gate runs at `/authorize` **before** consent renders, via `user_has_server_access()`. Fail-open per `D19` when AC is unavailable.
- **`D32`** — revoking a user approval cascades to token revocation, with a filter opt-out. Default-secure.
- **PKCE S256 mandatory** — a missing or downgraded challenge is rejected.
- **Single-use codes** — atomic CAS redemption (`B10`).
- **Refresh rotation** — reuse invalidates the whole `token_family_id`.
- **Audience binding** — a token for one server cannot act on another.
- **Bearer rejection** — `401` with a correct `WWW-Authenticate: Bearer` challenge.
- **Rate limiting** — active on token and registration endpoints.

---

## 5. Verification

Capture the contract surface before the move, from the companion:

```bash
grep -rEn "register_rest_route|add_rewrite_rule|do_action\(|apply_filters\(|wp_schedule_event" \
    --include='*.php' includes/OAuth/ includes/Connectors/ includes/Main.php
```

After the move, the same inventory must be reproducible from this plugin with identical route paths, hook names, and query vars. Only the namespace of the registering class changes.
