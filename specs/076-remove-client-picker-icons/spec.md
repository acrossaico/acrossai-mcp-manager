# Feature Specification: Remove client emoji from picker surfaces

**Feature Branch**: `076-remove-client-picker-icons`
**Created**: 2026-08-24
**Status**: Draft
**Input**: User description: "Remove the icon from the client picker UI on both surfaces (per-server MCP Clients tab + Quick Setup Step 11) but keep the get_icon() methods defined on the 16 client classes."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Operator sees clean, text-only client picker on both surfaces (Priority: P1)

The operator opens either the per-server **MCP Clients** admin tab or **Quick Setup Wizard Step 11 — Choose your client**. Each client is presented with its display name only (Claude Desktop, Claude Code, VS Code, GitHub Copilot, Codex, Cursor, Gemini CLI, Windsurf, Zed, Cline, Roo Code, Kilo Code, Amazon Q Developer, OpenCode, Antigravity, Custom Client) — no leading emoji, no icon glyph.

**Why this priority**: This is the entire scope of the feature. Design refresh — the picker read as cluttered with per-client emoji when only the name is what the operator navigates by.

**Independent Test**: On this local install, open `admin.php?page=acrossai_mcp_manager&action=edit&server=1&tab=clients`. Verify the pill sub-nav shows text-only pills. Click any pill — the `<h2>` below shows the client name with no emoji prefix. Open `?…&quick-setup=1&step=11&server=1` — verify the grid buttons show text-only names.

**Acceptance Scenarios**:

1. **Given** the operator is on the per-server MCP Clients tab, **When** the sub-nav pills render, **Then** each pill contains only the client's display name in its inner `<span>` — no `acrossai-client-tab-icon` span, no emoji character.
2. **Given** the operator has selected any client pill, **When** the per-client detail heading renders, **Then** the `<h2>` contains only the client's display name — no leading emoji + space.
3. **Given** the operator is on Quick Setup Step 11, **When** the "Choose your client" buttons render, **Then** each button's visible content is only `{name || slug}` — no `<span>` wrapping an emoji.
4. **Given** a companion plugin registers a new `MCPClient` subclass via the `acrossai_mcp_client_classes` filter and its `get_icon()` returns an emoji, **When** the picker renders on either surface, **Then** the emoji is NOT displayed — the picker consistently ignores all icons post-F076.

---

### Edge Cases

- **A client subclass returns an empty string from `get_icon()`**: no change — the picker rendered fine before (existing `''` default on `AbstractMCPClient::get_icon()`) and continues to render fine after (the emoji rendering path is gone entirely, so empty vs non-empty is moot).
- **A third-party JSX component consuming the `ConnectionMethodRegistry` DTO reads `.icon`**: the DTO field stays populated (FR-005). Third-party code sees the same value it saw pre-F076.
- **A companion plugin's own admin page renders the icon**: unaffected — F076 only removes usage from the two touched files. Any other renderer keeps working exactly as before.
- **RTL locales**: the removed emoji + space had no directionality dependency; nothing else changes.

---

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The per-server MCP Clients tab's pill sub-nav MUST render each client pill as `<a href="…" class="…"><span>{esc_html client_name}</span></a>` — a single inner `<span>` containing only the escaped client name. The `acrossai-client-tab-icon` span MUST be removed entirely from the pill markup.
- **FR-002**: The per-server MCP Clients tab's per-client detail heading MUST render `<h2>{esc_html client_name}</h2>` — no leading emoji, no space between an emoji and the name.
- **FR-003**: Quick Setup wizard Step 11's client-picker button MUST render only `{ c.name || c.slug }` inside the `<button>` body — the `{ c.icon && (<span>{ c.icon }</span>) }` conditional block MUST be removed entirely.
- **FR-004**: The 16 concrete `AbstractMCPClient::get_icon()` method definitions across `includes/MCPClients/*Client.php` MUST be untouched — return values, docblocks, method signatures all unchanged. The abstract-base default (`return '';`) also stays.
- **FR-005**: The `ConnectionMethodRegistry::get_clients()` DTO's `'icon'` field MUST stay in place — the DTO producer continues to call `$client->get_icon()` and set `$dto['icon']`. Only the DIRECT rendering usage in the two picker paths is removed. Rationale: third-party JSX components, companion-plugin renderers, and future in-house consumers may still want the value; removing the DTO field is a subtle shape break that a purely cosmetic UI change should not carry.

