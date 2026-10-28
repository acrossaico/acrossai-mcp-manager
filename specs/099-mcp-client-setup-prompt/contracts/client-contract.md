# Contract — MCP client extension point

`includes/MCPClients/AbstractMCPClient.php` is a public extension point: third parties register
client classes through the `acrossai_mcp_client_classes` filter. This contract governs what may be
added to it.

## New member

```php
/**
 * Paste-ready instructions telling an AI client to add this server to its own config.
 *
 * @param string $server_url  Fully-resolved MCP endpoint for one server.
 * @param string $auth_token  Application Password, or '' for the placeholder.
 * @return string             Plain text. Never HTML, never pre-escaped.
 */
public function get_setup_prompt( string $server_url, string $auth_token ): string;
```

**Backwards compatibility — binding**

- Declared **non-abstract** with a working default. A bare subclass implementing only the existing
  abstract members MUST continue to instantiate and MUST return a usable prompt (FR-021).
- The default MUST require zero per-client overrides. All per-client variation already lives in
  `get_config_files()`, `get_top_level_key()`, `get_config_snippet()` and `get_restart_step_text()`.
- Overriding is permitted for bespoke wording, following the `get_restart_step_text()` precedent.

**Purity — binding, and narrower than it first appears**

- **Only WordPress functions that `tests/phpunit/MCPClients/`'s WP-free bootstrap stubs**, guarded
  with `function_exists()` where the stub may be absent. This is *not* "no WordPress functions":
  `AbstractMCPClient` already calls `__()` (lines 189, 192), `apply_filters()` (269),
  `_doing_it_wrong()` (283, 296) and `esc_html__()` (287, 300), and `tests/bootstrap.php` already
  stubs `__()` (115) and `esc_html__()` (123) behind `function_exists()` guards — precisely the
  carve-out `A18` grants. Translating the prompt's prose (FR-026) is therefore permitted and
  required; a stricter reading would block the feature's own requirement.
- `A11` purity means **no instance state, no constructor arguments, no hook registration** — it is
  not a prohibition on calling WordPress.
- The suite must keep running without a WordPress bootstrap (A18 / SC-003). If it suddenly needs
  one, the builder has reached for an unstubbed function: change the builder, not the bootstrap.

**Output guarantees**

| Guarantee | Requirement |
|---|---|
| Contains the server URL | FR-001 |
| Contains every OS path the client declares, labelled | FR-002, FR-013 |
| Contains the top-level key | FR-006 |
| Contains the configuration, byte-identical to the manual path | FR-003 |
| Contains the `WP_API_USERNAME` **key** as well as the password | FR-005, B19 — the key is the guarantee. `current_username()` guards `wp_get_current_user()` with `function_exists` (line 467), so the resolved value is empty under the WP-free bootstrap by design. Assert on the key, not the value, in that suite |
| Contains the reload instruction | FR-006 |
| Contains **no** credential when `$auth_token === ''` | FR-007 |
| Deterministic — same inputs, identical output | data-model.md |
