# Research: Remove client emoji from picker surfaces (Feature 076)

**Feature**: [spec.md](./spec.md) | **Plan**: [plan.md](./plan.md) | **Created**: 2026-08-24

All Technical Context items in `plan.md` are resolved. No NEEDS CLARIFICATION markers exist in the spec. This document captures the one non-trivial decision.

---

## Decision 1 — Preserve the ConnectionMethodRegistry DTO's `'icon'` field even though nothing in-plugin renders it after F076

**Decision**: The `'icon' => $client->get_icon()` line in `ConnectionMethodRegistry::get_clients()` (currently at line 218) stays. The abstract base's `get_icon()` method and all 16 concrete overrides also stay. F076 removes only the two picker renderers that consume the value; the value keeps flowing through the DTO to any consumer.

**Rationale**: The `get_icon()` method + `icon` DTO field together form an extension contract. Companion plugins register `MCPClient` subclasses via the `acrossai_mcp_client_classes` filter and set an emoji they want to see; their own admin pages, or a JSX component in a companion wizard, may consume the DTO's `icon` field to render a picker of their own. Removing the DTO field would be a subtle shape break for those consumers — a compatibility hazard the plugin should not introduce for a purely cosmetic UI change.

The user's ask made the intent explicit: "remove it from the UI but keep that in classes where it has been defined." Extension contracts stay; internal rendering paths change.

**Alternatives considered**:

- **Remove the DTO field too**: Rejected. Silent breakage for downstream consumers. No cost saving worth the churn. Third-party JSX components would receive a shape change with no deprecation window.
- **Deprecate `get_icon()` entirely and delete all 16 overrides**: Rejected. Explicitly against the user's ask. Also violates the constitution's §V Extensibility Without Core Modification principle — an existing extension surface should not be retired without a broader deprecation cycle and no forward-looking motivation.
- **Keep both surfaces rendering the icon, hide via CSS**: Rejected. CSS hiding leaves markup on the wire and the operator's browser still parses it; also `display: none` doesn't play well with screen readers on inert glyphs. A clean HTML/JSX subtraction is more honest.

## Decision 2 — Zero PHPUnit changes; zero new tests

**Decision**: No test edits, no new tests. The `mcpclients` suite continues to run 100 / 100 with the same assertions post-F076.

**Rationale**: The removed rendering was untested pre-F076. There is no golden fixture or unit test that asserts on picker markup — the suite tests `get_client_slug()`, `get_client_name()`, `get_config_snippet()`, `derive_server_key()`, etc. F076 does not touch any of those code paths. Adding new tests to assert "picker no longer contains emoji" would test the rendering framework rather than plugin behavior — low signal, high maintenance cost.

**Alternatives considered**: Add a smoke test that renders `MCPClientsBlock` and asserts absence of specific emoji characters — rejected as noise. If future regressions re-introduce the icon markup, they will be caught by manual QA (spec § SC-001, SC-002) or by the canary greps (SC-003, SC-004).

## Non-decisions (already covered by spec)

- **Which surfaces render the icon**: enumerated in the spec's Module Placement section and here in `plan.md § Project Structure` after two Explore agent runs. No further discovery needed.
- **CSS follow-up**: covered by spec § Assumptions — the `acrossai-client-tab-icon` class may survive in `backend.scss` as dead code; safe to defer to a separate cleanup pass.
- **RTL / accessibility impact**: covered by spec § Edge Cases — the removed emoji had no directionality dependency; no aria labels reference the icon; screen readers experience no change beyond one fewer character-glyph announcement per client.
