# Research: Local-Dev TLS Bypass Notice (Feature 075)

**Feature**: [spec.md](./spec.md) | **Plan**: [plan.md](./plan.md) | **Created**: 2026-08-24

All Technical Context items in `plan.md` are resolved. No NEEDS CLARIFICATION markers remain. This document captures each material decision with rationale + rejected alternatives so downstream `/speckit.tasks` and `/speckit.implement` have a stable reference.

---

## Decision 1 — Detection helper shape

**Decision**: `includes/Utilities/LocalEnvironment.php`, static-only class, one public method `needs_tls_bypass(): bool`.

**Rationale**: Mirrors the shape of `includes/Utilities/SiteSlug.php` (the closest existing sibling). Static-only means no lifecycle overhead, no singleton bookkeeping, no hook registration — the method is called inline from render paths that already run inside admin/wizard contexts. Testable without a WP bootstrap by injecting `home_url` and env-type via WordPress filter mocks in PHPUnit.

**Alternatives considered**:

- **Instance/singleton class** — Rejected. No state, no lifecycle. Would add ceremony without value.
- **Filter callback on `home_url` at boot time** — Rejected. Scatters the detection across callers and couples every consumer to a filter's semantic contract. A pure helper is easier to reason about.
- **Method on `AbstractMCPClient` directly** — Rejected. The detection is not specific to clients; the wizard bootstrap payload (`admin/Main.php`) also needs it, so it belongs in `Utilities/`.

## Decision 2 — Single injection point via `AbstractMCPClient::build_env()`

**Decision**: Add `protected function build_env( string $server_url, string $auth_token, array $extra = array() ): array` on `AbstractMCPClient`. All 16 concrete client subclasses delegate to it.

**Rationale**: One place to add the `NODE_TLS_REJECT_UNAUTHORIZED` key. Refactoring the 16 clients to a shared helper is also a net DRY win (constitution §VI) — the current 16-way duplication of the identical `WP_API_URL` / `WP_API_USERNAME` / `WP_API_PASSWORD` array is retired in the same pass.

**Alternatives considered**:

- **Post-processing walker** — Reject. Walk the JSON after `get_config_snippet()` returns and inject the key wherever `env` is found. Brittle: assumes future clients keep the shape. A new client type (e.g., HTTP transport instead of stdio) could carry an `env` key with different semantics; a walker would inject the flag inappropriately.
- **Trait mixed into each client** — Rejected. `AbstractMCPClient` is already the base class; a trait for one method is over-engineering.
- **Static utility method** — Rejected. `build_env` needs access to `$this->safe_token()` and `$this->current_username()` — both instance methods on the base class. A static utility would need to receive those values as params, duplicating logic at each call site.

## Decision 3 — Warning-copy single source of truth in PHP, reached from JSX via `wp_localize_script`

**Decision**: Warning text and doc URL live in a single PHP source (a static method or class constant on `MCPClientsBlock` or `LocalEnvironment`). The admin surface renders it directly; the wizard receives it via `wp_localize_script` under a `tlsBypass` payload key.

**Rationale**: Single source of truth for translatable strings (constitution §II — text domain `acrossai-mcp-manager`). Prevents drift on future copy edits. Avoids duplicating a `__()` call in JSX (which the WP i18n pipeline handles separately from PHP).

**Alternatives considered**:

- **Duplicate copy inline** in both PHP and JSX — Rejected. Two translation entries, guaranteed drift over time.
- **New REST endpoint** returning the copy JSON — Rejected. Extra roundtrip for a static string; also adds a permission_callback surface that must be reviewed.

## Decision 4 — Extensibility via `acrossai_mcp_local_hostname_suffixes` filter (defensive against callable return type)

**Decision**: Ship an `apply_filters( 'acrossai_mcp_local_hostname_suffixes', array( '.local', '.test', '.localhost' ) )` call inside `LocalEnvironment::needs_tls_bypass()`. Wrap the return value in an `is_array()` check; fall back to defaults if a bad filter callback returns a non-array (spec FR-010, SC-005).

**Rationale**: Ops-team extensibility per spec §Clarifications Q2. Code-level filter (not admin toggle) preserves FR-007. Defensive `is_array()` guard prevents plugin fatals from a buggy third-party callback.

**Alternatives considered**:

- **Admin option** — Rejected. FR-007 bars an admin toggle for the underlying feature; extending the surface list via admin would be adjacent and undermine the same safety property.
- **Additional boolean override filter** (`acrossai_mcp_needs_tls_bypass`) — Rejected. Would let a plugin unconditionally enable the flag on a production site. No acknowledged use case; belongs in a future feature request if it ever surfaces.
- **Hardcode the three suffixes** — Rejected. Spec Clarification Q2 chose the filter option; ops teams using `.docker`, `.internal`, `.dev` would otherwise need a plugin PR for each new suffix.

## Decision 5 — Static (non-dismissible) notice on both surfaces

**Decision**: Warning renders unconditionally above the JSON on both the MCP Clients tab and Quick Setup Step 11 whenever `needs_tls_bypass()` is true. No dismiss button, no user-meta flag, no AJAX endpoint (spec FR-011).

**Rationale**: The site's local-vs-live status is a permanent property, not a one-time event. Dismissing the notice invites forgetting that the insecure flag is baked into every copied JSON. Storage-free implementation avoids introducing a user_meta key + dismiss endpoint + capability check that the feature would otherwise not need (constitution §III surface-minimization).

**Alternatives considered**:

- **Dismissible per-user via `user_meta`** — Rejected. Adds a persistence write, an AJAX endpoint (which needs a nonce and capability check), and undermines the safety goal — an operator who dismisses once still sees the flag in every future copied JSON, without any surface reminding them why.
- **Dismissible with an auto-re-appear rule** (e.g., re-appears when a new client is added) — Rejected. Adds complexity, still shares the "operator forgets" failure mode.

## Decision 6 — Detection scoping: `local` or `development` env types only (staging excluded)

**Decision**: `wp_get_environment_type() === 'staging'` alone does NOT trigger the injection. Only `local`, `development`, or a matching local-looking host suffix does.

**Rationale**: Spec §Clarifications Q1. Staging is close to production semantically; a staging box with a self-signed cert is a config bug for ops to fix, not something the plugin should silently paper over. The host-suffix rule still catches staging sites deployed on `.local` or `.test` hostnames, which is the ecosystem's dev-local pattern anyway.

**Alternatives considered**:

- **Include `staging` env-type** — Rejected per spec clarification.
- **Include `staging` env-type ONLY when host also matches suffix** — Rejected as redundant: the host-suffix rule catches this case independently of env-type.

## Non-decisions (already covered by spec)

- **Sites on plain HTTP**: Explicit in FR-009 — never fire. Not a new decision.
- **Multisite**: `home_url()` returns the per-subsite URL, so detection is correct per subsite without special handling.
- **Live HTTPS with invalid cert**: Explicit in spec §Assumptions and §Out-of-scope — user must fix the cert; feature 075 does not paper over this.
