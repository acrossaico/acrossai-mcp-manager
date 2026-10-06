# Planning: Migrate OAuth + AI Connectors from acrossai-pro (Feature 095)

Move the OAuth 2.1 authorization server and the entire AI Connectors stack —
~15,700 lines — out of the companion plugin `acrossai-pro` and into this
plugin, making one-click AI client connection a **free** capability. The
promo placeholder that currently occupies the slot
(`admin/Partials/ServerTabs/AIConnectorsPromoTab.php`, 583 lines, rendering an
"ADD-ON / Start free trial" card for Claude, ChatGPT, Gemini, Grok & Cursor) is
deleted and the real `AIConnectorsTab` takes its place. `acrossai-pro` retains
its 49,781-line Abilities library and AccessControl as the paid offering.

This feature **reverses Feature 040** (`363f170`, PR #57, 2026-08-02 —
114 files changed, 14,731 deletions), which moved OAuth *out* of this plugin.
The architecture chosen during that migration is precisely what makes the
reversal tractable, and it must be respected rather than fought:

- `acrossai-pro` already registers its REST routes under **this plugin's**
  namespace `acrossai-mcp-manager/v1`, and already fires this plugin's cron and
  action names verbatim. **Nothing needs renaming** — but nothing may drift
  either, or live clients break.
- `acrossai-pro` self-disables via a single probe,
  `mcp_manager_still_owns_oauth()` (`acrossai-pro/includes/Main.php:1190`),
  which tests `class_exists( '\AcrossAI_MCP_Manager\Includes\OAuth\AuthorizationController' )`.
  **The moment that class lands here, pro's OAuth goes dormant automatically** —
  no lockstep release, no coordinated deploy.
- The promo tab was deliberately built to match the companion's slug
  (`ai-connectors`) and priority (`10`), so the swap is visually seamless
  (`AIConnectorsPromoTab.php:91`, `:111`; Registry dedup is **last-wins**,
  documented at `Registry.php:21-28`).

The migration is **backwards-compatible with live OAuth grants**. This is the
single most important property of the feature: paying customers hold access and
refresh tokens that exist *only* as SHA-256 digests in the database
(`token_hash char(64)`, written via `SecretsVault::hash()`). The raw token lives
solely on the AI client. Any transformation of that column permanently
disconnects every connected client with no recovery path. Rows are therefore
**copied, never moved**, and pro's tables are left fully intact as a rollback
path, to be dropped in a later release.

The one deliberate behavioural change is that **`LegacyOAuthCleanup` is deleted
outright**. Feature 083 added a one-shot sweeper that drops orphaned
pre-F040 OAuth tables — and the four names on its kill-list are exactly the
four names this feature reinstates. See TASK-1; this must land *before* any
table is created.

---

## Speckit Workflow

```markdown
# 1. Branch
/speckit.git.feature "oauth-migration"
```

