# Research: Numbered STEP layout on the per-server MCP Clients admin tab (Feature 077)

**Feature**: [spec.md](./spec.md) | **Plan**: [plan.md](./plan.md) | **Created**: 2026-08-24

All Technical Context items in `plan.md` are resolved. No NEEDS CLARIFICATION markers exist. This document captures the one non-trivial decision.

---

## Decision 1 — Port CSS rules with color literals rather than importing wizard SCSS variables

**Decision**: The 4 wizard step CSS rules (`.qs-step`, `.qs-step-heading`, `.qs-step-heading__num`, `.qs-step-body`) get added to `src/scss/backend.scss` with color literals substituted for the wizard's `$qs-text` and `$qs-primary` SCSS variables. Same class names on both surfaces — same visual — but the CSS is duplicated across two bundles.

**Rationale**:

- The plugin builds independent admin (`backend.js` + `backend.css`) and wizard (`quick-setup.js` + `quick-setup.css`) bundles via `@wordpress/scripts` + webpack. Each has its own SCSS entry point.
- Importing the wizard's variable file (e.g. `_variables.scss` if one exists) into `backend.scss` would pull the wizard's whole variable palette into the admin bundle, wasting bytes on tokens the admin surface doesn't use.
- The 4 rules are ~25 lines total. Duplication cost is minimal; extraction/import cost is a whole webpack config discussion.
- Independent divergence is possible if the two surfaces ever need to differ visually (e.g. admin uses tighter padding for embedded contexts). A shared partial would force lockstep changes and require a discussion at every future SCSS edit.

**Alternatives considered**:

- **Extract to a shared SCSS partial** (e.g. `src/scss/_steps.scss`) imported by both `backend.scss` and `quick-setup.scss`. Rejected: over-engineered for 4 rules; introduces build-config complexity; future maintainers must understand cross-bundle SCSS resolution.
- **Enqueue the wizard's CSS on the MCP Clients tab**. Rejected: `build/js/quick-setup.css` (~200 KB) contains the whole wizard's styling, most of which is scoped to the wizard mount div (`#acrossai-mcp-quick-setup-root`) and won't apply outside it. Enqueuing it would bloat the admin bundle load and add a stylesheet with mostly-inert rules.
- **Use inline styles on the PHP `printf`s**. Rejected: separation-of-concerns violation. Also breaks copy-paste parity between the two surfaces (JSX uses classes; PHP would use inline).

## Decision 2 — No JSX changes; align only the admin PHP renderer with the wizard's existing layout

**Decision**: F077 modifies only `MCPClientsBlock.php` and `backend.scss`. `Step11_ClientDetail.jsx` is not touched. The wizard is treated as the canonical shape; the admin tab conforms to it.

**Rationale**: The wizard's step layout has been stable since F073 (added STEPS 1–4) and F075 follow-up (added STEP 5). No complaints about it. F077 is the second surface catching up to the first. Reversing that direction (making the wizard match a new admin layout) would require re-designing something already shipped and validated.

**Alternatives considered**:

- **Design a new shared layout that both surfaces adopt**: Rejected. The wizard's step scaffold is already visually clean; no reason to change it. This would be re-design without an ask.
- **Redirect the admin tab into an embedded copy of the wizard's Step 11**: Rejected. Two very different HTTP-request contexts (per-server-edit URL vs. wizard-URL); mounting the React wizard inside the admin tab would drag in wizard state management (`useWizardState`, scratchpad reads, method-picker context) for a screen that shouldn't need it.

## Non-decisions (already covered by spec)

- **What step titles to use**: Explicit in FR-002 — verbatim from the wizard's five step titles.
- **Trailing Access Control paragraph placement**: Explicit in FR-004 — after STEP 5, outside any `qs-step` block, matching the wizard.
- **Preservation of existing content**: Explicit in FR-006 — every existing button/JSON/warning/restart/paragraph stays.
- **No CSS-token refactor**: Explicit in FR-005 — port with color literals, don't import variables.
