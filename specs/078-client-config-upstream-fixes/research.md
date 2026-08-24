# Research: Client config paths + restart phrasings — upstream-docs verification pass (Feature 078)

**Feature**: [spec.md](./spec.md) | **Plan**: [plan.md](./plan.md) | **Created**: 2026-08-24

## Method

Four parallel `general-purpose` agents, each verifying 4 clients against upstream docs. Every finding cites at least one first-party source URL. Session-recorded transcript is the fact-check log.

## Decisions per client (one row = one client)

### Wrong / breaking — MUST fix (3)

| Client | What's wrong today | Correct value | Source |
|---|---|---|---|
| **KiloCodeClient** | `.kilocode/mcp.json` + `mcpServers` + `env` block is LEGACY (pre-v7.0.33). Current install may reject the config. | `.kilo/kilo.jsonc` (project) or `~/.config/kilo/kilo.jsonc` (global) + `mcp` top-level + `command: [array]` + `environment` block | [kilo.ai/docs/automate/mcp/using-in-kilo-code](https://kilo.ai/docs/automate/mcp/using-in-kilo-code) |
| **VSCodeClient** | `~/.vscode/mcp.json` is not a VS Code path (it's a Cursor convention). | `~/Library/Application Support/Code/User/mcp.json` (macOS) — user-level path per VS Code docs. Workspace-level `.vscode/mcp.json` (repo root) is still correct. | [code.visualstudio.com/docs/agents/reference/mcp-configuration](https://code.visualstudio.com/docs/agents/reference/mcp-configuration) |
| **GitHubCopilotClient** | Same wrong path (`~/.vscode/mcp.json`). Also "reactivate the GitHub Copilot extension" is misleading — Copilot auto-picks up MCP once VS Code (re)starts the server, but Chat must be in Agent mode. | Same path fix. Restart phrasing: "Ensure Copilot Chat is in Agent mode; VS Code auto-starts the server (reload the window if it doesn't appear)." | [code.visualstudio.com/docs/copilot/customization/mcp-servers](https://code.visualstudio.com/docs/copilot/customization/mcp-servers) |

### Misleading UX — SHOULD fix (6)

| Client | Today | Better (per upstream) | Source |
|---|---|---|---|
| **CursorClient** | "Restart Cursor" | "Reload Cursor (Cmd/Ctrl+Shift+P → Reload Window) or toggle the server in Settings → MCP" — Cursor does not hot-reload `mcp.json` but reload-window is lighter than full restart | [cursor.com/docs/mcp](https://cursor.com/docs/mcp), [useloadout.com/blog/cursor-mcp-not-working](https://useloadout.com/blog/cursor-mcp-not-working) |
| **WindsurfClient** | "Restart Windsurf" | "Click Refresh in Cascade's MCP panel (or restart Windsurf) to load the new MCP server" — Cascade has a dedicated Refresh button | [docs.windsurf.com/plugins/cascade/mcp](https://docs.windsurf.com/plugins/cascade/mcp) (redirects to `docs.devin.ai` post-Cognition acquisition), [fast.io/resources/windsurf-mcp-setup-guide](https://fast.io/resources/windsurf-mcp-setup-guide) |
| **ZedClient** | "Restart Zed" | "Zed live-reloads settings — open the Agent Panel to confirm the new tools appear" | [zed.dev/docs/ai/mcp](https://zed.dev/docs/ai/mcp) |
| **AntigravityClient** | "Restart Antigravity" | "Reload MCP servers (`/mcp` in CLI, Manage MCP Servers in IDE) or restart Antigravity" — Antigravity has dedicated `/mcp` command | [antigravity.google/docs/cli/mcp](https://antigravity.google/docs/cli/mcp), [antigravity.google/docs/ide/mcp](https://antigravity.google/docs/ide/mcp) |
| **AmazonQClient** | Instructions don't mention `default.json` (new GUI default) | Note `default.json` as GUI default alongside legacy `mcp.json` (CLI still uses `mcp.json`) | [docs.aws.amazon.com/amazonq/latest/qdeveloper-ug/mcp-ide.html](https://docs.aws.amazon.com/amazonq/latest/qdeveloper-ug/mcp-ide.html) |
| **RooCodeClient** | "Roo Code hot-reloads MCP servers — reopen the MCP Servers panel to confirm" | "Open the MCP Servers panel in the Roo Code sidebar; click Restart on the new server if it doesn't appear automatically" — per-server Restart button is documented, hot-reload is not | [roocodeinc.github.io/Roo-Code/features/mcp/using-mcp-in-roo](https://roocodeinc.github.io/Roo-Code/features/mcp/using-mcp-in-roo) |

### Optional cleanup (2)

| Client | Cleanup | Source |
|---|---|---|
| **ZedClient** | Drop `source: 'custom'` + `enabled: true` prefix on the `context_servers` entry — not shown in Zed's official docs; came from community sample code during F071 first-ship | [zed.dev/docs/ai/mcp](https://zed.dev/docs/ai/mcp) |
| **AmazonQClient** | Update `get_instructions()` to acknowledge `~/.aws/amazonq/default.json` as the current GUI default (mcp.json still supported as legacy) | [docs.aws.amazon.com/amazonq/latest/qdeveloper-ug/mcp-ide.html](https://docs.aws.amazon.com/amazonq/latest/qdeveloper-ug/mcp-ide.html) |

### Verified correct — NO changes (7)

| Client | Verified against | Source |
|---|---|---|
| **ClaudeDesktopClient** | `~/Library/Application Support/Claude/claude_desktop_config.json` + `mcpServers` + "Restart Claude Desktop" | [Anthropic Help Center — Local MCP servers](https://support.claude.com/en/articles/10949351-getting-started-with-local-mcp-servers-on-claude-desktop) |
| **ClaudeCodeClient** | `~/.claude.json` + `mcpServers` + restart works (docs also recommend `claude mcp add` CLI, but restart is fine) | [code.claude.com/docs/en/mcp-quickstart](https://code.claude.com/docs/en/mcp-quickstart) |
| **CodexClient** | `~/.codex/config.toml` + `[mcp_servers.<name>]` + "Restart Codex" | [learn.chatgpt.com/docs/extend/mcp?surface=cli](https://learn.chatgpt.com/docs/extend/mcp?surface=cli) |
| **GeminiClient** | `~/.gemini/settings.json` + `mcpServers` + restart (docs confirm no hot-reload) | [google-gemini/gemini-cli MCP docs](https://github.com/google-gemini/gemini-cli/blob/main/docs/tools/mcp-server.md) |
| **ClineClient** | `cline_mcp_settings.json` + `mcpServers` + hot-reload (matches docs) | [docs.cline.bot/mcp/configuring-mcp-servers](https://docs.cline.bot/mcp/configuring-mcp-servers) |
| **OpenCodeClient** | `~/.config/opencode/opencode.json` + `mcp` + `type: "local"` + array `command` + `environment` — all correct per docs | [opencode.ai/docs/mcp-servers](https://opencode.ai/docs/mcp-servers) |
| **CustomClient** | Generic fallback — `mcpServers` is de-facto community convention (Claude Desktop, Cursor, Windsurf, VS Code, Q Developer CLI, Antigravity all use it). Our shape matches. | [MCP spec](https://modelcontextprotocol.io/), [modelcontextprotocol/servers reference](https://github.com/modelcontextprotocol/servers) |

## Durable pattern candidate

**Periodic upstream-doc verification cycle**: propose codifying a rule that once per plugin major version bump, or when a new `MCPClient` subclass slug is added, an audit of the shipped `get_config_file` / `get_top_level_key` / `get_config_snippet` / `get_restart_step_text` against upstream docs is required. F078 is the reference execution. See `docs/memory/DECISIONS.md` companion entry for the codified pattern.
