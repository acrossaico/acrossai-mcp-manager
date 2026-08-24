# Feature Specification: Numbered STEP layout on the per-server MCP Clients admin tab

**Feature Branch**: `077-admin-tab-step-layout`
**Created**: 2026-08-24
**Status**: Draft
**Input**: User description: "Make the per-server MCP Clients admin tab visually match the Quick Setup wizard's Step 11 numbered STEP 1..5 layout — same content, same buttons, same JSON, just reorganized under numbered STEP headers."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Operator sees the same numbered STEP walkthrough on both surfaces (Priority: P1)

The operator opens either the **Quick Setup wizard Step 11** or the **per-server MCP Clients admin tab** and sees the same client-configuration walkthrough presented under identical numbered headers: STEP 1 · Generate the password → STEP 2 · Open the config file → STEP 3 · Locate the top-level key → STEP 4 · Copy this config and paste it under the top-level key → STEP 5 · Restart the MCP client. Every button, path, JSON snippet, warning, and instructional text that renders today still renders — only the outer visual scaffolding is now consistent.

**Why this priority**: This is the entire scope of the feature. Design consistency — the two surfaces currently show identical information under different layouts, which confuses operators who navigate between them.

**Independent Test**: Open the wizard at `?…&quick-setup=1&step=11&server=1`, note the five numbered STEP badges. Open the per-server admin tab at `?…&action=edit&server=1&tab=clients`. Confirm the same five badges render in the same order for whichever client is selected.

**Acceptance Scenarios**:

1. **Given** the operator is on the per-server MCP Clients tab with any client selected, **When** the client-detail area renders, **Then** it contains exactly five `<div class="qs-step">` blocks in the order STEP 1..5, each with a matching `<span class="qs-step-heading__num">Step N</span>` inside its `<h3 class="qs-step-heading">`.
2. **Given** STEP 1 renders, **When** the operator clicks the "Generate New Application Password" button inside its `qs-step-body`, **Then** the existing password-generation flow fires — no functional change from today.
3. **Given** STEP 4 renders on a local site, **When** the operator views the step body, **Then** the local-dev warning notice (F075) still renders above the Configuration JSON textarea, followed by the Copy button — same content, same order as today.
4. **Given** STEP 5 renders, **When** the operator switches between different client pills (Claude Desktop, Cursor, VS Code, etc.), **Then** the step body shows each client's specific restart / reload text (from `get_restart_step_text()`) — matches the wizard's per-client Step 5 behavior.
5. **Given** the trailing Access Control paragraph renders, **When** it appears, **Then** it renders below STEP 5, not inside any `qs-step` block — matches the wizard's placement of the same paragraph.

---

### Edge Cases

- **A client subclass returns an empty string from `get_restart_step_text()`** (companion plugin, strictly-typed bare subclass): STEP 5's body renders empty but the STEP 5 heading still shows. Alternative would be to skip STEP 5 entirely — but that would break the numbered-step contract (operators expect "Step 5" always to exist). Accepted: STEP 5 always renders, body may be empty in the edge case; the abstract base's default guarantees non-empty for every built-in client.
- **Site with no default MCP server**: `render_client_details()` isn't called at all — the outer block short-circuits earlier. F077 doesn't affect this path.
- **Companion plugin overrides `MCPClientsBlock`**: F077 targets the plugin's own renderer only. Any companion plugin that subclasses `AbstractClientRenderer` gets its own layout choice — F077 does not force the STEP scaffold on them.

---

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: `MCPClientsBlock::render_client_details()` MUST wrap each of the five existing content sections (Generate button + description; Config File value; Top-Level Key value; Configuration JSON + warning + Copy; Restart instruction) in a `<div class="qs-step">` block containing an `<h3 class="qs-step-heading">` with an inner `<span class="qs-step-heading__num">Step N</span>` badge followed by the translated step title, then a `<div class="qs-step-body">` wrapping the existing content.
- **FR-002**: The five step titles MUST match the wizard verbatim: "Generate the password", "Open the config file", "Locate the top-level key", "Copy this config and paste it under the top-level key", "Restart the MCP client". Translation strings use the same text-domain `acrossai-mcp-manager`.
- **FR-003**: The step order MUST be 1..5 sequential in DOM order.
- **FR-004**: The trailing Access Control notice MUST render AFTER STEP 5, OUTSIDE any `qs-step` block — matching the wizard's placement.
- **FR-005**: `src/scss/backend.scss` MUST contain rules for `.qs-step`, `.qs-step-heading`, `.qs-step-heading__num`, and `.qs-step-body` that visually match the wizard's rendering. Rules SHOULD use explicit color literals (matching the wizard's resolved `$qs-text` / `$qs-primary` values) rather than importing the wizard's SCSS variable file into the admin bundle.
- **FR-006**: The refactor MUST NOT change any generated JSON, any button action, any REST endpoint, any DTO field, any translation string on the existing content, or any conditional (warning notice, restart step render, etc.). Every existing acceptance from F075 / F076 continues to hold.

