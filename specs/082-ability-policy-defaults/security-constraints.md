# Security Constraints: F082 Per-server Ability Policy Defaults

**Feature**: 082-ability-policy-defaults
**Reviewed**: 2026-09-05 (inline lightweight review by `/speckit-architecture-guard-governed-plan`)
**Full review**: run `/speckit-security-review-plan` for the full protocol review; this file is the artefact contract that downstream architecture validation reads.

## Trust boundaries

| Boundary | Direction | Enforcement |
|---|---|---|
| Anonymous WWW → `GET /abilities` | inbound | `permission_check()` → `current_user_can( 'manage_options' )` (S2). Rejects with 403. |
| Anonymous WWW → `POST /abilities/policy` | inbound | `permission_check()` → `current_user_can( 'manage_options' )` (S2). No `__return_true`. |
| Non-admin WP user → `POST /abilities/policy` | inbound | Same capability check; user without `manage_options` is refused. |
| MCP client → tool call | inbound | Existing `mcp_adapter_pre_tool_call` chain: F015 (10) → **F017 gate now `resolve_effective()` (20)** → F020 (30). F015 deny still short-circuits via `is_wp_error( $result )`. |
| Admin browser → policy modal | intra-request | `apiFetch.createNonceMiddleware` (S1); nonce verified by REST core. |

## Authorisation assumptions

- `manage_options` is the site-admin gate for every mutating F082 surface (matches S2, memory D33 for OAuth AC and D31 for OAuth server_id first-class). No consent-surface exception applies (F082 has no browser-mediated user-scoped credential issuance).
- **F030's row-only permission-callback bypass is the load-bearing security invariant.** The row-only method — renamed from `resolve()` to `resolve_row_only()` in this feature per SEC-001 Option A — MUST NOT be widened; F030's call site with empty `$meta` MUST continue to return `false` for any (server, slug) pair without an explicit row. Locked in by FOUR independent gates:
  - **Name-level (fail-loud)**: the row-only method is now `resolve_row_only()`. Any widening happens inside a method whose name broadcasts the semantics; any migration of the F030 call site to a differently-named method is grep-visible in one shot. This shifts the safeguard from process-only to fail-loud-on-rename (addresses SEC-001 from the 2026-09-05 plan-phase security review).
  - FR-007 (spec) — normative requirement.
  - SC-005 (spec) — merge-blocker success criterion.
  - CONSTRAINTS block (companion planning brief) — first item.
  - Merge-blocker regression test `test_resolve_row_only_still_row_only_for_f030()` with a hazard-naming assertion message (TASK-9).
  - Review-gate comment above `PermissionOverrideProcessor.php:150` (TASK-4).

## Data isolation & validation

- **Policy string validation**: `enum: [ 'per-ability', 'expose', 'hide' ]` on the REST arg — 400 on any other value.
- **`server_id` validation**: `sanitize_callback: 'absint'`; missing server → 404 with `acrossai_mcp_server_not_found`.
- **DB writes**: `MCPServer\Query::update_item()` (BerlinDB parameterised); overrides-clearing DELETE uses either BerlinDB `delete_where` or a `$wpdb->prepare( "DELETE FROM %i WHERE server_id = %d", ... )` fallback (S4).
- **Server-delete cascade** (FR-018): PHPUnit-verified `SELECT COUNT(*) WHERE server_id = <deleted_id>` returns 0.

## Async / concurrency

- **Last-write-wins concurrency**: policy POST clears every override unconditionally; concurrent per-pair writes may be silently wiped. Accepted per spec Clarifications Q5. Observable via `acrossai_mcp_server_policy_changed`'s `affected_slugs` map. NOT a race that produces incorrect authorisation state — post-transition state is deterministic and authoritative.
- **Per-request static caches**: `ExposureResolver::$cache`, `$effective_cache`, `$policy_cache`. All three reset by `_reset_cache_for_tests()`. Not persistent — no cross-request pollution risk.
- **No queued jobs, no cron, no async workers.** All policy transitions are synchronous within the REST request.

## Observability

- New action `acrossai_mcp_server_policy_changed( int $server_id, string $old_policy, string $new_policy, array $affected_slugs, int $user_id )` — audit-log subscribers get the full per-slug transition map (Clarifications Q2 shape: `[ slug => [ 'was' => bool, 'now' => bool ] ]`). Never fired on no-op transitions (FR-015).
- Existing per-pair action `acrossai_mcp_ability_exposure_changed` unchanged (FR-012).
- Fail-open observability (D19) not directly relevant — F082 has no "server missing / vendor absent" fail-open path; the gate stays on `resolve_effective()` which resolves deterministically.

## Warnings / open items

- **Warning (soft)**: `security-constraints.md` was produced inline by the governed-plan workflow rather than by `/speckit-security-review-plan`. For a full pre-implementation security review, invoke `/speckit-security-review-plan` separately — it will re-audit the plan against the full Security Constitution and may surface additional constraints not covered here.
- **No high-risk authorisation issues detected.** The F030 hazard is the single explicit risk; it is fully mitigated by the resolver split + regression fence + review-gate comment.

## Cross-references

- Memory synthesis: [memory-synthesis.md](memory-synthesis.md) §Relevant Security Constraints (S2, S4, S6).
- Constitution §III (Security First — NON-NEGOTIABLE): every gate above traces to a §III rule.
- Companion planning brief: `docs/planings-tasks/082-per-server-ability-policy-defaults.md` CONSTRAINTS block + T-NN Evidence Collation Template §4 (F030 fence) + §6 (whole-plugin grep audits).
