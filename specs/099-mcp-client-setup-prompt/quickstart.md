# Quickstart — verifying the Generate-Prompt connect path

End-to-end verification, in the order that fails fastest.

## 0. Rule out the environment first

**B69**: Local by Flywheel's nginx omits `fastcgi_param HTTP_AUTHORIZATION`, so Application
Passwords resolve to user 0 and a perfectly correct configuration looks broken. Confirm an existing
client still connects before concluding anything about this feature.

## 1. Unit — the client contract (no WordPress needed)

```bash
vendor/bin/phpunit --testsuite mcpclients
```

For each of the sixteen clients, `get_setup_prompt()` must contain the server URL, the top-level
key, `WP_API_USERNAME`, every declared OS path, and the placeholder — and **no** credential. A bare
third-party subclass registered through `acrossai_mcp_client_classes` must also produce a usable
prompt (FR-021).

This suite runs without a WordPress bootstrap by contract (A18 / SC-003). If it suddenly needs one,
the prompt builder has reached for a WP function and must be changed, not the bootstrap.

## 2. Unit — byte-identity, including under translation

The configuration block inside the prompt must equal the manual path's character for character, and
must be **unchanged in a localized build** (FR-003, FR-026). Nothing else catches a translator
localising a JSON key or a file path.

## 3. Unit — the credential route gates

```bash
vendor/bin/phpunit --bootstrap tests/bootstrap-wp.php --testsuite admin
```

- Clear `WP_Error` when `wp_is_application_passwords_available_for_user()` is false
- Denial when `user_has_server_access()` is false, with `acrossai_mcp_access_control_denied` fired
- **Fails open** when the Access Control package is absent (D19)
- Response carries no-store headers
- Exactly **one** registration of `/generate-app-password` still exists (SC-013)

## 4. Both surfaces, side by side

Open the same server and client in both:

- `admin.php?page=acrossai_mcp_manager&action=edit&server=<id>&tab=connect`
- `admin.php?page=acrossai_mcp_manager&quick-connect=1&step=11&server=<id>`

| Check | Expect |
|---|---|
| Prompt text | **Identical** between the two (FR-012) |
| Config paths | Every declared OS, labelled, in both (FR-013) |
| Claude Desktop in the wizard | Windows path shown, not only macOS — this is the bug being fixed |
| Before generating | Placeholder visible, no credential (FR-007) |
| After generating | Real password spliced into both prompt and config block |
| Switching client | Password cleared, placeholder restored (FR-010) |
| Manual path | Still present and unchanged (FR-011) |
| Disclosure | Visible beside the copy control, with revocation one step away (FR-020) |

## 5. OpenCode specifically

The reason the splice fix exists. Generate a password on the OpenCode client and confirm it lands
in `environment` — not silently nowhere. Check on **both** surfaces; only the React one works today.

## 6. Repeat generation

Generate twice for the same client and server. The first credential must still **work**, and the
interface must show how many exist with a link to revoke each individually (FR-025). Confirm both
appear in the Tokens tab by name (FR-019).

## 7. Administrator-only

Confirm a lower-capability user reaching a surface that shows connection steps sees the manual path
and **no** generation control, and that the screen renders without error (FR-024, SC-014).

## 8. The actual point

Copy the Claude Code prompt, paste it into a real Claude Code session, and confirm the client ends
up connected. Everything above can pass while this fails — that is the only check that proves the
prompt is useful rather than merely well-formed.

## 9. Gates

```bash
composer phpcs
vendor/bin/phpstan clear-result-cache && composer phpstan   # warm cache invents errors CI never sees
npm run lint:js
npm run build                                                # expect no bundle drift beyond this feature
vendor/bin/phpunit --bootstrap tests/bootstrap-wp.php --testsuite renderers
```
