# Feature Specification: Client config paths + restart phrasings — upstream-docs verification pass

**Feature Branch**: `078-client-config-upstream-fixes`
**Created**: 2026-08-24
**Status**: Draft
**Input**: User description: "Verify the STEP 1..5 content for all 16 MCP clients against their official upstream docs; fix all wrong/outdated paths, keys, config shapes, and restart phrasings."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Operator gets a config that actually works on the current version of every supported MCP client (Priority: P1)

The operator opens the per-server MCP Clients tab (or Quick Setup Step 11), picks any of the 16 supported clients, copies the JSON snippet, follows STEP 2/3/4/5 instructions verbatim — and their MCP client picks up the new server on the first try. No "the config file you told me to open doesn't exist" (VS Code / GitHub Copilot on macOS), no "the extension can't parse the config" (Kilo Code post-v7.0.33), no "I restarted but tools don't appear" (Antigravity / Cursor / Windsurf where a lighter reload is enough).

**Why this priority**: Core value proposition. If STEP 2/3/4/5's paths, keys, and restart actions don't match upstream, the plugin ships broken instructions for that client.

**Independent Test**: For each of the 16 clients, cross-reference our shipped `get_config_file()` / `get_top_level_key()` / `get_config_snippet()` / `get_restart_step_text()` return values against the client's official upstream docs (URLs cited in research.md). Every mismatch is either fixed (F078 scope) or documented as an accepted deviation with reasoning.

**Acceptance Scenarios**:

1. **Given** the operator selects VS Code on macOS, **When** they read STEP 2's Config File value, **Then** it shows `~/Library/Application Support/Code/User/mcp.json` (correct macOS user-level path per VS Code docs), not `~/.vscode/mcp.json` (the Cursor-style path we currently ship).
2. **Given** the operator selects Kilo Code (v7.0.33+), **When** they read STEP 2's Config File value and STEP 3's Top-Level Key, **Then** they see `.kilo/kilo.jsonc` and `mcp` — the current format — not the legacy `.kilocode/mcp.json` + `mcpServers` shape.
3. **Given** the operator selects any of Cursor / Windsurf / Zed / Antigravity / Roo Code, **When** they read STEP 5's restart action, **Then** the copy reflects each client's documented lightest-weight reload action (Cascade Refresh button for Windsurf; live-reload for Zed; `/mcp` command or IDE panel for Antigravity; MCP Servers panel per-server Restart button for Roo Code; Reload Window or Settings→MCP toggle for Cursor).
4. **Given** the operator selects Zed, **When** they inspect STEP 4's JSON, **Then** the `context_servers` entry does NOT include `source: "custom"` or `enabled: true` prefix — those are not shown in Zed's official docs and were community-source noise in our F071 first-ship.
5. **Given** the operator selects GitHub Copilot, **When** they read STEP 5, **Then** the copy explains that Copilot Chat needs to be in **Agent mode** and VS Code auto-starts the server — not the misleading "reactivate the GitHub Copilot extension" phrasing we currently ship.

---

### Edge Cases

