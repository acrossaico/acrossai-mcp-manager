---
document_type: security-review
review_type: tasks
assessment_date: 2026-09-07
codebase_analyzed: acrossai-mcp-manager (Feature 084 — Connect tab merge)
total_files_analyzed: 8
total_findings: 5
overall_risk: MODERATE
critical_count: 0
high_count: 0
medium_count: 2
low_count: 2
informational_count: 1
owasp_categories: [A01, A03, A09]
cwe_ids: [CWE-79, CWE-209, CWE-352, CWE-862, CWE-1059]
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

# SECURITY REVIEW REPORT — TASKS (Feature 084)

## Executive Summary

The task list's security **coverage** is complete: every constraint C1–C8 has an implementing task
and a verifying task, and the coverage matrix is present as
`DEC-F025-TASKS-REVIEW-PRESERVATION-INVARIANT-AND-COVERAGE-MATRIX` requires. No constraint is
unrepresented.

The problem is **sequencing**, and it is a systematic one. Three of the four access-control and
information-disclosure protections (C2, C3, C4) are *implemented* in the blocking foundational phase
— correctly — but their *verifying tests* were filed under the user story whose acceptance criteria
happened to mention them. Those stories are P2 and P3. The task list's own Implementation Strategy
names the end of **US4** as the release gate, which means the plan as written can reach a shippable,
mergeable state with the decisive assertions for capability-filtered fallback, silent failure, and
error containment **never having run**.

This is the classic shape of "security work hidden in later phases": nothing is missing, everything
is late, and the lateness is invisible because the coverage matrix looks full.

**Overall risk: MODERATE** — 0 critical, 0 high, 2 medium, 2 low, 1 informational. All five findings
are task-ordering or task-coverage fixes; none requires a design change.

## Tasks Reviewed

`specs/084-connect-tab-merge/tasks.md` — 54 tasks across 8 phases, reviewed against `plan.md`,
`spec.md`, `contracts/connect-method-registration.md`, `security-constraints.md`, `quickstart.md`,
the refreshed `memory-synthesis.md`, and `docs/memory/INDEX.md` (targeted retrieval: S1, S5, S6, B6,
B8, B48, B51, B54, D41, D46, D48, D53).

---

## Vulnerability Findings

### [MEDIUM] SEC-084-T01 — C2's decisive fallback-safety assertion sits behind the release gate

**Location:** `specs/084-connect-tab-merge/tasks.md` T042 (Phase 6, US3, priority P2)
**OWASP Category:** A01:2025-Broken Access Control
**CWE:** CWE-862: Missing Authorization
**CVSS Score:** 5.3

**Description:** T042 is the single most important access-control test in the feature. It asserts
that on a non-local site where the user's capability hides `ai-connectors`, opening Connect with no
method selects `clients` — catching precisely the implementation that filters by capability *after*
resolving, which turns the step-4 fallback into a bypass onto a paid method.

It is filed in Phase 6 under **US3 (P2)** because it happens to involve the local/non-local
distinction. But what it actually tests is C2, implemented back in T012 (Phase 2). The task list's
Implementation Strategy names the end of **US4** as "the actual release gate" — three phases before
US3. A team that ships at its own stated gate ships C2 unverified.

The related C2 assertions in T026 and T045 cover *filtering* (a hidden method is absent from the
list) but not *fallback selection*, which is the part that fails silently and only for restricted
users.

**Remediation:** Move the fallback-safety assertion into the foundational phase, adjacent to T012
which implements it. Keep only the genuinely local-specific cases (local → `clients`, non-local →
first-in-order, explicit wins) in the US3 phase. The assertion does not need the local branch to
exist — it is a non-local scenario.

**Spec-Kit Task:** TASK-SEC-084-T01

---

### [MEDIUM] SEC-084-T02 — C3 and C4 verification is parked in the lowest-priority story

