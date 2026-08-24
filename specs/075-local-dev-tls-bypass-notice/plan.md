# Implementation Plan: Local-Dev TLS Bypass Notice for MCP Client Snippets

**Branch**: `075-local-dev-tls-bypass-notice` | **Date**: 2026-08-24 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `/specs/075-local-dev-tls-bypass-notice/spec.md`

## Summary

Detect at render time whether the current site is a local dev environment served over HTTPS. When yes, inject `NODE_TLS_REJECT_UNAUTHORIZED: "0"` into the `env` block of every generated MCP client JSON snippet, and render a static warning callout above the JSON on both surfaces (per-server **MCP Clients tab** and **Quick Setup wizard Step 11**) with a link to Automattic's troubleshooting doc. When no, the plugin behaves exactly as it does today — zero injection, zero warning. Fix routes through a new pure-function helper (`Utilities\LocalEnvironment`) and a shared `AbstractMCPClient::build_env()` method so all 16 concrete clients pick it up without per-client duplication.

## Technical Context

**Language/Version**: PHP 8.1+ (plugin baseline per constitution §II); JS/JSX via `@wordpress/scripts` for the wizard bundle.
**Primary Dependencies**: `@wordpress/i18n`, `@wordpress/element`, existing `AbstractMCPClient` factory, existing `ConnectionMethodRegistry`, existing `admin/Partials/Notices.php` notice pattern. No new npm or composer dependencies.
**Storage**: None. Detection reads `home_url()` + `wp_get_environment_type()` at render time; no options, no user meta, no DB rows.
**Testing**: PHPUnit `mcpclients` suite (WP-free per SC-003 of feature 031) for `LocalEnvironment` and canary + fixture tests for all 16 concrete clients. No Jest/JS test — the JSX change is a conditional render of an existing callout component.
**Target Platform**: WordPress admin (PHP 8.1+) + WordPress 6.9+ block editor context for the React wizard.
**Project Type**: WordPress plugin (single project, existing layout).
**Performance Goals**: Detection runs O(1) per render — one `home_url()` + one `wp_get_environment_type()` + one `str_ends_with` scan across ≤ 3 default suffixes (or the filtered list). No caching layer required. Total added wall-clock: microseconds.
**Constraints**: Must not introduce an admin toggle (spec FR-007). Must not fire on plain HTTP (FR-009). Must not fire on `wp_get_environment_type() === 'staging'` alone (Clarifications 2026-08-24).
**Scale/Scope**: One new file (~60 LOC), one method added to `AbstractMCPClient` (~15 LOC), 16 concrete-client mechanical edits (~2 LOC delta each), one PHP notice-block addition (~15 LOC), one PHP `wp_localize_script` payload extension (~6 LOC), one JSX conditional callout (~20 LOC), one PHPUnit test class (~80 LOC). ≈ 275 total LOC added, ≈ 150 removed.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Compliance | Notes |
|---|---|---|
| **I. Modular Architecture** | ✅ | `Utilities\LocalEnvironment` is a single-purpose module (one public method). Reused across `AbstractMCPClient` (env injection), `MCPClientsBlock` (admin notice), and `admin/Main.php` (wizard bootstrap payload) — no duplication. |
| **II. WordPress Standards** | ✅ (gates) | PHPCS + PHPStan L8 + ESLint gates enforced in Phase 2. All output escaped via `esc_html_e` / `esc_url` / `esc_attr` — spec §Security Checklist. Multisite: detection reads per-site `home_url()`, correct on subsite context. |
| **III. Security First** | ✅ | No new nonces, no new capabilities, no new REST routes, no DB access. Injected string literal `"0"` cannot come from user input. Doc URL is a constant. Escape-at-render enforced on the notice HTML. FR-007 explicitly bars an admin toggle → no attacker-controllable path to enable the flag on live sites. |
| **IV. User-Centric Design** | ✅ (falls under exception) | Notice on the MCP Clients tab lives under the pre-approved `?page=acrossai_mcp_manager` parent-menu exception. Wizard callout uses the existing wizard's callout component (or `@wordpress/components` `Notice`) — no custom form/table rendering. No DataForm/DataViews mandate applies (no data input, no data grid). |
| **V. Extensibility Without Core Modification** | ✅ | New feature is a self-contained utility + shared base-class method + per-surface render deltas. Ships an `acrossai_mcp_local_hostname_suffixes` filter for downstream extensibility (spec FR-010). |
| **VI. Reusability & DRY** | ✅ | `AbstractMCPClient::build_env()` eliminates the current 16-way duplication of the `env` literal — this feature is a net DRY win, not a debt. `LocalEnvironment` mirrors the shape of the existing `SiteSlug` utility. No new npm/composer deps. |
| **VII. Definition of Done** | 🟡 (to verify) | Feature's DoD gates enumerated in spec §Success Criteria; verified in Phase 2 via the `composer run phpcs`, `composer run phpstan`, `composer run test`, and `npm run build` chain. |

