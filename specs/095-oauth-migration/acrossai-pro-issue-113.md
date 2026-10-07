## ⚠️ BLOCKING — n8n stays in this plugin, and it is currently half-dead

**This section supersedes the original scope below. Read it first.**

The F095 scope has changed: **connectors go free, n8n stays paid.** `acrossai-mcp-manager` 0.4.0 has removed its n8n code entirely (`N8nTab`, `OAuth\AdminTokenController`, `includes/Integrations/`, the JS/SCSS bundles and all wiring). n8n is this plugin's product again.

That creates a release blocker, because **this plugin's n8n stack is already dormant.**

### Why it is dormant

`mcp_manager_still_owns_oauth()` (`includes/Main.php:1190`) is `class_exists( \AcrossAI_MCP_Manager\Includes\OAuth\AuthorizationController )`. That flipped to `true` the moment F095 landed. Everything inside `bootstrap_oauth_hooks()` (`includes/Main.php:1325`) is behind its early return at `:1326` — **including all four n8n registrations**:

| What | Line | Status now |
|---|---|---|
| n8n per-server tab (`acrossai_mcp_manager_server_tabs`, pri 36) | `includes/Main.php:1433` | **dormant** |
| n8n connect method (pri 40) | `includes/Main.php:1443` | **dormant** |
| `OAuth\AdminTokenController::register_routes` | `includes/Main.php:1446` | **dormant** |
| `maybe_log_n8n_token_issuance` audit action | `includes/Main.php:1453` | **dormant** |

Two pieces are **not** gated, which is what makes the state confusing rather than cleanly off:

| What | Line | Status now |
|---|---|---|
| `acrossai_pro_global_integrations` n8n descriptor (the Settings toggle) | `includes/Main.php:262-285`, inside `load_hooks()` | still runs |
| `maybe_enqueue_n8n_admin_app` | `includes/Main.php:1131`, inside `define_admin_hooks()` | still runs |

So on a site with both plugins today: the **n8n settings toggle still appears and can be switched on**, the JS bundle still enqueues — and the tab never appears and the token endpoint 404s. Turning the feature "on" does nothing visible.

### What has to change here

1. **Lift the four n8n registrations out of `bootstrap_oauth_hooks()`.** They are gated on OAuth ownership, but n8n is not OAuth — it is admin-issued bearer tokens, which is exactly why F095's memory D22 kept the two axes separate. They belong in a method that does not consult the probe.

2. **Keep n8n's token storage in THIS plugin — do not reach into mcp-manager's tables.**

   This is the decided approach. The alternative (calling mcp-manager's
   `AccessTokenRepository`) was considered and rejected: it makes a paid feature
   depend on a free-plugin repository class.

   The reason un-gating the hooks alone is not enough:

   - mcp-manager's `TokenValidator` runs on `determine_current_user` (priority
     20) and reads **mcp-manager's** `AccessTokenRepository` →
     `acrossai_mcp_oauth_tokens`.
   - This plugin's `AdminTokenController` writes via **this plugin's**
     `AccessTokenRepository` → `acrossai_pro_mcp_oauth_tokens`.

   So with only the hooks un-gated, an n8n token is **issued successfully and
   then fails every authentication** — the validator that now runs never reads
   the table it was written to. Silent, and worse than today's visible 404.

   What that means concretely:

   - Un-gate `bootstrap_database_tables()` (`includes/Main.php:1206`) for the
     **tokens and clients** tables. n8n needs tokens plus the durable per-server
     "n8n" admin client row that `ClientRepository::find_admin_client()` looks
     up. It does not need `auth_codes` or `connector_approved_users` — there is
     no authorization-code flow and no consent screen in the n8n path.
   - Re-register a **narrowed `TokenValidator`** on `determine_current_user`,
     scoped to n8n tokens only. Both validators then run; first match wins.
   - The audit needs no change at all: `AdminTokenController` fires
     `acrossai_mcp_manager_oauth_token_issued` itself (`:224`) and
     `maybe_log_n8n_token_issuance` listens, both inside this plugin.

   ### ⚠️ Pre-existing n8n tokens live in BOTH tables — decide who owns them

   F095 copied rather than moved, so every n8n token minted before the split now
   exists in `acrossai_pro_mcp_oauth_tokens` **and** `acrossai_mcp_oauth_tokens`,
   with `connector_slug = 'n8n'`. Two consequences, and neither is hypothetical:

   - **Revocation diverges.** Revoking in mcp-manager's UI updates only its copy.
     An unscoped validator here would keep honouring the row in this plugin's
     table — a revoked token that still works.
   - **Both validators match.** The same digest resolves in both plugins.

   Pick one owner for `connector_slug = 'n8n'` rows and make it explicit:

   - **(a)** mcp-manager excludes `connector_slug = 'n8n'` from its validator and
     from future migration runs; this plugin is sole authority. Cleanest, but
     needs a matching mcp-manager change.
   - **(b)** This plugin's narrowed validator honours only rows it minted after
     the split, and leaves the copied ones alone.

   Do not leave this implicit. "Both tables have the row and both validators
   accept it" is the state that produces a token nobody can fully revoke.