```markdown
# 2. Specify
/speckit.specify "Migrate the OAuth 2.1 server and AI Connectors stack from the
companion plugin acrossai-pro into acrossai-mcp-manager, making one-click AI
client connection a free capability. Move includes/OAuth/ (25 files: Authorization
Controller, TokenController, ClientRegistrationController, ConnectorAdminController,
AdminTokenController, DiscoveryController, DiscoveryHealthCheck,
DiscoveryConflictGuard, TokenValidator, OAuthRouter, PKCE, CimdResolver,
CimdRegistry, BearerChallengeHeader, CacheHeaders, Cleanup, UserLifecycle,
MessagePage, Security/RateLimiter, Security/SecretsVault, and the five
Repositories), the four BerlinDB modules includes/Database/OAuthClients,
OAuthTokens, OAuthAuthCodes and ConnectorApprovedUsers plus
includes/Database/Support/DatetimeColumn.php and TimezoneHealthCheck.php,
includes/Connectors/ (AbstractConnectorProfile, ConnectorSettings,
ConnectorProfileRegistry, ConnectorSlugDisplay), the five connector profiles
ClaudeConnectorProfile, ChatGPTConnectorProfile, CursorConnectorProfile,
GeminiConnectorProfile and GrokConnectorProfile,
includes/Discovery/DiscoveryConnectorAdapter.php, admin/ServerTabs/
AIConnectorsTab.php and N8nTab.php, templates/oauth/consent.php and message.php,
the OAuth-specific notices from admin/Partials/Notices.php, and the frontend
sources src/js/ai-connectors.js, src/js/n8n-admin.js, src/scss/ai-connectors.scss
and src/scss/n8n.scss. Rewrite the namespace from AcrossAI_Pro\\ to
AcrossAI_MCP_Manager\\Includes\\ and AcrossAI_MCP_Manager\\Admin\\ per the PSR-4
roots in composer.json. DELETE includes/Database/LegacyOAuthCleanup.php and
unwire its call site at includes/Main.php:296, and remove the four
acrossai_mcp_oauth_clients / acrossai_mcp_oauth_tokens / acrossai_mcp_oauth_auth_codes
/ acrossai_mcp_connector_approved_users entries from the uninstall.php drop list,
BEFORE creating any table — that F083 sweeper drops exactly the table names this
feature reinstates. Create the four tables under the acrossai_mcp_ namespace and
copy rows from the companion's acrossai_pro_mcp_oauth_clients,
acrossai_pro_mcp_oauth_tokens, acrossai_pro_mcp_oauth_auth_codes and
acrossai_pro_mcp_connector_approved_users, preserving token_hash, token_family_id
and server_id byte-for-byte; copy never move, leaving the companion's tables
intact as a rollback path. Migrate server meta keys _acrossai_pro_server_settings
to _acrossai_mcp_server_settings and _acrossai_pro_pending_users to
_acrossai_mcp_pending_users. Gate the migration on an
acrossai_mcp_oauth_migration_done option so it is idempotent and resumable, run it
from reconcile_database_schemas() and Activator::activate(), and skip cleanly when
the companion was never installed. Preserve verbatim the REST namespace
acrossai-mcp-manager/v1, the cron hook acrossai_mcp_manager_oauth_cleanup, the
actions acrossai_mcp_manager_oauth_token_issued, _revoked and
_authorization_denied, acrossai_mcp_connector_user_approval_revoked,
acrossai_mcp_manager_trusted_proxies and acrossai_mcp_manager_connector_profiles,
the option keys acrossai_mcp_connector_settings_%d_%s,
acrossai_mcp_connector_approved_users_%d_%s and
acrossai_mcp_connector_pending_approvals_%d_%s, and the five rewrite rules in
OAuthRouter plus the query vars acrossai_mcp_oauth and
acrossai_mcp_oauth_resource. Delete admin/Partials/ServerTabs/
AIConnectorsPromoTab.php and register the real AIConnectorsTab in its slot at
slug ai-connectors priority 10 via MethodRegistry, dropping the
new AIConnectorsPromoTab() seed at MethodRegistry.php:112 and its import at :36.
Rewrite src/js/quick-connect/steps/Step8_ProPromo.jsx into the real connect flow
so the wizard no longer dead-ends on 'requires the AcrossAI Pro plugin'. Restore
the js/ai-connectors and js/n8n-admin webpack entries, replacing the F040
tombstone comment at webpack.config.js:96. Rehome every hook registration from
the companion's bootstrap_oauth_hooks() into Includes\\Main::define_admin_hooks()
and define_public_hooks() via the Loader, per constitution rule A1 — feature
classes MUST NOT call add_action or add_filter themselves. Drop the companion-only
wiring on the way in: HostDependencies.php, HostCapabilities.php, the
mcp_manager_still_owns_oauth() probe, the Freemius can_use_premium_code() gates,
and all ACROSSAI_PRO_* constants. Reconcile the duplicate pair
acrossai-pro/includes/OAuth/CacheHeaders.php against this plugin's existing
includes/Utilities/CacheHeaders.php and keep one. Port the companion's existing
tests from tests/Unit/OAuth, tests/Unit/OAuthDiscovery and tests/Unit/Connectors
rather than resurrecting the pre-F040 suite from git. Do NOT remove the
acrossai_mcp_connector_% exclusion from the uninstall.php LIKE-sweep in this
release — the companion stays installed and dormant, so it still owns those
options until its OAuth is stripped in a follow-up. Do NOT delete any code from
acrossai-pro in this feature. Do NOT change Requires PHP (8.1) or Requires at
least. Do NOT drop the companion's acrossai_pro_mcp_* tables."
```

