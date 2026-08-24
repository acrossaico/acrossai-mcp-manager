---
description: "Tasks for feature 075 — Local-Dev TLS Bypass Notice"
---

# Tasks: Local-Dev TLS Bypass Notice for MCP Client Snippets

**Input**: Design documents from `/specs/075-local-dev-tls-bypass-notice/`
**Prerequisites**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md), [quickstart.md](./quickstart.md)

**Tests**: Included (spec §Success Criteria SC-004 explicitly requires ≥ 8 PHPUnit assertions on `LocalEnvironment` plus SC-005 for the defensive filter callback).

**Organization**: Foundational phase covers the pure detection helper + shared `build_env()` refactor that BOTH user stories depend on. US1 (MCP Clients tab warning) and US2 (Quick Setup wizard callout) then run in parallel — different files, different subsystems. US3 (live-site negative path) is verification-only because it exercises the same code paths from steps 1–2 with different detection inputs.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks)
- **[Story]**: `US1`, `US2`, `US3` maps to user stories from spec.md
- All paths absolute from repository root (`/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/plugins/acrossai-mcp-manager/`)

## Path Conventions

- **Single project** (WordPress plugin): `includes/`, `admin/`, `public/`, `src/js/`, `tests/phpunit/`, `build/js/` at repository root.

---

## Phase 1: Setup

**Purpose**: No new tooling. The plugin already ships PHPCS, PHPStan level 8, PHPUnit, `@wordpress/scripts`, and `npm run build`. Zero composer/npm dependency changes are required for this feature.

- [X] T001 Confirm baseline is green before any change: run `composer run phpcs && composer run phpstan && composer run test && npm run build`. Record any pre-existing failures so we can distinguish them from ones this feature introduces. (No code change — this is a gate.)

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: The detection helper + shared `build_env()` refactor is a prerequisite for BOTH user stories. Neither the MCP Clients tab warning (US1) nor the wizard callout (US2) can be tested end-to-end until every concrete client's `env` block routes through the shared helper.

**⚠️ CRITICAL**: US1 and US2 both depend on T002–T005 completing.

- [ ] T002 Create `includes/Utilities/LocalEnvironment.php`: static-only class in namespace `AcrossAI_MCP_Manager\Includes\Utilities`. Implements `public static function needs_tls_bypass(): bool` per spec FR-001/FR-002/FR-009. Enforces the HTTPS scheme gate (fails closed on plain HTTP), reads `wp_get_environment_type()` for `local`/`development` (staging excluded per Clarification Q1), matches `localhost` / `127.0.0.1` / `::1` verbatim, and matches host suffixes via `apply_filters( 'acrossai_mcp_local_hostname_suffixes', array( '.local', '.test', '.localhost' ) )`. Defensive `is_array()` guard on the filter return value with fallback to defaults (spec SC-005). Mirror the shape of the existing `includes/Utilities/SiteSlug.php` (static-only, no hooks, no state, no singleton). Add a public helper `public static function troubleshooting_doc_url(): string` returning the Automattic doc URL as the single source of truth.
- [ ] T003 Modify `includes/MCPClients/AbstractMCPClient.php`: add `protected function build_env( string $server_url, string $auth_token, array $extra = array() ): array` per spec FR-006. Base env: `WP_API_URL`, `WP_API_USERNAME` (via `$this->current_username()`), `WP_API_PASSWORD` (via `$this->safe_token( $auth_token )`). Append `NODE_TLS_REJECT_UNAUTHORIZED => '0'` (string literal `"0"`, NOT integer `0`) when `LocalEnvironment::needs_tls_bypass()` is true. Merge `$extra` last so subclasses can add extra keys (e.g., `OAUTH_ENABLED`). Add `use AcrossAI_MCP_Manager\Includes\Utilities\LocalEnvironment;` to the file header.
- [ ] T004 [P] Refactor the 16 concrete MCP client classes to call `$this->build_env( $server_url, $auth_token )` instead of inlining the `WP_API_URL` / `WP_API_USERNAME` / `WP_API_PASSWORD` array. Files: `includes/MCPClients/{ClaudeDesktop,ClaudeCode,VSCode,Cursor,GitHubCopilot,Codex,Gemini,Windsurf,Zed,Cline,RooCode,KiloCode,AmazonQ,OpenCode,Antigravity,Custom}Client.php`. For `ClaudeCodeClient.php`, pass the extra `OAUTH_ENABLED => 'false'` key via the third `$extra` argument. Preserve the outer JSON shape exactly — only the `env` sub-array is affected.
- [ ] T005 [P] Create `tests/phpunit/Utilities/LocalEnvironmentTest.php`: ≥ 9 assertions per spec §SC-004 and §SC-005. Cases: (a) env-type `local` + `https://foo.com` → true; (b) env-type `production` + `.local` host → true (via host suffix); (c) env-type `staging` + `.local` host → true (via host suffix — Clarification Q1); (d) env-type `staging` + `example.com` → **false** (staging alone MUST NOT trigger); (e) env-type `production` + `example.com` → false; (f) env-type `local` + `http://foo.local` → **false** (HTTPS gate); (g) `127.0.0.1` + HTTPS → true; (h) `.test` host + HTTPS → true; (i) custom suffix `.docker` registered via `acrossai_mcp_local_hostname_suffixes` filter + HTTPS → true; (j) filter callback returning `false` (non-array) → no fatal, falls back to defaults. Namespace `AcrossAI_MCP_Manager\Tests\Utilities`. Suite: `mcpclients` (WP-free per SC-003 of feature 031 precedent).

