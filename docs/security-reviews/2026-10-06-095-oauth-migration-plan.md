---
document_type: security-review
review_type: plan
assessment_date: 2026-10-06
codebase_analyzed: acrossai-mcp-manager (specs/095-oauth-migration)
total_files_analyzed: 9
total_findings: 6
overall_risk: MODERATE
critical_count: 0
high_count: 0
medium_count: 2
low_count: 2
informational_count: 2
owasp_categories: [A01, A04, A05, A09]
cwe_ids: [CWE-1188, CWE-532, CWE-348, CWE-665]
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

# Security Review — F095 OAuth + AI Connectors Migration (Plan)

## Executive Summary

**Overall risk: MODERATE. No Critical or High findings.**

This is a lift-and-shift of an OAuth 2.1 implementation that has already been through two security reviews (F032 plan + tasks, 2026-07-21) and a SEC-L1 remediation. The protocol design is sound and the plan preserves it deliberately rather than refactoring it mid-move — the right call for a security surface.

The security story of this feature is not the protocol. It is that **a credential-issuing surface moves from a paid plugin behind a licence gate into a free plugin distributed on wordpress.org**. The code is unchanged; the population exposed to it grows by orders of magnitude. Two of the six findings follow directly from that change of context, not from any defect in the code.

Two findings were downgraded after verification rather than left as assumptions:

- The upgrade window was initially suspected of being an authentication gap. `TokenValidator::authenticate()` returns the incoming `$user_id` on **every** failure branch, including a digest miss — it fails closed. The window is availability loss, not bypass.
- The dormant-companion handover was suspected of nondeterminism. It is sound in the expected configuration, but rests on an assumption worth pinning with a test.

**No blocking findings.** Implementation may proceed; SEC-001 and SEC-002 should be addressed within this feature.

## Plan Artifacts Reviewed

| Artifact | Notes |
|---|---|
| `specs/095-oauth-migration/spec.md` | 28 FRs, 11 SCs, 4 clarifications, §Security Checklist |
| `specs/095-oauth-migration/plan.md` | Constitution Check incl. C5 resolution |
| `specs/095-oauth-migration/research.md` | R1–R10 decisions |
| `specs/095-oauth-migration/data-model.md` | 4 tables, migration state |
| `specs/095-oauth-migration/contracts/oauth-endpoints.md` | Endpoint + hook contract |
| `specs/095-oauth-migration/quickstart.md` | 7 verification tests |
| `specs/095-oauth-migration/memory-synthesis.md` | Index-first memory retrieval |
| `docs/memory/INDEX.md` | Decisions, bug patterns, security constraints |
| `.specify/memory/constitution.md` | v1.1.0 |

`.specify/memory/security_constitution.md` does **not** exist — see SEC-005.

---

## Findings

### SEC-001 — Credential-issuing consent surface becomes free-tier default-reachable

- **Severity**: MEDIUM · **CVSS**: 5.3
- **OWASP**: A01:2025 — Broken Access Control
- **CWE**: CWE-1188: Insecure Default Initialization of Resource
- **Location**: `specs/095-oauth-migration/spec.md` FR-013; `plan.md` §C5
- **Spec-Kit task**: TASK-SEC-001

Constitution §III's consent-surface exception requires the surface be *"operator-gated via a default-OFF option so [it] does not exist on a fresh install without explicit operator opt-in."* Today the gate is commercial — the surface exists only where an operator bought and licensed the companion. F095 removes that gate while preserving permissive defaults: `ConnectorSettings::get_server_settings()` seeds every registered profile as enabled, and `require_admin_approval` collapses to false absent legacy values.

Net: on a fresh free install, any logged-in user can complete `/authorize` and obtain a token.

**Why this is MEDIUM, not HIGH.** The blast radius is bounded by design and verified in source: tokens bind to the consenting user and carry **only that user's own capabilities**, so a subscriber's AI client cannot exceed what the subscriber can already do; unrecognised DCR clients always require admin approval regardless of any toggle (`AuthorizationController.php:183-190`); and a connector must be enabled per-server first. This is the standard OAuth self-scoped-credential model, not privilege escalation.

**On the plan's resolution.** `plan.md` C5 argues this is pre-existing rather than new, because the constitution's own named exemplar — `FrontendAuth` — does not satisfy condition 3 either: `Activator.php:147` seeds `acrossai_mcp_npm_login_enabled` to `1` while `FrontendAuth.php:14` still claims default-OFF. That reasoning is factually correct and the conclusion is defensible. **It does not make the finding go away.** "The existing exemplar is also non-compliant" explains why F095 should not be singled out; it does not establish that the posture is right for a surface about to ship to a much larger population.

