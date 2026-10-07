---
document_type: security-review
review_type: tasks
assessment_date: 2026-10-06
codebase_analyzed: acrossai-mcp-manager (specs/095-oauth-migration/tasks.md)
total_files_analyzed: 8
total_findings: 6
overall_risk: MODERATE
critical_count: 0
high_count: 0
medium_count: 2
low_count: 2
informational_count: 2
owasp_categories: [A01, A03, A04, A09]
cwe_ids: [CWE-613, CWE-862, CWE-915, CWE-697]
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

# Security Review — F095 Task List

**MODERATE.** 0 Critical, 0 High, 2 Medium, 2 Low, 2 Informational.

## Executive Summary

The task list carries the plan's security requirements through faithfully — every finding from the two plan reviews has an owning task, and the risky work is front-loaded. Phase 2 clears the F083 landmine before any table of those names exists, and Phase 3 retires the irreversible data risk before a single new capability is built. That sequencing is correct and deliberate.

The findings here are mostly about **what happens between phases**, not within them. One is material: the task list's own incremental-delivery guidance would, if followed literally, produce a window in which token revocation silently stops working. That is a property of the delivery plan rather than of any individual task, which is exactly the class of problem a task-level review exists to catch.

Two coverage gaps also surfaced — security invariants named in `security-constraints.md` and `data-model.md` that no task owns. They would likely be honoured anyway by anyone porting the code carefully, but "likely, by someone careful" is not a control.

## Tasks Reviewed

`tasks.md` (74 tasks, T001–T074), cross-read against `spec.md`, `plan.md`, `data-model.md`, `contracts/oauth-endpoints.md`, `quickstart.md`, `security-constraints.md`, `memory-synthesis.md`.

---

## Findings

### SEC-T01 — Phase 3 in isolation creates split-brain token state; revocation stops working

- **Severity**: MEDIUM · **CVSS**: 5.4
- **OWASP**: A01:2025 — Broken Access Control
- **CWE**: CWE-613: Insufficient Session Expiration
- **Location**: `tasks.md` §Implementation Strategy; T029 vs T037
- **Spec-Kit task**: TASK-SEC-T01

The companion stands down when `class_exists( AuthorizationController )` becomes true. That class lands at **T037, in Phase 4**. But Phase 3 already:

- copies every token into the new tables (T020), and
- wires **our** `TokenValidator` to `determine_current_user` (T029).

So at the end of Phase 3 the companion is still fully active and still owns OAuth, while a second validator is also live, reading a *different copy* of the token data. Two sources of truth for the same credentials.

The failure is not that both might authenticate — they would, because the copy is faithful. It is **revocation**. An operator revoking a token through the companion's admin UI sets `revoked` on `acrossai_pro_mcp_oauth_tokens`. Our copy in `acrossai_mcp_oauth_tokens` is unchanged and still says the token is live. If our validator serves the next request, **the revocation has no effect** — and revocation is a security control, not a convenience.

The task list makes this reachable by stating:

> *"Phase 2 alone is a user-visible no-op — safe to merge early… Phase 3 → validate quickstart Test 1 → stop if it fails."*

Phase 2 genuinely is safe to merge alone. Phase 3 is **not**, and nothing in the task list says so.

**Recommendation**: state explicitly that Phase 3 must never reach a live site without Phase 4 — validate it on a test install only. Belt-and-braces alternative: defer T029's hook *wiring* to Phase 4 so the data copy lands in Phase 3 but the handover of authentication authority is atomic with the companion's stand-down. The first is a documentation fix; the second removes the possibility.

---

### SEC-T02 — No task asserts permission-callback coverage across the ported routes

- **Severity**: MEDIUM · **CVSS**: 4.3
- **OWASP**: A01:2025 — Broken Access Control
- **CWE**: CWE-862: Missing Authorization
- **Location**: `tasks.md` T034, T057, T062
- **Spec-Kit task**: TASK-SEC-T02

Seventeen REST routes move. §III and `S2` permit `__return_true` on exactly three — discovery metadata, DCR registration, and the token endpoint — and require an explicit capability check on every other.

Coverage is currently per-controller and partial: T057 states it for `ConnectorAdminController`, T062 for `AdminTokenController`. **Nothing checks the set as a whole.** T034's `ContractSurfaceTest` asserts routes are *registered* with the right paths, but says nothing about how they are *guarded* — a route could be ported with its `permission_callback` dropped and T034 would still pass, because the path is still there.

This is the highest-value single test in the feature relative to its cost: one test, enumerating the registered routes and asserting the permitted-public set is exactly the documented three.

**Recommendation**: add a task writing `tests/phpunit/OAuth/PermissionCallbackCoverageTest.php` that walks `rest_get_server()->get_routes()` for the namespace and asserts no route outside the allow-list uses `__return_true`.

---

### SEC-T03 — No automated check that the rollback path stays intact

- **Severity**: LOW · **CVSS**: 3.1
- **OWASP**: A04:2025 — Insecure Design
- **Location**: `tasks.md` T020; `quickstart.md` Test 2
- **Spec-Kit task**: TASK-SEC-T03

