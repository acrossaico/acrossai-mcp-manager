---
document_type: security-review
review_type: plan
assessment_date: 2026-10-06
codebase_analyzed: acrossai-mcp-manager (specs/095-oauth-migration)
total_files_analyzed: 11
total_findings: 7
overall_risk: MODERATE
critical_count: 0
high_count: 0
medium_count: 3
low_count: 2
informational_count: 2
owasp_categories: [A01, A04, A05, A08, A09]
cwe_ids: [CWE-1188, CWE-532, CWE-348, CWE-665, CWE-441]
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

# Security Review — F095 OAuth + AI Connectors Migration (Plan, v2)

Supersedes [`2026-10-06-095-oauth-migration-plan.md`](./2026-10-06-095-oauth-migration-plan.md). Read that first for the full v1 findings; this pass records only what changed and what is new.

## Executive Summary

**Overall risk: MODERATE. Still no Critical or High findings.** One new Medium (SEC-007), one status change (SEC-001), and four v1 assertions converted from assumption to verified evidence.

v1 was written against the plan as designed. v2 did what v1 did not: **went to the source and checked the claims**. Four controls that v1 listed as "confirmed secure patterns" on the strength of the plan's wording were verified in the companion's code. All four hold. That is worth stating plainly, because a review that asserts a control exists is worth considerably less than one that has looked.

The one genuinely new finding came from an area v1 never opened — `DiscoveryConflictGuard`. It is a well-built defence against **exactly one** named competitor, and F095's whole purpose is to multiply the number of sites running this code alongside arbitrary other MCP plugins.

## Plan Artifacts Reviewed

v1's nine, plus source verification of:

| File | Checked for |
|---|---|
| `acrossai-pro/includes/OAuth/CimdResolver.php` | SSRF on attacker-supplied metadata URLs |
| `acrossai-pro/includes/OAuth/DiscoveryConflictGuard.php` | Discovery-document precedence |
| `acrossai-pro/includes/OAuth/AdminTokenController.php` | CSRF on token issuance |
| `acrossai-pro/includes/OAuth/Security/RateLimiter.php` | Transient-read validation (`B11`) |
| `acrossai-pro/templates/oauth/consent.php` | Output escaping; S9 state sourcing |

---

## New Finding

### SEC-007 — Discovery-document precedence is guarded against one named competitor only

- **Severity**: MEDIUM · **CVSS**: 5.4
- **OWASP**: A08:2025 — Software and Data Integrity Failures
- **CWE**: CWE-441: Unintended Proxy or Intermediary ('Confused Deputy')
- **Location**: `acrossai-pro/includes/OAuth/DiscoveryConflictGuard.php:95-100`
- **Spec-Kit task**: TASK-SEC-007

`.well-known/oauth-authorization-server` and `.well-known/oauth-protected-resource` are how an MCP client discovers **where to send the user to authorize** and **where to exchange codes for tokens**. Both are registered as rewrite rules at `top` priority, and as the guard's own docblock states: *"whichever added its rule first wins for the whole site."*

`DiscoveryConflictGuard` handles this — but detection is `class_exists( self::WPMEDIA_BOOTSTRAP )`, an allow-list of exactly one competitor: `wp-media/mcp-oauth`. The guard's own docblock notes that copy ships bundled inside **Rank Math SEO** and **Enable Abilities for MCP**, booting with no opt-in. Any *other* plugin claiming those paths is unguarded, and the site serves whichever document registered first.

**Why F095 changes the risk rather than inheriting it.** Today this code runs on sites that bought a paid add-on. After F095 it runs on every install of a free wordpress.org plugin, co-installed with arbitrary others — and the MCP plugin ecosystem is crowded (this development install alone carries ~20). The guard's one-competitor allow-list does not generalise, and the probability of meeting an unguarded competitor rises with exactly the distribution change this feature exists to produce.

**Impact.** In the documented `wp-media` case the outcome is functional: clients receive an authorization-server document with no `registration_endpoint` and a protected-resource document naming a server the operator never created, so connectors fail at the OAuth step. The general class is more serious — a discovery document that names a different authorization endpoint directs the user's consent elsewhere. Rated Medium, not High, because it requires another plugin to be installed, the failure is loud rather than silent, and `DiscoveryHealthCheck` (283 LOC) already surfaces `.well-known` problems in Site Health.

**Recommendation**: do not try to enumerate competitors — that is the losing side of an arms race. Instead:

1. Have `DiscoveryHealthCheck` assert the served document is *ours* (fetch and compare `issuer` / `resource` against the expected value), not merely that the path returns 200. This converts an unbounded detection problem into a bounded verification one and catches every competitor including future ones.
2. Add a quickstart case: install this plugin alongside one bundling a competing `.well-known` handler, and assert the served discovery document names this plugin's endpoints.

---