### Detailed Description for `/speckit.specify`

> **Before writing a single line of code, read and internalize all five of
> these governing documents in full:**
>
> 1. `AGENTS.md` — this plugin's singleton pattern, the A1 hook-registration
>    rule (feature classes never call `add_action`/`add_filter`; everything is
>    wired through the Loader in `Includes\Main::define_admin_hooks()` /
>    `define_public_hooks()`, see `includes/Main.php:862`), and the Before
>    Commit Checklist.
> 2. `docs/planings-tasks/040-migrate-ai-connectors-to-companion.md` and
>    `specs/040-migrate-ai-connectors-to-companion/{spec,plan}.md` — the
>    migration this feature reverses. Its FR-017 hard constraints (do not
>    rename the cron hook, do not change the REST namespace, do not modify
>    `AbstractServerTab` or the `acrossai_mcp_manager_server_tabs` filter
>    mechanics) apply in reverse and still bind.
> 3. The source of truth for every moved file:
>    `/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/plugins/acrossai-pro/`
>    — specifically `includes/OAuth/`, `includes/Connectors/`,
>    `includes/Database/OAuth*/`, `admin/ServerTabs/`, and its
>    `includes/Main.php` `bootstrap_oauth_hooks()` for the authoritative list
>    of hook registrations to rehome.
> 4. `docs/planings-tasks/021-oauth-2-1-implementation.md`,
>    `027-oauth-dcr-default-none.md`,
>    `029-oauth-token-basic-auth-and-dcr-attribution.md`,
>    `032-oauth-per-server-scoping.md` and `phase-6-oauth.md` — the original
>    OAuth design trail from when this plugin owned it. Behaviour described
>    there is the behaviour being restored.
> 5. `docs/planings-tasks/011-berlindb-migration.md` for the BerlinDB
>    Table/Schema/Query/Row conventions, including the phantom-version guard
>    (`if ( ! $this->exists() ) { delete_option( $this->db_version_key ); }
>    parent::maybe_upgrade();`) that every Table subclass in this plugin
>    carries and the four incoming modules must adopt.
>
> Every decision — namespace rewrite, hook rehoming, table naming, migration
> ordering — must be justified against the above. If a choice is not explicitly
> covered, default to the shape already used by this plugin's five existing
> BerlinDB modules (`MCPServer`, `MCPServerAbility`, `MCPServerMeta`,
> `MCPServerTool`, `CliAuthLog`). Do not write code that would fail any
> Definition-of-Done gate: PHPStan, PHPCS, security review, and all `__()`
> calls using the correct text domain `'acrossai-mcp-manager'` (the moved code
> currently uses `'acrossai-pro'` — **every string must be retranslated**).
>
> **Identifiers to preserve verbatim (grep-gate before + after).** These are
> contracts with live, already-connected AI clients. A change to any of them
> silently breaks production connections:
>
> - REST namespace `acrossai-mcp-manager/v1`
> - Cron hook `acrossai_mcp_manager_oauth_cleanup`
> - Actions `acrossai_mcp_manager_oauth_token_issued`,
>   `acrossai_mcp_manager_oauth_token_revoked`,
>   `acrossai_mcp_manager_oauth_authorization_denied`,
>   `acrossai_mcp_connector_user_approval_revoked`,
>   `acrossai_mcp_connector_revoke_tokens_on_approval_revoked`
> - Filters `acrossai_mcp_manager_trusted_proxies`,
>   `acrossai_mcp_manager_connector_profiles`
> - Options `acrossai_mcp_connector_settings_%d_%s`,
>   `acrossai_mcp_connector_approved_users_%d_%s`,
>   `acrossai_mcp_connector_pending_approvals_%d_%s`
> - Query vars `acrossai_mcp_oauth`, `acrossai_mcp_oauth_resource`
> - The five rewrite rules: `^\.well-known/oauth-authorization-server/?$`,
>   `^\.well-known/oauth-protected-resource/?$`,
>   `^\.well-known/oauth-protected-resource/(.+?)/?$`, `^authorize/?$`,
>   `^token/?$` — all registered at `top` priority
> - Every REST route path under `/oauth/*` and
>   `/servers/(?P<server_id>\d+)/n8n/bearer/token`
>
> Pre-flight grep (records the contract surface that must be identical
> afterwards — run in `acrossai-pro/` and save the output):
> ```bash
> grep -rEn "register_rest_route|add_rewrite_rule|do_action\(|apply_filters\(|wp_schedule_event" \
>     --include='*.php' \
>     includes/OAuth/ includes/Connectors/ includes/Main.php
> ```
>
> **Namespace + text-domain rewrite gate.** After the move, this must return
> zero matches in this plugin:
> ```bash
> grep -rEn "AcrossAI_Pro|ACROSSAI_PRO_|'acrossai-pro'|can_use_premium_code|mcp_manager_still_owns_oauth" \
>     --include='*.php' includes/ admin/ public/ templates/
> ```
> The sole permitted exceptions are the one-shot data-migration routine, which
> must reference the companion's `acrossai_pro_mcp_*` table names and
> `_acrossai_pro_*` meta keys as migration *sources*, and the
> `acrossai_mcp_connector_%` exclusion comment in `uninstall.php`.

