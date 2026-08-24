# Quickstart: Local-Dev TLS Bypass Notice (Feature 075)

**Feature**: [spec.md](./spec.md) | **Plan**: [plan.md](./plan.md)

Six steps to verify this feature end-to-end on the local `.local` install this repo lives on, plus one negative-path smoke test for the production-simulation case.

---

## Prerequisites

- Plugin activated, WordPress admin accessible.
- At least one MCP Server row exists (Quick Setup wizard has probably already seeded the default `mcp-adapter-default-server`).
- Site is served over HTTPS on a `*.local` or `*.test` host — Local by Flywheel does this by default. If your dev tool serves plain HTTP, temporarily flip to HTTPS to exercise the positive path.
- Confirm the branch: `git branch --show-current` returns `075-local-dev-tls-bypass-notice`.

---

## Positive path (this local install — feature MUST fire)

**Step 1 — Verify `home_url()` scheme**

```bash
wp option get siteurl
# expected: https://<something>.local (or .test)
```

If it returns `http://…`, either fix your dev tool to serve HTTPS or set `WP_HOME` / `WP_SITEURL` for a scoped test.

**Step 2 — Per-server MCP Clients tab**

Navigate to:

```
/wp-admin/admin.php?page=acrossai_mcp_manager&action=edit&server=1&tab=clients
```

Verify:

1. A yellow warning notice sits **above** the "Configuration JSON" textarea.
2. Its text mentions `NODE_TLS_REJECT_UNAUTHORIZED`, calls out that TLS verification is disabled, and links to `https://github.com/Automattic/mcp-wordpress-remote/blob/trunk/Docs/troubleshooting.md`.
3. Click the link — it opens the Automattic troubleshooting doc in a new tab.
4. Click through at least three client pills (e.g., Claude Desktop, Cursor, VS Code). For each: the textarea JSON contains a line like `"NODE_TLS_REJECT_UNAUTHORIZED": "0"` inside the `env` block.

**Step 3 — Quick Setup wizard Step 11**

Open the wizard (from any admin page):

```
?page=acrossai_mcp_manager&quick-setup=1
```

Advance through the steps until Step 11 (Client Detail). Verify:

1. The same warning callout renders above the `<CodeBlock>` containing the JSON.
2. The JSON in the code block includes `"NODE_TLS_REJECT_UNAUTHORIZED": "0"`.
3. Click the wizard's copy-to-clipboard button; paste into a text editor; confirm the pasted content includes the flag.

**Step 4 — Real end-to-end proof with Claude Desktop**

Copy the Claude Desktop JSON from either surface above. Paste it into `~/Library/Application Support/Claude/claude_desktop_config.json` (macOS path — adjust for other OSes). Restart Claude Desktop.

Open a new Claude Desktop chat, check the MCP indicator. Tools from this WordPress install should now appear in the tool picker — proving the operator's end-to-end journey works with **zero manual JSON edits**.

**Step 5 — Custom-suffix filter smoke test**

Add a companion snippet (or drop into `wp-content/mu-plugins/tls-suffix-test.php`):

```php
<?php
add_filter( 'acrossai_mcp_local_hostname_suffixes', static function ( array $suffixes ) {
    $suffixes[] = '.docker';
    return $suffixes;
} );
```

Simulate a `.docker` host by adding to `/etc/hosts`:

```
127.0.0.1  wordpress-test.docker
```

And a `pre_option_home` / `pre_option_siteurl` filter temporarily returning `https://wordpress-test.docker`. Refresh the MCP Clients tab. Verify the warning + injected flag still fire.

Remove the snippet, the `/etc/hosts` line, and the filter afterward.

**Step 6 — Defensive filter callback smoke test (SC-005)**

Register a deliberately-broken filter callback:

```php
add_filter( 'acrossai_mcp_local_hostname_suffixes', static fn () => false );
```

Refresh the MCP Clients tab. Expect: **no PHP warning**, **no fatal**, feature behaves as if the filter didn't exist (falls back to the default `.local` / `.test` / `.localhost` list). Remove the snippet.

---

## Negative path (production simulation — feature MUST NOT fire)

**Step 7 — Simulate a live production site**

Add to `wp-config.php`:

```php
define( 'WP_ENVIRONMENT_TYPE', 'production' );
```

Add a `pre_option_home` filter forcing a public hostname (in `mu-plugins/prod-sim.php`):

```php
<?php
add_filter( 'pre_option_home',    static fn () => 'https://example.com' );
add_filter( 'pre_option_siteurl', static fn () => 'https://example.com' );
```

Reload both surfaces:

- MCP Clients tab: no warning notice; JSON has no `NODE_TLS_REJECT_UNAUTHORIZED` key.
- Quick Setup Step 11: no callout; JSON has no `NODE_TLS_REJECT_UNAUTHORIZED` key.

**Revert** — Delete `WP_ENVIRONMENT_TYPE` from `wp-config.php`, delete `mu-plugins/prod-sim.php`.

---

## Automated verification

```bash
# PHPUnit — LocalEnvironment axes + defensive filter
composer run test -- --testsuite mcpclients

# Static analysis + linting
composer run phpcs
composer run phpstan

# Frontend bundle
npm run build

# Sanity grep — zero occurrences of the legacy inline env-literal in the 16 clients
grep -RnE "'WP_API_URL'\s*=>" includes/MCPClients/*Client.php
# Expected: zero matches
```

---

## Success signal

- All three surfaces (per-server tab, wizard, real Claude Desktop restart) show the flag + the warning on this local install.
- All three surfaces show neither the flag nor the warning under production simulation.
- PHPUnit passes ≥ 9 assertions on `LocalEnvironment`.
- All quality gates green.
