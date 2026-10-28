# Contract — credential issuance route

**No new route.** This feature changes the behaviour of one existing endpoint and adds no second
registration (FR-023, SC-013).

| | |
|---|---|
| Route | `POST /wp-json/acrossai-mcp-manager/v1/generate-app-password` |
| Served by | `admin/Partials/ApplicationPasswords.php::generate_app_password()` |
| Auth | `manage_options` **and** per-server access |

## Required behaviour added

| # | Requirement | Failure mode without it |
|---|---|---|
| FR-014 | `wp_is_application_passwords_available()` and `..._for_user()` checked before issuing; a clear message naming the cause on failure | A site with App Passwords disabled gets a generic failure |
| FR-015 | `user_has_server_access()` **before** issuing; `acrossai_mcp_access_control_denied` fired with a distinct context on denial; fails **open** when the package is absent (D19) | D33 violation — mandatory for every connection-time credential surface |
| FR-016 | Issued only for `get_current_user_id()` | Request-supplied id would allow impersonation (SEC-013-002) |
| FR-017 | `CacheHeaders::apply_to_rest_response()` | A plaintext credential cached by an intermediary |
| FR-018 | Returned `uuid` retained | Revocation can only be done bluntly (B72) |
| FR-019 | Name carries `APP_NAME_PREFIX` | Credential invisible in the Tokens tab and `/list-app-passwords` |
| FR-027 | Issuance recorded: `EVENT_REQUEST` + `SOURCE_AUTH` | No answer to where a pile of credentials came from |

## Explicitly NOT added

- **No rate limiting** (FR-028). An administrator can already mint unlimited credentials through
  WordPress itself.
- **No second registration.** Two handlers already register this path and only the first-registered
  serves requests, leaving the stricter Feature-013 handler unreachable. Resolving that changes a
  ratified security model and is deferred to its own issue — note that the unreachable handler
  exists *for* the front-end contexts now being removed, so it may resolve by deletion.

## Response

Unchanged in shape — `{ success, password, username, app_id, message }` — with `username` already
present and required by FR-005 / B19.