---

## Task Breakdown

Sequenced so the working tree is never in a state where OAuth is half-owned.

```markdown
# 3. Plan + guard + security
/speckit.memory-md.plan-with-memory
/speckit.architecture-guard.governed-plan
/speckit.security-review.plan
```

```markdown
# 4. Tasks + guard
/speckit.tasks
/speckit.architecture-guard.governed-tasks
```

```markdown
# 5. Implement + quality checks
/speckit.architecture-guard.governed-implement
composer dump-autoload
composer run phpcs
composer run phpstan
npm run build
```

```markdown
# 6. Review + memory + commit
/speckit.analyze
/speckit.architecture-guard.architecture-review
/speckit.security-review.staged
/speckit.memory-md.capture-from-diff
/speckit.git.commit
```

---

## Manual Verification Checklist

### TASK-1 — Clear the F083 landmine (must land first)

Feature 083 added a one-shot sweeper whose kill-list is exactly the four table
names this feature reinstates. It only drops tables that are **empty** and is
gated on `acrossai_mcp_legacy_oauth_cleanup_done` — but neither mitigation
closes the race: on an install that has not yet run the cleanup, a freshly
created and still-empty OAuth table is precisely what it will drop.

- [ ] `includes/Database/LegacyOAuthCleanup.php` deleted.
- [ ] Its call site at `includes/Main.php:296` (inside
      `reconcile_database_schemas()`) unwired.
- [ ] The four F083 entries removed from the `$tables` array in
      `uninstall.php:78-81`: `acrossai_mcp_oauth_clients`,
      `acrossai_mcp_oauth_tokens`, `acrossai_mcp_oauth_auth_codes`,
      `acrossai_mcp_connector_approved_users`. The six live tables above them
      stay.
- [ ] The `acrossai_mcp_legacy_oauth_cleanup_done` option and the
      `acrossai_mcp_legacy_oauth_cleanup_skipped` action are no longer
      referenced anywhere.
- [ ] Any `tests/phpunit/**` covering `LegacyOAuthCleanup` deleted.
- [ ] **This task alone is a user-visible no-op.** Verify by activating on a
      clean install and confirming no behaviour change.

### TASK-2 — Data layer: four BerlinDB modules + migration

