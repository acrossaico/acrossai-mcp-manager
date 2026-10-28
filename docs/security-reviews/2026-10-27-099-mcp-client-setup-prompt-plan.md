---
document_type: security-review
review_type: plan
assessment_date: 2026-10-27
codebase_analyzed: acrossai-mcp-manager / specs/099-mcp-client-setup-prompt
total_files_analyzed: 8
total_findings: 7
overall_risk: HIGH
critical_count: 0
high_count: 1
medium_count: 3
low_count: 1
informational_count: 2
owasp_categories: [A01, A04, A09]
cwe_ids: [CWE-522, CWE-200, CWE-269, CWE-778, CWE-79, CWE-307]
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

# SECURITY REVIEW REPORT — PLAN

## Executive Summary

The plan is unusually well defended for a credential-issuing feature. It adds no storage, no new
route and no second issuance path; it keeps credentials out of the DTO entirely; and it pulls four
missing protections (availability check, access gate, no-store headers, UUID retention) onto a
route that lacks all of them today. Those are real improvements to existing security posture, not
just new-code hygiene.

**One finding dominates, and it is not a coding defect — it is a scope question the plan has not
answered.** The feature's purpose is to put a live credential into an AI assistant's conversation.
For hosted assistants that means the credential leaves the operator's machine and is retained by a
third-party vendor. The plan documents this honestly as a tradeoff (FR-020), but it describes the
credential only as "a live password". A WordPress Application Password is not scoped to MCP: it
authenticates as the user, with **all of that user's capabilities, across the entire REST API**.
Clarification Q1 then made the feature administrator-only, which means every credential this
feature issues is a full-administrator credential.

The combination — maximum-privilege credential, deliberately exported to a third party — is the
risk worth deciding about before implementation, not after.

Nothing here blocks the plan. One HIGH finding requires a decision; the rest are bounded.

## Plan Artifacts Reviewed

| Artifact | Bytes |
|---|---|
| `specs/099-mcp-client-setup-prompt/plan.md` | 8,653 |
| `specs/099-mcp-client-setup-prompt/spec.md` | 28,348 |
| `specs/099-mcp-client-setup-prompt/research.md` | 9,840 |
| `specs/099-mcp-client-setup-prompt/data-model.md` | 5,590 |
| `specs/099-mcp-client-setup-prompt/quickstart.md` | 3,996 |
| `specs/099-mcp-client-setup-prompt/contracts/{client,dto,rest}-contract.md` | 5,970 |
| `specs/099-mcp-client-setup-prompt/memory-synthesis.md` | 6,577 |

Memory hub: optimizer disabled, markdown-only. Baseline is `constitution.md` v1.1.1 Principle III
plus `docs/memory/` (S3, SEC-013-002, SEC-035-002, D19, D33, B19, B72).
`.specify/memory/security_constitution.md` is absent.

## Vulnerability Findings

### [HIGH] SEC-099-001 — The exported credential carries full administrator authority, and the plan does not say so

**Location:** `spec.md` FR-020, FR-024; `plan.md` Summary; `data-model.md` "Issued credential"
**OWASP Category:** A04:2025-Insecure Design
**CWE:** CWE-522: Insufficiently Protected Credentials; CWE-269: Improper Privilege Management
**CVSS:** 7.4

**Description:** A WordPress Application Password is not scoped to the MCP endpoint. It
authenticates as the user for the whole REST API, so it can create users, install plugins, read
private content and change settings — anything that user can do. FR-024 restricts generation to
administrators, so **every credential this feature issues is an administrator credential**.

The feature then deliberately places that credential into a prompt the operator pastes into an AI
client. For hosted assistants the credential transits to a vendor and is retained in conversation
history, which may be logged, used for training depending on plan, and readable by anyone with
access to that account. This is a materially different exposure from the manual path, which moves
the same credential from clipboard to a local file and no further.

FR-020 requires disclosure, but the wording the spec models — "contains a live password" —
understates it. An operator can reasonably read that as "a password for this MCP connection".

