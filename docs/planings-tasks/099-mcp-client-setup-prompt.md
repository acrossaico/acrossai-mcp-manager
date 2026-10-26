# Planning: Generate-Prompt connect path for MCP clients (Feature 099)

Add a second way to finish connecting an MCP client: a paste-ready block of prose that tells the
AI client to edit its own config file, alongside the existing manual instructions. Step 1
(generate an Application Password) is unchanged and shared; step 2 then offers two routes — "let
your AI client do it" and "do it manually".

This is the pattern Elementor ships (`elementor/vendor/elementor/elementor-mcp-composer/`,
`src/Admin/CredentialsController.php` + the client table in `assets/build/mcp-page.js`). Elementor
templates five clients; this plugin supports **sixteen**.

**The prompt describes the same configuration the manual path already shows** — the
`@automattic/mcp-wordpress-remote` stdio bridge with `WP_API_URL` / `WP_API_USERNAME` /
`WP_API_PASSWORD` env vars. No transport change, no new config shape, no second snippet builder.
The feature automates the file-editing steps (open the file, find the top-level key, merge the
block, save, restart) that the operator currently performs by hand. Those manual steps remain for
anyone who wants them.

The per-client data this needs **already exists**. Every class under `includes/MCPClients/`
carries its config-file path (single and per-OS), its top-level key, its snippet builder and its
restart text. The prompt is a composition of accessors already on the contract:

| Prompt sentence | Existing source |
|---|---|
| "Open or create the config file at …" | `get_config_files()` (per-OS map), falling back to `get_config_file()` |
| "Add this to the `<key>` block" | `get_top_level_key()` |
| the JSON/TOML block itself | `get_config_snippet( $server_url, $auth_token )` |
| "Restart … / re-open the panel" | `get_restart_step_text()` |

`ConnectionMethodRegistry::get_clients()` (`public/Discovery/ConnectionMethodRegistry.php`) is the
single DTO that both the PHP admin renderer and the React wizard consume, so **one new DTO field
reaches both surfaces** and the prompt cannot drift between them.

Two defects in adjacent code are in scope because this feature is what makes them start to
matter: the wizard's step-2 path display shows macOS paths to Windows users, and
`injectPasswordIntoConfig()` silently fails to splice the password for OpenCode. Both are
described under Manual Verification below.

---

## Speckit Workflow

```
# 1. Branch
/speckit.git.feature "mcp-client-setup-prompt"

# 2. Specify
/speckit.specify "<see Detailed Description below>"

# 3. Clarify
/speckit.clarify

# 4. Plan + guard + security
/speckit.memory-md.plan-with-memory
/speckit.architecture-guard.governed-plan
/speckit.security-review.plan

# 5. Tasks + guard
/speckit.tasks
/speckit.architecture-guard.governed-tasks
/speckit.security-review.tasks

# 6. Implement + quality checks
/speckit.architecture-guard.governed-implement

# 7. Review + memory + commit
/speckit.analyze
/speckit.architecture-guard.architecture-review
/speckit.architecture-guard.architecture-verify
/speckit.security-review.staged
/speckit.memory-md.capture-from-diff
/speckit.git.commit
```

### Detailed Description for `/speckit.specify`