- [ ] `includes/Database/{OAuthClients,OAuthTokens,OAuthAuthCodes,ConnectorApprovedUsers}/{Table,Schema,Query,Row}.php`
      ported, namespace rewritten, `$name` set to the `acrossai_mcp_*` form.
- [ ] `includes/Database/Support/{DatetimeColumn,TimezoneHealthCheck}.php` ported.
- [ ] Every Table subclass carries this plugin's phantom-version guard.
- [ ] All four registered in `bootstrap_database_tables()`
      (`includes/Main.php:204-222`) alongside the existing five.
- [ ] All four `maybe_upgrade()`-ed in `reconcile_database_schemas()`
      (`includes/Main.php:259-271`) and in `Activator::activate()`
      (`includes/Activator.php:67-86`).
- [ ] Migration routine written and wired into both paths, gated on
      `acrossai_mcp_oauth_migration_done`.
- [ ] Migration copies these, **leaving sources untouched**:

      | From (acrossai-pro) | To (this plugin) |
      |---|---|
      | `acrossai_pro_mcp_oauth_clients` | `acrossai_mcp_oauth_clients` |
      | `acrossai_pro_mcp_oauth_tokens` | `acrossai_mcp_oauth_tokens` |
      | `acrossai_pro_mcp_oauth_auth_codes` | `acrossai_mcp_oauth_auth_codes` |
      | `acrossai_pro_mcp_connector_approved_users` | `acrossai_mcp_connector_approved_users` |

- [ ] `token_hash` copied byte-for-byte — **no re-hashing, no normalisation,
      no case change.** This column is the only record of a live grant.
- [ ] `token_family_id` (RFC 9700 §2.2.2 family revocation) and `server_id`
      bindings preserved.
- [ ] Server meta migrated: `_acrossai_pro_server_settings` →
      `_acrossai_mcp_server_settings`, `_acrossai_pro_pending_users` →
      `_acrossai_mcp_pending_users`.
- [ ] Re-running the migration twice is a no-op (idempotent).
- [ ] Interrupting mid-run and re-running completes correctly (resumable).
- [ ] On a site that never had acrossai-pro, the migration skips silently with
      no errors and no empty-table churn.
- [ ] `SHOW TABLES LIKE 'wp_acrossai_mcp_%'` returns the six pre-existing
      tables **plus** the four new ones.
- [ ] Row counts match between each source and destination table.

### TASK-3 — OAuth server

- [ ] All 25 files under `includes/OAuth/` ported with namespace rewritten.
- [ ] `templates/oauth/{consent,message}.php` ported; loader paths updated
      (they were loaded from `AuthorizationController` and `MessagePage`).
- [ ] All hook registrations rehomed from the companion's
      `bootstrap_oauth_hooks()` into `Includes\Main::define_admin_hooks()` /
      `define_public_hooks()` via `$this->loader->add_action(...)`. **No
      `add_action`/`add_filter` inside any OAuth class** (constitution A1).
- [ ] REST routes registered on `rest_api_init` from `Main.php`, matching the
      existing pattern at `includes/Main.php:512-643`.
- [ ] Rewrite rules registered and `flush_rewrite_rules()` called on activation
      (`includes/Activator.php:135-150` already flushes — confirm the OAuth
      rules are registered before that point).
- [ ] Cron `acrossai_mcp_manager_oauth_cleanup` scheduled on activation and
      cleared on deactivation.
- [ ] `TokenValidator`'s `determine_current_user` bearer authenticator wired.
- [ ] Duplicate reconciled: `acrossai-pro/includes/OAuth/CacheHeaders.php` (89
      LOC) vs this plugin's `includes/Utilities/CacheHeaders.php` — one kept,
      the other's call sites repointed.
- [ ] **Checkpoint:** with this task complete,
      `\AcrossAI_MCP_Manager\Includes\OAuth\AuthorizationController` exists, so
      `mcp_manager_still_owns_oauth()` flips and pro's OAuth goes dormant.
      Verify no route, tab, cron or rewrite rule is now registered twice.