**Checkpoint**: `composer run test -- --testsuite mcpclients` passes and every existing `mcpclients` fixture / canary in `tests/phpunit/MCPClients/` still passes. Concrete clients' generated JSON now includes the flag on this local install. US1 and US2 can begin in parallel.

---

## Phase 3: User Story 1 — MCP Clients tab warning (Priority: P1) 🎯 MVP

**Goal**: Local-dev operator on the per-server **MCP Clients tab** sees the yellow warning notice above the JSON textarea and the copied JSON contains `NODE_TLS_REJECT_UNAUTHORIZED: "0"`.

**Independent Test**: Per quickstart.md Step 2 — navigate to `admin.php?page=acrossai_mcp_manager&action=edit&server=1&tab=clients`, verify warning + flag on this local install.

### Implementation for User Story 1

- [ ] T006 [US1] Modify `public/Renderers/MCPClientsBlock.php` around lines 223–258 (the Configuration JSON region inside `render_client_details()`). Above the `<textarea>`, render a `<div class="notice notice-warning" style="margin: 12px 0;"><p>…</p></div>` block when `LocalEnvironment::needs_tls_bypass()` is true. Copy: "Local dev detected — TLS certificate validation is disabled in this snippet. We added `NODE_TLS_REJECT_UNAUTHORIZED: \"0\"` so the proxy can reach your self-signed local site. This turns off TLS verification and is safe **only** for local, throwaway testing — never use this setting against a live site." with a trailing link (`<a href="…" target="_blank" rel="noopener">Read the troubleshooting doc.</a>`) using `LocalEnvironment::troubleshooting_doc_url()` (T002). Wrap the URL in `esc_url()`, the plain text in `esc_html_e()` / `esc_html__()`, and the `NODE_TLS_REJECT_UNAUTHORIZED` code fragment in a `<code>` tag with `esc_html()`. Follow the visual pattern established by `admin/Partials/Notices.php`. Add `use AcrossAI_MCP_Manager\Includes\Utilities\LocalEnvironment;` to the header.
- [ ] T007 [US1] Manual verification per quickstart.md Step 2 on this local install: navigate to the MCP Clients tab, click through at least three client pills (Claude Desktop, Cursor, VS Code), confirm the yellow notice renders above the textarea and the visible JSON contains the `NODE_TLS_REJECT_UNAUTHORIZED` line. Click the troubleshooting link — confirm it opens the Automattic doc in a new tab.

**Checkpoint**: US1 is fully functional and independently testable. Operators reaching the per-server tab get the fix without opening the wizard.

---

## Phase 4: User Story 2 — Quick Setup wizard callout (Priority: P1)