> **Before writing a single line of code, read and internalize these in full:**
>
> 1. `AGENTS.md` — singleton pattern, hook-registration rules (Constitution §A1: hooks are wired
>    in `Main.php` only), Before Commit Checklist.
> 2. `includes/MCPClients/AbstractMCPClient.php` in full — the contract, which methods are
>    abstract vs virtual-with-default, `EMPTY_TOKEN_PLACEHOLDER`, `build_env()`, `safe_token()`,
>    `derive_server_key()`, and `get_all_registered_clients()` as the sole canonical enumeration
>    path.
> 3. `public/Discovery/ConnectionMethodRegistry.php::get_clients()` — the DTO both the REST layer
>    and companion plugins read. Note that its string fields are **not** pre-escaped (SEC-035-002)
>    and that `config` is present only when `$server !== null`.
> 4. `public/Renderers/AbstractClientRenderer.php` and `MCPClientsBlock.php` — the renderer
>    contract, `context['cap']` (never hardcoded `manage_options`, SEC-013-005), and the shared
>    helpers `passwords_generate_button()`, `config_json_pre_block()`, `copy_config_button()`.
> 5. `docs/memory/INDEX.md`, then the entries it routes to for **B19**, **B69**, **B72**, **D19**,
>    **D33**, **DEC-CLIENT-RENDERER-PUBLIC-API**, and Constitution §III's consent-surface
>    exception.
> 6. The Elementor reference implementation at
>    `wp-content/plugins/elementor/vendor/elementor/elementor-mcp-composer/src/Admin/CredentialsController.php`
>    — specifically `get_application_password_blocker()`, which is the availability-check pattern
>    this plugin currently lacks entirely.
>
> **Scope: all sixteen clients.** The per-client data already exists, so covering sixteen costs
> little more than covering four and avoids a screen where some clients offer the one-click path
> and others silently do not.
>
> **The config shape does not change.** The prompt must embed the output of the client's existing
> `get_config_snippet()`, encoded with `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES` — the same
> flags `ConnectionMethodRegistry` uses — so the prompt's block and the manual block are
> byte-identical. `get_config_snippet()` is documented `string|array`; honour that contract with
> `is_array()` even though no in-tree client returns a string today.
>
> **`NODE_TLS_REJECT_UNAUTHORIZED` must NOT be gated on URL scheme.**
> `LocalEnvironment::needs_tls_bypass()` fires on local-looking sites (`wp_get_environment_type()`
> is `local`/`development`, or host ends `.local`/`.test`/`.localhost`) regardless of scheme, and
> the class docblock records that an HTTPS-scheme gate previously existed and was **deliberately
> removed under FR-009** — the real failure mode is a self-signed cert on an `https://…local`
> site, so scheme is the wrong test. The flag is already absent on production sites, which is the
> desired outcome. Leave `build_env()` alone.
>
> **New contract method — virtual, not abstract:**
>
> ```php
> public function get_setup_prompt( string $server_url, string $auth_token ): string
> ```
>
> Add to `AbstractMCPClient` **non-abstract with a working default**, following the exact
> precedent of `get_restart_step_text()` and `get_config_files()`: additive and defaulted, so bare
> third-party subclasses registered via the `acrossai_mcp_client_classes` filter keep compiling.
> The default composes the four accessors in the table above and must need **zero per-client
> overrides**; all per-client variation already lives in those accessors. Clients wanting bespoke
> wording override it.
>
> Paths in the prompt come from `get_config_files()` with a fallback to `get_config_file()` — the
> `MCPClientsBlock` precedence. Name the path for **every** OS the client declares, labelled, with
> no user-agent sniffing (an explicit prior review decision). Elementor's own prompt does the same,
> shipping `configFilePath` and `windowsConfigFilePath` side by side.
>
> **New DTO field:** add `setupPrompt` to `get_clients()` alongside `config`, present only when
> `$server !== null`. Build it with an **empty** token so it carries `EMPTY_TOKEN_PLACEHOLDER`.
> No credential ever travels in the DTO.
>
> **Credential handling — reuse, do not re-plumb.** Do **not** register a third
> `/generate-app-password` route. The handler that actually serves the route is
> `admin/Partials/ApplicationPasswords.php::generate_app_password()`; it already returns `username`
> alongside `password`, which is required — per **B19**, a password-only config 401s on every
> request with nothing in wp-admin explaining why. That handler currently has zero matches for any
> of the following, all of which must be added:
>
> - `wp_is_application_passwords_available()` and `wp_is_application_passwords_available_for_user()`
>   — Elementor's "blocker" pattern. Nothing in this plugin calls either today, so a site with app
>   passwords disabled by constant or filter gets a confusing failure instead of a clear one.
> - The **D33** access-control gate: `user_has_server_access( $user_id, $server_id )` **before**
>   issuing, firing `acrossai_mcp_access_control_denied` with a distinct `$context` string on
>   denial, failing open when the AC package is absent (**D19**). D33 records this as mandatory for
>   every connection-time credential-issuance surface.
> - `CacheHeaders::apply_to_rest_response()` — the response carries a plaintext credential and both
>   sibling mint paths already do this.
> - Capture and retain the returned `uuid` (**B72**) so a later revoke can target one credential
>   rather than `--all` destroying every credential on the user.
> - Name the password with `APP_NAME_PREFIX` (`'AcrossAI MCP Manager'`) so it appears in the Tokens
>   tab and `GET /list-app-passwords`, both of which filter on that substring. The wizard's current
>   `Quick Edit - <Client>` name does not match and is therefore invisible there.
> - Consider `OAuth\Security\RateLimiter::check()`. No app-password endpoint is rate-limited today,
>   and a prominent one-click mint button changes the threat model.
>
> **Explicitly out of scope — file separately.** Two handlers register the same route:
> `admin/Partials/ApplicationPasswords.php:67` and `includes/REST/ClientRendererController.php`.
> `Main::load_hooks()` calls `define_admin_hooks()` (line 218) before `define_public_hooks()`
> (line 219), so the stricter Feature-013 handler never runs and its three SEC-013 invariants
> (context-bound nonce, `user_id` lockdown, AC gate) are dead code on the live route. Confirmed by
> `src/js/backend.js:172` posting only `{ server_id }` with a bare `wp_rest` nonce
> (`AbstractClientRenderer.php:149`) while the button emits unused `data-client-slug` and
> `data-context` attributes. Deciding which handler should win is a change to Feature 013's
> security model, not this feature's work.
>
> **Credential-in-prompt is a deliberate, stated tradeoff.** The generated prompt contains a live
> credential in plain text, and the operator pastes it into an AI client — so it lands in that
> client's conversation history and, for hosted assistants, on a vendor's servers. This is inherent
> to the approach and Elementor accepts the same tradeoff. The UI MUST say so plainly next to the
> Copy button rather than leaving the operator to work it out, and revocation MUST be one click
> away from where the prompt was generated. The credential is a scoped Application Password,
> revocable without touching the operator's login.
>
> **Both render surfaces, and the drift between them.** Two screens show step 2 and both must get
> the new panel:
>
> - `public/Renderers/MCPClientsBlock.php::render_client_details()` —
>   `admin.php?page=acrossai_mcp_manager&action=edit&server=<id>&tab=connect`
> - `src/js/quick-connect/steps/Step11_ClientDetail.jsx` —
>   `admin.php?page=acrossai_mcp_manager&quick-connect=1&step=11&server=<id>`
>
> Their existing path display has already drifted and must be reconciled.
> `MCPClientsBlock.php:273-287` prefers the per-OS `get_config_files()` map, rendered by
> `render_os_config_paths()` ordered by `OS_LABELS`, falling back to the single
> `get_config_file()`. `Step11_ClientDetail.jsx:272` reads **only** `meta.config_file` and never
> touches `meta.config_files`, which the DTO already carries. Every client defines
> `get_config_file()`, so Step 11 is not blank — it is **wrong**: that value is the macOS path for
> `ClaudeDesktopClient`, `VSCodeClient` and `GitHubCopilotClient`, so a Windows user in the wizard
> is told to edit `~/Library/Application Support/…`. Upgrade Step 11 to render `config_files` with
> the same labels and ordering. Without this the new prompt would name correct paths while the
> panel beside it names a wrong one.
>
> **Password splicing — fix, don't inherit.** The real password is spliced client-side after the
> generate call. Two unsynchronised implementations exist, both keyed on the literal
> `(paste generated password here)`:
>
> - `Step11_ClientDetail.jsx:148` — `raw.split( PLACEHOLDER ).join( password )`, format-agnostic,
>   correct.
> - `src/js/backend.js::injectPasswordIntoConfig()` — parses the JSON and walks
>   `serverBlock.env`, so it **silently fails for OpenCode**, whose env key is `environment`, not
>   `env`. It also re-stringifies without `JSON_UNESCAPED_SLASHES`, so the redisplayed JSON escapes
>   slashes differently from the PHP original.
>
> Use the format-agnostic replace for the prompt on both surfaces and switch
> `injectPasswordIntoConfig()` to the same approach, fixing OpenCode on the existing manual path.
>
> Every `WP_Error` must use a hand-authored message — no `$e->getMessage()`, no
> `$wpdb->last_error`, no transient keys or filesystem paths in responses. All `__()` calls use
> the text domain `'acrossai-mcp-manager'`.

