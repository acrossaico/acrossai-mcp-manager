# Feature Specification: Local-Dev TLS Bypass Notice for MCP Client Snippets

**Feature Branch**: `075-local-dev-tls-bypass-notice`
**Created**: 2026-08-24
**Status**: Draft
**Input**: User description: "Auto-inject NODE_TLS_REJECT_UNAUTHORIZED=0 into copied MCP client JSON snippets and surface a warning notice with a link to the Automattic troubleshooting doc, but only when the current WordPress site looks like a local dev environment served over HTTPS."

## Clarifications

### Session 2026-08-24

- Q: Should sites where `wp_get_environment_type()` returns `staging` also get the auto-injected `NODE_TLS_REJECT_UNAUTHORIZED=0` flag when served over HTTPS? → A: No — the detection stays scoped to `local` or `development` environment types (plus the local-host suffix rule). Staging is close to production; a staging box with a self-signed cert is a config bug for ops to fix, not something the plugin should silently paper over.
- Q: Should the local-host suffix list (`.local` / `.test` / `.localhost`) be filterable so ops teams can add their own (e.g., `.dev`, `.internal`, `.docker`)? → A: Yes — ship an `acrossai_mcp_local_hostname_suffixes` filter that receives the default three-suffix array and returns the effective list. Low-cost forward compat; ops can register custom suffixes without a plugin update.
- Q: Should the TLS-bypass warning notice be dismissible per-user, or a static callout that always shows? → A: Static callout — always renders above the JSON on both surfaces. This is a permanent property of the current site config, not a one-time event; hiding it would invite forgetting that the insecure flag is baked into copied JSON. No user-meta storage or dismiss endpoint needed.
- Q: The earlier FR-009 gated the injection on `home_url()` returning `https://` so plain-HTTP local sites (dev tools that skip TLS entirely) got no warning and no flag. On this project's own local install the site turned out to be served over plain HTTP — the operator expected the affordance and didn't see it. Should the HTTPS-scheme gate be dropped so the feature fires on any local-looking site regardless of scheme? → A: Yes, drop the HTTPS gate. New rule: "site looks local → show the affordance". On HTTPS-local the injected `NODE_TLS_REJECT_UNAUTHORIZED` is the real fix; on HTTP-local it's a harmless no-op (Node's HTTP client never runs TLS validation) but the warning + doc link still guide operators to Automattic's troubleshooting page, which covers non-TLS local-dev issues too. Warning copy rewritten scheme-agnostic: "on HTTPS with self-signed cert the flag stops the proxy rejecting the cert; on plain HTTP the flag does nothing but is harmless. Never use against a live site." This supersedes FR-009 (see below).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Local-dev operator copies a working config on the first try (Priority: P1)

A developer running WordPress on Local by Flywheel (or MAMP / DDEV / `wp-env`) — with the site served over HTTPS on a `*.local` or `*.test` host using a self-signed certificate — opens the plugin's per-server **MCP Clients tab**, picks their client (Claude Desktop, Cursor, VS Code, etc.), copies the generated JSON, and pastes it into the client's config file. When they restart the client, the tool list populates on the first try. They also see a plain-language warning explaining what was added and why, with a link to the Automattic troubleshooting document.

**Why this priority**: This is the entire reason the feature exists. Without it, the copied JSON connects but returns zero tools — a silent failure with no error surface, wasting the operator's first impression of the plugin.

**Independent Test**: On a `.local` or `.test` install (HTTP or HTTPS — Clarification Q4), open `admin.php?page=acrossai_mcp_manager&action=edit&server=1&tab=clients`, click through any client pill, confirm the visible JSON contains a `NODE_TLS_REJECT_UNAUTHORIZED` line inside the `env` block, and confirm a yellow warning notice sits above the JSON with a working link to the troubleshooting doc.

**Acceptance Scenarios**:

1. **Given** the site is served over HTTP or HTTPS on `example.local`, **When** the operator opens the MCP Clients tab and selects any client, **Then** the visible JSON snippet contains `NODE_TLS_REJECT_UNAUTHORIZED: "0"` inside the client's `env` block AND a warning notice is rendered above the JSON with a link to `https://github.com/Automattic/mcp-wordpress-remote/blob/trunk/Docs/troubleshooting.md`.
2. **Given** the operator copies the JSON to their MCP client's config file and restarts the client, **When** the client reconnects, **Then** the tool list populates without further action.

---

### User Story 2 - Same fix reaches Quick Setup wizard users (Priority: P1)

A first-time operator running the plugin's Quick Setup wizard on the same local install reaches Step 11 (the per-client config screen), copies the JSON, and gets the same automatic fix + warning treatment — without needing to know the per-server tab exists.

**Why this priority**: Quick Setup is the primary onboarding surface. A local-dev operator who never sees the fix here will bounce off the plugin before ever reaching the per-server tabs.

**Independent Test**: On the same local HTTPS install, open the Quick Setup wizard (`?…&quick-setup=1`), advance to Step 11, verify the JSON in the on-screen code block contains `NODE_TLS_REJECT_UNAUTHORIZED: "0"`, and confirm a warning callout with the doc link is rendered above the code block.

**Acceptance Scenarios**:

1. **Given** the site is served over HTTPS on `https://example.local`, **When** the operator reaches Quick Setup Step 11, **Then** the code block contains `NODE_TLS_REJECT_UNAUTHORIZED: "0"` AND a warning callout is rendered above it with the same troubleshooting link.
2. **Given** the operator clicks the wizard's copy-to-clipboard button, **When** they paste, **Then** the clipboard content includes the `NODE_TLS_REJECT_UNAUTHORIZED` line.

---

### User Story 3 - Live-site operator sees no behavior change and no insecure flag (Priority: P1)

An operator running the plugin on a real production site (HTTPS with a valid certificate on a public hostname) opens either the MCP Clients tab or the Quick Setup wizard, copies the JSON, and pastes it into their MCP client. The JSON does **not** contain `NODE_TLS_REJECT_UNAUTHORIZED` and no TLS-bypass warning is shown.

**Why this priority**: Silently disabling TLS verification on a production install is an active security regression. Failing this test is worse than not shipping the feature at all.

**Independent Test**: Simulate a production environment (`WP_ENVIRONMENT_TYPE=production` in `wp-config.php` AND a `pre_option_home` filter forcing `https://example.com`). Open both surfaces. Confirm zero warning notice and zero `NODE_TLS_REJECT_UNAUTHORIZED` occurrences in the visible JSON.

**Acceptance Scenarios**:

1. **Given** `wp_get_environment_type()` returns `production` AND the site host is `example.com`, **When** the operator opens the MCP Clients tab, **Then** the JSON snippet contains no `NODE_TLS_REJECT_UNAUTHORIZED` key and no TLS-bypass notice is rendered.
2. **Given** the same live-site conditions, **When** the operator reaches Quick Setup Step 11, **Then** no warning callout appears and the JSON is identical to what shipped before this feature.

---

### Edge Cases

- **Local site served over plain HTTP** (e.g., `http://localhost:8080/wordpress` or `http://foo.local` on Local by Flywheel's HTTP mode): The feature fires — warning + flag both appear. The injected flag is a no-op on HTTP (Node's HTTP client never runs TLS validation) but the warning + doc link still guide operators to Automattic's troubleshooting page, which covers non-TLS local-dev issues (see Clarification Q4 2026-08-24 for the rationale over the earlier "HTTP short-circuits" design).
- **Local site behind a reverse proxy with an unusual `wp_get_environment_type()` value**: The host-suffix rule (`.local`, `.test`, `.localhost`, `localhost`, `127.0.0.1`, `::1`) fires independently of environment-type, so proxied local setups are still covered.
- **Freshly installed WordPress on a `.local` host without `WP_ENVIRONMENT_TYPE` set**: WordPress defaults `wp_get_environment_type()` to `production`, but the host-suffix rule fires anyway.
- **MCP adapter or Access Control plugin is missing**: This feature does not depend on either — detection is pure PHP on `home_url()` + core `wp_get_environment_type()`. No graceful-degrade branching needed.
- **Operator lacks `manage_options`**: The MCP Clients tab and Quick Setup wizard are already capability-gated by the enclosing admin pages — this feature adds no new capability surface.

