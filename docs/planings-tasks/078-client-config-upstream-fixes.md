# Planning: Client config paths + restart phrasings — upstream-docs verification pass (Feature 078)

## In plain English

Four parallel research agents cross-checked our 16 MCP client classes against upstream official docs (Anthropic, Microsoft, OpenAI, Google, AWS, Cursor, Codeium/Windsurf, Zed, Cline, Roo Code, Kilo Code, OpenCode, Antigravity, Model Context Protocol) as of 2026-08-24. Nine clients need fixes ranging from **breaking format changes** (Kilo Code's new `mcp` shape) to **wrong file paths** (VS Code + GitHub Copilot on macOS) to **misleading restart phrasings** (Cursor, Windsurf, Zed, Amazon Q, Roo Code, Antigravity).

F078 lands the fixes in one PR:

- **3 breaking / wrong-config fixes**: Kilo Code (shape refactor), VS Code (path), GitHub Copilot (path + Agent mode).
- **6 restart-phrasing polishes**: Cursor, Windsurf, Zed, Antigravity, Amazon Q Developer, Roo Code.
- **2 optional cleanups**: Zed drop non-canonical `source: 'custom'` + `enabled: true`; Amazon Q note `default.json` as GUI default alongside legacy `mcp.json`.

Total: 9 client classes touched. No REST changes, no DTO changes, no CSS changes. Golden fixtures for the 4 clients that ship them (Claude Desktop / Claude Code / VS Code / Codex / Cursor / Custom / Gemini / GitHub Copilot) may need updates for VS Code + GitHub Copilot path changes; other fixture files were not shipped for the F071 batch (Windsurf/Zed/Cline/Roo/Kilo/Amazon Q/OpenCode/Antigravity) so no updates needed there.

## Context

- User asked (2026-08-24) to verify the STEP 1..5 content across all clients against upstream docs.
- Four parallel `general-purpose` agents each verified 4 clients; findings compiled in the session transcript.
- Most upstream vendors evolved their MCP docs between the plugin's F031 / F071 first-ship and today; some (Kilo Code v7.0.33) shipped breaking format changes.

## Authoritative sources (cited in research.md)

- **Claude Desktop**: [Anthropic — Local MCP servers on Claude Desktop](https://support.claude.com/en/articles/10949351-getting-started-with-local-mcp-servers-on-claude-desktop) + [modelcontextprotocol.io](https://modelcontextprotocol.io/docs/develop/connect-local-servers)
- **Claude Code**: [Claude Code MCP quickstart](https://code.claude.com/docs/en/mcp-quickstart)
- **VS Code**: [VS Code MCP configuration reference](https://code.visualstudio.com/docs/agents/reference/mcp-configuration)
- **GitHub Copilot**: [Copilot MCP servers customization](https://code.visualstudio.com/docs/copilot/customization/mcp-servers)
- **Codex**: [OpenAI Codex CLI MCP](https://learn.chatgpt.com/docs/extend/mcp?surface=cli)
- **Cursor**: [Cursor MCP docs](https://cursor.com/docs/mcp)
- **Gemini CLI**: [google-gemini/gemini-cli MCP server](https://github.com/google-gemini/gemini-cli/blob/main/docs/tools/mcp-server.md)
- **Windsurf**: [Cascade MCP docs](https://docs.windsurf.com/plugins/cascade/mcp) (now redirects to `docs.devin.ai/desktop/cascade/mcp` after Cognition acquisition)
- **Zed**: [zed.dev MCP docs](https://zed.dev/docs/ai/mcp)
- **Cline**: [docs.cline.bot MCP](https://docs.cline.bot/mcp/configuring-mcp-servers)
- **Roo Code**: [roocodeinc.github.io/Roo-Code MCP](https://roocodeinc.github.io/Roo-Code/features/mcp/using-mcp-in-roo)
- **Kilo Code**: [kilo.ai/docs/automate/mcp/using-in-kilo-code](https://kilo.ai/docs/automate/mcp/using-in-kilo-code) — **new `.kilo/kilo.jsonc` + `mcp` shape**
- **Amazon Q Developer**: [AWS Amazon Q MCP IDE](https://docs.aws.amazon.com/amazonq/latest/qdeveloper-ug/mcp-ide.html) + [CLI](https://docs.aws.amazon.com/amazonq/latest/qdeveloper-ug/command-line-mcp-configuration.html)
- **OpenCode**: [opencode.ai MCP servers](https://opencode.ai/docs/mcp-servers/)
- **Antigravity**: [antigravity.google MCP](https://antigravity.google/docs/mcp/) + [CLI](https://antigravity.google/docs/cli/mcp/) + [IDE](https://antigravity.google/docs/ide/mcp/)
- **MCP spec**: [modelcontextprotocol.io](https://modelcontextprotocol.io/) + [reference servers](https://github.com/modelcontextprotocol/servers)

## Files to touch

| Client | File | Method(s) changed |
|---|---|---|
| **VSCodeClient** | `includes/MCPClients/VSCodeClient.php` | `get_config_file()`, `get_instructions()`, `get_restart_step_text()` |
| **GitHubCopilotClient** | `includes/MCPClients/GitHubCopilotClient.php` | `get_config_file()`, `get_instructions()`, `get_restart_step_text()` |
| **KiloCodeClient** | `includes/MCPClients/KiloCodeClient.php` | `get_config_file()`, `get_top_level_key()`, `get_config_snippet()`, `get_instructions()`, `get_restart_step_text()` |
| **CursorClient** | `includes/MCPClients/CursorClient.php` | `get_restart_step_text()` |
| **WindsurfClient** | `includes/MCPClients/WindsurfClient.php` | `get_restart_step_text()` |
| **ZedClient** | `includes/MCPClients/ZedClient.php` | `get_config_snippet()` (drop `source`/`enabled`), `get_restart_step_text()` |
| **AntigravityClient** | `includes/MCPClients/AntigravityClient.php` | `get_restart_step_text()` |
| **AmazonQClient** | `includes/MCPClients/AmazonQClient.php` | `get_instructions()` (note `default.json`), `get_restart_step_text()` (leave — semantically fine) |
| **RooCodeClient** | `includes/MCPClients/RooCodeClient.php` | `get_restart_step_text()` |

Golden fixtures to update:
- `tests/phpunit/MCPClients/fixtures/vscode-{with,empty}-token.json` — path change (if fixture asserts on it; likely no because fixtures assert on the config_snippet not on paths)
- `tests/phpunit/MCPClients/fixtures/github-copilot-{with,empty}-token.json` — same
- `tests/phpunit/MCPClients/fixtures/kilo-code-*` — likely doesn't exist (F071 batch didn't ship fixtures) — verify

## Final scope

Retained:
- 9 client class edits enumerated above.
- README.txt `= Unreleased =` bullet.
- Full spec-kit trace + memory captures.
- Existing PHPUnit `mcpclients` fixtures updated if broken by shape changes.

Not in scope:
- No new clients added.
- No CSS/JS changes (F078 is pure PHP text/shape).
- No REST/DTO/hook changes.
- No new tests — existing golden fixtures cover the shape assertions.
- The F077 STEP layout stays exactly as-is on the admin tab; F078 just changes the *content* rendered inside STEP 2, STEP 3, STEP 4, STEP 5.

## Durable lesson

**Per-client configuration paths, top-level keys, and reload actions drift over time as upstream MCP client vendors evolve their docs.** F078 shipped an audit + fix cycle for the first time; codify as durable pattern via a new decision — establish a periodic upstream-doc verification cadence (once per major plugin release, or when a client's slug is added/updated).

## Speckit workflow

```markdown
# 1. Branch
/speckit.git.feature "client-config-upstream-fixes"

# 2. Specify — paste the "Files to touch" table + FR-001..FR-N from spec.md.

# 3. Clarify (likely none — findings are unambiguous per-agent-verified doc URLs).

# 4. Plan + tasks
/speckit.plan
/speckit.tasks

# 5. Implement + quality checks
/speckit.implement
composer run phpcs
composer run phpstan
composer run test -- --testsuite mcpclients
npm run build

# 6. Review + memory + commit
/speckit.analyze
/speckit.security-review.staged
/speckit.memory-md.capture-from-diff
/speckit.git.commit
```

Added: 2026-08-24 on branch `078-client-config-upstream-fixes`.