## Status Changes from v1

### SEC-001 — Consent surface reachable by default (MEDIUM) → **formally accepted**

v1 recorded this as accepted on the strength of precedent — the constitution's own exemplar (`FrontendAuth`) is equally non-compliant with §III condition 3. v1 flagged that reasoning as insufficient on its own:

> *"The existing exemplar is also non-compliant" explains why F095 should not be singled out; it does not establish that the posture is right.*

**The acceptance is now explicit and owned** (2026-10-06): the surface is intended to be ON by default, because a free plugin whose headline capability ships switched off defeats the purpose of making it free. Recorded in `spec.md` §Assumptions, `plan.md` C5, `research.md` R9, and `security-constraints.md`.

This is a materially better footing. The residual risk is unchanged and remains acceptable because it is bounded **by design rather than configuration**: the credential is strictly self-scoped, unrecognised DCR clients always require admin approval regardless of any toggle, and the per-server `require_admin_approval` control already exists for operators who want it closed — only its default differs.

**Reviewer position**: §III condition 3 is **miscalibrated, not violated**. It reads as though every consent surface issues a credential that could exceed the consenting user's authority. The constitution PATCH is now required, and its purpose is to stop the rule flagging compliant designs — a security rule that cries wolf gets ignored, which is worse than not having it.

### SEC-002, SEC-003, SEC-004, SEC-005, SEC-006 — unchanged

SEC-002 (diagnostics must not disclose digests) and SEC-004 (handover determinism) remain open and should be addressed as v1 recommended.

---

## Verified Secure Patterns

v1 listed these as design strengths. v2 confirmed them in source — each now has evidence rather than an assertion behind it.

| Control | Evidence | Verdict |
|---|---|---|
| **SSRF defence on CIMD fetch** | `CimdResolver.php:171-175` — `wp_safe_remote_get()` (applies WP's `wp_http_validate_url` private-range and scheme blocking), `redirection => 0`, capped timeout, capped response size; hardening documented at `:19` | ✅ Correct. This is the right construction for fetching an attacker-supplied URL — v1 flagged it as worth checking and it needed no change. |
| **S9 — consent state from server-side store** | `templates/oauth/consent.php` — **zero** `$_GET` / `$_REQUEST` reads; all displayed context resolved server-side from the auth-code record | ✅ Satisfies §III condition 5 / CWE-451 / CWE-441 |
| **Consent-surface CSRF + escaping** | `consent.php:145` `wp_nonce_field( 'acrossai_mcp_manager_oauth_authorize' )`; 14 escaping calls across the template | ✅ Satisfies S1 and §III |
| **n8n token issuance defence-in-depth** | `AdminTokenController.php:96` `wp_verify_nonce( $nonce, 'wp_rest' )` **and** `:104` `current_user_can( 'manage_options' )` — both inside `permission_callback` | ✅ Capability alone would satisfy §III; the nonce adds CSRF defence on a credential-minting route |
| **Rate limiter transient read** | `RateLimiter.php:34` `(int) get_transient( $key )` — scalar, not an associative array | ✅ `B11`'s partial-write / type-drift hazard does not apply to this shape |

Plus the v1 list, unchanged: digest-only storage, no signing-key material, instant revocation, token-family revocation, fails-closed authentication, tenant binding with forensic stream separation, auditable admin self-bypass, mandatory PKCE S256, single-use codes under atomic CAS.

---

## Action Plan & Next Steps

1. **Address within F095**: SEC-002 (tighten FR-012 wording + test asserting no 64-char hex in diagnostics); SEC-007 recommendation 2 (quickstart case for a competing `.well-known` handler).
2. **Candidate for F095 or follow-up**: SEC-007 recommendation 1 — `DiscoveryHealthCheck` asserts document identity, not just reachability. Small change, converts an unbounded problem into a bounded one. Worth doing here.
3. **Verification strengthening**: SEC-004 — reversed `active_plugins` order in quickstart Test 3.
4. **Separate changes**: constitution PATCH (§III condition 3 scoped to credentials exceeding the consenting user's authority; §I module list); correct `FrontendAuth.php:14`; `/speckit.security-review.init`.
5. **Remediation planning**: not required — no Critical or High findings. `/speckit.security-review.followup` optional.
6. **Durable Memory Preservation**: this pass produced reusable lessons — "a conflict guard keyed on a named competitor does not generalise; verify your own output instead of enumerating rivals", and "a licence gate is not an operator gate". Capture via `/speckit.memory-md.capture` at the end of the chain, per the user's instruction to defer.

---

## Memory Hub INDEX.md Row

```text
| docs/security-reviews/2026-10-06-095-oauth-migration-plan-v2.md | plan | 2026-10-06 | MODERATE | C:0 H:0 M:3 L:2 | A01,A04,A05,A08,A09 |
```
