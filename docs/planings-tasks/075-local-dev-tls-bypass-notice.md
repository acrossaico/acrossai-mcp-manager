# Planning: Auto-inject `NODE_TLS_REJECT_UNAUTHORIZED=0` for local dev (Feature 075)

## Context

Local dev tooling — Local by Flywheel, MAMP, DDEV, `wp-env`, etc. — serves WordPress
over HTTPS with a self-signed certificate on `*.local` / `*.test` hosts. When an
operator copies the JSON config we generate for an MCP client (Claude Desktop,
Cursor, VS Code, Windsurf, Zed, and 11 others), the `npx @automattic/mcp-wordpress-remote`
Node.js proxy tries to connect to `https://<site>.local/wp-json/mcp/...` and
Node's TLS layer rejects the self-signed cert. The MCP client's TCP handshake
succeeds so the client appears "connected", but `tools/list` never returns any
tools. The operator has no error surface and no path to diagnosis unless they
happen to find the Automattic troubleshooting doc.

The fix documented upstream is a single Node env var: `NODE_TLS_REJECT_UNAUTHORIZED=0`
inside the client's `env` block. It disables all TLS certificate validation for
that process — safe only for throwaway local testing, dangerous against a live
site. Because the injection is scoped **per-copied-config-snippet**, this feature
adds it automatically **and only** when the current WordPress site looks like a
local dev environment served over HTTPS. Live sites — HTTPS with valid cert, or
plain HTTP — get zero behaviour change.

Right now the codebase has zero references to `NODE_TLS_REJECT_UNAUTHORIZED` and
no environment-detection helper, so this is a greenfield add. Two UI surfaces
consume the generated JSON — the per-server **MCP Clients tab** and the
**Quick Setup wizard Step 11** — both call `AbstractMCPClient::get_config_snippet()`
via `MCPClientsBlock::render_client_details()` and
`ConnectionMethodRegistry::get_clients()` respectively, so a single change in
the base client class covers both surfaces without any post-processing walker.

## Authoritative sources

- Automattic troubleshooting doc: <https://github.com/Automattic/mcp-wordpress-remote/blob/trunk/Docs/troubleshooting.md>
- Sibling utility this feature mirrors: `includes/Utilities/SiteSlug.php`
- Config-snippet contract: `includes/MCPClients/AbstractMCPClient.php::get_config_snippet()`

## Detection rule

`LocalEnvironment::needs_tls_bypass()` returns `true` when **both** conditions hold:

1. `parse_url( home_url(), PHP_URL_SCHEME )` is `https` (only HTTPS triggers TLS
   validation — on HTTP the flag is a no-op and would be misleading noise).
2. The environment looks local, via **any** of:
   - `wp_get_environment_type()` returns `local` or `development`;
   - `home_url()` host is `localhost`, `127.0.0.1`, or `::1`;
   - host ends with `.local`, `.test`, or `.localhost`.

Live HTTPS + valid cert: false (both conditions fail on real production). Live
HTTP: false (short-circuits on scheme). Live HTTPS + invalid cert: false — the
user should fix the cert, not have this plugin silently disable verification for
them.

## Files to touch