### TASK-4 — Connectors framework + profiles

- [ ] `includes/Connectors/` (4 files) ported.
- [ ] The five profiles ported: `Claude`, `ChatGPT`, `Cursor`, `Gemini`, `Grok`.
- [ ] `includes/Discovery/DiscoveryConnectorAdapter.php` ported and hooked to
      the existing seam `apply_filters( 'acrossai_mcp_manager_discovery_ai_connectors', [] )`
      at `public/Discovery/ConnectionMethodRegistry.php:306`.
- [ ] `ConnectorProfileRegistry` fires `acrossai_mcp_manager_connector_profiles`
      (the B-era name it already aliases) — the `acrossai_pro_profiles` alias is
      dropped.
- [ ] All `manage_options` capability checks preserved on every admin route.

### TASK-5 — UI swap

- [ ] `admin/Partials/ServerTabs/AIConnectorsPromoTab.php` **deleted** (583 LOC).
- [ ] `admin/Partials/ServerTabs/Connect/MethodRegistry.php`: the
      `new AIConnectorsPromoTab()` seed at `:112` and the import at `:36`
      removed; the real `AIConnectorsTab` seeded in its place.
- [ ] Slot identity unchanged: slug `ai-connectors`, priority `10`.
- [ ] `admin/ServerTabs/{AIConnectorsTab,N8nTab}.php` ported, now
      `extends AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\AbstractServerTab`
      as an internal reference.
- [ ] `src/js/quick-connect/steps/Step8_ProPromo.jsx` rewritten into the real
      connect flow. No "requires the AcrossAI Pro plugin" copy remains; Step 7 →
      Step 8 → Step 9 completes without parking the user.
- [ ] `webpack.config.js`: the F040 tombstone comment at `:96` replaced with
      restored `js/ai-connectors` and `js/n8n-admin` entries; `css/n8n` entry
      restored.
- [ ] `src/js/{ai-connectors,n8n-admin}.js` and
      `src/scss/{ai-connectors,n8n}.scss` ported.
- [ ] `admin/Main.php` gains the OAuth asset enqueue
      (`maybe_enqueue_ai_connectors_app`), matching the shape of the existing
      `maybe_enqueue_tools_app():491` etc.
- [ ] OAuth-specific notices (HTTPS, WP-Cron, discovery conflict) ported into
      this plugin's `admin/Partials/Notices.php`.
- [ ] `npm run build` succeeds; `build/js/ai-connectors.{js,css,asset.php}` and
      `build/js/n8n-admin.*` emitted.

### TASK-6 — Tests, release metadata

- [ ] Companion tests ported from `acrossai-pro/tests/Unit/{OAuth,OAuthDiscovery,Connectors}/`
      plus the profile tests (19+ files), namespace-rewritten, into
      `tests/phpunit/`.
- [ ] New tests for the data migration specifically: idempotency, resumability,
      `token_hash` fidelity, companion-absent skip.
- [ ] New test asserting `LegacyOAuthCleanup` no longer exists and that the
      four table names are absent from the `uninstall.php` drop list.
- [ ] Version bumped in three places: `acrossai-mcp-manager.php` `Version:`,
      `ACROSSAI_MCP_MANAGER_VERSION` in `includes/Main.php`, `Stable tag:` in
      `README.txt`.
- [ ] `changelog.txt` + `README.txt` changelog entries written, stating plainly
      that AI Connectors is now free and that existing connections survive the
      upgrade.

---

## End-to-End Verification (blockers before merge)

### 1. Live-connection survival — the single most important test

On a site with `acrossai-pro` active and a **real connected client** (Claude or
ChatGPT) holding a valid token:

- [ ] Upgrade this plugin. Make an MCP tool call **without re-authorising**.
      It must succeed.
- [ ] Force the access token to expire; confirm the refresh grant still works
      and rotates correctly across the handover.
- [ ] Confirm refresh-token family revocation still fires (revoke one token,
      confirm its whole family is invalidated).

