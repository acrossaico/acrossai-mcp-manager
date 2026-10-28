# Implementation Plan: Generate-Prompt connect path for MCP clients

**Branch**: `099-mcp-client-setup-prompt` | **Date**: 2026-10-27 | **Spec**: [spec.md](spec.md)
**Input**: Feature specification from `/specs/099-mcp-client-setup-prompt/spec.md`

## Summary

Add a second way to finish connecting an MCP client: a paste-ready block of prose that instructs
the AI client to edit its own config file, offered beside the existing manual steps on both
surfaces that show connection steps. Step 1 (generate an Application Password) is unchanged and
shared.

**The configuration described does not change.** It remains the
`@automattic/mcp-wordpress-remote` stdio bridge with `WP_API_*` env vars, byte-identical to what
the manual path already shows. This feature automates the *file-editing steps*, not the
configuration.

Technical approach, in one line: **one new virtual method on the client contract, one new field on
the existing DTO producer, consumed by both renderers.** That shape is not a choice so much as a
requirement — `D43 / DEC-CROSS-SURFACE-PARITY-UNIFY-AT-DATA-LAYER` mandates that two renderers
showing one domain object share a DTO producer and never a component, and names
`ConnectionMethodRegistry::get_clients()` as its reference implementation.

Three defects in adjacent code are in scope because this feature is what makes them matter:
the wizard shows macOS config paths to Windows users, password substitution silently fails for one
client, and the credential route lacks the availability and access checks a prominent one-click
mint button demands.

## Technical Context

**Language/Version**: PHP 8.1+ (floor pinned by the BerlinDB dependency); ES2021 via
`@wordpress/scripts`
**Primary Dependencies**: WordPress 6.9+ core (Application Passwords, `WP_Scripts`);
`@wordpress/element`, `@wordpress/components`, `@wordpress/i18n`, `@wordpress/api-fetch`;
optional `wpboilerplate/wpb-access-control`
**Storage**: None added. Credentials are created and hashed by WordPress core; this feature retains
only the non-secret UUID alongside the existing credential record
**Testing**: PHPUnit 9.6 — `mcpclients` suite runs **without** a WordPress bootstrap (A18/SC-003);
`admin` and `renderers` suites run under `tests/bootstrap-wp.php`
**Target Platform**: WordPress admin (wp-admin), single site; multisite-neutral
**Project Type**: WordPress plugin — PHP backend with two admin front ends (server-rendered PHP tab
and a React wizard)
**Performance Goals**: None meaningful. The prompt is string assembly over data already resolved
for the existing panel; no new queries, no new HTTP calls
**Constraints**: The configuration block inside the prompt MUST be byte-identical to the manual
path's (FR-003), including under translation (FR-026). No new REST route (FR-023). No second
credential-issuance path (SC-013)
**Scale/Scope**: 16 client classes, 2 render surfaces, 1 existing REST route hardened, 0 new
database tables

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Assessment |
|---|---|
| **I. Modular Architecture** | **PASS.** Additive to the existing MCPClients module. The prompt builder is a defaulted virtual method on `AbstractMCPClient`, so no sibling module changes and third-party subclasses keep compiling (FR-021). |
| **II. WordPress Standards** | **PASS.** WPCS strict, PHPStan 8, ESLint zero-warning, escaping at render, sanitisation at boundary, WP 6.9+/PHP 8.1+ all carried in the DoD gates. **Multisite**: the spec scopes this increment single-site; justified because the feature adds no site-scoped storage and therefore inherits the existing screens' behaviour unchanged — it is multisite-*neutral*, not multisite-incompatible. |
| **III. Security First (NON-NEGOTIABLE)** | **PASS.** Capability check on the panel (FR-024), `permission_callback` on the existing route, mint locked to `get_current_user_id()` (FR-016), no raw queries, credentials remain core-hashed (S3), responses marked no-store (FR-017). **The §III consent-surface exception is deliberately NOT used** — clarification Q1 made this administrator-only. |
| **IV. User-Centric Design (NON-NEGOTIABLE)** | **PASS, with the reasoning stated.** The new panel is neither a form nor a filterable row set — it is read-only text plus a copy control — so `DataForm`/`DataViews` are not triggered. It extends a screen already covered by `DEV5` (per-server tab sub-forms may be hand-rolled) and reuses the existing copy-control and code-block components rather than inventing patterns. |
| **V. Extensibility** | **PASS.** Virtual-with-default follows the `get_restart_step_text()` / `get_config_files()` precedent exactly, so clients registered through `acrossai_mcp_client_classes` need no change. The Access Control integration stays optional and fails open (D19). |
| **VI. Reusability & DRY** | **PASS, and net-negative on duplication.** The prompt composes four accessors that already exist; the DTO producer is reused rather than forked (D43); and the splice fix *removes* an existing duplicate implementation rather than adding one. |
| **VII. Definition of Done** | **PASS.** Gates enumerated in the spec, including the WP-free bootstrap contract for `tests/phpunit/MCPClients/`. |