**Location:** `specs/084-connect-tab-merge/tasks.md` T046 (Phase 7, US5, priority P3)
**OWASP Category:** A09:2025-Logging & Monitoring Failures / A03:2025-Injection
**CWE:** CWE-209: Information Exposure Through an Error Message; CWE-79
**CVSS Score:** 4.3

**Description:** T046 carries three distinct assertions: that a throwing `render_callback` is
contained (C4), that the emitted error leaks no exception message, file path, or trace (C4), and
that a `?method=` payload never appears in rendered output (C3).

All three protect **every** user story — C3 guards the resolution path that US1 and US2 both use, and
C4 guards the dispatch path that every method renders through. Both are implemented in Phase 2 (T017,
T018). Yet the tests are filed under US5, the P3 extensibility story, purely because US5's acceptance
criteria are where "a deliberately failing method" is described.

US5 is the most droppable phase in the list. If it slips, the feature ships with an untested
`\Throwable` boundary and an untested no-reflection guarantee.

There is a second-order effect worth naming: C3 and C4 interact. If containment leaks an exception
message, and the exception message happens to embed the requested method name, C3 is breached through
C4's failure. A test suite that exercises them together, early, catches that composition; two tests in
different phases may not.

**Remediation:** Split T046. The generic containment and no-reflection assertions belong in the
foundational phase alongside T017/T018. Keep the *third-party-specific* case — a method registered
through the public filter that throws — in US5, where it genuinely belongs as an extension-point
guarantee.

**Spec-Kit Task:** TASK-SEC-084-T02

---

### [LOW] SEC-084-T03 — No negative test that a legacy address cannot reach a capability-excluded method

**Location:** `specs/084-connect-tab-merge/tasks.md` Phase 4 (US2), tasks T030–T032
**OWASP Category:** A01:2025-Broken Access Control
**CWE:** CWE-862: Missing Authorization
**CVSS Score:** 3.7

**Description:** Resolution step 2 (plan D-4) maps a legacy `?tab=` value to its method "if in the
filtered set". That conditional is the only thing stopping a stale bookmark from being a
capability-bypass vector — `?tab=ai-connectors` is a perfectly ordinary URL that any user can type,
and it reaches the resolver through a *different* branch than `?method=ai-connectors` does.

Every US2 task is a positive-path test: the five addresses resolve, the deep links preserve their
selection, no redirect fires. There is no abuse case. If an implementer writes step 2 as a plain
`LEGACY_TAB_METHODS` lookup without re-checking membership in the filtered set — a very natural
mistake, since steps 1 and 2 read differently — nothing in the suite fails.

**Remediation:** Add a negative test to the US2 phase: for a user whose capability excludes
`ai-connectors`, requesting `?tab=ai-connectors` falls back to a permitted method, produces no
`ai-connectors` content, and is indistinguishable from requesting an unknown method (C2 + C3).

**Spec-Kit Task:** TASK-SEC-084-T03

---

### [LOW] SEC-084-T04 — The S1 nonce-binding invariant is listed but has no asserting task

**Location:** `specs/084-connect-tab-merge/tasks.md` §Preserved invariants; T022
**OWASP Category:** A01:2025-Broken Access Control
**CWE:** CWE-352: Cross-Site Request Forgery
**CVSS Score:** 3.1

**Description:** The preserved-invariants list correctly names the risk: "the nonce actions on the npm
and MCP Clients forms — those forms change **location**, not their nonce binding. A moved form losing
its binding is the regression to watch (**S1**)." That is a constitution §III MUST.

But the verification column points only at T009, T049 and T053 — a regression run of an unrelated test
file, a grep block that does not mention nonces, and a manual walkthrough. None of them asserts the
nonce action is unchanged. The invariant is documented and unverified, which is the weaker of the two
states it could be in.

T022 is the task that creates the risk: it repoints `submit_target_url` in `ClientsTab.php:75` and
`NpmTab.php:73`. `AbstractServerTab::nonce_field()` derives the action from the tab, so a
target-URL change should not touch it — but "should not" is the reason to assert, not the reason to
skip asserting.

