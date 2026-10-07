# Security Constraints — F095 OAuth + AI Connectors Migration

Distilled from the plan review (`docs/security-reviews/2026-10-06-095-oauth-migration-plan.md`, MODERATE, 0 Critical / 0 High). These are the constraints implementation must satisfy; consumed by architecture violation-detection and by `/speckit.tasks`.

## Trust boundaries

| Boundary | Assumption |
|---|---|
| Anonymous → `/token`, `/oauth/register`, `.well-known/*` | Public by protocol. Authenticate by protocol means (PKCE, client credentials, DCR), never by WordPress capability. `__return_true` permitted **only** here. |
| Logged-in user → `/authorize` | Consent-surface exception to the `manage_options` minimum. Credential issued is bound to the consenting user and carries only their capabilities. |
| Operator (`manage_options`) → all `/oauth/*` admin routes | Full capability check on every route, no exceptions. |
| Bearer token → MCP tool call | Token must be bound to `server_id`; a token for one server cannot act on another. |
| This plugin ↔ dormant companion | Companion must be inert, not merely deprioritised. No handler may be registered twice. |

## Binding constraints

**SC-C1 — Digests are opaque and immutable in transit.**
The migration MUST copy `token_hash`, `code_hash` and `client_secret_hash` byte-for-byte. No re-hashing, normalisation, re-encoding, trimming or case change. These are the sole server-side record of credentials whose plaintext exists only on the client.

**SC-C2 — Diagnostics MUST NOT disclose credential material.** *(SEC-002)*
Migration failure output — Site Health message and error log alike — carries only table name, cursor position and row count. Never row contents, column values, `$wpdb->last_query`, or `$wpdb->last_error` where it may embed the statement. Test: no 64-character hex string may appear in the diagnostic payload.

**SC-C3 — Authentication MUST fail closed.**
`TokenValidator::authenticate()` currently returns the incoming `$user_id` on every failure branch, including a digest miss. This behaviour MUST be preserved verbatim through the namespace rewrite. A missing or empty token table MUST yield unauthenticated, never a grant.

**SC-C4 — Tenant binding MUST be validated, not merely accepted.** *(`D31`, `B37`)*
Every per-server route requires *and validates* `server_id`. Mismatch → `WP_Error` `acrossai_mcp_oauth_cross_server`, status 403, plus a 4-arg `acrossai_mcp_oauth_cross_server_attempted` that **never includes the owning `server_id`** (SEC-032-001).

**SC-C5 — Forensic streams stay separate.** *(`D34`)*
`/oauth/revoke-client-tokens-all-servers` is a deliberate carve-out. It MUST NOT fire `acrossai_mcp_oauth_cross_server_attempted`; that action is reserved for genuine bypass attempts.

**SC-C6 — Admin self-bypass stays auditable.** *(`D32`, `B38`, SEC-L1)*
The `manage_options` self-approval path at `/authorize` MUST keep firing `acrossai_mcp_connector_admin_self_bypassed`. Without it, `approved_by === user_id` rows are indistinguishable from reviewer approvals.

**SC-C7 — Protocol invariants are preserved, not refactored.**
Mandatory PKCE S256 (downgrade rejected); single-use codes redeemed by atomic CAS (`B10` — never `SELECT` then `UPDATE`); refresh reuse invalidates the whole `token_family_id`; audience binding enforced; `401` carries a correct `WWW-Authenticate: Bearer` challenge; rate limiting active on token and registration endpoints.

**SC-C8 — Mass-assignment guard retained.** *(`B7`)*
Query writers filter against `Schema::columns()` before persisting. The migration writes attacker-influenced historical data and MUST NOT bypass this.

**SC-C9 — Every DB statement uses `$wpdb->prepare()`.**
Including the migration's cross-table copy. Where a dynamic `IN()` clause trips `WordPress.DB.PreparedSQL.InterpolatedNotPrepared`, use `phpcs:ignore` with a defensive comment (`B39`) — never by dropping `prepare()`.

