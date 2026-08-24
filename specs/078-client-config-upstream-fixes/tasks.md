---
description: "Tasks for feature 078 — Client config paths + restart phrasings verification fixes"
---

# Tasks: Client config paths + restart phrasings — upstream-docs verification pass

**Input**: Design documents from `/specs/078-client-config-upstream-fixes/`
**Prerequisites**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md), [quickstart.md](./quickstart.md)

**Tests**: Existing PHPUnit `mcpclients` suite covers the shape assertions via golden fixtures. VS Code + GitHub Copilot fixtures need lockstep updates.

## Format: `[ID] [P?] [Story] Description`

---

## Phase 1: Setup

- [X] T001 Confirm baseline is green: `composer run phpcs && composer run phpstan && composer run test -- --testsuite mcpclients`. No pre-existing failures.

---

## Phase 2: User Story 1 — 9 client fixes (Priority: P1)

**Goal**: Every fixed client renders STEP 2/3/4/5 with values matching upstream docs.

### Wrong-path / wrong-shape fixes (P1)

- [ ] T002 [US1] Edit `includes/MCPClients/KiloCodeClient.php` — biggest change:
  - `get_config_file()` → `'~/.config/kilo/kilo.jsonc'`
  - `get_top_level_key()` → `'mcp'`
  - `get_config_snippet()` → new shape: `array('mcp' => array($this->derive_server_key($server_url) => array('command' => array('npx', '-y', '@automattic/mcp-wordpress-remote@latest'), 'environment' => $this->build_env($server_url, $auth_token))))`
  - `get_instructions()` → mention `.kilo/kilo.jsonc` (project) or `~/.config/kilo/kilo.jsonc` (global) + new format callout
  - `get_restart_step_text()` → describe MCP Servers sidebar panel (matches Roo Code / Cline family)

- [ ] T003 [US1] Edit `includes/MCPClients/VSCodeClient.php`:
  - `get_config_file()` → `'~/Library/Application Support/Code/User/mcp.json'`
  - `get_instructions()` → update to reflect the new user-level path (workspace-level `.vscode/mcp.json` still mentioned as alternative)
  - `get_restart_step_text()` → reflect auto-start ("VS Code auto-starts new servers; reload the window (Cmd/Ctrl+Shift+P → Developer: Reload Window) if it doesn't appear.")

- [ ] T004 [US1] Edit `includes/MCPClients/GitHubCopilotClient.php`:
  - `get_config_file()` → same macOS path as VS Code
  - `get_instructions()` → update path + mention Agent mode requirement
  - `get_restart_step_text()` → "Ensure Copilot Chat is in Agent mode; VS Code auto-starts the new server (reload the window if it doesn't appear)."

### Restart-phrasing polishes

- [ ] T005 [P] [US1] Edit `includes/MCPClients/CursorClient.php` `get_restart_step_text()` → "Reload Cursor (Cmd/Ctrl + Shift + P → Reload Window) or toggle the server in Settings → MCP to load the new MCP server."
- [ ] T006 [P] [US1] Edit `includes/MCPClients/WindsurfClient.php` `get_restart_step_text()` → "Click Refresh in Cascade's MCP panel (or restart Windsurf) to load the new MCP server."
- [ ] T007 [P] [US1] Edit `includes/MCPClients/ZedClient.php` `get_restart_step_text()` → "Zed live-reloads settings — open the Agent Panel to confirm the new tools appear."
- [ ] T008 [P] [US1] Edit `includes/MCPClients/AntigravityClient.php` `get_restart_step_text()` → "Reload MCP servers with the `/mcp` CLI command or the Manage MCP Servers panel in the IDE; a full Antigravity restart also works."
- [ ] T009 [P] [US1] Edit `includes/MCPClients/AmazonQClient.php`:
  - `get_instructions()` → add "IDE users may also see `~/.aws/amazonq/default.json` as the current GUI default; `mcp.json` still works."
  - `get_restart_step_text()` → keep abstract default ("Restart Amazon Q Developer to load the new MCP server.") — already accurate