**Remediation:**
1. Make FR-020's disclosure state the **scope**, not just the existence: that the credential acts
   as the full account across the whole site, and that pasting it into a hosted assistant sends it
   to that vendor.
2. Decide and record whether this feature should recommend a dedicated, lower-privileged WordPress
   user for AI connections. FR-024's administrator-only rule currently forecloses that — the two
   requirements interact and should be decided together.
3. Keep revocation one step away (already FR-020) and ensure the revoke affordance is reachable
   from the same panel, not merely documented.

**Spec-Kit Task:** TASK-SEC-001

---

### [MEDIUM] SEC-099-002 — Administrator-only maximises the blast radius of SEC-099-001

**Location:** `spec.md` FR-024; clarification Q1
**OWASP Category:** A01:2025-Broken Access Control
**CWE:** CWE-269: Improper Privilege Management
**CVSS:** 5.3

**Description:** Q1 chose administrator-only, which is defensible for surface-reduction reasons —
fewer people can mint. But it has an inverse effect on credential strength: a lower-privileged user
would have minted a correspondingly weaker credential. By restricting *who* can generate, the
design guarantees that *every* generated credential is maximally privileged, which is the worst
case for anything that leaves the machine.

This is not an argument to reverse Q1. It is an argument that Q1 and FR-020's disclosure cannot be
decided independently.

**Remediation:** Record the interaction explicitly in the spec's Assumptions so a future increment
that broadens access below administrator revisits the disclosure at the same time — the same
coupling already noted for FR-028's no-throttle decision.

**Spec-Kit Task:** TASK-SEC-002

---

### [MEDIUM] SEC-099-003 — Credentials outlive the record of where they came from

**Location:** `research.md` R7; `spec.md` FR-025, FR-027
**OWASP Category:** A09:2025-Security Logging and Monitoring Failures
**CWE:** CWE-778: Insufficient Logging
**CVSS:** 4.3

**Description:** FR-025 makes credentials accumulate with no expiry and no automatic cleanup.
FR-027 records each issuance, but R7 routes that record to `EVENT_REQUEST`, which is retained
**30 days**. After 30 days a credential still exists and still works, with no remaining record of
who created it, for which client, or why. The Tokens tab proves existence, not provenance.

This is a direct consequence of a tradeoff I recorded in R7 and is worth surfacing rather than
leaving implicit: the mitigation chosen for "no rate limiting" (record instead) decays while the
thing it was mitigating does not.

**Remediation — revised after the architecture scan.** My first draft of this finding recommended
exempting `SOURCE_AUTH` rows from the 30-day retention. That advice was wrong, or at least much
more expensive than it sounded: `prune_by_event_type( string $event_type, int $days )`
(`Query.php:644`) validates `in_array( $event_type, EVENT_TYPES, true )` and has no source
dimension; it is called only from `Cleanup.php:107,111`; and the Query class docblock states the
design intent as *"retention, per event kind (never one global cutoff)"*. Adding a source
exemption means a new Query API, a Cleanup change, new tests, and cutting against documented
intent — for a log row.

**Prefer instead: make the credential's own name carry the provenance.** FR-019 already requires
the name to carry `APP_NAME_PREFIX`; the existing sibling path already names credentials
`AcrossAI MCP — <client_slug> (server #<id>)`. If the name encodes client, server and date, then
provenance lives in the Tokens tab **indefinitely**, survives any log retention, and costs one
`sprintf`. The activity-log row then becomes a 30-day convenience rather than the system of
record, and FR-027's purpose — answering where a pile of credentials came from — is satisfied by
the durable surface rather than the expiring one.

Order of preference:
1. Encode client + server + date in the credential name (durable, ~free, no retention work).
2. State in the spec that log provenance is 30-day information and the Tokens tab is the record.
3. Extend the retention API with a source dimension — only if 1 and 2 are both rejected.

**Spec-Kit Task:** TASK-SEC-003

---

### [MEDIUM] SEC-099-004 — The stricter credential handler remains unreachable (pre-existing, knowingly deferred)