**No violations to justify** — Complexity Tracking section omitted.

## Project Structure

### Documentation (this feature)

```text
specs/075-local-dev-tls-bypass-notice/
├── spec.md              # /speckit.specify + /speckit.clarify output (this feature — 3 clarifications resolved)
├── plan.md              # This file (/speckit.plan)
├── research.md          # Phase 0 output — decisions + rationale + alternatives (see Phases below)
├── quickstart.md        # Phase 1 output — verification steps (see Phases below)
├── tasks.md             # Phase 2 output (/speckit.tasks — NOT created by /speckit.plan)
└── checklists/
    └── requirements.md  # Populated by /speckit.specify (already ✅ all passing)
```

**Not created** (this feature has no data model and no external contracts):
- `data-model.md` — no entities beyond a stateless detection helper
- `contracts/` — no REST routes, no new interfaces

### Source Code (repository root)

```text
includes/
├── Utilities/
│   ├── LocalEnvironment.php          # NEW — pure static helper; mirrors SiteSlug.php shape
│   └── SiteSlug.php                  # UNCHANGED (referenced as shape template only)
└── MCPClients/
    ├── AbstractMCPClient.php         # MODIFIED — add protected build_env() helper
    ├── ClaudeDesktopClient.php       # MODIFIED — swap literal env array for $this->build_env()
    ├── ClaudeCodeClient.php          # MODIFIED — same, passing OAUTH_ENABLED via $extra
    ├── VSCodeClient.php              # MODIFIED — same
    ├── CursorClient.php              # MODIFIED — same
    ├── GitHubCopilotClient.php       # MODIFIED — same
    ├── CodexClient.php               # MODIFIED — same
    ├── GeminiClient.php              # MODIFIED — same
    ├── WindsurfClient.php            # MODIFIED — same
    ├── ZedClient.php                 # MODIFIED — same
    ├── ClineClient.php               # MODIFIED — same
    ├── RooCodeClient.php             # MODIFIED — same
    ├── KiloCodeClient.php            # MODIFIED — same
    ├── AmazonQClient.php             # MODIFIED — same
    ├── OpenCodeClient.php            # MODIFIED — same
    ├── AntigravityClient.php         # MODIFIED — same
    └── CustomClient.php              # MODIFIED — same
public/
└── Renderers/
    └── MCPClientsBlock.php           # MODIFIED — render <div class="notice notice-warning"> above the JSON textarea when needs_tls_bypass()

admin/
└── Main.php                          # MODIFIED — extend wp_localize_script payload with 'tlsBypass' object

src/js/quick-setup/steps/
└── Step11_ClientDetail.jsx           # MODIFIED — render warning callout above <CodeBlock> when bootstrap.tlsBypass.enabled

tests/phpunit/
└── Utilities/
    └── LocalEnvironmentTest.php      # NEW — 9 assertions per spec §SC-004 + SC-005

README.txt                            # MODIFIED — one bullet under = Unreleased = section
```