3. **Decide the option namespace.** `acrossai_n8n_enabled` is a frozen public string (memory D22). `acrossai-mcp-manager` no longer reads it. Confirm this plugin's `GlobalIntegrationRegistry` is the only reader.

### Host-side support that `acrossai-mcp-manager` 0.4.0 deliberately keeps

You do **not** need to ask for these; they are already there:

- `ConnectTab::LEGACY_TAB_METHODS['n8n']` — resolves `?tab=n8n` to the Connect tab, so this plugin's legacy addresses keep working.
- `MethodRegistry` **priority 40 is reserved and left unseeded**, with a test asserting both that nothing is seeded there and that a registration at 40 slots between `npm` (30) and `wp-cli` (50). Register the n8n method at 40 and it lands in the right place.
- `AIConnectorsTab` still filters rows with `connector_slug = 'n8n'` out of its Connections panel, so n8n grants do not leak onto the connectors surface regardless of which plugin wrote them.

### Sequencing

This must ship **before or alongside** `acrossai-mcp-manager` 0.4.0. It is **not** part of the deferred cleanup below — the "do not start until F095 has shipped" precondition does not apply to it. Between 0.4.0 shipping and this landing, n8n is dead on every site that has both plugins, which is every Pro customer.

---

## Context

`acrossai-mcp-manager` Feature 095 moves the OAuth 2.1 server and the AI Connectors stack **out of this plugin and into the free plugin**, making one-click AI client connection a free capability. This plugin keeps its Abilities library (~49,781 lines across 301 files), AccessControl **and the n8n integration** as the paid offering.

F095 deliberately **does not touch this repo**. For OAuth and connectors it relies on the existing self-disable probe — the moment `\AcrossAI_MCP_Manager\Includes\OAuth\AuthorizationController` exists, `mcp_manager_still_owns_oauth()` (`includes/Main.php:1190`) returns true and this plugin's OAuth goes dormant on its own.

~~No release coordination needed.~~ **Superseded:** that was true when n8n was moving to the free plugin too. It is not: see the blocking section above.

The rest of this issue tracks the cleanup pass that removes the now-dead OAuth/connector code, **in a later release**.

## Do not start the cleanup below until

- [ ] F095 has shipped in `acrossai-mcp-manager` and been verified on real installs
- [ ] The n8n blocker above is resolved
- [ ] The data migration is confirmed complete wherever this plugin is installed — **our tables are F095's rollback path** and must not be dropped while that path is still needed
- [ ] Live connections confirmed working from the free plugin's tables (an AI client making a tool call without re-authorising)

Dropping these tables early permanently disconnects every connected AI client. Token digests are stored only as SHA-256; the raw token exists solely on the client, so there is no recovery.

## Scope — code to delete

> **n8n is excluded from every row.** `admin/ServerTabs/N8nTab.php`, `includes/OAuth/AdminTokenController.php`, `src/js/n8n-admin.js`, `src/scss/n8n.scss` and `includes/Integrations/` all **stay in this plugin**.

| Area | Files | ~LOC |
|---|---|---|
| `includes/OAuth/` **except `AdminTokenController.php`** (270 LOC — keep) | 17 of 18 | ~6,209 |
| `includes/Database/OAuthAuthCodes/` + `ConnectorApprovedUsers/` only — **`OAuthTokens/` and `OAuthClients/` STAY**, n8n reads them (blocker §2) | 8 | ~1,450 |
| `includes/Database/Support/{DatetimeColumn,TimezoneHealthCheck}.php` | 2 | 300 |
| `includes/Connectors/` | 4 | 1,438 |
| Connector profiles — `{Claude,ChatGPT,Cursor,Gemini,Grok}ConnectorProfile.php` | 5 | 1,865 |
| `admin/ServerTabs/AIConnectorsTab.php` | 1 | 879 |
| ~~`admin/ServerTabs/N8nTab.php`~~ — **KEEP** (1,049) | — | — |
| `includes/Discovery/DiscoveryConnectorAdapter.php` | 1 | 127 |
| `templates/oauth/{consent,message}.php` | 2 | 273 |
| `src/js/ai-connectors.js`, `src/scss/ai-connectors.scss` — **n8n bundles stay** | 2 | ~1,174 |
| ~~`includes/Integrations/`~~ — **KEEP** (406); it is how the n8n settings toggle is registered | — | — |
| OAuth-specific notices in `admin/Partials/Notices.php` | partial | ~244 |
| Tests: `tests/Unit/{OAuth,OAuthDiscovery,Connectors}/` + profile tests — **keep the `AdminTokenController` tests** | 19+ | — |

