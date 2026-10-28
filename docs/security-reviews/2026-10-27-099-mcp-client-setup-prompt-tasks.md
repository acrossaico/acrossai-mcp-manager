---
document_type: security-review
review_type: tasks
assessment_date: 2026-10-27
codebase_analyzed: acrossai-mcp-manager / specs/099-mcp-client-setup-prompt
total_files_analyzed: 6
total_findings: 7
overall_risk: HIGH
critical_count: 0
high_count: 2
medium_count: 2
low_count: 2
informational_count: 1
owasp_categories: [A01, A04, A09]
cwe_ids: [CWE-522, CWE-532, CWE-200, CWE-778]
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

# SECURITY REVIEW REPORT — TASKS

## Executive Summary

Coverage is good: every one of SC-C1..C18 has a named owner, the gates precede the surface work, and
tests exist for the gate behaviours. The list is reviewable and the dependency graph is honest.

**Two findings matter, and both come from the same blind spot — treating the disclosure and the
log record as lower-priority than the mechanism.**

The first is a scope error that the task list makes easy to miss: **the stated MVP ships a
one-click credential export without the disclosure.** SC-C13 is owned by T030 in Phase 5, but the
copy control is created by T021 in Phase 3, and the implementation strategy names "Phase 1 + 2 + 3"
as shippable. An MVP that exports a full-administrator credential while saying nothing about what
it can do is precisely what SC-C13 exists to prevent — and the spec itself calls US3 "small and
must not be dropped."

The second is an omission: **nothing forbids the credential from appearing in the issuance record.**
T010 adds activity-log recording, and this plugin's redaction is key-name based with a limit its own
0.4.4 release notes state plainly — a secret that does not sit under a recognised sensitive key is
stored as sent. No task says the issuance record must not contain the credential.

## Tasks Reviewed

`specs/099-mcp-client-setup-prompt/tasks.md` — 43 tasks, 7 phases, 14 parallel.
Cross-checked against `spec.md` (28 FRs, 17 SCs), `security-constraints.md` (SC-C1..C18),
`plan.md`, `research.md`, `memory-synthesis.md`.

## Vulnerability Findings

### [HIGH] SEC-T-001 — The MVP ships credential export with no disclosure

**Location:** `tasks.md` — T021 (Phase 3) vs T030 (Phase 5); "Implementation Strategy"
**OWASP Category:** A04:2025-Insecure Design
**CWE:** CWE-522: Insufficiently Protected Credentials
**CVSS:** 7.1

**Description:** The copy control that exports a live full-administrator credential is created in
Phase 3 (T021). The disclosure explaining what that credential can do is T030, in Phase 5, behind a
P2 story. The Implementation Strategy explicitly names Phases 1–3 as the MVP and says the later
phases are "shippable increments on top".

So the defined MVP is: a button that exports an administrator credential into a third-party AI
assistant, with no statement of scope and no revocation affordance. SC-C13 and SC-C14 both land a
phase later. The spec anticipated this risk — US3's own rationale says *"shipping a one-click
credential-sharing control with no disclosure would be indefensible"* — but the phase ordering
reintroduces exactly that state as a legitimate stopping point.

**Remediation:** Move T030 and T031 into Phase 3 and make them blocking for it. US3's remaining
tasks (T032 count display, T033 test) can stay in Phase 5. The disclosure is not a feature
increment; it is a condition of the control existing at all.

**Spec-Kit Task:** TASK-SEC-010

---

### [HIGH] SEC-T-002 — Nothing forbids the credential appearing in the issuance record

**Location:** `tasks.md` T010; `spec.md` FR-027
**OWASP Category:** A09:2025-Security Logging and Monitoring Failures
**CWE:** CWE-532: Insertion of Sensitive Information into Log File
**CVSS:** 7.0

**Description:** T010 records each issuance to the activity log. No task states that the record must
not contain the credential, and the redaction this plugin relies on cannot be trusted to catch it:
`ActivityObservabilityHandler` redacts via `is_sensitive_key` across 24 key patterns, and the 0.4.4
release notes state the limit directly — a secret that does not sit under a recognised key name
matches nothing and is stored as sent.

A credential written into an issuance record would then persist in a table with 30-day retention,
exportable through the plugin's own personal-data exporter. This is the one place in the feature
where a plaintext credential could come to rest in the database, and the plan's otherwise-strong
"credential never enters the DTO" discipline does not extend to it.

**Remediation:** Add an explicit task and an explicit test: the issuance record carries the
credential **UUID and name only**, never the password, and a test asserts the written row contains
no substring of the issued credential.

**Spec-Kit Task:** TASK-SEC-011

---

### [MEDIUM] SEC-T-003 — SC-C11 has no real owner