**Result: no violations. Complexity Tracking is not required and is omitted.**

One item is worth stating so a later reader does not mistake it for an oversight: FR-024 requires an
explicit administrator check while `DEC-CLIENT-RENDERER-PUBLIC-API` and SEC-013-005 forbid
hardcoding `manage_options`. Both hold simultaneously — the **block's** capability stays contextual,
and administrator-only is an **additional check at the panel**. Satisfying FR-024 by hardcoding the
capability inside the renderer would violate the decision.

## Revision — the route split (post-plan)

The plan above describes Step 2 becoming *two panels*. That is not what shipped.

After the first implementation, the Claude Design file *MCP Client Config Steps* showed why: with
the prompt and the manual steps both rendered, the screen reads as one long sequence and an
operator cannot tell the two are alternatives, or where the first ends. **Step 2 is now the choice
itself, and only the chosen route is visible** (FR-029 … FR-032).

What this changed from the plan as written:

- The picker is a new element, not a reorganisation of the existing steps.
- The manual route's Steps 2–5 became lettered sub-steps a/b/c under Step 2, with the reload
  instruction folded into (c). FR-011 was amended accordingly — it previously demanded the manual
  content be byte-unchanged, which the redesign made false.
- Selection is client-side so a generated credential survives the switch, with server-side
  `?setup=` honouring and real `href`s as the no-JavaScript path.
- `CodeBlock` gained a `copyLabel` prop: the wizard pane is now used for two payloads and
  "Copy config" is wrong on a prompt.

The DTO shape and the credential hardening are unaffected — the split is presentational, and both
surfaces still consume one `setupPrompt` from one producer, which is what D43 requires.

## Project Structure

### Documentation (this feature)

```text
specs/099-mcp-client-setup-prompt/
├── plan.md                    # This file
├── spec.md                    # 28 FRs, 17 SCs, 4 clarifications applied
├── research.md                # Phase 0 output
├── data-model.md              # Phase 1 output
├── quickstart.md              # Phase 1 output
├── contracts/                 # Phase 1 output
├── memory-synthesis.md        # Durable-memory synthesis (889 words, within budget)
├── checklists/requirements.md # Spec quality checklist, 16/16
└── tasks.md                   # Phase 2 — created by /speckit.tasks, not here
```

### Source Code (repository root)

```text
includes/
├── MCPClients/
│   └── AbstractMCPClient.php          # + get_setup_prompt() — virtual, defaulted
└── Main.php                           # unchanged — this feature adds no hooks (A1)

public/
├── Discovery/
│   └── ConnectionMethodRegistry.php   # + setupPrompt DTO field (server-scoped only)
└── Renderers/
    └── MCPClientsBlock.php            # + prompt panel in step 2; per-OS paths unchanged

admin/
└── Partials/
    └── ApplicationPasswords.php       # + availability check, AC gate, cache headers,
                                       #   UUID retention, prefixed name, issuance record

src/js/
├── quick-connect/steps/
│   └── Step11_ClientDetail.jsx        # + prompt panel; manual panel upgraded to per-OS paths
└── backend.js                         # injectPasswordIntoConfig() → format-agnostic replace

tests/phpunit/
├── MCPClients/ConcreteClientsTest.php        # prompt content, all 16 clients (WP-free)
├── MCPClients/AbstractMCPClientTest.php      # bare-subclass compatibility (FR-021)
├── Public/Renderers/MCPClientsBlockRenderTest.php
└── Admin/Partials/ApplicationPasswordsGateTest.php   # new — availability + AC gate
```

**Structure Decision**: No new directories and no new classes. Every change is additive to a file
that already exists, which is what keeps the diff reviewable and the blast radius small. The one
structural question — where the prompt is *built* — is answered by D43: in the DTO producer, so
that encoding and translation decisions live in one place and cannot drift between the two
renderers.

Note on a moving boundary: the front-end exposure of the client renderer (shortcodes and the action
dispatcher in `includes/REST/ClientRendererController`) is being removed separately. The renderers
keep serving the admin tab and `ConnectionMethodRegistry` survives, so this plan is unaffected
either way. While the shortcode is still registered, `A22` binds and the panel's class names must be
plugin-owned rather than wp-admin ones.
