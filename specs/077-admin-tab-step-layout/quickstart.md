# Quickstart: Numbered STEP layout on the per-server MCP Clients admin tab (Feature 077)

**Feature**: [spec.md](./spec.md) | **Plan**: [plan.md](./plan.md)

Four-step operator verification for the F077 presentational refactor, plus canary greps confirming the FR-006 preservation contract holds.

---

## Prerequisites

- Plugin activated, WordPress admin accessible.
- At least one MCP Server row exists (the default `mcp-adapter-default-server` is seeded on install).
- On branch `077-admin-tab-step-layout` with the two file edits applied and `npm run build` completed.

---

## Positive path — five numbered STEP blocks render for every client

**Step 1 — Reload the MCP Clients tab**

Navigate to:

```
/wp-admin/admin.php?page=acrossai_mcp_manager&action=edit&server=1&tab=clients
```

Do a hard-reload (Cmd+Shift+R / Ctrl+Shift+R) to bust any cached CSS. Verify:

1. Five numbered STEP badges (small light-blue "Step 1", "Step 2", …, "Step 5" pills) render in the client-detail area for whichever client is selected in the top pill row.
2. Each badge sits inside an `<h3>` with a bold sub-heading text next to it: "Generate the password", "Open the config file", "Locate the top-level key", "Copy this config and paste it under the top-level key", "Restart the MCP client".
3. Each step body renders below its heading with the correct content.

**Step 2 — Cross-check STEP 4 (Copy config) still contains the F075 local-dev warning**

On this local install (HTTPS or HTTP), STEP 4 must contain, in order:

1. The yellow local-dev notice: "Local dev — added `NODE_TLS_REJECT_UNAUTHORIZED: "0"` for local testing (never use on a live site). [More info]"
2. The `<textarea class="widefat code readonly">` with the mcpServers JSON.
3. The blue "Copy Configuration" button.

No new content, no reordering.

**Step 3 — Cross-check STEP 5 (Restart) still shows client-specific text**

Click through the pill row: Claude Desktop → Cursor → VS Code → GitHub Copilot → Cline → Custom Client. Verify STEP 5's body shows the client-specific restart / reload text on every one:

- Claude Desktop: "Restart Claude Desktop to load the new MCP server."
- VS Code: "Reload VS Code (Cmd/Ctrl + Shift + P → Developer: Reload Window) to load the new MCP server."
- GitHub Copilot: "Restart VS Code and reactivate the GitHub Copilot extension to load the new MCP server."
- Cline / Roo Code / Kilo Code: sidebar hot-reload sentence.
- Custom Client: generic reload sentence.

**Step 4 — Verify Access Control paragraph placement**

Scroll to the very bottom of the client-detail area. The blue "The generated password belongs to your current WordPress user..." Access Control notice must render **outside** any `qs-step` block — below STEP 5, no numbered badge above it.

Inspect-element sanity check: the Access Control notice's parent should be the `.mcp-tab-panel` container, not a `.qs-step` block.

---

## Preservation contracts — three canary greps

**SC-001 / SC-002**: The refactor must emit exactly 5 numbered step blocks in order:

```bash
grep -c 'qs-step-heading__num' public/Renderers/MCPClientsBlock.php
# Expected: 5

grep -oE "Step [1-5]" public/Renderers/MCPClientsBlock.php | head
# Expected in order: Step 1, Step 2, Step 3, Step 4, Step 5
```

**SC-006**: The stylesheet has the four qs-step rules:

```bash
grep -c '.qs-step\|.qs-step-heading\|.qs-step-heading__num\|.qs-step-body' src/scss/backend.scss
# Expected: >= 4 (one line per rule minimum)
```

**FR-006 preservation** (nothing existing was accidentally deleted):

```bash
# Local-dev warning notice still present
grep -c 'LocalEnvironment::needs_tls_bypass\|troubleshooting_doc_url' public/Renderers/MCPClientsBlock.php
# Expected: >= 2 (call + URL reference)

# Restart step call still present
grep -c 'get_restart_step_text' public/Renderers/MCPClientsBlock.php
# Expected: >= 1

# Access Control paragraph still present
grep -c 'Access Control still applies' public/Renderers/MCPClientsBlock.php
# Expected: 1
```

---

## Automated verification

```bash
# PHPCS — zero errors on the touched PHP file
composer run phpcs

# PHPStan level 8 — zero errors on the touched PHP file
composer run phpstan

# PHPUnit — mcpclients suite still 100+ tests, zero test changes
composer run test -- --testsuite mcpclients

# Frontend bundle rebuilt (backend.css picked up the new step rules)
npm run build
```

---

## Success signal

- MCP Clients tab renders five numbered STEP blocks for every client.
- STEP 4 still contains the F075 local-dev warning + JSON + Copy button (no regression to F075).
- STEP 5 still shows the client-specific restart text (no regression to F075 follow-up).
- Access Control notice renders below STEP 5, outside any `qs-step` block.
- All four automated gates green.