| File | Change |
|---|---|
| `includes/Utilities/LocalEnvironment.php` | **NEW** (~50 lines). Static-only class matching `SiteSlug.php` shape. Public `needs_tls_bypass(): bool` implementing the rule above. Private `is_local_host( string $host ): bool` + `is_local_env_type(): bool` for testability. No hooks, no state. |
| `includes/MCPClients/AbstractMCPClient.php` | Add `protected function build_env( string $server_url, string $auth_token, array $extra = array() ): array` that returns the standard `WP_API_URL` / `WP_API_USERNAME` / `WP_API_PASSWORD` array plus `NODE_TLS_REJECT_UNAUTHORIZED => '0'` when `LocalEnvironment::needs_tls_bypass()` is true. Merges `$extra` last so a subclass can add flags like `OAUTH_ENABLED`. |
| `includes/MCPClients/*.php` (16 files) | Swap the inlined `env => array( ... )` literal for `$this->build_env( $server_url, $auth_token )` (or `$this->build_env( $server_url, $auth_token, array( 'OAUTH_ENABLED' => 'false' ) )` in `ClaudeCodeClient`). Files: `ClaudeDesktopClient`, `ClaudeCodeClient`, `VSCodeClient`, `CursorClient`, `GitHubCopilotClient`, `CodexClient`, `GeminiClient`, `WindsurfClient`, `ZedClient`, `ClineClient`, `RooCodeClient`, `KiloCodeClient`, `AmazonQClient`, `OpenCodeClient`, `AntigravityClient`, `CustomClient`. |
| `public/Renderers/MCPClientsBlock.php` | Around lines 223–258 (Configuration JSON region), render a `notice notice-warning` block above the `<textarea>` when `LocalEnvironment::needs_tls_bypass()` is true. Reuse the escaping and class-name pattern from `admin/Partials/Notices.php`. Message string + doc link live in a single `private static function tls_bypass_notice_message(): string` on the block so admin PHP and Quick Setup JS reference the same copy. |
| `admin/Main.php` | Inside `maybe_enqueue_quick_setup_app()` (~lines 189–237), extend the `wp_localize_script` payload with `'tlsBypass' => array( 'enabled' => LocalEnvironment::needs_tls_bypass(), 'message' => …, 'docUrl' => 'https://github.com/Automattic/mcp-wordpress-remote/blob/trunk/Docs/troubleshooting.md' )`. |
| `src/js/quick-setup/steps/Step11_ClientDetail.jsx` | Render a `<Notice status="warning" isDismissible={false}>` (or existing wizard callout component) above the `<CodeBlock>` when `bootstrap.tlsBypass.enabled` is true. Include an `<a target="_blank" rel="noopener">` to the troubleshooting doc. No JSON mutation on the frontend — the pre-rendered string from `ConnectionMethodRegistry::get_clients()` already contains the flag because it calls `get_config_snippet()`. |
| `README.txt` | One bullet under `= Unreleased =` summarising Feature 075. |

## Reference files (READ, do not modify)

- `includes/Utilities/SiteSlug.php` — static-only, no hooks, no state — shape template for `LocalEnvironment.php`.
- `includes/MCPClients/ClaudeDesktopClient.php` lines 43–57 — canonical `env` literal being replaced.
- `includes/MCPClients/ClaudeCodeClient.php` lines 50–64 — only client with an extra env key (`OAUTH_ENABLED`), template for the `$extra` param.
- `public/Renderers/MCPClientsBlock.php` lines 171–259 — `render_client_details()` where the PHP notice slots in.
- `public/Discovery/ConnectionMethodRegistry.php` lines 195–242 — `get_clients()` pre-renders the JSON via `wp_json_encode(get_config_snippet(...))`, so the flag reaches the wizard automatically.
- `admin/Partials/Notices.php` lines 115–161 — reference pattern for translated warning notices.

## Constraints

- **Do NOT auto-inject on live sites.** The detection rule is a hard gate; no filter, no admin toggle in Feature 075. The insecure flag on a production install is an active security regression.
- **Do NOT short-circuit if `home_url()` scheme is `http`.** The flag is meaningless there — but injecting it would signal to a reviewer that we think the site is "local", which is a lie. Fail closed.
- **Do NOT walk / post-process the generated JSON to insert the flag.** All 16 clients extend `AbstractMCPClient` and hand-build the `env` array. Route the change through `build_env()` so any future client that inlines its own env dict is a code-review catch, not a silent skip.
- **Do NOT change the public shape of `get_config_snippet()`.** Return type, key names, and array structure stay identical; only the `env` sub-array grows one optional key.
- **Do NOT duplicate the warning copy between PHP and JS.** Single source of truth in PHP (`tls_bypass_notice_message()` + the `docUrl` constant), reached from JS via the `wp_localize_script` `tlsBypass` payload.
- **Do NOT add a REST endpoint, DB column, option, or capability check.** Detection is pure-function on `home_url()` + `wp_get_environment_type()`.
- **PHPStan L8 + PHPCS must pass on every touched file** per §VII DoD.
- **Do NOT modify `AbstractMCPClient` beyond adding `build_env()`.** The `derive_server_key` / `current_username` / `safe_token` helpers and factory stay untouched.

