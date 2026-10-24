---
document_type: security-review
review_type: tasks
assessment_date: 2026-10-16
codebase_analyzed: acrossai-mcp-manager (F096 — MCP Activity Log, 93 tasks)
total_files_analyzed: 10
total_findings: 6
overall_risk: HIGH
critical_count: 0
high_count: 2
medium_count: 3
low_count: 1
informational_count: 0
owasp_categories: [A03, A04, A09]
cwe_ids: [CWE-79, CWE-89, CWE-359, CWE-532, CWE-674, CWE-778]
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

# Security Review — F096 MCP Activity Log (Tasks)

## Executive summary

**Overall risk: HIGH**, and the reason is worth stating precisely: **every binding constraint
from the plan review has an owning task, yet two of them are owned by a phase that ships after
the phase which creates the risk.** Coverage is not the problem. Sequencing is.

The plan review could not have found this — tasks did not exist when it ran. The task list's own
Implementation Strategy explicitly recommends shipping US1 alone as the MVP ("Ship it before
starting US2"). Taking that advice as written produces a release that:

- exposes `DELETE /activity` (T032, US1) while the task that records removals is T075 in US4, so
  the MVP has an **unlogged purge** — exactly the SEC-001 finding the plan review closed,
  reintroduced through ordering rather than through design; and
- stores full source addresses against every event (T028, US1) while the personal-data exporter
  and eraser are T077/T078 in US4, so the MVP holds personal data with **no subject-data path**.

Both are fixed by moving tasks between phases — no new work, no design change. A third finding
has the same shape at lower severity: the recursion guard (T080) sits in US4 while the risk
begins in Phase 2.

Two findings are genuinely new rather than misplaced: the prepared-statement requirement is
explicit for the keyset comparison but silent for the retention DELETEs, and the Phase 1
verification handler logs raw adapter tags to `error_log` with no redaction.

## Tasks reviewed

`specs/096-mcp-activity-log/tasks.md` — 93 tasks across 7 phases. As reviewed: Setup 6,
Foundational 14, US1 28, US2 15, US3 8, US4 12, Polish 10. **After the fixes below were applied:
Foundational 15, US1 30, US4 9** (three tasks moved, none added). Read alongside `spec.md` (45 FR / 23 SC), `plan.md`,
`data-model.md`, `contracts/rest-activity.md`, `research.md`, `memory-synthesis.md`,
`security-constraints.md` (SC-C1…C12), `docs/memory/INDEX.md` (S1–S9, A24, D60) and
`.github/copilot-instructions.md`.

### Constraint coverage — all 12 owned

| Constraint | Owning task | Phase |
|---|---|---|
| SC-C1 recorded deletion | T075 | US4 ⚠ *see SEC-T01* |
| SC-C2 erasure de-identifies | T078 | US4 ⚠ *see SEC-T02* |
| SC-C3 redaction limit stated | T061, T083 | US2, US4 |
| SC-C4 preview filter | T079 | US4 |
| SC-C5 `wp_json_encode` | T038 | US1 |
| SC-C6 no `dangerouslySetInnerHTML` + inert test | T039, T048 | US1 |
| SC-C7 `esc_url` | T044 | US1 |
| SC-C8 explicit `permission_callback` | T032 | US1 |
| SC-C9 `$wpdb->prepare()` | T034 only ⚠ *see SEC-T04* | US1 |
| SC-C10 purge isolation both ways | T082 | US4 |
| SC-C11 failure visibility | T084 | Polish |
| SC-C12 addresses in export | T085 | Polish |

## Vulnerability findings

### SEC-T01 — The MVP ships a purge that records nothing (HIGH)

- **location**: `specs/096-mcp-activity-log/tasks.md` T032 (US1) vs T075 (US4)
- **owasp_category**: A09:2025-Security Logging and Monitoring Failures
- **cwe**: CWE-778: Insufficient Logging
- **cvss_score**: 7.1
- **spec_kit_task**: TASK-SEC-008

T032 creates the controller "with `GET /activity`, `/activity/summary`, `/activity/filters`,
`/activity/{id}`, and `DELETE /activity`" — the purge route is in US1. T075, which writes the
removal record, is in Phase 6 (US4). The Implementation Strategy recommends shipping US1 alone.

So the recommended MVP exposes an authenticated endpoint that erases the entire audit log and
leaves no trace, which is the precise condition A24 was just written into durable memory to
forbid. The plan review closed this as SEC-001; ordering reopened it.

**Recommendation**: either move `DELETE /activity` out of T032 into US4 beside T075, or move
T075 forward into US1. Preferred: **remove the purge from T032** — the MVP has no need to delete
anything, deletion belongs with the governance story that also brings retention, and a route
that cannot yet be safely offered should not ship.

### SEC-T02 — The MVP stores personal data with no subject-data path (HIGH)

- **location**: `specs/096-mcp-activity-log/tasks.md` T028 (US1) vs T077/T078 (US4)
- **owasp_category**: A04:2025-Insecure Design
- **cwe**: CWE-359: Exposure of Private Personal Information to an Unauthorized Actor
- **cvss_score**: 7.0
- **spec_kit_task**: TASK-SEC-009

T028 records `ip_address` in full on every request row, in US1. The exporter (T077) and the
de-identifying eraser (T078) are in US4, two phases later. Clarification Q1 chose the full
address **on the explicit condition** that WordPress's export and erasure hooks accompany it —
the alternative on offer was an anonymised address precisely because it carries no such
obligation. Splitting the decision across phases keeps the cost and defers the mitigation.

A US1-only release therefore accumulates per-user movement data, for up to the retention window,
with no mechanism to answer a subject request — in a plugin that until this feature had no
privacy plumbing at all, so no fallback exists elsewhere.

**Recommendation**: move T077 and T078 into US1, immediately after T028. They are two callbacks
in one new file (`includes/Observability/PersonalData.php`) and do not depend on anything US4
introduces. This restores the clarification's bargain: full address *and* subject-data path, in
the same release.

### SEC-T03 — The recursion guard lands two phases after the recursion risk (MEDIUM)

- **location**: `specs/096-mcp-activity-log/tasks.md` T080 (US4) vs T011 (Phase 2), T052 (US2)
- **owasp_category**: A09:2025-Security Logging and Monitoring Failures
- **cwe**: CWE-674: Uncontrolled Recursion
- **cvss_score**: 5.3
- **spec_kit_task**: TASK-SEC-010

T080 makes the recorders ignore their own table and guard re-entry — FR-015, which the spec
places in its **Safety** block alongside FR-013/FR-014, i.e. foundational. The task is in US4.
The risk begins earlier: the recorder exists from T011 (Phase 2), and from T052 (US2) any
ability that reads or writes the activity table is itself recorded, which can re-enter the
recorder. T054 adds a re-entry flag but only for the double-capture case (ability name +
`parent_id`), not for self-capture of the recorder's own writes.

**Recommendation**: move T080 into Phase 2, next to T011/T012 where the ignore list constant
(T007) already exists. It is a guard clause, not a feature.

### SEC-T04 — The prepared-statement requirement is silent on the retention DELETEs (MEDIUM)

- **location**: `specs/096-mcp-activity-log/tasks.md` T072, T074 vs T034
- **owasp_category**: A03:2025-Injection
- **cwe**: CWE-89: SQL Injection
- **cvss_score**: 5.9
- **spec_kit_task**: TASK-SEC-011

SC-C9 requires `$wpdb->prepare()` on every query, "explicitly including the keyset comparison
**and the per-`event_type` retention cutoffs**". Verified: the string `prepare` appears in
exactly one task — T034, the keyset comparison. T072 (`prune_by_event_type()`) and T074
(`enforce_ceiling()`) construct DELETE statements from operator-supplied option values and say
nothing about preparation.

The values come from settings rather than from a request, so this is not directly
attacker-controlled — but a day count and a row ceiling interpolated into a DELETE is the
textbook shape, options are writable by anything with `manage_options` or a filter, and S4 is
unconditional.

**Recommendation**: state `$wpdb->prepare()` with `%d` casts in T072 and T074, and add an
assertion to T082 that both prune paths use prepared statements.

### SEC-T05 — The verification handler logs raw adapter tags with no redaction (MEDIUM)

- **location**: `specs/096-mcp-activity-log/tasks.md` T001, T006
- **owasp_category**: A09:2025-Security Logging and Monitoring Failures
- **cwe**: CWE-532: Insertion of Sensitive Information into Log File
- **cvss_score**: 4.7
- **spec_kit_task**: TASK-SEC-012

T001 installs a throwaway handler that `error_log()`s "every `record_event()` call" against real
client traffic (T002 invokes a live `toolset/*` tool). `sanitize_tags()` is not introduced until
T026, so Phase 1 writes unredacted tool arguments — the `params` tag — to `debug.log`, a file
frequently web-readable on shared hosting and routinely pasted into support threads.

T006 reverts the handler, which is the only thing standing between this and production.

**Recommendation**: have T001 log **tag keys and the duration only**, never values — the four
facts T002 verifies (slug, `tool_name` presence, `session_id`, `duration_ms`) need no argument
content. Make T006 a hard checkpoint rather than a task, and add "confirm no throwaway handler
remains" to T091's pre-ship sweep.

### SEC-T06 — The inert-rendering test covers one field of five (LOW)

- **location**: `specs/096-mcp-activity-log/tasks.md` T048 vs T056, T055
- **owasp_category**: A03:2025-Injection
- **cwe**: CWE-79: Improper Neutralization of Input During Web Page Generation
- **cvss_score**: 3.5
- **spec_kit_task**: TASK-SEC-013

T048 asserts a scripted `component_name` renders inert. Five fields are client-influenced:
`component_name`, `error_message`, `client_name`, `user_agent` and `input_preview` — and US2
adds `output_preview` (T056) and `component_category` (T055), neither covered.

**Recommendation**: parameterise T048 over all client-influenced fields, and extend it in US2
when `output_preview` arrives.

## Confirmed secure patterns

| Pattern | Evidence |
|---|---|
| **Every binding constraint is owned** | All 12 of SC-C1…C12 map to a named task; none was dropped in translation from plan to tasks |
| **Redaction precedes going live** | T026 (`sanitize_tags()`) is sequenced before T030, which swaps the real handler in. The capture path is never live without redaction |
| **Authorization is one task, early** | T032 establishes `permission_check` → `manage_options` on all routes at the point of creation, not retrofitted |
| **The holder lifecycle is foundational** | T014/T015 put the A17 three-stage lifecycle in Phase 2 with its test (T020), before any story depends on it — correct, since a leak here misattributes calls |
| **The drift guard is foundational** | T018 asserts column widths in Phase 2, so B34's silent write-loss cannot hide behind later phases |
| **Negative and abuse cases are present** | Subscriber refusal (T047), forced storage failure (T019), thrown ability (T062), scripted field (T048), double-capture (T062), cross-table purge isolation (T082) |
| **Parallel markers respect prerequisites** | All 21 `[P]` tasks are either distinct test files or distinct source files; none runs ahead of a dependency. T018–T020 parallelise only with each other |
| **No security work is hidden in Polish** | T084/T085 are the two deferred *recommended* (SHOULD) constraints; every binding (MUST) constraint sits in Phase 2 or a story phase |

## Action plan & next steps

**Before T001** — four task moves and two edits, no new work. **All six were applied on
2026-10-16; this section records what was done, not what remains.**

1. **SEC-T01**: remove `DELETE /activity` from T032; it ships in US4 with T075.
2. **SEC-T02**: move T077 and T078 into US1, after T028.
3. **SEC-T03**: move T080 into Phase 2, beside T011/T012.
4. **SEC-T04**: add `$wpdb->prepare()` with `%d` casts to T072 and T074; assert in T082.
5. **SEC-T05**: restrict T001 to keys-and-duration; make T006 a checkpoint; add the sweep to T091.
6. **SEC-T06**: parameterise T048 across client-influenced fields.

Two HIGH findings exist, so `/speckit.security-review.followup` is **available** — but not the
right tool here. Followup creates remediation *tasks*; these findings are remediated by moving
existing tasks between phases, which is an edit to `tasks.md`, not an addition to it.

**Durable memory**: one candidate, and it generalises well beyond this feature —
*"a constraint satisfied by a task in a later phase is not satisfied for any release that ships
the earlier phase."* The MVP-first strategy and phase-ordered security work are in direct
tension, and nothing in the task template surfaces it. Proposed through the formal capture flow.