**Remediation:** Add a task asserting the npm and MCP Clients forms emit the same nonce action after
the repoint as before it, ideally by capturing the action strings in the Phase 1 baseline and
comparing.

**Spec-Kit Task:** TASK-SEC-084-T04

---

### [INFORMATIONAL] SEC-084-T05 — The C1 output-site review lags the phases that add output sites

**Location:** `specs/084-connect-tab-merge/tasks.md` T049 (Phase 8) vs. T029 (Phase 4) and T035 (Phase 5)
**OWASP Category:** A03:2025-Injection
**CWE:** CWE-79: Cross-site Scripting
**CVSS Score:** 0.0 (process note)

**Description:** T049 runs the C1 review — every `method_url()` consumer checked against the
contract's inventory table — in the final polish phase. T029 (list-table shortcuts) adds two output
sites in Phase 4 and T035 (companion `panel_url()`) adds two more in Phase 5. Between those phases and
Phase 8, a new unescaped output site can exist unnoticed.

This is not a defect: T049 is a pre-merge gate, so nothing unescaped can actually ship. It is recorded
because B54 — captured from this very feature — says the inventory must grow *in the change that adds
the consumer*, not in a later sweep. The task list satisfies the letter (a gate exists) but not quite
the spirit (the inventory row lands with the consumer).

**Remediation:** Add "and add its row to the contract's inventory table" to T029 and T035 themselves,
so the inventory is never stale between phases. T049 remains the backstop.

**Spec-Kit Task:** TASK-SEC-084-T05

---

## Confirmed Secure Patterns

- **Coverage is genuinely complete.** All eight constraints C1–C8 have both an implementing and a
  verifying task, and the matrix distinguishes the two columns rather than conflating them. The
  findings above are all about *when*, never *whether*.
- **Secure foundations really are first.** Every access-control and escaping mechanism — the
  capability filter (T012), the sanitized reads (T016), the silent fallback (T017), the `\Throwable`
  boundary (T018), the raw-return contract (T015) — is in the blocking Phase 2, before any story
  phase. The implementation ordering is right; only the test placement drifted.
- **C2 is enforced structurally, not by convention.** T010 mandates that the accessor not be named
  `for_server()` and that collection stay private, so the bypass is unrepresentable rather than
  merely discouraged. This is the strongest form the constraint could take.
- **C7's extraction invariants are verified in the same phase they are implemented** (T005 → T008),
  including the `WP_DEBUG`-off silence assertion. This is the pattern the other constraints should
  follow.
- **The parallel markers do not bypass security prerequisites.** Every `[P]` task in Phase 2 is
  either a test or touches a different file; none runs before the mechanism it depends on. T008/T009
  correctly follow T007; T012 correctly follows T011.
- **No new trust boundary is introduced by the task list** that the plan did not already account for:
  still no REST route, DB query, form handler, nonce, credential, upload, outbound HTTP, or redirect.
- **The companion's widened gate keeps its full predicate** (T036) and is asserted in the same phase
  (T037), correctly scoped to the other repository.

---

## Action Plan & Next Steps

1. **Re-sequence `tasks.md`** to close SEC-084-T01 through -T05: promote the C2 fallback-safety
   assertion and the generic C3/C4 containment assertions into Phase 2; add the legacy-address abuse
   case to US2; add the S1 nonce-binding assertion; fold the inventory-row obligation into T029/T035.
   Applied in this turn.
2. **Update `security-constraints.md`** — the coverage matrix's verifying-task column changes for C2,
   C3 and C4, and gains rows for the new abuse-case and nonce assertions.
3. **No `/speckit.security-review.followup`** — no critical or high findings.
4. **Durable memory**: one candidate lesson identified — *file a security test with the constraint it
   verifies, not with the user story whose acceptance criteria mention it* — proposed for capture
   below.

---

## Memory Hub INDEX.md Row

```text
| docs/security-reviews/2026-09-07-084-connect-tab-merge-tasks.md | tasks | 2026-09-07 | MODERATE | C:0 H:0 M:2 L:2 | A01,A03,A09 |
```
