---
description: "Tasks for feature 076 — Remove client emoji from picker surfaces"
---

# Tasks: Remove client emoji from picker surfaces

**Input**: Design documents from `/specs/076-remove-client-picker-icons/`
**Prerequisites**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md), [quickstart.md](./quickstart.md)

**Tests**: No new tests. Existing PHPUnit `mcpclients` suite covers the untouched code paths; F076 is a subtractive UI change with no logic to assert.

**Organization**: Single user story (P1) — small subtractive change. No Foundational phase.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks)
- **[Story]**: `US1` maps to the sole user story from spec.md
- All paths absolute from repository root (`/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/plugins/acrossai-mcp-manager/`)

---

## Phase 1: Setup

- [X] T001 Confirm baseline is green before any change: `composer run phpcs && composer run phpstan && composer run test -- --testsuite mcpclients && npm run build`. Record any pre-existing failures so we can distinguish them from ones this feature introduces. (No code change — this is a gate.)

---

## Phase 2: User Story 1 — Text-only picker on both surfaces (Priority: P1)

**Goal**: Both client-picker surfaces render text-only pickers; the 16 `get_icon()` methods and the DTO field stay untouched.

**Independent Test**: Per quickstart.md Steps 1 + 2 — reload the two admin surfaces and confirm no emoji renders on any pill/button.

### Implementation for User Story 1

- [ ] T002 [P] [US1] Modify `public/Renderers/MCPClientsBlock.php` **sub-nav pill** (currently around lines 144–155). Delete the `$emoji = $client->get_icon();` assignment. Rewrite the `printf` call so the anchor's inner HTML is a single `<span>{esc_html(client_name)}</span>` — remove the `<span class="acrossai-client-tab-icon">%s</span>` fragment entirely and drop the emoji `printf` argument. Preserve `esc_url()` on `$url` and `esc_attr()` on `$css_class`.
- [ ] T003 [P] [US1] Modify `public/Renderers/MCPClientsBlock.php` **per-client detail heading** (currently around lines 173–184). Delete the `$emoji = $client->get_icon();` assignment. Rewrite the `printf` call to `printf( '<h2>%s</h2>', esc_html( $client->get_client_name() ) );` — no leading emoji, no space.
- [ ] T004 [P] [US1] Modify `src/js/quick-setup/steps/Step11_ClientDetail.jsx` picker button (currently around lines 187–191). Delete the entire `{ c.icon && (<span style={ { marginRight: 6 } }>{ c.icon }</span>) }` conditional block. The `<button>` body's only child stays `{ c.name || c.slug }`.
- [ ] T005 [US1] Rebuild the JS bundle: `npm run build`. Verify `build/js/quick-setup.js` + `build/js/quick-setup.asset.php` update. Depends on T004.

**Checkpoint**: US1 fully functional. Both surfaces render text-only pickers.

---

## Phase 3: Verification

- [ ] T006 [US1] Manual verification per quickstart.md Steps 1 + 2 on this local install: reload the per-server MCP Clients tab (Step 1) and Quick Setup Step 11 (Step 2). Confirm no emoji renders on any pill/button/heading.
- [ ] T007 Canary greps (SC-003 through SC-006):
  - `grep -RnE 'get_icon\(\)' public/Renderers/MCPClientsBlock.php` → **zero matches** (removed usage).
  - `grep -n 'c\.icon' src/js/quick-setup/steps/Step11_ClientDetail.jsx` → **zero matches** (removed usage).
  - `grep -RnE 'public function get_icon\(\)' includes/MCPClients/*Client.php | wc -l` → **exactly 16** (methods preserved).
  - `grep -n "'icon'" public/Discovery/ConnectionMethodRegistry.php` → still returns the `'icon' => $client->get_icon(),` line (DTO shape preserved).

---

## Phase N: Polish & Cross-Cutting Concerns

- [ ] T008 [P] Update `README.txt`: add one bullet under `= Unreleased =`:
  ```
  * **UI — Client picker emojis removed on both admin surfaces (F076).** The per-server MCP Clients tab pill sub-nav and Quick Setup wizard Step 11 client-picker buttons now render each client's name only — no leading emoji glyph. The `get_icon()` methods on all 16 client classes stay defined (so companion plugins reading the ConnectionMethodRegistry DTO's `icon` field still see the value); only the two visible pickers stop rendering it.
  ```
- [ ] T009 Quality gates chain:
  ```bash
  composer run phpcs                              # zero errors on MCPClientsBlock.php
  composer run phpstan                            # zero errors on MCPClientsBlock.php
  composer run test -- --testsuite mcpclients     # 100/100 still green
  npm run build                                   # already ran in T005; re-run only if further edits landed
  ```

**Final checkpoint**: All 9 tasks green. Feature ready for `/speckit.analyze`, `/speckit.security-review.staged`, `/speckit.memory-md.capture-from-diff`, and `/speckit.git.commit`.

---

## Dependencies & Story Ordering

```
T001 (Setup baseline)
  │
  ▼
Phase 2 — US1 (parallel across the 3 files)
  T002 (MCPClientsBlock pill sub-nav)      ┐
  T003 (MCPClientsBlock detail heading)    │  All independent (T002 + T003 same file, disjoint printf sites; T004 different file)
  T004 (Step11 JSX)                        ┘
    │
    ▼
  T005 (npm build) — depends on T004
    │
    ▼
Phase 3 — Verification
  T006 (manual smoke)
  T007 (canary greps)
    │
    ▼
Phase N — Polish
  T008 (README bullet)
  T009 (quality gates)
```

## Parallel Execution Examples

**Phase 2 parallel batch** (after T001 baseline): T002, T003, T004 all touch disjoint code (T002/T003 hit the same file but different `printf` blocks). T005 gates on T004.

## Implementation Strategy

- Single-phase MVP: T001 → Phase 2 → Phase 3 → Phase N. No incremental delivery split — the whole change is ~15 LOC net removal.
- Feature is small enough that all 9 tasks land in one implement pass.

## Task count

- Setup: 1
- US1: 4 (T002, T003, T004, T005)
- Verification: 2 (T006, T007)
- Polish: 2 (T008, T009)
- **Total: 9**