**Location:** `contracts/rest-contract.md` "Explicitly NOT added"; `includes/REST/ClientRendererController.php`
**OWASP Category:** A01:2025-Broken Access Control
**CWE:** CWE-200: Exposure of Sensitive Information
**CVSS:** 5.0

**Description:** Two handlers register `POST /generate-app-password`; only the first-registered
serves requests, so the Feature-013 handler's three invariants (context-bound nonce, `user_id`
lockdown, access gate) are dead code. The plan correctly scopes this out — resolving it changes a
ratified security model — and correctly compensates by porting the gates onto the handler that does
serve traffic.

Recorded here as an **accepted risk**, not a new one. The feature reduces its impact rather than
increasing it.

**Remediation:** File the separate issue before this feature merges, so the deferral is tracked
rather than forgotten. Include the observation from `rest-contract.md` that the unreachable handler
exists *for* the front-end contexts now being removed, so it may resolve by deletion.

**Spec-Kit Task:** TASK-SEC-004

---

### [LOW] SEC-099-005 — Prompt rendering must escape at both boundaries

**Location:** `contracts/dto-contract.md`; `spec.md` FR-022
**OWASP Category:** A01:2025-Broken Access Control
**CWE:** CWE-79: Cross-site Scripting
**CVSS:** 3.5

**Description:** DTO strings are deliberately unescaped (SEC-035-002) and the prompt is assembled
from client-supplied values — including `get_client_name()` and `get_config_file()` from classes
third parties register via `acrossai_mcp_client_classes`. A hostile or careless third-party client
class could return markup. The React surface is safe by construction (text nodes only, already
required). The PHP surface depends on the renderer escaping correctly.

**Remediation:** State the escaping function explicitly in the tasks (`esc_textarea()` for the
prompt block, `esc_html()` for labels) rather than leaving it to "escape at the render boundary",
and add a test that a client class returning markup cannot inject it into the rendered page.

**Spec-Kit Task:** TASK-SEC-005

---

### [INFORMATIONAL] SEC-099-006 — No rate limiting, assessed and accepted

**Location:** `spec.md` FR-028; `research.md` R9
**OWASP Category:** A04:2025-Insecure Design
**CWE:** CWE-307: Improper Restriction of Excessive Authentication Attempts

**Description:** The reasoning is sound — an administrator can already mint unlimited credentials
through core. Noted only that core's path (`profile.php`, one at a time) has materially higher
friction than a one-click control inside a workflow, so accumulation is likelier here. FR-025's
visible count is the right compensating control.

No action required.

---

### [INFORMATIONAL] SEC-099-007 — Clipboard exposure is inherent

**Location:** `spec.md` FR-020

**Description:** The copy control places a full administrator credential on the system clipboard,
readable by any local process until overwritten. Inherent to any copy-paste credential flow,
including the manual path. Noted for completeness.

No action required.

## Confirmed Secure Patterns

| Pattern | Where | Why it matters |
|---|---|---|
| **Credential never enters the DTO** | FR-008, `data-model.md` | The prompt is built server-side with an empty token. A cached, logged or filtered DTO cannot leak a credential — the strongest single design choice in this plan |
| Issued only for `get_current_user_id()` | FR-016 | SEC-013-002; a request-supplied id cannot drive impersonation |
| Availability checked before issuing | FR-014 | Turns a confusing failure into a stated cause; nothing in the plugin did this before |
| D33 access gate, failing open per D19 | FR-015 | Mandatory for connection-time issuance; absent from this route today |
| No-store on credential responses | FR-017 | Both sibling mint paths already do this; this one did not |
| UUID retained for precise revocation | FR-018 | Directly prevents the B72 `--all` disaster |
| Credentials remain core-hashed | S3 | Plugin persists no secret |
| One issuance path, no new route | FR-023, SC-013 | Prevents the duplicate-handler problem from recurring |
| Capability checked at the panel, not hardcoded in the renderer | R6 | Honours FR-024 without violating `DEC-CLIENT-RENDERER-PUBLIC-API` |
| Username emitted alongside password | FR-005, B19 | Prevents the silent-401 failure mode |