## Verification

1. **PHPUnit — new helper + client-suite parity**:
   ```
   composer run test -- --testsuite mcpclients
   ```
   Add a `LocalEnvironmentTest` case:
   - `.local` host + HTTPS → `true`
   - `.test` host + HTTPS → `true`
   - `localhost` + HTTPS → `true`
   - env-type `local` + HTTPS + arbitrary host → `true`
   - env-type `production` + HTTPS + `example.com` → `false`
   - env-type `local` + `http://` scheme → `false` (scheme gate)
   All existing 16 client fixtures need a paired `-local` variant OR the existing golden fixtures must assert against the mocked "production" branch — pick whichever the current `ConcreteClientsTest` shape already prefers (fixture-based golden files vs. runtime mock).

2. **PHPCS + PHPStan L8** clean:
   ```
   composer run phpcs && composer run phpstan
   ```

3. **Admin smoke — MCP Clients tab (this local install)**:
   - Confirm `wp option get siteurl` returns `https://…local` or `https://…test` (this environment).
   - Open **MCP Servers → any server → MCP Clients tab** for two representative clients (Claude Desktop + VS Code).
   - Expect yellow warning notice above the JSON textarea, with a working link to the Automattic troubleshooting doc.
   - Copied JSON's `env` block contains `"NODE_TLS_REJECT_UNAUTHORIZED": "0"`.

4. **Wizard smoke — Step 11**:
   - Open `?…&quick-setup=1` and advance to Step 11.
   - Same two expectations: warning callout above the CodeBlock + `NODE_TLS_REJECT_UNAUTHORIZED` present in the JSON.
   - Verify the copy-to-clipboard button captures the flag.

5. **Live-site simulation — must NOT trigger**:
   - Temporarily set `define( 'WP_ENVIRONMENT_TYPE', 'production' )` in `wp-config.php` AND (harder) simulate a non-`.local` host via a `pre_option_home` filter returning `https://example.com`.
   - Reload both surfaces. Expect **no** warning and **no** `NODE_TLS_REJECT_UNAUTHORIZED` in the JSON. Revert the config.

6. **End-to-end proof (this local install)**:
   - Copy Claude Desktop JSON with the flag, paste into `~/Library/Application Support/Claude/claude_desktop_config.json`, restart Claude Desktop.
   - Verify tools now appear in the MCP client — this is the user-reported symptom Feature 075 exists to fix.

7. **README.txt changelog** — new Feature 075 bullet present under `= Unreleased =`.

## Speckit workflow

```markdown
# 1. Branch
/speckit.git.feature "local-dev-tls-bypass-notice"

# 2. Specify — paste the "Detection rule" + "Files to touch" + "Constraints" sections above.

# 3. Plan + guard + security
/speckit.memory-md.plan-with-memory
/speckit.architecture-guard.governed-plan
/speckit.security-review.plan

# 4. Tasks + guard
/speckit.tasks
/speckit.architecture-guard.governed-tasks

# 5. Implement + quality checks
/speckit.architecture-guard.governed-implement
composer dump-autoload
composer run phpcs
composer run phpstan
composer run test -- --testsuite mcpclients
npm run build

# 6. Review + memory + commit
/speckit.analyze
/speckit.architecture-guard.architecture-review
/speckit.security-review.staged
/speckit.memory-md.capture-from-diff
/speckit.git.commit
```

## Durable lesson

**Environment-scoped ergonomic fixes belong behind a detection helper, not a global toggle.** Adding `NODE_TLS_REJECT_UNAUTHORIZED=0` as an admin option would let an operator flip it on for a live site "just to test" and forget. Gating it on `home_url()` scheme + host + `wp_get_environment_type()` means the insecure flag is physically absent from the JSON the operator copies on production — the affordance for misuse never exists. When adding any future "dev-only" convenience (verbose logging, cert bypass, permissive CORS, sample-data seeding), route it through a `Utilities/*Environment` pure-function gate that the code path cannot bypass, not a checkbox that a human can.

Added: 2026-08-24 on branch `fix/vendor-missing-fail-safe` (rebase to `075-local-dev-tls-bypass-notice` before the /speckit.git.feature step).