**Location:** `tasks.md` Security Constraint Coverage table; T011
**OWASP Category:** A04:2025-Insecure Design
**CWE:** CWE-522
**CVSS:** 4.0

**Description:** The coverage table maps SC-C11 ("credentials stay core-hashed; the plugin persists
no secret") to "T011 (asserted, not implemented)". But T011's description lists five assertions —
clear error, denial, fail-open, no-store, name prefix — and none of them is that assertion. The
constraint is mapped to a task that does not cover it, which reads as covered in review and is not.

**Remediation:** Add the assertion to T011 explicitly, or give SC-C11 its own test task. Combined
with SEC-T-002, the honest statement of this constraint is "no plaintext credential is persisted
anywhere by this plugin — not in a table, not in a log row, not in an option."

**Spec-Kit Task:** TASK-SEC-012

---

### [MEDIUM] SEC-T-004 — No test for clearing the credential on client switch

**Location:** `tasks.md` T025; `spec.md` FR-010
**OWASP Category:** A01:2025-Broken Access Control
**CWE:** CWE-200: Exposure of Sensitive Information
**CVSS:** 4.3

**Description:** T025 implements "clear it when the client changes", satisfying FR-010. No task
tests it. A regression would leave a credential minted for client A visible in client B's prompt —
a stale-credential display bug that is invisible in review and easy to reintroduce when the panel
is refactored.

**Remediation:** Add a test task covering FR-010 on both surfaces.

**Spec-Kit Task:** TASK-SEC-013

---

### [LOW] SEC-T-005 — FR-022 is unreferenced by any task

**Location:** `tasks.md`; `spec.md` FR-022
**CWE:** CWE-200

**Description:** FR-022 requires every administrator-facing error to be hand-authored, with no
exception text, database error text, storage keys or filesystem paths. Only T004 touches error
messaging, and only for the availability path. The requirement spans every error branch the feature
adds. A grep confirms `FR-022` appears nowhere in `tasks.md`.

**Remediation:** Reference FR-022 from a polish-phase task that reviews all new error branches.

**Spec-Kit Task:** TASK-SEC-014

---

### [LOW] SEC-T-006 — FR-028 is unreferenced, and it is a negative requirement

**Location:** `tasks.md`; `spec.md` FR-028
**CWE:** CWE-778

**Description:** FR-028 states the route MUST NOT be rate limited — a deliberate decision with
recorded reasoning. Negative requirements decay silently: a later contributor adding a limiter
"for safety" would violate a decision nobody is checking. No task asserts it.

**Remediation:** Add an assertion that no rate limiter is wired to this route, so the decision is
enforced rather than merely documented.

**Spec-Kit Task:** TASK-SEC-015

---

### [INFORMATIONAL] SEC-T-007 — Phase 2 implies a dependency that does not exist, and the stated rationale is weaker than the real one

**Location:** `tasks.md` Phase 2

**Description:** Two observations on the sequencing, in response to the explicit invitation to
challenge it.

**The placement is right, but the justification given is not the strong one.** The stated reason —
"a one-click mint button is a more discoverable entry point" — is weak, because the existing
generate button is also one click and the hardening ships in the same PR regardless of phase. The
genuinely load-bearing reason is that **T008 and T009 change the credential's data shape**: the
retained UUID and the new name format are what T032's count display and T033's naming test depend
on. They are structural prerequisites, not merely security ones. Keep the order; fix the reasoning.

**The phase conflates two independent groups.** T004–T012 (route hardening) and T013–T020 (contract
and DTO) share no dependency. Presenting them as one sequential phase implies the contract work
waits on the access gate. If T005 stalls on the Access Control interface, nothing should block
T013–T020 — but the phase as written suggests otherwise.

**Remediation:** Split Phase 2 into 2A (route) and 2B (contract/DTO), noting they are parallel.

## Confirmed Secure Patterns

| Pattern | Evidence |
|---|---|
| Gates precede surface work | T004–T012 complete before T021 creates any control |
| Gate behaviours tested, including the fail-open branch | T011 — fail-open is the easiest branch to omit and the most dangerous to get wrong |
| Route collision tested through real routing | T012 uses `rest_do_request()`, not a direct `permission_check()` call — the precise gap that let issue #193 hide |
| Credential never enters the DTO, and it is tested | T016 builds with an empty token; T020 asserts it |
| Injection test for third-party client classes | T026 — the extension point is an untrusted input surface |
| Negative test for third-party compatibility | T018 — a bare subclass must still work |
| Byte-identity asserted under translation | T019 — the only check that catches a localised JSON key |
| Deferred work fenced, not forgotten | T012 asserts this feature does not worsen #193 |