### WordPress Requirements

**PHP Version**: PHP 8.0+ (plugin baseline)
**WordPress Version**: 6.9+
**Multisite**: unchanged from current — pure UI subtraction
**Required Plugins / Packages**: none new
**Optional Integrations**: none

### Module Placement

**PHP Class(es)**:
- `public/Renderers/MCPClientsBlock.php` (modified) → namespace `AcrossAI_MCP_Manager\Public\Renderers` — two printf rewrites (pill sub-nav + detail heading).

**JS**:
- `src/js/quick-setup/steps/Step11_ClientDetail.jsx` (modified) — one JSX conditional deleted.
- `build/js/quick-setup.*` (regenerated) — rebuilt by `npm run build`.

**Hook Registration**: no new hooks. Subtractive-only edits.

### Admin UI Requirements

**Pre-approved WP_List_Table exception applies**: the MCP Clients tab lives under the pre-ratified `?page=acrossai_mcp_manager` parent screen — F076 removes markup fragments only; no DataForm/DataViews or new-screen concerns arise.

**Quick Setup wizard**: Step 11 remains a React screen; F076 removes a JSX conditional inside an existing `<button>` element.

### Database / Storage

**No persistent storage**: N/A — purely UI subtraction.

### Security Checklist

- [x] All form/AJAX handlers verify nonce — N/A (no new form or AJAX)
- [x] All admin page renders check `current_user_can` — N/A (inherits gating from enclosing pages)
- [x] All REST routes have explicit `permission_callback` — N/A (no new REST route)
- [x] All user input sanitized at boundary — N/A (no new input)
- [x] All output escaped at point of rendering — existing `esc_html`/`esc_attr`/`esc_url` calls on the remaining `printf` arguments stay in place; nothing new to escape
- [x] All DB queries use `$wpdb->prepare()` — N/A (no DB)
- [x] OAuth tokens hashed — N/A
- [x] File uploads validated — N/A

### Key Entities

None. F076 has no persistent data.

---

## Success Criteria *(mandatory)*

### Definition of Done Gates

- [ ] PHPCS validation: zero errors and zero warnings on `public/Renderers/MCPClientsBlock.php`
- [ ] PHPStan level 8: zero errors on `public/Renderers/MCPClientsBlock.php`
- [ ] `npm run build` succeeds and the resulting `build/js/quick-setup.js` renders the icon-free picker on this local install
- [ ] PHPUnit `mcpclients` suite still passes (100 / 100 — no test asserts on picker markup)
- [ ] 16 `get_icon()` method definitions across `includes/MCPClients/*Client.php` remain unchanged (canary grep)
- [ ] `ConnectionMethodRegistry::get_clients()` DTO still carries the `'icon'` field (canary grep)

### Measurable Outcomes

- **SC-001**: On this local install, the per-server MCP Clients tab renders text-only pills for all 16 clients — visible confirmation via browser reload.
- **SC-002**: On this local install, Quick Setup Step 11's picker renders text-only buttons for all 16 clients — visible confirmation via browser reload.
- **SC-003**: Canary grep `grep -RnE 'get_icon\(\)' public/Renderers/MCPClientsBlock.php` returns **zero** matches.
- **SC-004**: Canary grep `grep -n 'c\.icon' src/js/quick-setup/steps/Step11_ClientDetail.jsx` returns **zero** matches.
- **SC-005**: Canary grep `grep -RnE 'public function get_icon\(\)' includes/MCPClients/*Client.php` returns exactly **16** matches — one per concrete subclass, untouched.
- **SC-006**: Canary grep `grep -n "'icon'" public/Discovery/ConnectionMethodRegistry.php` still returns the DTO-field line — the DTO shape is preserved.

---

## Assumptions

- No existing PHPUnit test asserts on the picker's rendered markup — verified by the `mcpclients` suite passing pre- and post-F076 without changes.
- No CSS follow-up needed. The `acrossai-client-tab-icon` class disappears from the emitted HTML with the emoji span; if the class survives in `backend.scss` it becomes dead but not visually broken. A separate cleanup can remove it later.
- The two Explore agents' inventory of icon-rendering sites is exhaustive — no other admin/wizard file was found to render the picker with per-client emoji.
- Companion plugins reading the DTO's `'icon'` field, if any, continue to work — the field is preserved.