FR-010's copy-never-move rule exists because the companion's tables are the **only** rollback path if the digest copy goes wrong — and there is no other recovery, since raw tokens live solely on clients. T020 states the rule and quickstart Test 2 checks it by hand with `SHOW TABLES`.

No automated test asserts the source tables are byte-unchanged after migration. A bug that truncated, updated or dropped a source row would be caught only by someone remembering to run the manual step — and it would destroy the very thing that makes the migration recoverable.

**Recommendation**: extend `MigrationFidelityTest` (T017) to checksum the source tables before and after, asserting equality.

---

### SEC-T04 — Two named invariants have no owning task

- **Severity**: LOW · **CVSS**: 3.1
- **OWASP**: A03:2025 — Injection (mass assignment)
- **CWE**: CWE-915: Improperly Controlled Modification of Dynamically-Determined Object Attributes; CWE-697: Incorrect Comparison
- **Location**: `security-constraints.md` SC-C8; `data-model.md` §Constraints carried from memory
- **Spec-Kit task**: TASK-SEC-T04

Both are written down and neither is assigned:

- **SC-C8 / `B7`** — Query writers must filter against `Schema::columns()` before persisting, blocking mass-assignment via forged keys. The migration writes historical, attacker-influenced rows. No task mentions it.
- **`B18`** — `$wpdb` returns TINYINT as string, so `1 === $row->revoked` is always false. Both `revoked` and `used` are TINYINT. A wrong comparison here means a revoked token reads as live, or a used authorization code reads as unused — both security failures, and both silent.

**Recommendation**: add a verification task covering both against the four ported modules and the migration writer.

---

### SEC-T05 — Parallel-marked tasks in different phases edit the same file

- **Severity**: INFORMATIONAL
- **Location**: `tasks.md` T054 (Phase 4), T064 (Phase 6)

Both are marked `[P]` and both edit `webpack.config.js`. They are in different phases, so the documented sequential path is safe — but the task list also offers "different user stories can be worked on in parallel by different team members," under which these collide.

**Recommendation**: note the shared file on both tasks, or merge the webpack entry restoration into one task in Phase 4.

---

### SEC-T06 — Security-critical tests follow their implementation

- **Severity**: INFORMATIONAL
- **Location**: `tasks.md` T025→T026, T029→T030

The migration tests (T017–T019) correctly precede the implementation (T020). Two security-critical tests do not: T026 (diagnostics disclose nothing) follows T025, and T030 (validator fails closed) follows T029.

Fail-closed behaviour is precisely the kind of property that is easy to assert after the fact and hard to notice losing. Writing T030 first would make the ported behaviour demonstrably correct rather than presumed correct.

**Recommendation**: reorder T030 before T029. Minor, but free.

---

## Confirmed Secure Patterns

- **Destructive work is sequenced first and in isolation.** T004–T006 clear the sweeper before T008–T011 create tables of those names, and the dependency graph states the ordering explicitly rather than relying on task numbers.
- **The irreversible risk is retired before any feature work.** Phase 3 is the MVP; everything after is additive. The checkpoint reads "if it fails, stop and restore the snapshot" — a real stop condition, not a soft warning.
- **Every plan-review finding has an owning task**: SEC-002 → T025/T026, SEC-004 → T070, SEC-007 → T041/T042, plus `B20`→T014, `B42`→T043, `B44`→T006/T016, `D31`→T010/T013, `D34`→T058, A1→T049.
- **Behavioural invariants are named at their porting task** rather than left to a general "preserve behaviour" instruction — `D27` at T038, `D32` at T059, `D34` at T058, the self-bypass audit action at T037, the fails-closed contract at T029.
- **The regression guard is bidirectional.** T016 asserts both that `LegacyOAuthCleanup` is gone *and* that the four names remain in the uninstall drop list — protecting against a future "tidying" in either direction.
- **Tests precede implementation where the risk is highest** (T017–T019 before T020).

---

## Action Plan & Next Steps

1. **Before implementation**: SEC-T01 (delivery-boundary wording, or move T029's wiring to Phase 4) and SEC-T02 (permission-callback coverage test). Both are cheap and both close real gaps.
2. **Fold into existing tasks**: SEC-T03 into T017; SEC-T04 as a new verification task; SEC-T05 and SEC-T06 as task-list edits.
3. **Remediation planning**: not required — no Critical or High findings.
4. **Durable Memory Preservation**: one reusable lesson — *"a phased migration that copies state before transferring authority creates a window where the old owner's writes are invisible to the new reader; sequence authority transfer with the copy, or forbid shipping the intermediate state."* Capture at the end of the chain per the user's instruction.

---

## Memory Hub INDEX.md Row

```text
| docs/security-reviews/2026-10-06-095-oauth-migration-tasks.md | tasks | 2026-10-06 | MODERATE | C:0 H:0 M:2 L:2 | A01,A03,A04,A09 |
```