A failure here means `token_hash` was not preserved byte-for-byte. Stop and fix
the migration — do not work around it by asking users to reconnect.

### 2. Companion goes dormant automatically

- [ ] `mcp_manager_still_owns_oauth()` returns `true` post-upgrade.
- [ ] pro skips table `maybe_upgrade()`, cron scheduling and rewrite flush
      (`acrossai-pro/includes/Activator.php:52`).
- [ ] pro skips asset enqueue (`acrossai-pro/admin/Main.php:72`, `:173`).
- [ ] No REST route, server tab, cron event or rewrite rule registered twice.
- [ ] The Connectors tab renders once, from this plugin.

### 3. The F083 race, explicitly

- [ ] On an install where `acrossai_mcp_legacy_oauth_cleanup_done` is **unset**:
      upgrade, and confirm the four new OAuth tables exist and were not dropped.
- [ ] On an install where it is already set: same result.

### 4. OAuth protocol conformance

- [ ] RFC 7591 dynamic client registration via `/oauth/register`.
- [ ] Authorization-code grant with PKCE S256; a downgraded or absent
      `code_challenge` is rejected.
- [ ] Authorization codes are single-use.
- [ ] Refresh rotation + family revocation (RFC 9700 §2.2.2).
- [ ] `.well-known/oauth-authorization-server` (RFC 8414) and
      `.well-known/oauth-protected-resource` (RFC 9728) return correct metadata.
- [ ] Bearer rejection returns 401 with a correct `WWW-Authenticate: Bearer`
      challenge.
- [ ] Redirect-URI matching, including loopback-port tolerance.
- [ ] Audience/`server_id` binding enforced — a token for server A cannot call
      server B.
- [ ] Rate limiting active on the token and register endpoints.

### 5. Fresh install, no companion

- [ ] Clean site, only this plugin: the Quick Connect wizard completes through
      to a connected client.
- [ ] Zero "requires AcrossAI Pro" / "ADD-ON" / "Start free trial" /
      "money-back" copy anywhere in the UI **or the built assets**:
      ```bash
      grep -rEn "Activate add-on|Start free trial|money-back|requires the AcrossAI Pro" \
          build/ admin/ includes/ public/ src/
      ```

### 6. All five profiles + n8n

- [ ] Claude, ChatGPT, Gemini, Grok, Cursor each connect end to end.
- [ ] The n8n admin bearer-token route issues and validates a token.

### 7. Uninstall

- [ ] With `acrossai_mcp_uninstall_delete_data` set: the four new tables are
      dropped.
- [ ] `acrossai_mcp_connector_%` options are **still preserved** — the
      companion is dormant, not gone, and still owns them until the follow-up.

---

### Final full-repo audit (blocker before merge)

```bash
grep -rEn "AcrossAI_Pro|ACROSSAI_PRO_|'acrossai-pro'|can_use_premium_code|mcp_manager_still_owns_oauth|LegacyOAuthCleanup|AIConnectorsPromoTab" \
    --include='*.php' --include='*.jsx' --include='*.js' \
    includes/ admin/ public/ templates/ src/ acrossai-mcp-manager.php uninstall.php
```

- [ ] Returns **zero matches**, with two permitted exceptions: the data-migration
      routine's references to `acrossai_pro_mcp_*` table names and
      `_acrossai_pro_*` meta keys as migration *sources*, and the
      `acrossai_mcp_connector_%` exclusion comment in `uninstall.php`.
- [ ] Each permitted exception carries an inline comment naming Feature 095 and
      the follow-up that removes it.

### Quality gates (all must be green before commit)

- [ ] PHPStan — zero errors.
- [ ] PHPCS — zero errors, including PHPCompatibility at the declared floor.
- [ ] `composer test` — all PHPUnit suites pass, including the ported ones.
- [ ] `composer dump-autoload` — succeeds with zero warnings.
- [ ] `npm run build` — succeeds; all restored entries emitted.
- [ ] Text domain: zero `__()`/`_e()`/`esc_html__()` calls using
      `'acrossai-pro'` anywhere in this plugin.