**Goal**: Same fix reaches operators who go through the Quick Setup wizard — Step 11 shows a warning callout above the code block and the JSON contains the flag.

**Independent Test**: Per quickstart.md Step 3 — open `?…&quick-setup=1`, advance to Step 11, verify callout + JSON.

### Implementation for User Story 2

- [ ] T008 [US2] Modify `admin/Main.php::maybe_enqueue_quick_setup_app()` (lines ~189–237, inside the `wp_localize_script` payload). Add a `'tlsBypass'` key:
  ```php
  'tlsBypass' => array(
      'enabled' => LocalEnvironment::needs_tls_bypass(),
      'message' => __( '…same copy string as the PHP notice in T006…', 'acrossai-mcp-manager' ),
      'docUrl'  => LocalEnvironment::troubleshooting_doc_url(),
  ),
  ```
  Add `use AcrossAI_MCP_Manager\Includes\Utilities\LocalEnvironment;` to the header.
- [ ] T009 [P] [US2] Modify `src/js/quick-setup/steps/Step11_ClientDetail.jsx`. Read `bootstrap.tlsBypass` (from the localized `window.acrossaiMcpQuickSetup`). When `enabled === true`, render a warning callout above the `<CodeBlock>` — reuse the wizard's existing callout component; if none exists, use `<Notice status="warning" isDismissible={ false }>` from `@wordpress/components`. The callout body renders `bootstrap.tlsBypass.message` followed by a link (`<a href={ bootstrap.tlsBypass.docUrl } target="_blank" rel="noopener noreferrer">Read the troubleshooting doc.</a>`). Do NOT mutate the JSON string in the frontend — the pre-rendered `state.methods.clients[i].config` already contains the flag from the T003 refactor.
- [ ] T010 [US2] Rebuild the JS bundle: `npm run build`. Verify `build/js/quick-setup.js` and its `.asset.php` file update. Depends on T009.
- [ ] T011 [US2] Manual verification per quickstart.md Step 3 on this local install: open the wizard, advance to Step 11, confirm the callout renders above the code block and the visible JSON contains the flag. Click the copy button, paste into a scratch buffer, confirm the pasted JSON includes `NODE_TLS_REJECT_UNAUTHORIZED`.

**Checkpoint**: US2 is fully functional. First-run operators via the wizard get the same automatic fix and same warning treatment as US1.

---

## Phase 5: User Story 3 — Live-site negative path (Priority: P1)

**Goal**: On a real production install, both surfaces show zero warning and zero `NODE_TLS_REJECT_UNAUTHORIZED` occurrences.

**Independent Test**: Per quickstart.md Step 7 — simulate `WP_ENVIRONMENT_TYPE=production` + `example.com` host, reload both surfaces.

### Verification for User Story 3

- [ ] T012 [US3] Manual production simulation per quickstart.md Step 7: add `define( 'WP_ENVIRONMENT_TYPE', 'production' )` to `wp-config.php` and drop an `mu-plugins/prod-sim.php` with `pre_option_home` / `pre_option_siteurl` filters returning `https://example.com`. Reload the MCP Clients tab and Quick Setup Step 11. Confirm zero warning + zero flag in the generated JSON. Revert `wp-config.php` and delete `mu-plugins/prod-sim.php` afterward.

**Checkpoint**: All three user stories independently functional. Feature is behavior-safe on both local and simulated production.

---

## Phase N: Polish & Cross-Cutting Concerns

**Purpose**: DoD gates, canary tests, documentation.

- [ ] T013 [P] Canary grep — zero legacy inline env-literals surviving in the 16 clients:
  ```bash
  grep -RnE "'WP_API_URL'\\s*=>" includes/MCPClients/*Client.php
  ```
  Expected: **zero matches** (every literal was replaced by `$this->build_env()` in T004). Any hit is a refactor miss — go back to T004.