- [ ] T010 [P] [US1] Edit `includes/MCPClients/RooCodeClient.php` `get_restart_step_text()` → "Open the MCP Servers panel in the Roo Code sidebar; click Restart on the new server if it doesn't appear automatically."

### Optional cleanup

- [ ] T011 [US1] Edit `includes/MCPClients/ZedClient.php` `get_config_snippet()` — drop `'source' => 'custom'` and `'enabled' => true` from the `context_servers` server entry. Keep `command`, `args`, `env` (via `build_env()`) — the canonical stdio shape per Zed docs.

### Fixture updates

- [ ] T012 [US1] Update `tests/phpunit/MCPClients/fixtures/vscode-{with,empty}-token.json` — regenerate with the new `get_config_file()` value if the fixture asserts on it. If fixtures only assert on `get_config_snippet()` output (shape), no change needed since the SNIPPET's structure is unchanged (only the config-file DISPLAY path changed).
- [ ] T013 [US1] Update `tests/phpunit/MCPClients/fixtures/github-copilot-{with,empty}-token.json` — same treatment as VS Code fixtures.
- [ ] T014 [US1] Verify no fixtures exist for Kilo Code / Roo Code / Zed / Amazon Q / Antigravity / Windsurf / Cursor — those were F071-batch and didn't ship golden fixtures. If any DO exist, regenerate them from the new PHP return.

**Checkpoint**: All 9 clients render correct STEP 2/3/4/5 content.

---

## Phase 3: Verification

- [ ] T015 Canary greps (SC-001..007 from spec.md § Success Criteria):
  ```bash
  grep -c 'kilocode' includes/MCPClients/KiloCodeClient.php    # 0
  grep -c 'mcpServers' includes/MCPClients/KiloCodeClient.php  # 0
  grep -c 'Application Support/Code/User/mcp.json' includes/MCPClients/VSCodeClient.php includes/MCPClients/GitHubCopilotClient.php  # both files match
  grep -cE "'source'\\s*=>\\s*'custom'|'enabled'\\s*=>\\s*true" includes/MCPClients/ZedClient.php  # 0
  grep -c 'Agent mode' includes/MCPClients/GitHubCopilotClient.php  # >=1
  grep -c 'default.json' includes/MCPClients/AmazonQClient.php  # >=1
  ```
- [ ] T016 Syntax check: `for f in includes/MCPClients/*Client.php; do php -l "$f" > /dev/null || echo "FAIL: $f"; done` — no output.
- [ ] T017 Manual browser smoke on this local install: reload MCP Clients tab, click through the 9 fixed pills, confirm each STEP renders the target values from `quickstart.md`.

---

## Phase N: Polish

- [ ] T018 Update `README.txt` — one bullet under `= Unreleased =`:
  ```
  * **UI — 9 MCP client configs updated after upstream-docs verification (F078).** Kilo Code migrated to the new `.kilo/kilo.jsonc` + `mcp` format (per v7.0.33+); VS Code + GitHub Copilot user-level path corrected to the documented macOS `~/Library/Application Support/Code/User/mcp.json`; GitHub Copilot restart phrasing now mentions Agent mode; Cursor/Windsurf/Zed/Antigravity/Roo Code restart phrasings polished to reflect each client's documented lightweight reload path (Reload Window / Cascade Refresh / live-reload / `/mcp` command / MCP Servers panel Restart button). Amazon Q Developer instructions note `default.json` as the current GUI default. Zed drops non-canonical `source: 'custom'` + `enabled: true` prefix. Every fix cites its official upstream doc URL — see `specs/078-client-config-upstream-fixes/research.md`.
  ```
- [ ] T019 Quality gates:
  ```bash
  composer run phpcs
  composer run phpstan
  composer run test -- --testsuite mcpclients
  npm run build   # no changes; re-verify
  ```

---

## Task count

- Setup: 1
- US1 fixes: 10 (T002–T011) + 3 fixture (T012–T014)
- Verification: 3 (T015–T017)
- Polish: 2 (T018–T019)
- **Total: 19**