**Risk accepted 2026-10-06 — explicitly and by decision, not by default.** The product owner confirmed the surface is intended to be ON by default: a free plugin whose headline capability ships switched off, requiring the operator to find and enable a setting first, defeats the purpose of making it free. This is a stronger basis than the consistency argument above and supersedes it as the primary justification.

The acceptance is defensible because the risk is bounded **by design rather than by configuration**: the credential is strictly self-scoped, so no privilege is gained by obtaining one; unrecognised DCR clients always require admin approval regardless of any toggle; and operators who want the surface closed already have the per-server `require_admin_approval` control — only its default differs.

**Recommendation**: accept. The reviewer's conclusion is that §III condition 3 is **miscalibrated rather than violated** — it reads as though every consent surface issues a credential that could exceed the consenting user's authority, which is not true here. Treat the two follow-ups in `plan.md` C5 as **required**: the constitution PATCH (so the rule stops flagging compliant designs) and the `FrontendAuth.php:14` correction (a docblock making a false security claim that reviewers rely on).

---

### SEC-002 — Migration failure diagnostics may disclose credential material

- **Severity**: MEDIUM · **CVSS**: 4.3
- **OWASP**: A09:2025 — Security Logging and Monitoring Failures
- **CWE**: CWE-532: Insertion of Sensitive Information into Log File
- **Location**: `spec.md` FR-012; `data-model.md` §Migration state
- **Spec-Kit task**: TASK-SEC-002

FR-012 requires that on repeated failure the migration surface *"a Site Health critical issue naming the affected table and the cursor position"* plus a matching error-log entry. The migration moves `token_hash`, `code_hash` and `client_secret_hash`. A naive implementation logging the failing row, the `$wpdb` error with the offending statement, or an exception carrying query context would write credential digests to `debug.log` and potentially to a Site Health panel.

Memory records this exact hazard: `D39` flags SEC-007 log disclosure as a known trade-off of per-listener isolation, since exception messages land in the PHP error log.

**Recommendation**: FR-012 should state explicitly that diagnostics carry **only** table name, cursor position and row count — never row contents, column values, or raw `$wpdb->last_query`/`last_error` output. Add a test asserting no 64-character hex string appears in the diagnostic payload.

---

### SEC-003 — Upgrade window causes authentication loss (availability)

- **Severity**: LOW · **CVSS**: 3.7
- **OWASP**: A04:2025 — Insecure Design
- **CWE**: CWE-665: Improper Initialization
- **Location**: `research.md` R1; `spec.md` FR-011, Edge Cases
- **Spec-Kit task**: TASK-SEC-003

Migration runs admin-side only. WordPress does not fire activation hooks on update, so on a site updated by auto-update or WP-CLI nothing migrates until someone loads wp-admin — while the companion has already stood down. Connected clients cannot authenticate during that window.

**Verified fails closed.** `TokenValidator::authenticate( $user_id )` returns the incoming `$user_id` on every failure branch — missing token, digest miss at `find_by_hash` (`:82-84`), expiry, revocation, audience mismatch. It never grants on error. An empty token table therefore yields unauthenticated, i.e. `401`, not a bypass. This is **availability loss, not an authentication gap** — which is why it is LOW rather than HIGH.

The spec documents this as an accepted risk with release-note mitigation. That is a legitimate engineering trade-off, consciously made.

**Recommendation**: no change. Ensure the release note is written — it is the entire mitigation.

---

### SEC-004 — Handover determinism depends on autoloader timing

- **Severity**: LOW · **CVSS**: 3.1
- **OWASP**: A04:2025 — Insecure Design
- **CWE**: CWE-665: Improper Initialization
- **Location**: `acrossai-pro/includes/Main.php:1190`; `spec.md` FR-006, SC-007
- **Spec-Kit task**: TASK-SEC-004

The companion stands down via `class_exists( '\AcrossAI_MCP_Manager\Includes\OAuth\AuthorizationController' )`. `class_exists()` triggers autoloading, and this plugin registers its Jetpack autoloader when its own entry file is required (`acrossai-mcp-manager.php:62`). If the companion evaluated the probe at file-load time *before* this plugin loaded, the probe would return false and both plugins would register OAuth — producing duplicate routes and nondeterministic handler precedence, where one reads the new tables and the other the old.