**Structure Decision**: Reuses the plugin's existing single-project layout. No new top-level directories. The new `Utilities/LocalEnvironment.php` sits alongside `Utilities/SiteSlug.php` and follows the same static-only, no-hooks, no-state shape. The 16-way client refactor is a mechanical pattern — one search-replace across `includes/MCPClients/*.php`.

## Phases

### Phase 0 — Research

All architectural questions were resolved by the pre-drafted planning doc (`docs/planings-tasks/075-local-dev-tls-bypass-notice.md`) plus the three /speckit.clarify answers integrated into `spec.md § Clarifications`. Emitting `research.md` for the record so downstream `/speckit.tasks` and `/speckit.implement` have a stable reference — no NEEDS CLARIFICATION markers remain.

Content of `research.md`:

- **Decision**: Detection helper lives at `includes/Utilities/LocalEnvironment.php`, static-only, mirroring `SiteSlug.php`.  
  **Rationale**: Sibling utility for site slug follows the same shape and is testable without a WP bootstrap.  
  **Alternatives considered**: (a) Instance/singleton class — rejected: no state, no lifecycle. (b) Filter on `home_url` at boot time — rejected: leaves the detection scattered across callers.

- **Decision**: `env` injection happens via a new `protected AbstractMCPClient::build_env()` method that the 16 concrete clients delegate to.  
  **Rationale**: Single point of injection; no post-processing walker required; new clients cannot accidentally skip the flag.  
  **Alternatives considered**: (a) Walker that mutates the JSON after `get_config_snippet()` returns — rejected: brittle across future clients that might change JSON shape. (b) Trait — rejected: `AbstractMCPClient` is already the base class; adding a trait for one method is over-engineering.

- **Decision**: Warning-notice copy lives once in PHP; the JS surface receives it via `wp_localize_script`.  
  **Rationale**: Single source of truth for translatable strings. Constitution §II compliance (text domain `acrossai-mcp-manager`).  
  **Alternatives considered**: (a) Duplicate the copy inline in both PHP and JSX — rejected: drift risk on future copy edits. (b) Fetch via a new REST route — rejected: extra roundtrip for a static string.

- **Decision**: `acrossai_mcp_local_hostname_suffixes` filter, defaulting to `['.local', '.test', '.localhost']`, callable-return-defensive.  
  **Rationale**: Ops-team extensibility (spec Clarification 2), no admin surface, no security regression (HTTPS gate is enforced independently of suffix list per spec §Assumptions).  
  **Alternatives considered**: (a) Admin option — rejected: FR-007 bars an admin toggle. (b) Additional boolean override filter (`acrossai_mcp_needs_tls_bypass`) — rejected: adds complexity for no acknowledged use case.

- **Decision**: Notice is a static callout (never dismissible).  
  **Rationale**: Config state is permanent for the current site; hiding the notice invites forgetting the insecure flag is baked into copied JSON (spec Clarification 3).  
  **Alternatives considered**: Dismissible via user meta — rejected: adds a storage row and dismiss endpoint for a UX gain that undermines the safety goal.

### Phase 1 — Design & Contracts

**Data model**: N/A — feature has no entities. `data-model.md` intentionally not created.

**Contracts**: N/A — no REST routes, no new interfaces. `contracts/` directory intentionally not created.

**Quickstart** (`quickstart.md`): Six-step operator verification, derived from spec §Success Criteria SC-001..005 plus the two additional edge tests (staging-env exclusion + defensive filter callback). Explicitly runnable against this local `.local` install.

**Agent context update**: The plugin uses `.github/copilot-instructions.md` per the plan-template convention — the `<!-- SPECKIT START --> / <!-- SPECKIT END -->` block should be updated to point at this `plan.md`.

### Phase 2 — Post-Design Constitution Check (re-verify after research)

- ✅ No new violations introduced by the design.
- ✅ FR-010 filter is a WordPress action-hook extension point (constitution §V compliant).
- ✅ Zero new storage → no `$wpdb->prepare()` review needed.
- ✅ Static PHP notice + JSX callout inherit escaping/rendering conventions from their host files; no new attack surface.

Handoff to `/speckit.tasks`.