**SC-C11 — Discovery documents must be verified as ours, not merely reachable.** *(SEC-007)*
`.well-known/oauth-authorization-server` and `.well-known/oauth-protected-resource` are registered as `top`-priority rewrite rules, and whichever plugin registers first wins site-wide. `DiscoveryConflictGuard` detects exactly one named competitor (`wp-media/mcp-oauth`, bundled by Rank Math SEO and others) via `class_exists()`. That allow-list does not generalise, and free-tier distribution multiplies co-installation with unguarded competitors. `DiscoveryHealthCheck` MUST assert the served document's `issuer` / `resource` match this plugin's expected values — not merely that the path returns 200. Do not attempt to enumerate competitors.

**SC-C10 — No secret in a non-hashed column.** *(`B20`)*
Grep gate on `Schema.php`: any column named `*_secret`, `*_token`, `*_password`, `*_key` MUST be `char(64)` SHA-256, never `varchar(255)` plaintext.

## Accepted risks

| Risk | Rationale | Mitigation |
|---|---|---|
| **Consent surface reachable by default on free installs** (SEC-001) | **Deliberate product decision, confirmed 2026-10-06: the surface is ON by default.** The plugin exists so a site owner can install it and connect an AI client; shipping the headline capability switched off defeats that. Risk is bounded by design, not by configuration: tokens bind to the consenting user and carry only that user's own capabilities, so no privilege is gained; unrecognised DCR clients always require admin approval regardless of any toggle; and a connector must be enabled per-server first. Supporting: §III condition 3 is equally unmet by `FrontendAuth`, the exemplar the constitution itself names. | Operators who want it closed already have the per-server `require_admin_approval` toggle — the control exists, only its default differs. Two follow-ups are **required, not optional**: constitution PATCH restating condition 3 to apply only where the credential could exceed the consenting user's authority; correct the false default-OFF claim at `FrontendAuth.php:14`. |
| **Upgrade window breaks authentication** (SEC-003) | Admin-side-only trigger, chosen to match five precedents and BerlinDB convention. Verified fails closed — availability loss, not bypass. | Release note instructing operators to load wp-admin once after updating. This is the entire mitigation; it must actually be written. |
| **Rate-limiter proxy-header trust** (SEC-006) | Pre-existing, imported unchanged. Filter defaults to an empty trust list, so default posture is correct. | Document the filter's security significance in connector docs. |

## Status — all recommendations applied 2026-10-06

| Finding | Action taken | Where |
|---|---|---|
| SEC-002 | FR-012 tightened: diagnostics limited to table / cursor / row count; row contents, column values and raw DB error/query text explicitly forbidden. SC-012 added. | `spec.md` FR-012, SC-012 |
| SEC-004 | Quickstart Test 3 gains a reversed `active_plugins` order case with `wp option` steps. | `quickstart.md` Test 3 |
| SEC-007 | FR-016 extended: site-health check MUST compare served issuer/resource against expected values, and MUST NOT enumerate competitors by name. SC-013 added; quickstart Test 4 gains a real-competitor case. | `spec.md` FR-016, SC-013; `quickstart.md` Test 4 |
| SEC-001 | Accepted by explicit product decision; recorded in four artifacts. | `spec.md` §Assumptions, `plan.md` C5, `research.md` R9, this file |
| SEC-003 | Accepted; mitigation is the release note. | `spec.md` Edge Cases, §Assumptions |
| SEC-005, SEC-006 | Informational; tracked as separate work. | — |

## Verification additions

- **SEC-002**: assert no 64-char hex appears in migration diagnostics.
- **SEC-004**: run quickstart Test 3 under a **reversed `active_plugins` order** — the companion's stand-down relies on `class_exists()` resolving after both autoloaders register, which is true in the expected configuration but pinned by nothing.
- **SC-C3**: assert an MCP call against an empty token table returns `401`, not a grant.
- **SC-C11**: install alongside a plugin bundling a competing `.well-known` handler (e.g. one carrying `wp-media/mcp-oauth`) and assert the served discovery document names this plugin's endpoints.
