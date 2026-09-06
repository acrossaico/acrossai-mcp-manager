---
document_type: security-review
review_type: plan
assessment_date: 2026-09-05
codebase_analyzed: acrossai-mcp-manager (F082 planning artefacts)
total_files_analyzed: 6
total_findings: 6
overall_risk: MODERATE
critical_count: 0
high_count: 0
medium_count: 1
low_count: 3
informational_count: 2
owasp_categories: [A01, A03, A04, A05, A09]
cwe_ids: [CWE-89, CWE-266, CWE-284, CWE-829, CWE-863, CWE-1188]
field_summaries:
  document_type: "Always 'security-review'. Allows indexers to skip non-review documents."
  review_type: "Which command generated this document: audit, branch, staged, plan, tasks, or followup."
  assessment_date: "ISO 8601 date the review was performed (YYYY-MM-DD)."
  overall_risk: "Highest severity tier with active findings (CRITICAL, HIGH, MODERATE, LOW, INFORMATIONAL)."
  critical_count: "Number of Critical findings (CVSS 9.0-10.0)."
  high_count: "Number of High findings (CVSS 7.0-8.9)."
  medium_count: "Number of Medium findings (CVSS 4.0-6.9)."
  low_count: "Number of Low findings (CVSS 0.1-3.9)."
  informational_count: "Number of Informational findings."
  owasp_categories: "OWASP Top 10 2025 categories (A01-A10) that have at least one finding."
  cwe_ids: "CWE identifiers referenced in this document."
  finding_id: "Unique finding identifier (SEC-NNN) for cross-referencing and task linkage."
  location: "File path and line number of the vulnerable code (path/to/file.ext:line)."
  owasp_category: "OWASP Top 10 2025 category for this finding (AXX:2025-Name)."
  cwe: "Common Weakness Enumeration identifier with short name (CWE-NNN: Name)."
  cvss_score: "CVSS v3.1 base score (0.0-10.0). 9.0+=Critical, 7.0-8.9=High, 4.0-6.9=Medium, 0.1-3.9=Low."
  spec_kit_task: "Spec-Kit task ID for backlog tracking and remediation follow-up (TASK-SEC-NNN)."
---

# Security Review — Plan Phase: F082 Per-server Ability Policy Defaults

## Executive Summary

