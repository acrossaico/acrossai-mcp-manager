# Quickstart: Verifying the OAuth Migration

How to exercise F095 end to end. Tests are ordered by consequence — **Test 1 is the one that can cause irreversible harm**; run it first and stop if it fails.

## Prerequisites

Two environments:

- **Upgrade site** — WordPress with `acrossai-pro` active, at least one AI client genuinely connected and holding a valid token. Take a database snapshot before starting.
- **Clean site** — WordPress with only `acrossai-mcp-manager`, never had the companion.

---

## Test 1 — A live connection survives the upgrade (blocking)

Covers US1, FR-007/FR-008, SC-001.

1. On the upgrade site, confirm a connected client works: issue an MCP tool call from Claude or ChatGPT.
2. Record the baseline:
   ```sql
   SELECT COUNT(*) FROM wp_acrossai_pro_mcp_oauth_tokens;
   SELECT token_hash, token_family_id, server_id FROM wp_acrossai_pro_mcp_oauth_tokens LIMIT 5;
   ```
3. Upgrade the plugin. Load any wp-admin page — this is what triggers migration (R1).
4. **Without re-authorising**, issue another tool call from the same client. **It must succeed.**
5. Confirm digests transferred unaltered:
   ```sql
   SELECT s.token_hash = d.token_hash AS identical
   FROM wp_acrossai_pro_mcp_oauth_tokens s
   JOIN wp_acrossai_mcp_oauth_tokens d ON s.id = d.id
   LIMIT 20;
   ```
   Every row must return `1`.
6. Force the access token to expire; confirm the refresh grant issues a new token and rotates the old one.
7. Revoke one token of a family; confirm the whole family is invalidated.

**If step 4 fails, stop.** `token_hash` was not preserved. Restore the snapshot — there is no other recovery, because the raw token exists only on the client.

---

## Test 2 — Migration mechanics

Covers FR-010 through FR-012, SC-003/SC-004/SC-005, and the `B44` landmine.

```sql
-- Row counts must match source exactly
SELECT 'clients' t, COUNT(*) FROM wp_acrossai_mcp_oauth_clients
UNION ALL SELECT 'tokens',     COUNT(*) FROM wp_acrossai_mcp_oauth_tokens
UNION ALL SELECT 'auth_codes', COUNT(*) FROM wp_acrossai_mcp_oauth_auth_codes
UNION ALL SELECT 'approvals',  COUNT(*) FROM wp_acrossai_mcp_connector_approved_users;

-- Source must be untouched (FR-010: copy, never move)
SHOW TABLES LIKE 'wp_acrossai_pro_mcp_%';
```

- **Idempotency**: `DELETE FROM wp_options WHERE option_name = 'acrossai_mcp_oauth_migration_done';` then load wp-admin again. Row counts must be unchanged — no duplicates.
- **Resumability**: set the cursor to a mid-table id, clear the done-flag, reload wp-admin. The migration must resume from the cursor rather than restarting, and still reach correct totals.
- **Companion absent**: on the clean site, confirm the migration sets its done-flag, writes no cursor state, creates no empty churn, and shows no admin notice.
- **The F083 race**: on a site where `acrossai_mcp_legacy_oauth_cleanup_done` is unset, upgrade and confirm the four new tables **exist and were not dropped**. This is the specific failure FR-004 exists to prevent.
- **Diagnostics disclose nothing** (SEC-002, FR-012): force a batch failure — rename a destination table mid-run, or revoke INSERT on it — then inspect both the Site Health message and `debug.log`. Neither may contain a 64-character hex string, any column value, or the failing statement. Assert mechanically rather than by eye:
  ```bash
  grep -nE '[0-9a-f]{64}' wp-content/debug.log && echo "FAIL: digest leaked" || echo "OK"
  ```

---

## Test 3 — Companion goes dormant by itself

Covers FR-006, SC-007.

With both plugins active after upgrade:

- `mcp_manager_still_owns_oauth()` returns `true`.
- The companion skips table upgrades, cron scheduling and rewrite flush (`acrossai-pro/includes/Activator.php:52`) and asset enqueue (`admin/Main.php:72`, `:173`).
- Nothing is registered twice — check REST routes, server tabs, the cron event, and rewrite rules:
  ```bash
  wp cron event list | grep acrossai_mcp_manager_oauth_cleanup   # exactly one
  wp rewrite list | grep -E 'well-known|authorize|token'          # five rules, no duplicates
  ```
- The Connectors tab renders **once**, served by this plugin.