## Scope — wiring to unpick

- `mcp_manager_still_owns_oauth()` probe (`includes/Main.php:1190`) and its seven call sites: `includes/Main.php:396`, `includes/Activator.php:52`, `includes/Deactivator.php:42`, `admin/Main.php:72`, `admin/Main.php:173`, `uninstall.php:13`, `uninstall.php:45` — **note the n8n registrations must have moved out from behind it first (blocker §1)**
- `includes/HostDependencies.php` (171 LOC) and `includes/HostCapabilities.php` (198 LOC) — OAuth-related entries at minimum. **`HostCapabilities::CONNECT_METHODS_FILTER` is still used by `register_n8n_method()` (`includes/Main.php:1443`), so it cannot be deleted wholesale.** `acrossai-mcp-manager` removed its own copy of `HostCapabilities` in F095; this plugin's copy is independent and still live.
- `bootstrap_oauth_hooks()` in `includes/Main.php` and the connector filter registration at `includes/Main.php:1427` (`acrossai_mcp_manager_server_tabs`, priority 35). **`:1433` (n8n, priority 36) stays.**
- Freemius `can_use_premium_code()` gates for connectors at `includes/Main.php:230` and `:337` — the Abilities library **and n8n** gates stay
- `webpack.config.js:77-91` — remove the `js/ai-connectors` entry and its built artefacts. **`js/n8n-admin` and `css/n8n` stay.**

## Scope — data

**Only two of the four may ever be dropped.** n8n keeps using the other two (blocker §2), so they are now permanent, not transitional:

| Table | Fate |
|---|---|
| `{prefix}acrossai_pro_mcp_oauth_tokens` | **KEEP** — n8n mints into it |
| `{prefix}acrossai_pro_mcp_oauth_clients` | **KEEP** — holds the per-server n8n admin client |
| `{prefix}acrossai_pro_mcp_oauth_auth_codes` | drop |
| `{prefix}acrossai_pro_mcp_connector_approved_users` | drop |

Remove the `*_db_version` options only for the two that are dropped.

Drop even those two only after the preconditions above are met — they are
F095's rollback path until the migration is confirmed everywhere.

Observed volume on acrossai.co (2026-10-06) for reference: 11 clients, 520 tokens, 0 auth codes, 1 approval.

## ⚠️ Cross-repo action — easy to miss

`acrossai-mcp-manager/uninstall.php:95-104` excludes `acrossai_mcp_connector_%` from its option sweep **because this plugin still owns those options**. F095 deliberately leaves that exclusion in place.

Once this issue is done, that exclusion is wrong — it would orphan options on uninstall. **Remove it in `acrossai-mcp-manager` as part of this work**, not separately.

Option keys involved (`includes/Connectors/ConnectorSettings.php:498-520`):
- `acrossai_mcp_connector_settings_%d_%s`
- `acrossai_mcp_connector_approved_users_%d_%s`
- `acrossai_mcp_connector_pending_approvals_%d_%s`

## Verification

**For the n8n blocker (do first):**
- [ ] With `acrossai-mcp-manager` 0.4.0 active, the n8n tab appears when `acrossai_n8n_enabled` is on
- [ ] A bearer token can be generated, is shown once, and is stored only as a hash
- [ ] An authenticated MCP call with that token succeeds
- [ ] Regenerating revokes only the regenerating admin's prior tokens, not other admins'
- [ ] Exactly one plugin writes OAuth token rows — confirm which table received the n8n token
- [ ] The 90-day TTL cap is enforced

**For the cleanup:**
- [ ] Plugin activates cleanly with no OAuth/connector code present
- [ ] Abilities library, AccessControl **and n8n** unaffected
- [ ] No fatal when `acrossai-mcp-manager` is deactivated (the probe is gone — confirm nothing still calls it)
- [ ] Connections served by the free plugin keep working throughout
- [ ] `acrossai-mcp-manager` uninstall no longer leaves `acrossai_mcp_connector_%` options behind
- [ ] PHPCS / PHPStan / PHPUnit green in both repos

## References

- Planning doc: `acrossai-mcp-manager/docs/planings-tasks/095-oauth-migration.md`
- Spec: `acrossai-mcp-manager/specs/095-oauth-migration/spec.md` (FR-023–FR-026 are the explicit non-goals that defer this work)
- n8n reversal and the host-side support kept for it: `acrossai-mcp-manager` tasks T086–T088 in `specs/095-oauth-migration/tasks.md`
- The original split this reverses: `acrossai-mcp-manager` F040, commit `363f170`, PR #57