- **Legacy Kilo Code installs (pre-v7.0.33)**: still support `.kilocode/mcp.json` + `mcpServers`. The docs at [kilo.ai](https://kilo.ai/docs/automate/mcp/using-in-kilo-code) note the migration but older installs will keep working with the legacy shape. F078 ships the NEW format going forward; the operator on an old install can manually adapt the snippet.
- **VS Code operators on Linux or Windows**: our shipped macOS user-level path won't match. We follow the convention set by Claude Desktop (which also ships the macOS path). Cross-OS support could be a follow-up feature.
- **Amazon Q Developer with new `default.json` GUI default**: `mcp.json` still supported; we ship `mcp.json` and add a note in instructions that IDE users may also see `default.json`.
- **Antigravity CLI vs IDE**: our instructions mention both the IDE panel and CLI `/mcp` command paths in the restart text.

---

## Requirements *(mandatory)*

### Functional Requirements

**Wrong-path / wrong-shape fixes (P1 correctness)**:

- **FR-001**: `KiloCodeClient::get_config_file()` MUST return the current-format path `~/.config/kilo/kilo.jsonc` (project override: `.kilo/kilo.jsonc` mentioned in instructions).
- **FR-002**: `KiloCodeClient::get_top_level_key()` MUST return `mcp` (not `mcpServers`).
- **FR-003**: `KiloCodeClient::get_config_snippet()` MUST emit the new server-entry shape: `command` as JSON array (`['npx', '-y', '@automattic/mcp-wordpress-remote@latest']`) and `environment` (not `env`) — same shape family as `OpenCodeClient`.
- **FR-004**: `VSCodeClient::get_config_file()` MUST return `~/Library/Application Support/Code/User/mcp.json` (correct macOS user-level path per VS Code docs).
- **FR-005**: `GitHubCopilotClient::get_config_file()` MUST return the same VS Code user-level path (Copilot MCP shares VS Code's config).

**Restart-phrasing polish (misleading UX)**:

- **FR-006**: `VSCodeClient::get_restart_step_text()` MUST reflect VS Code's auto-start behavior (server starts automatically once config is saved; reload window if it doesn't appear).
- **FR-007**: `GitHubCopilotClient::get_restart_step_text()` MUST reflect Agent-mode requirement and auto-start behavior — NOT "reactivate the extension."
- **FR-008**: `CursorClient::get_restart_step_text()` MUST reflect the lighter reload path (Reload Window / Settings → MCP toggle).
- **FR-009**: `WindsurfClient::get_restart_step_text()` MUST reflect Cascade's Refresh button as the primary path, with restart as fallback.
- **FR-010**: `ZedClient::get_restart_step_text()` MUST reflect Zed's live-reload behavior — no restart required.
- **FR-011**: `AntigravityClient::get_restart_step_text()` MUST mention Antigravity's dedicated reload paths (`/mcp` command in CLI; Manage MCP Servers panel in IDE).
- **FR-012**: `RooCodeClient::get_restart_step_text()` MUST describe the per-server Restart button in the MCP Servers panel — more accurate than the current "hot-reloads" phrasing.

**Optional cleanup**:

- **FR-013**: `ZedClient::get_config_snippet()` MUST NOT emit `source: 'custom'` or `enabled: true` keys on the `context_servers` entry — those are non-canonical per Zed's official docs.
- **FR-014**: `AmazonQClient::get_instructions()` SHOULD note `default.json` as the current GUI default alongside the legacy `mcp.json`.

**Preservation**:

- **FR-015**: All other clients (Claude Desktop, Claude Code, Codex, Gemini CLI, Cline, OpenCode, Custom Client) MUST NOT be modified — the audit found no drift on their values.
- **FR-016**: All REST endpoints, DTO fields, hooks, filters, and JS/JSX code MUST be unchanged. F078 is pure PHP text/shape edits.
- **FR-017**: F075 local-dev TLS bypass, F075 follow-up restart-step affordance, F076 no-emoji picker, and F077 numbered STEP layout MUST all continue to work exactly as before — F078 doesn't touch any renderer.

### WordPress Requirements

**PHP Version**: PHP 8.0+ (plugin baseline)
**WordPress Version**: 6.9+
**Multisite**: unchanged
**Required Plugins / Packages**: none new

### Module Placement

**PHP Class(es)** (all under `AcrossAI_MCP_Manager\Includes\MCPClients`):
- `includes/MCPClients/VSCodeClient.php` (modified)
- `includes/MCPClients/GitHubCopilotClient.php` (modified)
- `includes/MCPClients/KiloCodeClient.php` (modified)
- `includes/MCPClients/CursorClient.php` (modified)
- `includes/MCPClients/WindsurfClient.php` (modified)
- `includes/MCPClients/ZedClient.php` (modified)
- `includes/MCPClients/AntigravityClient.php` (modified)
- `includes/MCPClients/AmazonQClient.php` (modified)
- `includes/MCPClients/RooCodeClient.php` (modified)

**JSX**: No changes.

**Hook Registration**: no new hooks.

### Admin UI Requirements

Pre-approved WP_List_Table exception applies; F078 changes only the *content* rendered by existing renderers.

### Database / Storage

**No persistent storage**: N/A.

### Security Checklist

- [x] All output escaped at point of rendering — existing `esc_html`/`esc_attr` calls on the new/updated instruction strings continue to escape correctly (values are hardcoded literals in PHP)
- [x] No new input surface, no new REST route, no new form/AJAX handler
- [x] Existing golden fixtures continue to pass or are updated in lockstep

### Key Entities

None.

---

## Success Criteria *(mandatory)*

### Definition of Done Gates

- [ ] PHPCS validation: zero errors and zero warnings on the 9 touched client files
- [ ] PHPStan level 8: zero errors on the 9 touched files
- [ ] PHPUnit `mcpclients` suite passes — golden fixtures for VS Code + GitHub Copilot updated in lockstep with the new path; other fixtures unchanged
- [ ] `npm run build` — no changes needed (no JS/CSS touched), but re-verified clean

### Measurable Outcomes

- **SC-001**: `grep 'kilocode' includes/MCPClients/KiloCodeClient.php` returns zero matches — legacy `.kilocode/` path is removed.
- **SC-002**: `grep 'mcpServers' includes/MCPClients/KiloCodeClient.php` returns zero matches — legacy top-level key is gone.
- **SC-003**: `grep 'Application Support/Code/User/mcp.json' includes/MCPClients/{VSCodeClient,GitHubCopilotClient}.php` returns matches in both files.
- **SC-004**: `grep "source' => 'custom'\|enabled' => true" includes/MCPClients/ZedClient.php` returns zero matches — non-canonical prefix removed.
- **SC-005**: `grep 'Agent mode' includes/MCPClients/GitHubCopilotClient.php` returns at least one match — GitHub Copilot restart phrasing correctly mentions Agent mode.
- **SC-006**: `grep 'default.json' includes/MCPClients/AmazonQClient.php` returns at least one match — Amazon Q instructions note the new IDE default.
- **SC-007**: All 16 client `get_config_snippet()` calls still produce syntactically-valid JSON (or in Kilo Code's case, syntactically-valid JSONC).

---

## Assumptions

- Kilo Code's new format is stable enough post-v7.0.33 to ship. Legacy installs on older versions manually adapt (accepted regression).
- VS Code path fix uses macOS convention (same as Claude Desktop). Cross-OS parity is a future feature; today one canonical path per client is the plugin's convention.
- Golden fixtures for the F071 batch (Windsurf/Zed/Cline/Roo/Kilo/Amazon Q/OpenCode/Antigravity) may or may not exist; if they do, they're updated in lockstep. If not, no follow-up needed.
- No PHPUnit new tests — existing golden-fixture assertions are the shape gate; restart-text and instructions changes are docblock-level and don't need unit tests.
- No wizard/JSX changes because the DTO producer just reads the PHP methods; F078's changes propagate automatically to both surfaces.
