---
document_type: security-review
review_type: tasks
assessment_date: 2026-09-15
codebase_analyzed: acrossai-mcp-manager (Feature 090 — Server Types)
total_files_analyzed: 10
total_findings: 5
overall_risk: MODERATE
critical_count: 0
high_count: 0
medium_count: 2
low_count: 2
informational_count: 1
owasp_categories: [A01, A04, A05]
cwe_ids: [CWE-696, CWE-20, CWE-1188, CWE-451]
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

# Security Review — Feature 090 Task List

## Executive Summary

The task list **faithfully carries every control from the plan into concrete, file-scoped
work**. All four prior plan-stage findings (SEC-001 … SEC-004) and both architecture
resolutions (ARCH-1, ARCH-2) map to explicit tasks. Negative tests exist for the two highest
risk behaviours — the corrective UPDATE regression (T012) and the diagnostic ability leaking
onto healthy servers (T039).

The findings here are about **sequencing and verification, not design**:

1. The enablement security boundary is scheduled **after** the release the strategy calls the
   MVP, so shipping the recommended MVP yields server types with no gate.
2. Write-side input validation is implemented but **never asserted by a test**, despite the
   plan-stage review recording it as a confirmed secure control.

No Critical or High findings. **Overall risk: MODERATE**, driven by SEC-005, which is fixed by
moving two tasks between phases.

> This document reviews `tasks.md`. The plan-stage review lives in `security-constraints.md`
> and is **partially stale** — it predates the ARCH-1/ARCH-2 resolutions and does not mention
> `ServerEnablement`, `effective_tools`, or the new CI grep gate. Those surfaces are reviewed
> here instead.

## Tasks Reviewed

`specs/090-server-types/tasks.md` — 55 tasks, 7 phases. Cross-read against `plan.md`,
`spec.md`, `data-model.md`, `research.md`, `contracts/`, `quickstart.md`,
`memory-synthesis.md`, `security-constraints.md`, `docs/memory/INDEX.md`.

---

## Vulnerability Findings

### SEC-005 — The enablement boundary is sequenced after the declared MVP