**Reversed plugin order** (SEC-004). The companion stands down via `class_exists()`, which only resolves once this plugin's autoloader has registered. In the expected configuration that is guaranteed — the probe runs on `plugins_loaded`, and `acrossai-mcp-manager` sorts first alphabetically in `active_plugins`. Neither is pinned by anything, so test the configuration that would break it:

```bash
wp option get active_plugins --format=json          # record the original
# reorder so acrossai-pro precedes acrossai-mcp-manager, then:
wp option update active_plugins '<reordered json>' --format=json
```

Re-run every assertion in this test. All must still hold — in particular, exactly one registration of each route, tab, cron event and rewrite rule. Restore the original order afterwards.

---

## Test 4 — Protocol conformance

Covers FR-001, SC-008, and the §III security checklist.

- RFC 7591 registration via `POST /wp-json/acrossai-mcp-manager/v1/oauth/register`
- Authorization-code grant **with** PKCE S256; a missing or downgraded `code_challenge` is rejected
- An authorization code cannot be redeemed twice
- Refresh rotation; reuse invalidates the whole family
- `GET /.well-known/oauth-authorization-server` and `/.well-known/oauth-protected-resource` return correct metadata
- An unauthenticated MCP call returns `401` with a correct `WWW-Authenticate: Bearer` challenge
- Redirect-URI matching, including loopback-port tolerance
- A token issued for server A cannot act on server B
- Rate limiting fires on the token and registration endpoints

### Discovery documents are ours, not a competitor's (SEC-007, FR-016)

`.well-known` paths are `top`-priority rewrite rules and whichever plugin registers first wins site-wide — so a competing implementation silently redirects clients to a different authorization endpoint. Test against a real competitor, not a mock:

1. Activate a plugin bundling `wp-media/mcp-oauth` — Rank Math SEO and Enable Abilities for MCP both carry it, and it boots with no opt-in.
2. Fetch both documents and confirm they name **this** plugin's endpoints:
   ```bash
   curl -s https://<site>/.well-known/oauth-authorization-server | jq '.issuer, .registration_endpoint'
   curl -s https://<site>/.well-known/oauth-protected-resource   | jq '.resource'
   ```
   A document with no `registration_endpoint`, or a `resource` naming a server the operator never created, means the competitor won.
3. Confirm Site Health reports a **failure** in that situation — not merely that the path returned 200. This is the check FR-016 adds; a reachability-only test passes while clients are being misdirected.
4. Repeat with plugin activation order reversed, since rewrite-rule precedence depends on it.

---

## Test 5 — The free path, with no companion

Covers US2, FR-016 through FR-018, SC-002, SC-009.

On the clean site:

1. Open a server's Connectors tab — the real interface renders, not a promo card.
2. Run Quick Connect, choose one-click connection. The wizard must go from the method grid **straight** to the connector detail screen — no promo step, no add-on setup step, no licence prompt.
3. Connect a client and call a tool.
4. No purchase, trial, install or licence copy anywhere:
   ```bash
   grep -rEn "Activate add-on|Start free trial|money-back|requires the AcrossAI Pro" \
       build/ admin/ includes/ public/ src/
   ```
   Must return nothing — **including `build/`**, since stale bundles outlive source deletions.

---

## Test 6 — All five profiles plus n8n

Claude, ChatGPT, Gemini, Grok and Cursor each complete a connection end to end. Then issue an n8n bearer token and make an authenticated MCP call with it; confirm the token is shown once and stored only as a hash.

---

## Test 7 — Uninstall

Covers FR-005, FR-027, and `A20`.

With `acrossai_mcp_uninstall_delete_data` set to `1` and the companion still installed:

- The four new `acrossai_mcp_*` tables are dropped — this is what FR-005 preserves by **keeping** them in the drop list.
- `acrossai_mcp_connector_%` options **survive** — the companion still owns them until its OAuth is stripped in the follow-up.

---

## Gates

```bash
composer run phpcs      # zero errors, zero warnings
composer run phpstan    # zero errors at the configured level (see plan.md C3)
composer test           # all suites, including ported companion tests
npm run lint:js
npm run build
npm run validate-packages
```

Plus the namespace and text-domain gate — must return nothing but the documented migration-source exceptions:

```bash
grep -rEn "AcrossAI_Pro|ACROSSAI_PRO_|'acrossai-pro'|can_use_premium_code|mcp_manager_still_owns_oauth|LegacyOAuthCleanup|AIConnectorsPromoTab" \
    --include='*.php' --include='*.jsx' --include='*.js' \
    includes/ admin/ public/ templates/ src/ acrossai-mcp-manager.php uninstall.php
```