F082 introduces a tri-state server-level ability policy plus a resolver split (`ExposureResolver::resolve()` frozen row-only for F030's permission-callback bypass; new sibling `resolve_effective()` for the three-tier priority) and lands the new column via BerlinDB's D28 3-part contract. The plan is **fundamentally sound**: it correctly identifies the load-bearing security invariant (F030's row-only bypass), designs a safe extension shape, and locks the invariant with a merge-blocker regression fence and a review-gate comment.

**Overall risk: MODERATE** — 0 Critical, 0 High, 1 Moderate, 3 Low, 2 Informational.

The single Moderate finding is that the F030-invariant preservation is **process-only** (comment + regression test + spec FR) rather than runtime-enforced. This is an inherent limitation of the "add-a-sibling" pattern; the review recommends at least one runtime-detectable safety net beyond the process gates.

Compared to F017's plan review (HIGH, 2026-07-07) — which addressed the same subsystem — F082 comes in one tier lower because F017's fixes (capability gating, resolver caching, DataViews pattern) are inherited and F082 does not reopen the same weaknesses.

## Plan Artifacts Reviewed

| Path | Purpose | Read Fully |
|---|---|---|
| `specs/082-ability-policy-defaults/spec.md` | Feature spec, 5 stories, 19 FRs, 7 SCs, Clarifications | Yes (in context from `/speckit-specify` + `/speckit-clarify`) |
| `specs/082-ability-policy-defaults/plan.md` | Implementation plan, Constitution Check, phase plan, risks | Yes (in context) |
| `specs/082-ability-policy-defaults/memory-synthesis.md` | 880-word synthesis, 5 decisions, 5 arch, 3 bugs, 3 deviations, 3 security, 2 worklog | Yes |
| `specs/082-ability-policy-defaults/security-constraints.md` | Inline artefact from `/speckit-architecture-guard-governed-plan` (trust boundaries, F030 mitigation, concurrency) | Yes |
| `docs/planings-tasks/082-per-server-ability-policy-defaults.md` | 1201-line companion planning brief, TASK-1..10, CONSTRAINTS, evidence template | Yes (in context) |
| `.specify/memory/constitution.md` v1.1.0 | 7 principles, §III Security First (NON-NEGOTIABLE) | Yes |
| `docs/memory/INDEX.md` | 232-line memory hub, S1–S9 constraints, prior security-review roll-up | Yes (via §Security Constraints + §Security Reviews sections) |

Not reviewed (intentionally absent for F082): `research.md`, `data-model.md`, `quickstart.md`, `contracts/` — the spec's Requirements/Storage/REST-Contract sections plus the companion planning brief cover the same ground; plan.md §Phase Plan documents this decision.

No dedicated `security_constitution.md` exists in this project — `constitution.md` §III + memory hub S1–S9 rows are the security-constitution surface.

## Vulnerability Findings

### SEC-001 — F030 row-only invariant preservation is process-only, not runtime-enforced

- **finding_id**: SEC-001
- **location**: `includes/Database/MCPServerAbility/ExposureResolver.php` (TASK-4) + `includes/Abilities/PermissionOverrideProcessor.php:150` (TASK-4 comment) + `tests/phpunit/Database/ExposureResolverTest.php::test_resolve_still_row_only_for_f030()` (TASK-9)
- **owasp_category**: A01:2025-Broken Access Control
- **cwe**: CWE-863: Incorrect Authorization
- **cvss_score**: 5.3 (MODERATE — CVSS:3.1/AV:N/AC:H/PR:H/UI:N/S:U/C:H/I:L/A:N; exploitability requires a future contributor code-change that widens `resolve()`, not a runtime attack path)
- **spec_kit_task**: TASK-SEC-001

**Description**: The plan defends the F030 row-only bypass invariant with three process gates:
1. FR-007 + SC-005 in the spec (normative + merge-blocker).
2. A 6-line review-gate comment above the `resolve()` call at `PermissionOverrideProcessor.php:150`.
3. A merge-blocker regression fence test `test_resolve_still_row_only_for_f030()` with a hazard-naming assertion message.

None of these enforces the invariant **at runtime**. A future contributor who:
- deletes/renames/silences the regression test,
- removes the review-gate comment during a "code cleanup",
- adds a third sibling method (`resolve_effective_v2()`) and migrates the F030 call site to it,

…defeats every gate simultaneously. The invariant then fails silently on every install that ships that change. This is exactly the B32-class failure mode ("filter defaults that gate security decisions") the plan cites as its own inspiration.

**Impact**: If the invariant is defeated, the F030 permission-callback bypass silently widens to every ability on any `policy='expose'` server (D30 explicitly notes this). Real-world blast radius: any operator who ever clicks Enable All grants unauthenticated tool-call access to every registered ability on that server. This is the exact scenario D29 + D30 + FR-007 exist to prevent.

**Mitigation options** (pick at least one; both are stronger together):

- **Option A (rename, most fail-loud)**: Rename `resolve()` → `resolve_row_only()`. F030's call site MUST update, and the new name is grep-visible everywhere. Cost: it's a public-API surface break; existing consumers (if any beyond F030) need updating.
- **Option B (self-attesting constant)**: Add `ExposureResolver::F030_LOCKED_METHOD = 'resolve';` and inside `PermissionOverrideProcessor::should_bypass()` add `assert( method_exists( ExposureResolver::class, self::F030_LOCKED_METHOD ), 'F030 hazard: resolve() method renamed' );`. Any rename fatals on the assertion. Cost: PHP assertions are typically disabled in production; use a plain `if ( ! method_exists ) throw new \RuntimeException()` instead.
- **Option C (caller allow-list)**: Inside `ExposureResolver::resolve()`, use `debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 3 )` to detect callers, `_doing_it_wrong` (or `wp_trigger_error`) if the caller is neither `PermissionOverrideProcessor::should_bypass()` nor a test class. Cost: performance overhead on the hot path; only viable behind a `WP_DEBUG` gate.

**Recommendation**: Adopt **Option A** as the primary mitigation. It is the most grep-visible, has zero runtime overhead, and codifies the invariant in the method name itself. If Option A is rejected on API-stability grounds, fall back to **Option B** with `throw new \RuntimeException` (not `assert`).

Document the chosen mitigation in the plan §Risks table and add a new task (**TASK-SEC-001**) to implement it.

**SEC-001 addendum (2026-09-05, governed-implement pre-flight discovery)**: this review estimated the rename blast radius at 1-2 production call sites (F030 + the F017 gate). An implementation-time grep revealed the true caller count is **5 production callers plus 6 test callers**. The additional production callers are:
- `includes/Database/MCPServer/AbilityDiscovery.php:82` (F026 advertisement-time enumeration)
- `includes/Abilities/AbilityHelpers.php:79` (F026 `apply_exposure_filter` composer — explicitly cited in D24)
- `includes/REST/AbilitiesController.php:278 + :284` (was/now snapshots for the per-pair `acrossai_mcp_ability_exposure_changed` action)
- `includes/REST/QuickConnectController.php:788` (Quick-Connect flow, via aliased namespace)

Per D24 defense-in-depth (advertisement-time = effective exposure), all four additional callers migrate to `resolve_effective()`; only F030 stays on `resolve_row_only()`. This does not change the Moderate severity — the security invariant (F030 stays row-only) is unchanged, but the scope of the rename PR is larger than initially estimated. Impact on downstream tasks captured in `specs/082-ability-policy-defaults/tasks.md` T007c (production caller sweep) + T008a (test caller migration) — scope amendment banner added to both `tasks.md` and the companion planning brief.

---

### SEC-002 — Server-delete cascade audit is deferred to tasks-time preflight grep

- **finding_id**: SEC-002
- **location**: TASK-2b (server-delete cascade, spec FR-018) — file path not yet resolved
- **owasp_category**: A01:2025-Broken Access Control (data-lifecycle hygiene, not direct AC violation)
- **cwe**: CWE-284: Improper Access Control
- **cvss_score**: 4.3 (LOW-MODERATE — CVSS:3.1/AV:N/AC:H/PR:H/UI:N/S:U/C:L/I:L/A:N; requires server-id reuse to produce user-visible confusion)
- **spec_kit_task**: TASK-SEC-002

**Description**: FR-018 requires a cascade DELETE on every server-delete code path. Plan.md acknowledges "Location TBD — probably admin/Partials/MCPServerListTable.php or a controller under includes/REST/; tasks.md must resolve the exact file(s) via a preflight grep before implementation." Multiple code paths likely exist (list-table row action, bulk action, REST controller, WP-CLI, admin AJAX). If the preflight grep misses one, that path leaves orphan override rows.

**Additional concerns not captured in the plan**:
- **F032 co-located cascade**: F032 shipped `server_id` first-class on 3 OAuth tables. Does F032's server-delete flow already cascade? If yes, F082 must co-locate. If no, F082 is inventing the first server-cascade pattern and should design for extension (a `do_action( 'acrossai_mcp_server_deleting', $server_id )` hook that both F082 and future features subscribe to, matching D32's connector-revoke-cascade shape).
- **Concurrent policy POST + server delete**: If admin A opens the policy POST while admin B deletes the server, `MCPServer\Query` mid-flight may SELECT successfully and then UPDATE a row that's about to be deleted. Not a security defect but a data-lifecycle race.

**Impact**: Orphan rows in `acrossai_mcp_server_abilities` after server deletion. On server-ID reuse (rare but not impossible on aggressive DB-reset workflows), `has_override` counters and `resolve_effective()` behaviour become confusing.

**Recommendation**: Expand TASK-2b in `/speckit-tasks` to:
1. Enumerate **every** server-delete code path (grep: `DELETE FROM.*acrossai_mcp_servers`, `delete_item.*mcp_servers`, `Query.*delete.*server`).
2. Cross-reference F032's server-delete cascade path (if it exists) and co-locate.
3. Prefer a `do_action( 'acrossai_mcp_server_deleted', $server_id )` hook (or extend F032's if it already exists) so future features can subscribe without editing the delete code path each time (D5 extensibility).
4. Add a PHPUnit test asserting `SELECT COUNT(*) FROM acrossai_mcp_server_abilities WHERE server_id = <deleted_id>` = 0 after each delete path.

---

### SEC-003 — Policy transitions have no persistent audit trail

- **finding_id**: SEC-003
- **location**: `includes/REST/AbilitiesController.php::post_policy()` (TASK-6)
- **owasp_category**: A09:2025-Security Logging & Monitoring Failures
- **cwe**: CWE-266: Incorrect Privilege Assignment (adjacent — accountability gap)
- **cvss_score**: 3.1 (LOW — CVSS:3.1/AV:N/AC:L/PR:H/UI:N/S:U/C:N/I:L/A:N)
- **spec_kit_task**: TASK-SEC-003

**Description**: `acrossai_mcp_server_policy_changed` fires with `$user_id` and `$affected_slugs`, but there is no persistent audit-log row written by the plugin itself. Subscribers who are not listening at that moment miss the transition. There is no DB history of "server X flipped from `per-ability` → `expose` by user Y at time T".

The pre-flight attestation notes dev/local-only install, so this is acceptable for the current merge scope. But once a production install exists (spec Assumptions explicitly acknowledges the attestation is time-bounded to merge), the accountability gap becomes real.

**Impact**: In a future production scenario, an operator who is compromised (or a rogue admin) can flip a server's policy to `expose`, take actions, and flip back to `per-ability` — with no persistent record beyond the mid-transition action fire. If no audit subscriber is registered, the transition is unforensicable.

**Recommendation**:
- **Document explicitly** in the release note and `docs/planings-tasks/082-*.md` §Assumptions that policy transitions are observable only through the action fire — no persistent record.
- **Update D51's memory-capture body** to include the trade-off: "no persistent audit; subscribers must be registered". This makes the accountability gap discoverable to future operators.
- **Optionally** (deferred to follow-up): add an `acrossai_mcp_server_policy_history` table row per transition, with columns (server_id, old_policy, new_policy, user_id, changed_at, affected_slugs_json). Not required for F082; recommended as F082's D51 says "when this pattern is used, a persistent audit sibling is often the natural next feature".

---

### SEC-004 — Confirm-modal generic copy relies on time-bounded dev-only attestation

- **finding_id**: SEC-004
- **location**: `src/js/abilities.js` (TASK-7 Delta 2/3 — Enable All / Disable All modal)
- **owasp_category**: A04:2025-Insecure Design (process gap, not code defect)
- **cwe**: CWE-829: Inclusion of Functionality from Untrusted Control Sphere (adjacent — the trust boundary is time-bounded)
- **cvss_score**: 3.7 (LOW — CVSS:3.1/AV:N/AC:H/PR:H/UI:R/S:U/C:N/I:L/A:N)
- **spec_kit_task**: TASK-SEC-004

**Description**: Spec Clarifications Q3 answered with a `<Modal>` using generic copy (not an override enumeration). The dev/local-only attestation provides cover: "no site outside `~/local-sites/` runs this plugin against real MCP server data." But the attestation window is bounded — "2026-09-04 → Feature 082 merge." Between attestation and merge, if a new production install appears, the "clear all overrides without listing them" behaviour is riskier than a modal that lists what's about to be nuked.

**Impact**: Operator confusion at best; unintended override deletion at worst. Not a security exploit — the operator already has `manage_options` and consciously clicked Enable All — but a data-loss risk.

**Recommendation**:
- Add a **release-checklist gate** (not just a doc note) in the F082 merge PR template: "Confirm no new production installs have appeared since 2026-09-04 attestation."
- If a production install has appeared, upgrade to Option C (spec Clarifications Q3 alt): `<Modal>` that lists the exact overrides about to be cleared. This is a small React change; not urgent for the current merge.

---

### SEC-005 — `%i` identifier placeholder in fallback DELETE (mitigated, verify at implementation)

- **finding_id**: SEC-005
- **location**: `includes/REST/AbilitiesController.php::post_policy()` (TASK-6 step 6, fallback DELETE)
- **owasp_category**: A03:2025-Injection
- **cwe**: CWE-89: SQL Injection
- **cvss_score**: 2.4 (LOW — CVSS:3.1/AV:N/AC:H/PR:H/UI:N/S:U/C:L/I:N/A:N; requires WP < 6.2 or a buggy 6.2/6.3 host)
- **spec_kit_task**: TASK-SEC-005

**Description**: Plan.md says the overrides-clearing DELETE uses `$wpdb->prepare( "DELETE FROM %i WHERE server_id = %d", $table, $server_id )` as the fallback (if BerlinDB's `Query::delete_where` doesn't exist). `%i` for identifiers is WordPress 6.2+. Constitution says WP 6.9+ minimum, so this is fine on paper. Some early WP 6.2/6.3 versions had `%i` regression bugs; a plugin operating on a stale-host WP 6.2.0 could hit them.

**Recommendation**: Verify at implementation time that `%i` is already in use elsewhere in the plugin (grep: `grep -rEn "%i" includes/`); if yes, the pattern is already-adopted and safe. If no, prefer BerlinDB's `Query::delete_where` (if it exists) or interpolate the identifier through a hardcoded constant table name (defence-in-depth against dynamic identifier concerns). Do not accept a `%i` DELETE as the sole write path without verifying WP-version compatibility on the hosting matrix.

---

### SEC-006 — `affected_slugs` map may leak ability inventory to subscribers

- **finding_id**: SEC-006
- **location**: `includes/REST/AbilitiesController.php::post_policy()` action fire (TASK-6 + spec Clarifications Q2)
- **owasp_category**: A05:2025-Security Misconfiguration (adjacent — information exposure to subscribers)
- **cwe**: none directly applicable; loosely CWE-200
- **cvss_score**: not scored (INFORMATIONAL)
- **spec_kit_task**: TASK-SEC-006

**Description**: The `acrossai_mcp_server_policy_changed` action carries a map of every ability slug + its before/after state. Subscribers (audit-log integrations, observability collectors, external log-shippers) receive the full inventory of registered ability slugs and their exposure states. On installs with sensitive/internal abilities registered by a mu-plugin, a compromised audit-log destination sees the whole surface.

Not a plugin defect — subscribers already need `manage_options`-level PHP-process trust. Worth flagging so operators choosing an audit-log integration understand what's shipped in the payload.

**Recommendation**:
- Add a one-line note to the release-note bullet in `README.txt`: "Policy-transition audit events carry the affected ability slugs and their exposure states — choose audit-log integrations you trust."
- Include the same note in D51's memory-capture body.

## Confirmed Secure Patterns

- **Column default `'per-ability'` is the safe choice** (CWE-1188 avoided). Fresh installs and upgraded installs both start with the least-privileged policy. Adopting the column on `admin_init@3` is a no-op behaviourally — matches D28 3-part contract + memory synthesis's arch constraints.
- **3-priority slot map preserved** (F015=10, F017/F082=20, F020=30). D18 + DEC-F020-TOOL-ENFORCEMENT-PRIORITY correctly honored. F015 and F020 gates are byte-for-byte untouched. Deny-precedence via `is_wp_error($result)` short-circuit maintained.
- **`permission_callback` correct everywhere**. `permission_check()` shared helper gates on `current_user_can('manage_options')` for every mutating F082 surface (S2 + constitution §III). No `__return_true` on mutating routes.
- **Argument validation via WP REST schema `enum`**. The new policy string is validated via `enum: [ 'per-ability', 'expose', 'hide' ]` in the route registration — 400 on any other value before the handler runs.
- **`sanitize_callback: absint`** on `server_id` prevents any non-integer coercion issues.
- **Per-request caches, not persistent**. Both new caches in `ExposureResolver` are per-request statics with test-reset hooks (`_reset_cache_for_tests()`). No cross-request pollution risk; no cache-poisoning surface.
- **Additive-only public API**. No REST route renamed, no filter renamed, no column dropped, no method signature changed. Companion plugins subscribed to `acrossai_mcp_ability_exposure_changed` continue to work unchanged (FR-012).
- **Client-side merge deletion enforced same-commit as GET augment**. TASK-6 (server-truth GET) + TASK-7 (delete client-side merge) MUST ship together per companion brief CONSTRAINTS. Prevents the "tab lies on `policy='expose'` servers" transient bug.
- **No-op policy transitions suppressed** (FR-015). Prevents audit-log spam and avoids re-issuing the DELETE for an unchanged policy.

## Action Plan & Next Steps

**Recommended tasks to add to `tasks.md` (via `/speckit-tasks`)**:

| Task | Priority | Description |
|---|---|---|
| **TASK-SEC-001** | REQUIRED | Adopt a runtime-detectable safety net for the F030 invariant. **Recommended: Option A (rename `resolve()` → `resolve_row_only()`)**. Fallback: Option B (self-attesting constant + `throw new \RuntimeException` if renamed). Document choice in plan.md §Risks. |
| **TASK-SEC-002** | REQUIRED | Expand TASK-2b to enumerate every server-delete code path via preflight grep; cross-reference F032's cascade; prefer a `acrossai_mcp_server_deleted` action-hook pattern. |
| **TASK-SEC-003** | RECOMMENDED | Document "no persistent audit trail" in release note + D51 capture body. Optionally scope a follow-up feature for `acrossai_mcp_server_policy_history` table. |
| **TASK-SEC-004** | RECOMMENDED | Add release-checklist gate: "no new production installs since 2026-09-04 attestation" as a merge blocker. |
| **TASK-SEC-005** | REQUIRED-AT-IMPL | Verify `%i` fallback DELETE is already-adopted-pattern; prefer BerlinDB `delete_where` if available. |
| **TASK-SEC-006** | RECOMMENDED | One-line note in release note about `affected_slugs` payload; same note in D51 capture body. |

**No Critical or High findings — no `/speckit-security-review-followup` required.** All findings are addressable pre-implementation by expanding tasks.md; none require a plan revision.

**Durable Memory Preservation** (mandatory check):

One reusable security pattern surfaced during this review: **"Runtime-detectable safety net for security invariants preserved across a resolver split"** — the F030 fence-vs-runtime-enforcement tradeoff (SEC-001) is likely to recur any time a resolver / gate / permission-callback is split into siblings. Candidate durable-memory entry:

> **DEC-RESOLVER-SPLIT-RUNTIME-INVARIANT-SAFETY-NET (proposed)**: When splitting a security-critical resolver / gate / permission callback into siblings where one caller's semantics MUST NOT drift, add at least one runtime-detectable safety net beyond process gates (spec FR, review comment, regression test). Preferred pattern: rename the frozen method to a name that broadcasts its semantics (e.g. `resolve()` → `resolve_row_only()`) so any silent widening is grep-visible. Fallback: self-attesting constant + `RuntimeException` on method-name drift. Rationale: process gates all fail simultaneously to any developer inclined to "clean up" the split; runtime enforcement is the only path that survives future contributor pressure. Cross-references: SEC-001 (F082 plan review 2026-09-05), B32 (filter-defaults security regression pattern), D30 (F030 explicit-exposure-only).

**Not proactively firing `/speckit-memory-md-capture` this turn** — F082's post-implementation `/speckit-memory-md-capture-from-diff` (TASK-10 per companion planning brief) is the natural capture point; firing it now would stamp evidence that isn't in the diff yet. If the user wants to formalize the pattern immediately, run `/speckit-memory-md-capture` separately.

---

## Memory Hub INDEX.md Row

```text
| docs/security-reviews/2026-09-05-082-ability-policy-defaults-plan.md | plan | 2026-09-05 | MODERATE | C:0 H:0 M:1 L:3 I:2 | A01,A03,A04,A05,A09 |
```
