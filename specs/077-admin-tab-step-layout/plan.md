# Implementation Plan: Numbered STEP layout on the per-server MCP Clients admin tab

**Branch**: `077-admin-tab-step-layout` | **Date**: 2026-08-24 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `/specs/077-admin-tab-step-layout/spec.md`

## Summary

Purely presentational refactor. `MCPClientsBlock::render_client_details()` wraps its five existing content sections in the same `.qs-step` / `.qs-step-heading` / `.qs-step-heading__num` / `.qs-step-body` scaffold the wizard's Step 11 uses. Four CSS rules ported from `src/scss/quick-setup.scss` into `src/scss/backend.scss` so the admin bundle picks them up. Content unchanged: Generate button, Config File value, Top-Level Key value, local-dev warning + JSON textarea + Copy button, client-specific restart text, trailing Access Control paragraph. FR-006 preservation contract — every current behavior stays intact.

## Technical Context

**Language/Version**: PHP 8.1+ (plugin baseline); SCSS via `@wordpress/scripts`.
**Primary Dependencies**: Existing `MCPClientsBlock` renderer, existing wizard SCSS at `src/scss/quick-setup.scss:681–714`. No new PHP or npm dependencies.
**Storage**: None.
**Testing**: PHPUnit `mcpclients` suite (existing) — no test asserts on the flat markup; will remain green with zero test edits.
**Target Platform**: WordPress admin (PHP 8.1+) + WordPress 6.9+ block-editor context.
**Project Type**: WordPress plugin (existing single-project layout).
**Performance Goals**: O(0) — same number of DOM elements as before (five wrappers added; five previously-flat sections wrapped).
**Constraints**: FR-006 no-behavior-change contract on every existing feature (F075 local-dev warning, F075 follow-up restart affordance, F076 no-emoji picker).
**Scale/Scope**: ~35 LOC net in `MCPClientsBlock.php` (mostly moving existing content into new wrapper blocks), ~30 LOC in `backend.scss` (4 CSS rules). Total ~65 LOC added, minimal removals.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Compliance | Notes |
|---|---|---|
| **I. Modular Architecture** | ✅ | Refactor is contained to one existing method + one existing stylesheet. No new module. |
| **II. WordPress Standards** | ✅ | PHPCS + PHPStan L8 gates enforced. All new `<h3>` / `<span>` output uses `esc_html__()` / `esc_html_e()`. |
| **III. Security First** | ✅ | No new input, no new output surface. Escaping preserved on all moved content. |
| **IV. User-Centric Design** | ✅ (pre-approved exception) | MCP Clients tab is under the ratified `?page=acrossai_mcp_manager` parent-menu WP_List_Table exception. F077 restructures markup inside that exception; no DataForm/DataViews mandate applies. |
| **V. Extensibility Without Core Modification** | ✅ | No filter/hook changes. `AbstractClientRenderer` interface preserved. Companion plugins that subclass `AbstractClientRenderer` retain their own render choice — F077 only affects `MCPClientsBlock`. |
| **VI. Reusability & DRY** | ✅ | Reuses the exact class names the wizard uses (`.qs-step*`) — no new namespace, no divergent stylesheet. The 4 CSS rules ported to `backend.scss` are a light duplication of 4 rules in `quick-setup.scss`; both are small and self-contained; extraction to a shared partial is overkill for 4 rules. |
| **VII. Definition of Done** | 🟡 (verify Phase 2) | PHPCS + PHPStan + PHPUnit + `npm run build` gates enforced. No new tests (no logic change). |

**No violations to justify** — Complexity Tracking section omitted.

## Project Structure

### Documentation (this feature)

```text
specs/077-admin-tab-step-layout/
├── spec.md              # /speckit.specify + /speckit.clarify output (no clarifications needed)
├── plan.md              # This file
├── research.md          # Phase 0 output — one recorded decision (color-literal port over SCSS import)
├── quickstart.md        # Phase 1 output — 4-step operator verification runbook
├── tasks.md             # Phase 2 output (/speckit.tasks — NOT created by /speckit.plan)
└── checklists/
    └── requirements.md  # Populated by /speckit.specify (all boxes ✅)
```

**Not created** (F077 has no data model and no external contracts):
- `data-model.md` — no entities
- `contracts/` — no REST routes, no new interfaces

### Source Code (repository root)

```text
public/Renderers/
└── MCPClientsBlock.php               # MODIFIED — refactor render_client_details() into 5 qs-step blocks

src/scss/
└── backend.scss                       # MODIFIED — add 4 CSS rules (.qs-step, .qs-step-heading, .qs-step-heading__num, .qs-step-body)

build/css/
├── backend.css                        # REGENERATED — npm run build
└── backend.asset.php                  # REGENERATED

README.txt                             # MODIFIED — one bullet under = Unreleased =
```

**Structure Decision**: Reuses the plugin's existing layout. No new files. No new directories. No new hooks. No new REST routes.

## Phases

### Phase 0 — Research

One recorded decision:

- **Decision**: Port the 4 wizard step CSS rules into `src/scss/backend.scss` with color literals (matching the wizard's resolved `$qs-text` / `$qs-primary` values), rather than importing the wizard's SCSS variable file into the admin bundle.
- **Rationale**: (a) The admin bundle and wizard bundle build independently via `@wordpress/scripts`; cross-bundle SCSS imports would pull the wizard's whole variable palette into the admin bundle, bloating it. (b) The 4 CSS rules are small and self-contained; duplication is cheaper than an extraction refactor for 4 rules. (c) If either surface diverges in the future, the two files can drift independently — a shared partial would force lockstep changes.
- **Alternatives considered**: (a) Extract to a shared SCSS partial + import from both bundles — rejected for over-engineering. (b) Reuse the wizard's CSS by enqueuing `build/js/quick-setup.css` on the MCP Clients tab — rejected because that stylesheet is scoped inside the wizard's mount div and pulls in ~200 KB of unrelated wizard styles.

No unknowns; no NEEDS CLARIFICATION markers in the spec.

### Phase 1 — Design & Contracts

**Data model**: N/A. `data-model.md` intentionally not created.

**Contracts**: N/A. `contracts/` directory intentionally not created.

**Quickstart** (`quickstart.md`): 4-step operator verification runbook — reload the MCP Clients tab, count 5 STEP badges, spot-check STEP 4 (JSON + warning) + STEP 5 (client-specific restart), verify Access Control paragraph position.

**Agent context update**: No update to `.github/copilot-instructions.md` — small visual refresh, no cross-agent context change warranted.

### Phase 2 — Post-Design Constitution Check

- ✅ No new violations introduced by the design. Every principle either stays green (I, III, V, VI) or falls under the existing pre-approved exception (IV — WP_List_Table).
- ✅ FR-006 preservation contract protects every current behavior.
- ✅ No new storage → no `$wpdb->prepare()` review needed.
- ✅ Subtractive-plus-decorative HTML → security-review.staged expected zero findings.

Handoff to `/speckit.tasks`.
