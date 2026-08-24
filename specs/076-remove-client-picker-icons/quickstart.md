# Quickstart: Remove client emoji from picker surfaces (Feature 076)

**Feature**: [spec.md](./spec.md) | **Plan**: [plan.md](./plan.md)

Four-step operator verification for the F076 subtractive UI change, plus two canary greps confirming the preservation contracts hold.

---

## Prerequisites

- Plugin activated, WordPress admin accessible.
- At least one MCP Server row exists (Quick Setup wizard has probably already seeded the default `mcp-adapter-default-server`).
- On branch `076-remove-client-picker-icons` with the three edits applied and `npm run build` completed.

---

## Positive path — both surfaces render text-only

**Step 1 — Per-server MCP Clients tab**

Navigate to:

```
/wp-admin/admin.php?page=acrossai_mcp_manager&action=edit&server=1&tab=clients
```

Verify:

1. The pill sub-nav shows 16 pills, each with client name only (Claude Desktop, Claude Code, VS Code, GitHub Copilot, Codex, Cursor, Gemini CLI, Windsurf, Zed, Cline, Roo Code, Kilo Code, Amazon Q Developer, OpenCode, Antigravity, Custom Client).
2. **No** emoji glyph appears before any client name.
3. Click any pill (e.g., Claude Desktop). The `<h2>` below the pills shows `Claude Desktop` — no leading `🍰` and no leading space that used to sit between the emoji and the name.
4. Open your browser's inspect-element on any pill. The pill's inner HTML is a single `<span>{name}</span>` — no `<span class="acrossai-client-tab-icon">…</span>` element remains.

**Step 2 — Quick Setup Wizard Step 11**

Open the wizard:

```
/wp-admin/admin.php?page=acrossai_mcp_manager&quick-setup=1&step=11&server=1
```

Verify:

1. The "Choose your client" grid shows 16 buttons, each with client name only.
2. **No** emoji glyph appears before any client name.
3. Inspect any button in the grid. The button body contains only the name text (or slug if name is empty) — no `<span>` wrapping an emoji.

---

## Preservation contracts — three canary greps

**Step 3 — Confirm `get_icon()` methods on client classes are untouched (FR-004 / SC-005)**

```bash
grep -RnE 'public function get_icon\(\)' includes/MCPClients/*Client.php | wc -l
# Expected: 16
```

Spot-check one concrete implementation to confirm the return value still exists:

```bash
grep -A 2 "public function get_icon" includes/MCPClients/ClaudeDesktopClient.php
# Expected: still returns '🍰';
```

**Step 4 — Confirm the DTO field is preserved (FR-005 / SC-006)**

```bash
grep -n "'icon'" public/Discovery/ConnectionMethodRegistry.php
# Expected: 'icon' => $client->get_icon(), line still present (currently line 218)
```

---

## Automated verification

```bash
# PHPCS — zero errors on the touched PHP file
composer run phpcs

# PHPStan level 8 — zero errors on the touched PHP file
composer run phpstan

# PHPUnit — mcpclients suite still 100 / 100 (no test changes)
composer run test -- --testsuite mcpclients

# Frontend bundle rebuilt
npm run build

# Removed-usage greps (SC-003 / SC-004)
grep -RnE 'get_icon\(\)' public/Renderers/MCPClientsBlock.php
# Expected: zero matches

grep -n 'c\.icon' src/js/quick-setup/steps/Step11_ClientDetail.jsx
# Expected: zero matches
```

---

## Success signal

- Both surfaces show text-only client pickers.
- All 16 `get_icon()` methods still exist on the concrete `*Client.php` files.
- `ConnectionMethodRegistry` DTO still carries the `'icon'` field.
- All four automated gates green.