---

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: When the site looks local (any of: `wp_get_environment_type()` returns `local` or `development` — explicitly **not** `staging`; host is `localhost` / `127.0.0.1` / `::1`; host ends with one of the suffixes returned by the `acrossai_mcp_local_hostname_suffixes` filter, defaulted to `['.local', '.test', '.localhost']`), the plugin MUST inject `NODE_TLS_REJECT_UNAUTHORIZED: "0"` into the `env` block of every generated MCP client JSON snippet — for every registered client, without a per-client opt-in. The scheme of `home_url()` (http vs https) does NOT gate detection (Clarification Q4 2026-08-24).
- **FR-002**: When either condition in FR-001 fails, the plugin MUST NOT inject `NODE_TLS_REJECT_UNAUTHORIZED` into any generated JSON snippet.
- **FR-003**: When FR-001's conditions are satisfied, the **per-server MCP Clients tab** MUST render a warning notice above the copied JSON block, explaining that TLS verification is disabled, that this is safe only for local throwaway testing, and linking to `https://github.com/Automattic/mcp-wordpress-remote/blob/trunk/Docs/troubleshooting.md`.
- **FR-004**: When FR-001's conditions are satisfied, the **Quick Setup wizard Step 11** MUST render an equivalent warning callout above the client config code block, with the same warning text and the same troubleshooting link.
- **FR-005**: The warning text and doc link MUST be defined once in PHP and reach the JavaScript surface via `wp_localize_script` — the two surfaces MUST NOT drift out of sync as copy is revised.
- **FR-006**: The injection MUST NOT change the public shape of the generated JSON: only one optional key is added to the existing `env` sub-array; all other keys, order, and structure are preserved.
- **FR-007**: The feature MUST NOT expose an admin toggle or filter that lets the operator force-enable `NODE_TLS_REJECT_UNAUTHORIZED` on a site that fails the detection rule.
- **FR-008**: The detection helper MUST be a pure, side-effect-free function that can be exercised in a WP-free PHPUnit context (mockable via constructor-injected inputs or filterable `home_url` / environment-type overrides).
- **FR-009**: ~~When the site is served over plain HTTP, the injection MUST NOT occur and the warning MUST NOT render — even if the environment/host otherwise looks local — because the flag is meaningless on non-HTTPS URLs and injecting it would signal a false positive.~~ **Superseded by Clarification Q4 (2026-08-24)** — the HTTPS-scheme gate was dropped. The feature now fires on any local-looking site regardless of scheme. On HTTP the injected `NODE_TLS_REJECT_UNAUTHORIZED` is a harmless no-op (Node's HTTP client never runs TLS validation) but the warning + doc link still guide operators to Automattic's troubleshooting page. Warning copy rewritten scheme-agnostic (see FR-003 / FR-004 for the new phrasing).
- **FR-010**: The plugin MUST expose an `acrossai_mcp_local_hostname_suffixes` filter that receives the default array `['.local', '.test', '.localhost']` and returns the effective suffix list used by FR-001's host-suffix check. The filter runs once per detection call. Filter callbacks that return a non-array MUST cause the detection helper to fall back to the default list (defensive), and MUST NOT crash the render.
- **FR-011**: The TLS-bypass warning notice MUST be a static callout on both surfaces — always visible above the JSON when FR-001 conditions are satisfied. The feature MUST NOT introduce a dismiss control, a user-meta flag, or an AJAX endpoint to hide it.

### WordPress Requirements

**PHP Version**: PHP 8.0+ (plugin baseline)
**WordPress Version**: 6.9+
**Multisite**: Single-site and multisite both supported — the detection reads per-site `home_url()`, so multisite behaves correctly per subsite.
**Required Plugins / Packages**: None new. Reuses the existing `AbstractMCPClient` factory + `ConnectionMethodRegistry`.
**Optional Integrations**: None.

### Module Placement

**PHP Class(es)**:
- `includes/Utilities/LocalEnvironment.php` → namespace `AcrossAI_MCP_Manager\Includes\Utilities` — new pure-function utility; mirrors the shape of the existing `includes/Utilities/SiteSlug.php`.
- `includes/MCPClients/AbstractMCPClient.php` (extended) → namespace `AcrossAI_MCP_Manager\Includes\MCPClients` — adds a `protected function build_env()` helper.
- `includes/MCPClients/{Claude,VS,Cursor,GitHubCopilot,Codex,Gemini,Windsurf,Zed,Cline,RooCode,KiloCode,AmazonQ,OpenCode,Antigravity,Custom}*Client.php` (refactored) → same namespace — swap the literal `env` array for `$this->build_env(...)`.
- `public/Renderers/MCPClientsBlock.php` (extended) → namespace `AcrossAI_MCP_Manager\Public\Renderers` — renders the warning notice above the JSON textarea.
- `admin/Main.php` (extended) → namespace `AcrossAI_MCP_Manager\Admin` — extends the `wp_localize_script` payload for the Quick Setup wizard.
- `src/js/quick-setup/steps/Step11_ClientDetail.jsx` (extended) — renders the warning callout above the code block.

**Hook Registration**: No new WordPress hooks are added by this feature. Detection is called inline from the two existing render paths.

### Admin UI Requirements

**Pre-approved WP_List_Table exception applies**: The MCP Clients tab lives under the `?page=acrossai_mcp_manager` parent screen, which is pre-ratified — this feature adds only a `<div class="notice notice-warning">` sibling above the existing config-JSON textarea, matching the visual pattern already used in `admin/Partials/Notices.php`.

**Quick Setup wizard**: Step 11 is a React screen. The warning uses the wizard's existing callout component (or `@wordpress/components` `Notice` with `status="warning"` if the wizard has no existing analogue) — no custom card component invented.

### Database / Storage

**No persistent storage**: N/A. Detection is a pure read of `home_url()` and `wp_get_environment_type()` at render time.

### Security Checklist

- [x] All form/AJAX handlers verify nonce — N/A (no new form or AJAX endpoint)
- [x] All admin page renders check `current_user_can('manage_options')` — N/A (inherits gating from enclosing MCP Manager pages)
- [x] All REST routes have explicit `permission_callback` — N/A (no new REST route)
- [x] All user input sanitized at boundary — N/A (feature has no user input; detection reads WP core APIs only)
- [ ] All output escaped at point of rendering with most-specific function (`esc_html()`, `esc_attr()`, `esc_url()`) — the doc URL and warning text MUST be escaped via `esc_url()` and `esc_html()` respectively
- [x] All DB queries use `$wpdb->prepare()` — N/A (no DB access)
- [x] OAuth tokens / Application Passwords stored hashed — N/A (no token handling)
- [x] File uploads validated — N/A (no file handling)
- [ ] The injected `NODE_TLS_REJECT_UNAUTHORIZED=0` value MUST be a string `"0"`, not integer `0`, per Node.js env var conventions — and the injection MUST be unreachable on any site that fails FR-001's local-environment gate (verified by a PHPUnit test)

### Key Entities

None. This feature carries no persistent data model.

---

## Success Criteria *(mandatory)*

### Definition of Done Gates

- [ ] PHPCS validation: zero errors and zero warnings (`vendor/bin/phpcs`)
- [ ] PHPStan level 8: zero errors (`vendor/bin/phpstan`)
- [ ] ESLint: zero errors on `src/js/quick-setup/steps/Step11_ClientDetail.jsx` (`npm run lint:js`)
- [ ] PHPUnit tests written and passing for `LocalEnvironment::needs_tls_bypass()` across ≥ 9 axes: env-type `local` + HTTPS → true; env-type `development` + HTTPS → true; env-type `production` + `.local` host → true; env-type `staging` alone (public host) → false; env-type `production` + `example.com` → false; env-type `local` + `http://foo.local` → **true** (HTTP-local fires — Clarification Q4); public HTTP host + production → false; `127.0.0.1` + HTTPS → true; `.test` host + HTTPS → true; custom suffix via filter + HTTPS → true; defensive filter callback returning non-array → falls back to defaults (SC-005)
- [ ] Security checklist above: all applicable items verified
- [ ] All 16 concrete `*Client` classes updated to route their `env` array through `AbstractMCPClient::build_env()` — verified by a canary test that greps for surviving inline `WP_API_URL` array literals in `includes/MCPClients/*.php`
- [ ] `npm run build` succeeds and the resulting `build/js/quick-setup.js` renders the new warning callout on the local install
- [ ] Zero `NODE_TLS_REJECT_UNAUTHORIZED` occurrences in generated JSON when a `production` environment + non-local host is simulated

### Measurable Outcomes

- **SC-001**: On a local HTTPS `.local` install, the operator can copy the MCP Clients tab JSON, paste it into Claude Desktop, restart Claude Desktop, and see the plugin's tools populate — with **zero manual edits** to the copied JSON.
- **SC-002**: On the same install, the Quick Setup wizard Step 11 shows a warning callout with a working link to the Automattic troubleshooting doc, above the same auto-fixed JSON.
- **SC-003**: On a simulated production install (`WP_ENVIRONMENT_TYPE=production` + `example.com` host), **zero** `NODE_TLS_REJECT_UNAUTHORIZED` strings appear anywhere in the generated JSON across all 16 registered clients, and **zero** TLS-bypass warning notices render on either surface.
- **SC-004**: PHPUnit coverage of `LocalEnvironment::needs_tls_bypass()` includes ≥ 9 assertions matching FR-001 / FR-002 / FR-010 (env-type local + HTTPS → true; env-type development + HTTPS → true; env-type production + `.local` → true via host suffix; env-type staging + `.local` → true via host suffix; env-type staging + `example.com` → false (staging alone MUST NOT trigger); `example.com` + production → false; env-type local + HTTP → **true** (Clarification Q4 — HTTP local sites now fire too); `example.com` + production over HTTP → false; `127.0.0.1` + HTTPS → true; `.test` + HTTPS → true; custom suffix `.docker` added via filter + HTTPS → true).
- **SC-005**: A filter callback registered on `acrossai_mcp_local_hostname_suffixes` that returns a non-array value (defensive test — e.g., a plugin bug returning `false` or a string) MUST NOT throw a fatal or generate a PHP warning; detection falls back to the default suffix list.

---

## Assumptions

- WordPress core's `wp_get_environment_type()` is available and returns one of the standard values (`local`, `development`, `staging`, `production`) — this has been core since WP 5.5.
- Users running Local by Flywheel, MAMP, DDEV, or `wp-env` will have `home_url()` return a host whose suffix matches one of the sentinel patterns, or an environment-type of `local` — one or the other reliably fires for the ecosystem's common tooling.
- The Automattic troubleshooting URL is stable — if that page moves, updating the URL is a one-line follow-up in the single PHP source of truth.
- The `NODE_TLS_REJECT_UNAUTHORIZED` env var is honored by every current and future MCP client we generate JSON for, because they all delegate to the `@automattic/mcp-wordpress-remote` Node proxy, which reads this env var via Node's built-in TLS layer. Any future client that uses a non-Node transport is out of scope for this feature.
- Multisite is respected because detection reads `home_url()` per subsite; no site-wide static caching of the detection result is introduced.
- The `acrossai_mcp_local_hostname_suffixes` filter is a code-level extension point (not an admin toggle) — ops teams that self-host on custom suffixes like `.docker` or `.internal` can opt in via a companion plugin snippet. The default three-suffix list ships intentionally conservative; the HTTPS gate (FR-009) is enforced independently of the suffix list, so the filter cannot enable the flag on a plain-HTTP site regardless of what suffixes it registers.