---

## Manual Verification Checklist

### TASK-1 — Contract method on every client

- [ ] `get_setup_prompt()` is non-abstract on `AbstractMCPClient` with a working default.
- [ ] A bare subclass implementing only the abstract methods still instantiates (register one via
      `acrossai_mcp_client_classes` in a test and assert a usable prompt comes back).
- [ ] For each of the sixteen clients the prompt contains: the server URL, the top-level key,
      `WP_API_USERNAME` (B19's stated grep check), and the placeholder.
- [ ] The prompt contains **no** literal credential.
- [ ] The prompt names **every** OS the client declares — not just one.
- [ ] `tests/phpunit/MCPClients/*` run without a WordPress bootstrap (SC-003), so the default
      implementation must not call WP functions or must receive them injected.

### TASK-2 — DTO

- [ ] `setupPrompt` present when a server is resolved, absent when `$server === null` (matching
      `config`'s existing condition).
- [ ] `ConnectionMethodRegistry::get_all()` memoization and `flush_cache()` still behave.

### TASK-3 — Both surfaces

- [ ] Connect tab and wizard Step 11 show the identical prompt for the same server and client.
- [ ] Step 11's manual panel now lists per-OS paths; Claude Desktop no longer shows only macOS.
- [ ] Prompt panel absent when no server is resolved.
- [ ] Output escaped at the render boundary; React path uses `CodeBlock` text nodes, never
      `dangerouslySetInnerHTML`.
- [ ] Credential warning and a revoke affordance are visible beside the Copy button.

### TASK-4 — Mint route hardening

- [ ] Clear `WP_Error` when `wp_is_application_passwords_available_for_user()` is false.
- [ ] Denial when `user_has_server_access()` is false, with
      `acrossai_mcp_access_control_denied` fired carrying a distinct context string.
- [ ] Fails **open** when the AC package is absent (D19).
- [ ] Response carries `CacheHeaders`.
- [ ] Minted password appears in the Tokens tab and `GET /list-app-passwords`.
- [ ] No third registration of `/generate-app-password` was added.

### TASK-5 — OpenCode splice regression

- [ ] Generating a password on the OpenCode client lands it in `environment`, on both the Connect
      tab and the wizard. This is the splice fix's reason for existing.

### TASK-6 — End to end on the live site

- [ ] At `admin.php?page=acrossai_mcp_manager&action=edit&server=5&tab=connect`: generate a
      password, copy the Claude Code prompt, confirm the credential is spliced in and the JSON
      matches the manual block byte for byte.
- [ ] Paste the prompt into an actual client and confirm it connects.
- [ ] **Rule out B69 first.** Local by Flywheel's nginx omits
      `fastcgi_param HTTP_AUTHORIZATION`, so Application Passwords can resolve to user 0 and the
      connection will look broken for reasons unrelated to this code.

### Quality gates (all must be green before commit)

- [ ] `composer phpcs`
- [ ] `composer phpstan` — **clear the result cache first**; a warm cache invents errors CI never
      sees.
- [ ] `npm run lint:js`
- [ ] `npm run build` exits 0 with no unexplained bundle drift.
- [ ] Full PHPUnit suite.
- [ ] Rename-gate suite still green.

---

## Deferred — recorded so it is not rediscovered

Per-client **native HTTP** support — a short `url` + `Authorization: Basic` config needing no
Node.js at all — was verified against official vendor documentation for all sixteen clients during
this feature's research. It is viable for fifteen, with real per-client landmines:

| Client | Shape |
|---|---|
| Claude Code | `mcpServers`, `type:"http"`, `url`, `headers`; `claude mcp add --transport http --header` |
| Cursor | `mcpServers`, no `type` for remote, `url`, `headers`; `${env:NAME}` supported |
| VS Code / Copilot agent mode | **`servers`**, `type:"http"` **required**, `url`, `headers`; `${input:id}` confirmed |
| Gemini CLI | **`httpUrl`** not `url` (plain `url` means legacy SSE) |
| Codex | TOML **`mcp_servers`**, `url`, **`http_headers`** / `env_http_headers` |
| Cline | `type` must be exactly **`streamableHttp`** — otherwise silent SSE fallback and a 405 |
| Roo Code | `type:"streamable-http"` (hyphenated, unlike Cline) |
| Kilo Code / OpenCode | top-level **`mcp`**, `type:"remote"` |
| Windsurf | **`serverUrl`** not `url` |
| Zed | **`context_servers`**; a static Authorization header explicitly bypasses OAuth |
| Antigravity | `serverUrl`, `headers` |
| Amazon Q | murkiest — headers documented only in the IDE GUI and the admin governance registry (array-of-objects shape) |
| **Claude Desktop** | **Cannot.** Static headers are UI-only (Settings → Connectors); a file-based setup always needs the bridge. |

Also noted: Claude Code reads env vars whose names match `TOKEN|SECRET|PASSWORD|KEY|AUTH` as
**empty** inside remote-server headers, as an anti-exfiltration measure — any future env-var
approach must use a deliberately bland variable name.

Worth its own feature. Explicitly **not** this one: this feature keeps the bridge config unchanged.