- [ ] T014 [P] Custom-suffix filter smoke test per quickstart.md Step 5 on this local install. Register a companion `add_filter( 'acrossai_mcp_local_hostname_suffixes', … )` snippet, simulate a `.docker` host, confirm the flag still fires. Remove the snippet + hosts entry afterward.
- [ ] T015 [P] Defensive filter callback smoke test per quickstart.md Step 6: register `add_filter( 'acrossai_mcp_local_hostname_suffixes', static fn () => false )`. Reload the MCP Clients tab. Expect **no PHP warning**, no fatal, feature falls back to default suffixes. Remove the snippet afterward.
- [ ] T016 [P] Update `README.txt`: add one bullet under `= Unreleased =`:
  ```
  * Local dev sites served over HTTPS with a self-signed certificate (Local by Flywheel, MAMP, DDEV, wp-env) now auto-receive NODE_TLS_REJECT_UNAUTHORIZED=0 in the copied MCP client JSON, with a warning notice explaining what was added and linking to the Automattic troubleshooting doc. Live sites are unaffected.
  ```
- [ ] T017 Update `docs/planings-tasks/README.md` Feature Specs table: append a row `| 075 | local-dev-tls-bypass-notice | 2026-08-24 | Complete | [075-local-dev-tls-bypass-notice.md](075-local-dev-tls-bypass-notice.md) |`. (Optional — the table is currently stale at row 040; skipping is consistent with F041/F072–F074 conventions.)
- [ ] T018 Quality gates chain — all MUST pass before merge:
  ```bash
  composer run phpcs
  composer run phpstan
  composer run test -- --testsuite mcpclients
  npm run build
  ```
  Zero errors on each. Cross-reference the failures (if any) against T001's baseline snapshot.

**Final checkpoint**: All 18 tasks green. Feature ready for `/speckit.analyze` and `/speckit.git.commit`.

---

## Dependencies & Story Ordering

```
T001 (Setup baseline)
  │
  ▼
Phase 2 — Foundational (blocks US1 and US2)
  T002 (LocalEnvironment)
    │
    ▼
  T003 (AbstractMCPClient::build_env)
    │
    ▼
  T004 (refactor 16 clients — parallel across files, single search-replace pattern)
  T005 (PHPUnit LocalEnvironmentTest — parallel with T004, different files)
    │
    ▼
Phase 3 — US1                Phase 4 — US2         (parallel with US1)
  T006 (PHP notice)            T008 (localize payload)
  T007 (manual verify)          T009 (JSX callout)
                                T010 (npm run build — depends on T009)
                                T011 (manual verify)
    │                             │
    └──────────────┬──────────────┘
                   ▼
              Phase 5 — US3
              T012 (production simulation smoke)
                   │
                   ▼
              Phase N — Polish
              T013–T018 (mostly parallel; T018 is the final gate)
```

## Parallel Execution Examples

**Phase 2 parallel batch** (after T002 → T003 land): T004 (client refactor across 16 files) and T005 (PHPUnit test file) touch disjoint paths.

**Phase 3 + Phase 4 parallel batch** (after Phase 2 checkpoint): US1's T006 (`public/Renderers/MCPClientsBlock.php`) and US2's T008–T009 (`admin/Main.php` + `src/js/quick-setup/steps/Step11_ClientDetail.jsx`) touch disjoint files.

**Phase N parallel batch** (after Phase 5 checkpoint): T013 (grep), T014 (filter smoke), T015 (defensive smoke), T016 (README), T017 (docs index) all parallel. T018 (gates chain) runs last, sequentially.

## Implementation Strategy

**MVP scope** = Foundational (T002–T005) + US1 (T006–T007). Delivers a working fix on the per-server tab even if the wizard callout ships later. Ship US2 in the same PR by default (it's low-cost given the foundation is already in place), but the phasing allows a split PR if reviewer prefers.

**Increment order**: Setup → Foundational → US1 → US2 → US3 verification → Polish. Each phase is independently reviewable and rebuildable.

## Task count

- Setup: 1
- Foundational: 4 (T002, T003, T004, T005)
- US1: 2 (T006, T007)
- US2: 4 (T008, T009, T010, T011)
- US3: 1 (T012)
- Polish: 6 (T013–T018)
- **Total: 18**

Parallel opportunities: T004 || T005 within Phase 2; T006 || (T008, T009→T010) between US1 and US2; T013 || T014 || T015 || T016 in Polish.
