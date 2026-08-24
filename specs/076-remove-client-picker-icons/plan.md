# Implementation Plan: Remove client emoji from picker surfaces

**Branch**: `076-remove-client-picker-icons` | **Date**: 2026-08-24 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `/specs/076-remove-client-picker-icons/spec.md`

## Summary

Two Explore agents mapped every place `get_icon()` reaches a rendered surface. Exactly three code paths render an icon in the two client-picker UIs: two `printf` calls in `public/Renderers/MCPClientsBlock.php` (pill sub-nav + per-client detail heading) and one JSX conditional block in `src/js/quick-setup/steps/Step11_ClientDetail.jsx` (picker button). This plan subtracts each of the three. The 16 concrete `get_icon()` method definitions on `AbstractMCPClient` subclasses and the `'icon'` field on the `ConnectionMethodRegistry` DTO are explicitly preserved (FR-004, FR-005).

## Technical Context

**Language/Version**: PHP 8.1+ (plugin baseline); JS/JSX via `@wordpress/scripts` for the wizard bundle.
**Primary Dependencies**: Existing `AbstractMCPClient` factory, existing `ConnectionMethodRegistry` DTO producer, `@wordpress/scripts` build pipeline. No new dependencies.
**Storage**: None (pure UI subtraction).
**Testing**: PHPUnit `mcpclients` suite (existing) — no test asserts on picker markup; will remain 100 / 100 with zero test changes. No new tests introduced (nothing new to assert on — the removed rendering was untested before too).
**Target Platform**: WordPress admin (PHP 8.1+) + WordPress 6.9+ block-editor context for the React wizard.
**Project Type**: WordPress plugin (existing single-project layout).
**Performance Goals**: O(0) — subtraction only. Removes one `->get_icon()` call per pill iteration on the admin tab.
**Constraints**: FR-004 preservation contract on all 16 `get_icon()` methods; FR-005 preservation contract on the DTO field.
**Scale/Scope**: Two PHP printf rewrites + one JSX conditional deletion + one `npm run build` invocation. Approximately −15 LOC net.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Compliance | Notes |
|---|---|---|
| **I. Modular Architecture** | ✅ | Purely subtractive; no new module. Existing `Public\Renderers` boundary unchanged. |
| **II. WordPress Standards** | ✅ | Remaining `esc_html`/`esc_attr`/`esc_url` calls preserved on the shortened printf. PHPCS + PHPStan L8 gates required in Phase 2. |
| **III. Security First** | ✅ | No new input. No new output. Escaping on the remaining args stays. No new attack surface. |
| **IV. User-Centric Design** | ✅ (WP_List_Table exception) | MCP Clients tab is the pre-approved exception; F076 just removes a markup fragment from that exception. Wizard uses existing `<button>` — no DataForm/DataViews mandate applies. |
| **V. Extensibility Without Core Modification** | ✅ | The `get_icon()` extension surface (companion plugins overriding it via `acrossai_mcp_client_classes`) stays intact — return values reach the DTO unchanged; only the plugin's own picker renderers stop consuming the value. |
| **VI. Reusability & DRY** | ✅ | Net-negative code — deletes duplicated icon rendering. No new duplication introduced. |
| **VII. Definition of Done** | 🟡 (verify in Phase 2) | PHPCS + PHPStan + PHPUnit + `npm run build` gates enforced. No new logic → no new tests. |

**No violations to justify** — Complexity Tracking section omitted.

## Project Structure

### Documentation (this feature)

```text
specs/076-remove-client-picker-icons/
├── spec.md              # /speckit.specify + /speckit.clarify output (this feature — no clarifications needed)
├── plan.md              # This file (/speckit.plan)
├── research.md          # Phase 0 output — one recorded decision (DTO field preservation)
├── quickstart.md        # Phase 1 output — 4-step verification runbook
├── tasks.md             # Phase 2 output (/speckit.tasks — NOT created by /speckit.plan)
└── checklists/
    └── requirements.md  # Populated by /speckit.specify (all boxes ticked ✅)
```

**Not created** (F076 has no data model and no external contracts):
- `data-model.md` — no entities
- `contracts/` — no REST routes, no new interfaces

### Source Code (repository root)

```text
public/Renderers/
└── MCPClientsBlock.php               # MODIFIED — remove emoji from sub-nav pill printf + from detail heading printf

src/js/quick-setup/steps/
└── Step11_ClientDetail.jsx           # MODIFIED — delete the `{ c.icon && (…) }` conditional in the picker button

build/js/
├── quick-setup.js                    # REGENERATED — npm run build
└── quick-setup.asset.php             # REGENERATED

includes/MCPClients/*Client.php       # UNTOUCHED (FR-004) — 16 concrete get_icon() methods
public/Discovery/ConnectionMethodRegistry.php  # UNTOUCHED (FR-005) — DTO 'icon' field stays

README.txt                            # MODIFIED — one bullet under = Unreleased =
```

**Structure Decision**: Reuses the plugin's existing layout. No new files. No new directories. No new hooks. No new REST routes.

## Phases

### Phase 0 — Research

One recorded decision (see `research.md`):

- **Decision**: Preserve the `'icon'` field on the `ConnectionMethodRegistry` DTO even though the two touched picker paths no longer consume it. Third-party JSX components or companion plugins may still read the value; removing the DTO field is a subtle shape break that a purely-cosmetic UI change should not carry.
- **Rationale**: F076 is a design refresh, not a deprecation of the `get_icon()` extension surface. Constitution §V (Extensibility Without Core Modification): the abstract method + DTO field together form the extension contract; removing either would signal to companion-plugin authors that the icon feature is retired, which is not the intent.
- **Alternatives considered**: (a) Remove the DTO field too — rejected: silent breakage for downstream consumers, and no cost saving worth the churn. (b) Deprecate `get_icon()` entirely (return null from abstract, delete all overrides) — rejected: explicitly against the user's ask "keep that in classes where it has been defined."

No unknowns; no NEEDS CLARIFICATION markers anywhere in the spec.

### Phase 1 — Design & Contracts

**Data model**: N/A. `data-model.md` intentionally not created.

**Contracts**: N/A. `contracts/` directory intentionally not created.

**Quickstart** (`quickstart.md`): 4-step operator verification runbook — reload MCP Clients tab, reload Step 11, run three canary greps (SC-003, SC-004, SC-005), verify DTO field preservation (SC-006).

**Agent context update**: The plugin uses `.github/copilot-instructions.md` per the plan-template convention. F076 is a small subtractive change — no update needed to the SPECKIT block.

### Phase 2 — Post-Design Constitution Check

- ✅ No new violations introduced by the design. Every principle either stays green (I, III, V, VI) or falls under an existing pre-approved exception (IV — WP_List_Table parent-menu).
- ✅ FR-004 + FR-005 preservation contracts protect the extension surface.
- ✅ No new storage → no `$wpdb->prepare()` review needed.
- ✅ Subtractive HTML → security-review.staged expected zero findings.

Handoff to `/speckit.tasks`.