- [ ] `SHOW TABLES LIKE 'wp_acrossai_mcp_%'` returns exactly ten rows on a
      migrated install.
- [ ] `SELECT option_name FROM wp_options WHERE option_name LIKE 'acrossai_mcp%_db_version'`
      returns one row per module with values matching each Table's `$version`.

---

## Pre-flight Attestation (required before TASK-2)

**The data migration's risk profile depends entirely on whether live,
customer-held OAuth grants exist.** Fill this in before implementing TASK-2; it
determines whether the copy-never-move rule and the rollback path are
load-bearing or merely prudent.

**Captured**: 2026-10-06, by direct query against the live site.

**Attestation**: **YES — live OAuth data exists outside `~/local-sites/`.** The
production site acrossai.co, queried over MCP on 2026-10-06, holds:

| Table | Rows |
|---|---|
| `wp_acrossai_pro_mcp_oauth_clients` | 11 |
| `wp_acrossai_pro_mcp_oauth_tokens` | **520** |
| `wp_acrossai_pro_mcp_oauth_auth_codes` | 0 |
| `wp_acrossai_pro_mcp_connector_approved_users` | 1 |

Those 520 rows are live grants held by 11 registered clients. Auth codes sit at
zero as expected — they are single-use and short-lived.

**Consequence**: this is **not** a fresh-install-only migration, so the F016 /
`D21` retirement pattern does **not** apply. Every data-safety control in this
feature is load-bearing rather than precautionary:

- copy-never-move (FR-010) — the companion's tables are a real rollback path
- byte-for-byte digest transfer (FR-008) — 520 live credentials depend on it
- quickstart Test 1 is a genuine stop-the-line gate, not a formality

**Still required from the operator before T020**: confirm a tested backup and
restore path for acrossai.co, and whether any **other** production install
carries companion OAuth data. One known site is enough to make the controls
mandatory; the count only affects blast radius.

**Basis for**: the copy-never-move rule, retention of the companion's four
tables as a rollback path, and the severity rating of verification test 1.

**Attesting user**: raftaar1191@gmail.com

**Validity window**: 2026-10-06 → Feature 095 merge. Any new production install
between attestation and merge widens blast radius but does not change the
controls, which are already at their strictest.

---

## Explicitly out of scope

- **Deleting OAuth from `acrossai-pro`.** It stays installed and dormant this
  release. The follow-up strips `includes/OAuth/`, `includes/Connectors/`, the
  five profiles, both tabs, the four DB modules, the probe and the
  `HostDependencies`/`HostCapabilities` OAuth wiring; drops pro's four tables;
  and **removes the `acrossai_mcp_connector_%` exclusion from this plugin's
  `uninstall.php:95-104`**. That last item is easy to forget — it is recorded
  here and in TASK-1 so it survives the gap between releases.
- **Version floor changes.** `Requires PHP: 8.1` stays (BerlinDB officially
  requires 8.1 — README and `composer.json` at 3.0.0 and 3.0.1). The incoming
  OAuth code is PHP 7.4-syntax-clean and imposes nothing. Two separate findings
  from the floor audit deserve their own small feature: `Requires at least: 7.0`
  is undocumented drift and should be `6.9` (nothing uses a post-6.9 API; all 26
  `wp_get_abilities()` call sites pass zero args), and
  `acrossai-mcp-manager.php:34` declares `Requires WP:` — a header WordPress
  does not read — so the WP minimum is currently unenforced entirely.
- **Replacing the hand-rolled OAuth with a library.** Deliberate: adopting
  `league/oauth2-server` + `lcobucci/jwt` would save ~1,300 lines of our code
  while vendoring ~20,800 lines across 229 files, forcing a PSR-7 bridge, and
  storing an RSA private key in `wp_options`. The current opaque-token design
  has no key material to leak, revokes instantly, and already hashes at rest.
- **Marketing and pricing for `acrossai-pro`.** It loses its headline feature
  and keeps ~50k lines of Abilities plus AccessControl. Positioning handled
  separately.
