# Quickstart: Client config + restart fixes (Feature 078)

**Feature**: [spec.md](./spec.md) | **Plan**: [plan.md](./plan.md)

Per-client verification runbook. After the F078 implementation lands, walk each of the 9 fixed clients and confirm the STEP 2/3/4/5 content matches the target values.

---

## Per-client verification checklist

### VS Code (STEP 2 fix)
```
Open MCP Clients tab → click VS Code pill
STEP 2 · Open the config file: `~/Library/Application Support/Code/User/mcp.json`
STEP 3 · Top-level key: `servers`
STEP 5 · restart: mentions auto-start + "reload window if it doesn't appear"
```

### GitHub Copilot (STEP 2 + STEP 5 fix)
```
Click GitHub Copilot pill
STEP 2 · Config file: `~/Library/Application Support/Code/User/mcp.json`
STEP 5 · restart: mentions "Agent mode" + auto-start
```

### Kilo Code (STEP 2 + STEP 3 + STEP 4 shape fix)
```
Click Kilo Code pill
STEP 2 · Config file: `~/.config/kilo/kilo.jsonc` (or `.kilo/kilo.jsonc` project)
STEP 3 · Top-level key: `mcp`
STEP 4 · JSON: uses `command: ["npx", "-y", ...]` (array) and `environment` (not `env`)
```

### Cursor (STEP 5 polish)
```
Click Cursor pill
STEP 5 · restart: mentions Reload Window + Settings → MCP toggle
```

### Windsurf (STEP 5 polish)
```
Click Windsurf pill
STEP 5 · restart: mentions "Cascade's MCP panel Refresh button" first, restart as fallback
```

### Zed (STEP 4 cleanup + STEP 5 polish)
```
Click Zed pill
STEP 4 · JSON: `context_servers` entry does NOT contain `source: "custom"` or `enabled: true`
STEP 5 · restart: "live-reload" or "open Agent Panel to confirm tools appear"
```

### Antigravity (STEP 5 polish)
```
Click Antigravity pill
STEP 5 · restart: mentions `/mcp` CLI command + Manage MCP Servers panel
```

### Amazon Q Developer (Instructions update)
```
Click Amazon Q Developer pill
STEP 2 · Config file still `~/.aws/amazonq/mcp.json` (legacy — still works)
Instructions/callout mentions `default.json` as the current GUI default
```

### Roo Code (STEP 5 polish)
```
Click Roo Code pill
STEP 5 · restart: describes per-server Restart button in the sidebar panel (accurate) instead of "hot-reloads"
```

---

## Canary greps (SC-001 through SC-007)

```bash
# SC-001 — Kilo Code legacy path removed
grep -c 'kilocode' includes/MCPClients/KiloCodeClient.php
# Expected: 0

# SC-002 — Kilo Code legacy top-level key removed
grep -c 'mcpServers' includes/MCPClients/KiloCodeClient.php
# Expected: 0

# SC-003 — VS Code + Copilot correct macOS path
grep -c 'Application Support/Code/User/mcp.json' includes/MCPClients/VSCodeClient.php includes/MCPClients/GitHubCopilotClient.php
# Expected: both files have >=1 match

# SC-004 — Zed non-canonical prefix removed
grep -cE "'source'\s*=>\s*'custom'|'enabled'\s*=>\s*true" includes/MCPClients/ZedClient.php
# Expected: 0

# SC-005 — GitHub Copilot mentions Agent mode
grep -c 'Agent mode' includes/MCPClients/GitHubCopilotClient.php
# Expected: >= 1

# SC-006 — Amazon Q notes default.json
grep -c 'default.json' includes/MCPClients/AmazonQClient.php
# Expected: >= 1

# SC-007 — all 16 client get_config_snippet still valid PHP
for f in includes/MCPClients/*Client.php; do php -l "$f" > /dev/null 2>&1 || echo "SYNTAX ERROR: $f"; done
# Expected: no output
```

---

## Automated verification

```bash
composer run phpcs
composer run phpstan
composer run test -- --testsuite mcpclients   # existing suite; golden fixtures updated for VS Code + GitHub Copilot
npm run build                                  # no changes but re-verify clean
```

---

## Success signal

- All 9 fixed clients show the correct STEP 2 path + STEP 3 key + STEP 5 restart text on both admin surfaces.
- Kilo Code STEP 4 shows the new-shape JSON with array `command` + `environment` key.
- Zed STEP 4 no longer includes `source: 'custom'` or `enabled: true`.
- All four automated gates green.
- 7 non-modified clients (Claude Desktop, Claude Code, Codex, Gemini, Cline, OpenCode, Custom) render exactly as before F078.