In the expected configuration this is safe: the probe is evaluated from `Main` during `plugins_loaded`, by which point all plugin files are required and both autoloaders registered; and `acrossai-mcp-manager` sorts before `acrossai-pro` alphabetically in `active_plugins`. Two assumptions, neither pinned by a test.

**Recommendation**: SC-007 already requires "every route, tab, scheduled event and rewrite rule registered exactly once." Strengthen the quickstart to assert it under a **reversed `active_plugins` order**, which is the configuration that would expose the assumption.

---

### SEC-005 — No security constitution exists

- **Severity**: INFORMATIONAL
- **Location**: `.specify/memory/security_constitution.md` (absent)

The security-review extension expects a `security_constitution.md`; only `constitution.md` exists. Security rules are consequently spread across §III, the `S1`–`S9` rows in `docs/memory/INDEX.md`, and `PROJECT_CONTEXT.md`. This review reconstructed them from those sources.

**Recommendation**: consider `/speckit.security-review.init` as its own task. Not a blocker for F095.

---

### SEC-006 — Rate limiter trusts a filterable proxy header

- **Severity**: INFORMATIONAL
- **OWASP**: A05:2025 — Security Misconfiguration
- **CWE**: CWE-348: Use of Less Trusted Source
- **Location**: `acrossai-pro/includes/OAuth/Security/RateLimiter.php:70`

Client-IP resolution for rate limiting honours `acrossai_mcp_manager_trusted_proxies`. On a site behind no proxy, or one misconfigured to trust an attacker-controllable header, per-IP limits on the token and registration endpoints can be evaded by varying the forwarded header.

Pre-existing and imported unchanged; the filter defaults to an empty trust list, so the default posture is correct. Noted because the free tier widens the population running it with default configuration.

**Recommendation**: no change in F095. Document the filter's security significance when connector documentation is written.

---

## Confirmed Secure Patterns

These are design strengths worth protecting through the move — several exceed what the three reference MCP plugins implement:

- **No credential material at rest beyond digests.** Tokens stored only as `char(64)` SHA-256 (`data-model.md`); the raw token exists solely on the client. A stolen database yields nothing replayable. Satisfies §III and `S3`.
- **No signing-key material at all.** The hand-rolled opaque-token design was retained deliberately over `league/oauth2-server`, which would require an RSA private key in `wp_options` (`research.md`, `plan.md` §Complexity Tracking). This avoids an entire class of key-disclosure risk.
- **Instant revocation.** Opaque tokens validated by DB lookup revoke immediately — unlike self-contained JWTs, which remain valid until expiry absent a blocklist.
- **Token-family revocation** (RFC 9700 §2.2.2) via `token_family_id`, preserved by FR-008.
- **Fails closed.** `TokenValidator::authenticate()` returns unauthenticated on every error path.
- **Tenant binding enforced**, with forensic stream separation: `acrossai_mcp_oauth_cross_server_attempted` is reserved for genuine bypass attempts and deliberately not fired by the legitimate site-wide revoke (`D31`, `D34`).
- **Admin self-bypass is auditable** via a distinct action (`D32`, SEC-L1 remediation) — resolves the `B38` ambiguity where `approved_by === user_id` is otherwise indistinguishable from reviewer approval.
- **Unrecognised DCR clients always require admin approval**, documented as *"Higher-risk category → hard requirement, not opt-in."*
- **Mandatory PKCE S256**, single-use codes under atomic CAS (`B10`), audience binding, correct `WWW-Authenticate` challenge.
- **Contract preservation is treated as a security property**, not just compatibility — `contracts/oauth-endpoints.md` grep-gates the identifier surface before and after.

---

## Action Plan & Next Steps

1. **Address within F095**: SEC-001 (follow-ups made required, not optional) and SEC-002 (FR-012 wording + test).
2. **Verification strengthening**: SEC-004 — add a reversed-plugin-order case to quickstart Test 3.
3. **Separate work**: SEC-005 (`/speckit.security-review.init`); the two C5 follow-ups (constitution PATCH; correct `FrontendAuth.php:14`).
4. **Remediation planning**: not required — no Critical or High findings, so `/speckit.security-review.followup` is optional here.
5. **Durable Memory Preservation**: this review produced reusable patterns — the "licence gate is not an operator gate" lesson, and the fails-closed verification approach. Capture via `/speckit.memory-md.capture` at the end of the chain, per the user's instruction to defer it until after planning.

---

## Memory Hub INDEX.md Row

```text
| docs/security-reviews/2026-10-06-095-oauth-migration-plan.md | plan | 2026-10-06 | MODERATE | C:0 H:0 M:2 L:2 | A01,A04,A05,A09 |
```