### WordPress Requirements

**PHP Version**: PHP 8.0+ (plugin baseline)
**WordPress Version**: 6.9+
**Multisite**: unchanged from current — pure presentational refactor
**Required Plugins / Packages**: none new
**Optional Integrations**: none

### Module Placement

**PHP Class(es)**:
- `public/Renderers/MCPClientsBlock.php` (modified) → namespace `AcrossAI_MCP_Manager\Public\Renderers` — refactor of `render_client_details()` around the existing content into five `qs-step` blocks.

**CSS/SCSS**:
- `src/scss/backend.scss` (modified) — add 4 CSS rules porting `.qs-step*` from the wizard SCSS. Color literals resolved from the wizard's `$qs-text` (dark gray) and `$qs-primary` (blue) values.
- `build/css/backend.{css,asset.php}` (regenerated) — via `npm run build`.

**JSX**: No changes. Wizard already has the numbered layout.

**Hook Registration**: no new hooks. Subtractive/decorative-only edits.

### Admin UI Requirements

**Pre-approved WP_List_Table exception applies**: the MCP Clients tab lives under the pre-ratified `?page=acrossai_mcp_manager` parent screen — F077 restructures markup inside an existing exception-approved surface. No DataForm/DataViews or new-screen concerns arise.

### Database / Storage

**No persistent storage**: N/A — purely presentational.

### Security Checklist

- [x] All form/AJAX handlers verify nonce — N/A (no new form/AJAX; existing password-generate button unchanged)
- [x] All admin page renders check `current_user_can` — N/A (inherits gating from enclosing pages)
- [x] All REST routes have explicit `permission_callback` — N/A (no new REST route)
- [x] All user input sanitized at boundary — N/A (no new input)
- [x] All output escaped at point of rendering — the new `<h3>` and `<span>` titles are wrapped in `esc_html__()` / `esc_html_e()`; existing escapes on the moved content are preserved
- [x] All DB queries use `$wpdb->prepare()` — N/A
- [x] OAuth tokens hashed — N/A
- [x] File uploads validated — N/A

### Key Entities

None. F077 has no persistent data.

---

## Success Criteria *(mandatory)*

### Definition of Done Gates

- [ ] PHPCS validation: zero errors and zero warnings on `public/Renderers/MCPClientsBlock.php`
- [ ] PHPStan level 8: zero errors on the same file
- [ ] PHPUnit `mcpclients` suite still passes (no test asserts on the flat layout markup)
- [ ] `npm run build` succeeds and `build/css/backend.css` renders the numbered STEP layout on this local install
- [ ] Every button + JSON + warning + Access Control paragraph that renders on the tab today still renders (spec § FR-006, verified by browser check)

### Measurable Outcomes

- **SC-001**: On this local install, the per-server MCP Clients tab renders exactly **5** `qs-step` blocks for every client — visible confirmation via browser inspect-element. Grep gate: `grep -c 'qs-step-heading__num' public/Renderers/MCPClientsBlock.php` returns **5**.
- **SC-002**: The DOM order matches Step 1 (Generate) → Step 2 (Open config file) → Step 3 (Top-level key) → Step 4 (Copy config) → Step 5 (Restart). Grep gate: extracting the `Step N` strings in source order yields `Step 1, Step 2, Step 3, Step 4, Step 5`.
- **SC-003**: The trailing Access Control notice renders **after** the last `qs-step` block — no `qs-step` block wraps the Access Control paragraph.
- **SC-004**: On a local HTTPS or HTTP site, STEP 4's body still renders the F075 local-dev warning notice above the JSON textarea. No regression to F075's behavior.
- **SC-005**: STEP 5's body renders the client-specific restart text — Claude Desktop shows "Restart Claude Desktop to load the new MCP server.", VS Code shows the Cmd+Shift+P reload text, GitHub Copilot shows the "Restart VS Code + reactivate GitHub Copilot extension" text, Cline/Roo Code/Kilo Code show their sidebar hot-reload texts. No regression to F075 follow-up.
- **SC-006**: `src/scss/backend.scss` contains a `.qs-step-heading__num` rule with a background color and border-radius matching the wizard's visual (light-blue tint pill, small caps).

---

## Assumptions

- No existing PHPUnit test asserts on the flat markup of `render_client_details()` — verified by the `mcpclients` suite passing pre- and post-F077 without changes.
- The wizard's `.qs-step*` visual is the desired shape (F073 shipped it, F075 follow-up extended it with Step 5, no complaints about wizard scaffold since). F077 mirrors it rather than proposing a new design.
- The admin bundle's stylesheet reaches the MCP Clients tab. Verified by any existing admin styling on that tab (F015 / F017 tab CSS lives in the same bundle).
- Companion-plugin subclasses of `AbstractClientRenderer` (if any) are out of scope — they render their own markup and are not affected by this change.