- **finding_id**: SEC-005
- **location**: `specs/090-server-types/tasks.md` — T027, T031 (Phase 4 / US2) vs the
  Implementation Strategy section ("MVP First (User Story 1 only): Phases 1 → 2 → 3, then stop
  and validate")
- **owasp_category**: A04:2025-Insecure Design
- **cwe**: CWE-696: Incorrect Behavior Order
- **cvss_score**: 5.4 (Medium)
- **spec_kit_task**: TASK-SEC-005

**Issue.** The enablement *rule* is foundational — `ServerTypes::is_available()` and
`enablement_error()` land in T009, Phase 2. But the *enforcement* does not:
`ServerEnablement::set()` is **T027** and the CI grep gate is **T031**, both in Phase 4 (US2,
priority P2).

The task list simultaneously declares Phases 1 → 2 → 3 an independently shippable MVP.

**Consequence of taking that MVP at its word.** After Phase 3 an operator can select the
`acrossai` type (T021 ships the selector), create a server with it (US2's create-form tasks
have not landed, so it takes the column default — but the Tools-tab switcher can set it), and
**enable it with nothing preventing them**. US3's runtime diagnostic is also absent, so the
server advertises an empty tool list: a live, enabled, reachable MCP endpoint that serves
nothing. That is precisely the state A21's safety layer exists to prevent and that this
feature was written to close.

Phase 2 is defined in the task list as "blocking prerequisites for all user stories". A
security boundary that every story's correctness depends on belongs there.

**Required change.** Move **T027** (`ServerEnablement` facade) and **T031** (grep gate) into
Phase 2, immediately after T009 which supplies the rule they enforce. Their UI wiring
(T028–T030, T032) can remain in US2 — the boundary must exist early; the messages need not.
Alternatively, if the phases stay as they are, the Implementation Strategy MUST state that
US1 is an internal checkpoint and not a release boundary.

---

### SEC-006 — Write-side input validation is implemented but never tested

- **finding_id**: SEC-006
- **location**: `specs/090-server-types/tasks.md` — T019, T047 (implement validation); no
  corresponding assertion among the 13 PHPUnit tasks
- **owasp_category**: A01:2025-Broken Access Control
- **cwe**: CWE-20: Improper Input Validation (as an unverified control)
- **cvss_score**: 4.3 (Medium)
- **spec_kit_task**: TASK-SEC-006

**Issue.** Two tasks implement enum validation on the new writable columns — T019 rejects an
unknown `server_type` with `acrossai_mcp_invalid_server_type`, T047 enum-validates the policy
route. The plan-stage review recorded "Enum validation on both new columns" as a **confirmed
secure pattern**.

Of the thirteen PHPUnit tasks, **none asserts either rejection.** T013 covers the *read* path
("unknown slug degrades without fatal"), which is the opposite direction: it proves a bad
value already in the database is survivable, not that a bad value is refused entry.

**Consequence.** The control most directly guarding the new attack surface — operator-supplied
values reaching two columns that drive an authorization-adjacent gate — has no regression
test. A future refactor that drops the validation passes CI. Because `server_type` determines
whether the enablement gate fires at all, a forged value is the cheapest route to bypassing it.

**Required change.** Add a test task in Phase 2 or US1:

> PHPUnit: `POST /servers/{id}/tools` rejects an unregistered `server_type` with
> `acrossai_mcp_invalid_server_type` (400) and leaves the stored value unchanged;
> `POST /servers/{id}/tools/policy` rejects a value outside `all|none|per-tool`. Include a
> forged-value case (a slug that is a valid string but not a registered type).

---

### SEC-007 — A parallel-marked task shares a file with a sequential security task

- **finding_id**: SEC-007
- **location**: `specs/090-server-types/tasks.md` — T034 `[P]` and T030 (no `[P]`), both
  editing `includes/REST/QuickConnectController.php`
- **owasp_category**: A04:2025-Insecure Design
- **cwe**: N/A — development-process hazard, not a runtime weakness
- **cvss_score**: 2.0 (Low)
- **spec_kit_task**: TASK-SEC-007

**Issue.** The `[P]` marker means "different files, no dependency on an incomplete task".
T034 carries it, but edits `QuickConnectController.php:631` — the same file T030 edits at
`:729` to route enablement through `ServerEnablement::set()`. T030 is correctly sequential;
T034 is not.

**Consequence.** If both are executed in parallel by separate agents or contributors, one edit
can silently overwrite the other. The edit at risk (T030) is the one that installs a security
control on the REST enable path, and its loss would be silent — nothing else in the task list
would fail.

**Required change.** Remove the `[P]` marker from T034, or split it so the JSX change stays
parallel and the `QuickConnectController.php:631` change is sequenced after T030.

---

### SEC-008 — SEC-003 remediation is incomplete in the task text

- **finding_id**: SEC-008
- **location**: `specs/090-server-types/tasks.md` — T049
- **owasp_category**: A05:2025-Security Misconfiguration
- **cwe**: CWE-1188: Insecure Default Initialization of Resource
- **cvss_score**: 2.6 (Low)
- **spec_kit_task**: TASK-SEC-008

**Issue.** SEC-003's required change was that the UI state `'all'` is a **standing rule
covering future additions** — so an operator choosing it understands they are accepting tools
that do not exist yet, sight unseen.

T049 instead says the pill must state "that `all`/`none` overrides the type's set until the
rule returns to `per-tool`". That is the *precedence* warning (a usability point), not the
*forward-consent* warning (the security point). The two are different sentences and only one
is scheduled.

**Consequence.** An operator can enable a standing rule that auto-exposes every tool-level
ability a future plugin registers, without the interface ever telling them that is what they
chose. Mitigated — as in the plan review — by exposure ≠ authorization (D24): each ability
still runs its own `permission_callback`, so the blast radius is discovery, not execution.

**Required change.** Extend T049 to state that `'all'` includes tool-level abilities
registered **after** the choice is made, not only that it overrides the type's set.

---

### SEC-009 — No observability signal when an enablement is refused

- **finding_id**: SEC-009
- **location**: `specs/090-server-types/tasks.md` — T027–T030
- **owasp_category**: A01:2025-Broken Access Control
- **cwe**: CWE-451: User Interface Misrepresentation of Critical Information (weak fit;
  recorded as observability debt)
- **cvss_score**: 0.0 (Informational)
- **spec_kit_task**: TASK-SEC-009

**Issue.** `ServerEnablement::set()` returns a `WP_Error` to the caller, which renders a
message. Nothing fires a `do_action()`, so a refusal leaves no trace an operator can audit
after the fact — relevant when a bulk enable partially succeeds (T029) and the operator later
asks which servers were skipped and why.

The codebase has an established pattern for exactly this: **D19** wires scoped `do_action()`
signals on defensive paths so operators can log anomalies without a hard observability
dependency. D19 is framed around fail-*open* paths; this is fail-*closed*, which is why this
is Informational rather than a defect.

**Required change.** None blocking. Consider `do_action( 'acrossai_mcp_server_enable_refused',
$server_id, $server_type, $reason )` inside the facade, consistent with D19.

---

## Confirmed Secure Patterns

| Pattern | Evidence in `tasks.md` |
|---|---|
| **Every plan-stage finding is scheduled** | SEC-001 → T033/T034/T035; SEC-002 → T041 + test T039; SEC-003 → T049 (partially, see SEC-008); SEC-004 → T040 (translatable, correctly not hardcoded) |
| **Both architecture resolutions carried through** | ARCH-1 → T027 + T031; ARCH-2 → T017 + T043, with T043 explicitly naming `Controller.php:322`, the second composition site the first draft missed |
| **The riskiest behaviour has a dedicated regression test** | T012 exists solely to prove the corrective UPDATE cannot revert a deliberate operator switch — the failure mode most likely to pass unnoticed |
| **Abuse case tested, not just the happy path** | T039 asserts the diagnostic ability is absent from `ToolAbilities`, from discover, and from healthy servers — the leak SEC-002 identified |
| **Non-bypassable CI enforcement** | T031 adds a grep gate so a future fourth `is_enabled` writer fails the build rather than relying on review memory |
| **Data-integrity guarantee is tested** | T044 asserts a deactivate→reactivate cycle leaves curated rows byte-identical, proving the runtime swap writes nothing |
| **Test-harness hazard pre-empted** | T011 instructs explicit schema restore because DDL escapes `WP_UnitTestCase` rollback (B53) — a known repo-specific trap |
| **Static analysis explicitly declared insufficient** | T014 requires a real wp-admin page load, because PHPCS and PHPStan both passed on code that fatally broke the site during pre-planning |
| **Correct pinned test idiom stated up front** | The header mandates `@dataProvider` over `#[DataProvider]` under the `^9.6` pin, pre-empting the stale B9 guidance |

---

## Action Plan & Next Steps

1. **Before implementation** — move **T027** and **T031** into Phase 2 (SEC-005), add the
   validation-rejection test task (SEC-006), and drop the `[P]` from **T034** (SEC-007). All
   three are task-list edits, not design changes.
2. **During implementation** — extend T049's copy per SEC-008.
3. **Optional** — the D19-style refusal signal (SEC-009).
4. **Durable Memory Preservation** — SEC-005 is a reusable sequencing lesson: *a security
   boundary whose rule is foundational but whose enforcement is scheduled in a later story
   creates a shippable window with the rule and no enforcement.* Worth capturing.
5. No Critical or High findings, so `/speckit.security-review.followup` is **not** required.

---

## Memory Hub INDEX.md Row

```text
| specs/090-server-types/security-constraints-tasks.md | tasks | 2026-09-15 | MODERATE | C:0 H:0 M:2 L:2 | A01,A04,A05 |
```
