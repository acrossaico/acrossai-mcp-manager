---
description: "Tasks for feature 077 — Numbered STEP layout on the per-server MCP Clients admin tab"
---

# Tasks: Numbered STEP layout on the per-server MCP Clients admin tab

**Input**: Design documents from `/specs/077-admin-tab-step-layout/`
**Prerequisites**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md), [quickstart.md](./quickstart.md)

**Tests**: No new tests. Existing PHPUnit `mcpclients` suite covers untouched code paths; F077 is a purely presentational refactor with no logic change.

**Organization**: Single user story (P1). Small refactor + CSS port.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel
- **[Story]**: `US1` maps to the sole user story from spec.md

---

## Phase 1: Setup

- [X] T001 Confirm baseline is green: `composer run phpcs && composer run phpstan && composer run test -- --testsuite mcpclients && npm run build`. No pre-existing failures.

---

## Phase 2: User Story 1 — Numbered STEP layout on admin tab (Priority: P1)

**Goal**: The admin tab's client-detail area renders five numbered STEP blocks matching the wizard's Step 11 layout. Every existing content (button, JSON, warning, restart, Access Control paragraph) still renders identically inside the new scaffold.

**Independent Test**: Per quickstart.md — reload the MCP Clients tab, count 5 STEP badges, spot-check STEP 4 (JSON + warning) and STEP 5 (client-specific restart), verify Access Control paragraph is outside any `qs-step` block.

### Implementation for User Story 1

- [ ] T002 [P] [US1] Modify `src/scss/backend.scss` — append 4 CSS rules for `.qs-step`, `.qs-step-heading`, `.qs-step-heading__num`, `.qs-step-body`. Source of truth: `src/scss/quick-setup.scss:681–714`. Resolve `$qs-text` → `#1e1e1e` (dark gray) and `$qs-primary` → `#3858e9` (blue) with the light tint `#eef1ff` on the badge background. Wrap in a small F077 banner comment.
- [ ] T003 [US1] Modify `public/Renderers/MCPClientsBlock.php::render_client_details()` — refactor the flat content sections into 5 `<div class="qs-step">` blocks. Each block has:
  - `<h3 class="qs-step-heading"><span class="qs-step-heading__num">Step N</span> <esc_html step-title></h3>`
  - `<div class="qs-step-body">` wrapping the existing content
  - **STEP 1** wraps: the existing `passwords_generate_button()` call + the "Creates a one-time password…" description
  - **STEP 2** wraps: the existing Config File value (rendered as a readonly `<input>` inside the body for wizard-style presentation)
  - **STEP 3** wraps: the existing Top-Level Key value (same treatment)
  - **STEP 4** wraps: the existing local-dev warning notice (F075) + Configuration JSON `<textarea>` + Copy button
  - **STEP 5** wraps: the client-specific restart text from `$client->get_restart_step_text()`
  - Trailing Access Control notice renders AFTER the last `qs-step` block, outside any `.qs-step` wrapper (FR-004).
  - Existing get_client_slug / get_client_name / get_description block at the top of render_client_details() (F076 no-emoji, no icon) stays exactly as it is — no STEP wrapper around it.
- [ ] T004 [US1] Rebuild the CSS bundle: `npm run build`. Verify `build/css/backend.css` + `build/css/backend.asset.php` update. Depends on T002.

**Checkpoint**: US1 fully functional. Admin tab renders 5 numbered STEP blocks matching the wizard.

---

## Phase 3: Verification

- [ ] T005 [US1] Manual verification per quickstart.md Steps 1–4 on this local install: reload the MCP Clients tab (hard-reload), count STEP badges, click through 5+ client pills, confirm STEP 4 has the F075 warning + JSON + Copy button, STEP 5 has client-specific restart text, Access Control notice sits outside any `qs-step` block.
- [ ] T006 Canary greps (SC-001 through SC-006):
  - `grep -c 'qs-step-heading__num' public/Renderers/MCPClientsBlock.php` → **5**
  - `grep -oE "Step [1-5]" public/Renderers/MCPClientsBlock.php | head -5` → `Step 1, Step 2, Step 3, Step 4, Step 5` in order
  - `grep -c '.qs-step' src/scss/backend.scss` → ≥ 4
  - Preservation greps: `LocalEnvironment::needs_tls_bypass`, `get_restart_step_text`, `Access Control still applies` all still present in `MCPClientsBlock.php` (each ≥ 1)

---

## Phase N: Polish & Cross-Cutting Concerns

- [ ] T007 [P] Update `README.txt` — one bullet under `= Unreleased =`:
  ```
  * **UI — Per-server MCP Clients tab now uses the same numbered STEP 1..5 walkthrough as the Quick Setup wizard's Step 11 (F077).** The admin tab's client-detail area is reorganized under STEP 1 (Generate the password) → STEP 2 (Open the config file) → STEP 3 (Locate the top-level key) → STEP 4 (Copy this config and paste it under the top-level key — includes the local-dev warning + JSON + Copy button) → STEP 5 (Restart the MCP client — client-specific action). Same content as before; consistent visual scaffolding between the two surfaces.
  ```
- [ ] T008 Quality gates chain:
  ```bash
  composer run phpcs                              # zero errors on MCPClientsBlock.php
  composer run phpstan                            # zero errors
  composer run test -- --testsuite mcpclients     # existing suite still green, zero test edits
  npm run build                                   # re-run if additional edits landed
  ```

**Final checkpoint**: All 8 tasks green. Feature ready for `/speckit.analyze`, `/speckit.security-review.staged`, `/speckit.memory-md.capture-from-diff`, and `/speckit.git.commit`.

## Task count

- Setup: 1
- US1: 3 (T002, T003, T004)
- Verification: 2 (T005, T006)
- Polish: 2 (T007, T008)
- **Total: 8**
