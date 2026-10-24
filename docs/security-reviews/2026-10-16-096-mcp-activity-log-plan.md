---
document_type: security-review
review_type: plan
assessment_date: 2026-10-16
codebase_analyzed: acrossai-mcp-manager (F096 — MCP Activity Log)
total_files_analyzed: 9
total_findings: 7
overall_risk: MODERATE
critical_count: 0
high_count: 0
medium_count: 4
low_count: 2
informational_count: 1
owasp_categories: [A03, A04, A05, A09]
cwe_ids: [CWE-79, CWE-117, CWE-359, CWE-532, CWE-778]
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

# Security Review — F096 MCP Activity Log (Plan)

## Executive summary

**Overall risk: MODERATE.** No critical or high findings. The plan's conventional attack
surface — REST authorization, SQL construction, mass assignment, XSS escaping — is
well-covered, in several places better than the constitution requires. Every finding below
instead comes from the one property that makes this feature different from the twelve plans
reviewed before it: **it is an audit log, so its own integrity and confidentiality are the
security property**, not a side-concern.

Four of the seven findings share a single root cause worth stating plainly: *the plan treats
the log as a diagnostic convenience, and designs its failure modes accordingly.* A diagnostic
tool may silently stop working, be wiped without record, and store whatever the caller sent.
An audit record may not. Nothing here requires redesign — the fixes are a recorded purge
event, de-identification instead of deletion, and two honesty constraints in the UI — but they
should land in the plan before tasks, because retrofitting accountability onto a log that has
already been shipped without it means a schema change.

The feature also *improves* the plugin's posture in one concrete way: it stores no credentials
at all, and it is the first feature to bring WordPress's personal-data export and erasure hooks
into this plugin, which had none.

## Plan artifacts reviewed

| Artifact | Role in review |
|---|---|
| `specs/096-mcp-activity-log/plan.md` | Constitution Check, Complexity Tracking, structure |
| `specs/096-mcp-activity-log/spec.md` | 42 FR / 20 SC, Security Checklist, Assumptions |
| `specs/096-mcp-activity-log/data-model.md` | 30 columns, validation rules, lifecycle |
| `specs/096-mcp-activity-log/contracts/rest-activity.md` | 6 routes, authorization, error contract |
| `specs/096-mcp-activity-log/research.md` | 9 decisions incl. redaction and capture seams |
| `specs/096-mcp-activity-log/quickstart.md` | Operator-facing behaviour and filters |
| `specs/096-mcp-activity-log/memory-synthesis.md` | S1–S9, B7/B11/B34, A15/A17 |
| `.specify/memory/constitution.md` (v1.1.1) | Principle III as the normative baseline |
| `docs/memory/INDEX.md` | Security constraints S1–S9; 46 prior reviews |

`.specify/memory/security_constitution.md` **does not exist** — see SEC-007.

## Vulnerability findings

### SEC-001 — Purging the activity log leaves no record that it happened (MEDIUM)

- **location**: `specs/096-mcp-activity-log/contracts/rest-activity.md` (route 6, `DELETE /activity`)
- **owasp_category**: A09:2025-Security Logging and Monitoring Failures
- **cwe**: CWE-778: Insufficient Logging
- **cvss_score**: 4.9
- **spec_kit_task**: TASK-SEC-001

`DELETE /activity` erases the table and returns a count. Nothing records **who** purged,
**when**, or **how much** — and because the purge targets the only activity log, the act of
destroying the evidence is itself unrecorded. The same applies to the retention cron and the
new retained-event ceiling, both of which delete rows on a schedule.

This matters more here than in the twelve prior plan reviews, because those features' logs were
incidental; this one *is* the accountability record. An administrator who misuses an MCP
connector and then purges the log leaves a clean site. The reference implementation
(easy-mcp-ai) has the same gap, so there is no precedent to inherit.

**Recommendation**: record purge as an event *in the table it purges* — write the "purged N
events, by user X, at T" row **after** the delete, so it survives as row 1 of the new history.
Cheap (one `Recorder` call), needs no schema change, and makes the gap self-closing. Cron-driven
retention and ceiling trims should write the same event kind at most once per run.

### SEC-002 — Personal-data erasure destroys audit evidence (MEDIUM)

- **location**: `specs/096-mcp-activity-log/spec.md` (FR-034)
- **owasp_category**: A09:2025-Security Logging and Monitoring Failures
- **cwe**: CWE-778: Insufficient Logging
- **cvss_score**: 5.3
- **spec_kit_task**: TASK-SEC-002

FR-034 requires an eraser that *"removes or de-identifies"* a user's recorded activity. The
disjunction is the problem: "removes" lets a subject-data request delete audit history, which
converts a privacy mechanism into an evidence-destruction mechanism available to any user who
can file one. WordPress's eraser runs on request, not on administrator judgement.

**Recommendation**: narrow FR-034 to **de-identification only** — null the `user_id`, clear
`ip_address` and `user_agent`, retain the event, its timing, its outcome and its server. That
fully satisfies a subject request (the data subject is no longer identifiable) while preserving
the security record, and it is the standard resolution of this well-known tension. State the
choice in the eraser's response message so the operator can explain it.

### SEC-003 — Pattern-based redaction is incomplete by construction (MEDIUM)

- **location**: `specs/096-mcp-activity-log/research.md` (D-2), `spec.md` (FR-014), `data-model.md` (validation rules)
- **owasp_category**: A09:2025-Security Logging and Monitoring Failures
- **cwe**: CWE-532: Insertion of Sensitive Information into Log File
- **cvss_score**: 5.5
- **spec_kit_task**: TASK-SEC-003

`input_preview` stores a redacted, truncated copy of tool arguments. Redaction is
`McpObservabilityHelperTrait::sanitize_tags()` — 24 **key-name** patterns (`token`, `secret`,
`api_key`, …). Reusing it is right (Principle VI, one list not two), but its guarantee is
narrower than the plan implies: it redacts values whose *key* matches. It cannot redact a
secret that arrives in a free-text argument — a `content` field containing a pasted API key, a
`search` term containing a password, a `message` body containing a customer's data. MCP tool
arguments are arbitrary and frequently prose.

The spec's Security Checklist records this as "all user input sanitized… every recorded tag",
which overstates what is achieved and is the kind of line a later reader trusts.

**Recommendation**: keep the redaction, correct the claim. (a) State in the spec that redaction
is key-name-based and that free-text argument values are stored as sent, subject to truncation.
(b) Surface it where it is actionable — the settings copy for ability logging should say that
arguments are recorded. (c) Add a filter (`acrossai_mcp_activity_input_preview`) so a site
handling regulated data can suppress or further scrub previews without disabling the log.

### SEC-004 — The log renders attacker-influenced content to an administrator (MEDIUM)

- **location**: `specs/096-mcp-activity-log/plan.md` (UI), `contracts/rest-activity.md` (route 4)
- **owasp_category**: A03:2025-Injection
- **cwe**: CWE-79: Improper Neutralization of Input During Web Page Generation
- **cvss_score**: 5.4
- **spec_kit_task**: TASK-SEC-004

`component_name`, `error_message`, `client_name`, `user_agent` and `input_preview` all
originate outside the site — from the connected MCP client. They are then rendered on an
admin screen to a `manage_options` user: the highest-value XSS target on the install. A
semi-trusted or compromised client can therefore attempt to attack the administrator *through
the audit UI*, and a stored payload persists for the retention window.

React escapes text children by default and DataViews renders through React, so the default path
is safe. The risk is in the exceptions: the PHP mount that localizes `initialState`, any
`dangerouslySetInnerHTML`, any future PHP-rendered column or CSV export, and the
`admin_url()`-derived deep links (S5 — it is filterable).

**Recommendation**: make the constraint explicit rather than incidental. (a) `wp_json_encode()`
for every localized value, never `esc_html()`/`esc_attr()` — B36 records that both are
HTML-context escapers that do not make a value safe inside a JS string literal. (b) Forbid
`dangerouslySetInnerHTML` anywhere in `src/js/activity-log/`. (c) `esc_url()` on every
`admin_url()` per S5. (d) Add a test asserting a scripted `component_name` round-trips to the
screen inert.

### SEC-005 — A recorder that cannot write fails invisibly (LOW)

- **location**: `specs/096-mcp-activity-log/spec.md` (FR-013), `data-model.md` (A15 contract)
- **owasp_category**: A09:2025-Security Logging and Monitoring Failures
- **cwe**: CWE-778: Insufficient Logging
- **cvss_score**: 3.7
- **spec_kit_task**: TASK-SEC-005

FR-013 and A15 require the recorder to swallow every `\Throwable` and route it to
`error_log()`. That is correct — a logging bug must never break an MCP call. The security
consequence is that **recording can stop while the UI continues to look healthy**: a full disk,
a dropped table, a broken index, or B34's silent drift (where `add_item()` returns `false` and
the caller reads success) all produce an empty-looking but "enabled" log. An attacker who can
provoke write failure gets unlogged activity.

**Recommendation**: distinguish "no events" from "could not record". Track consecutive write
failures in an option; when non-zero, the screen says recording is failing and names the
`error_log` as the place to look. This also closes the gap between SC-005's "nothing in the
list" and a genuine fault.

### SEC-006 — Full address retention broadens breach impact (LOW)

- **location**: `specs/096-mcp-activity-log/spec.md` (FR-035, Clarification Q1)
- **owasp_category**: A04:2025-Insecure Design
- **cwe**: CWE-359: Exposure of Private Personal Information to an Unauthorized Actor
- **cvss_score**: 3.3
- **spec_kit_task**: TASK-SEC-006

Full IPv4/IPv6 addresses are retained for 30 days against every event, joined to `user_id` and
`user_agent` — a per-user movement record where none existed before. This was a deliberate,
recorded choice (Q1: full address + privacy hooks, over the anonymised alternative) justified
by a real diagnostic need, and it follows core's own treatment of `comment_author_IP`. Noted
rather than contested.

**Recommendation**: no change to the decision. Two cheap reductions: have the retained-event
ceiling and retention default apply to addresses as aggressively as to the rest of the row, and
document in the privacy-exporter output that addresses are included, so a subject request
returns a complete picture.

### SEC-007 — No security constitution exists for this project (INFORMATIONAL)

- **location**: `.specify/memory/security_constitution.md` (absent)
- **owasp_category**: A05:2025-Security Misconfiguration
- **cwe**: n/a (process)
- **cvss_score**: 0.0
- **spec_kit_task**: TASK-SEC-007

`/speckit-security-review-init` has never run: only
`.specify/extensions/architecture-guard/templates/` holds constitution templates. This review
therefore ran against Principle III plus S1–S9 from `docs/memory/`, which is a solid baseline
but is not the artifact the workflow expects, and nothing enforces that future reviews use the
same baseline. 46 prior reviews share this gap.

**Recommendation**: run `/speckit-security-review-init` once, seeding it from Principle III and
S1–S9 so the de facto baseline becomes the declared one.

## Confirmed secure patterns

Verified present in the plan, several exceeding the constitution's minimum:

| Pattern | Evidence |
|---|---|
| **No credentials stored** | Only `connector_slug` and a client-reported application name. S3 / the token-hashing rule is satisfied vacuously — there is nothing to hash. Notably stronger than the reference plugin, which stores a `token_id` |
| **Uniform explicit authorization** | All six routes via `permission_check` + `manage_options`; `__return_true` appears nowhere. S2 satisfied with no new exception; S7/S8 not invoked |
| **No raw bodies, ever** | The seam does not supply them and the plan declines to reconstruct them. This is precisely where the third-party `mcp-tracker` plugin fails (LONGTEXT bodies, no truncation, no redaction) |
| **Prepared statements including the hard case** | `$wpdb->prepare()` named explicitly for the keyset comparison and the per-`event_type` cutoffs — the two places a hand-rolled query would have interpolated. S4 |
| **Mass-assignment guard** | B7 applied to both untrusted-key surfaces: the declarative column map and all 19 collection params filter against `Schema::columns()` |
| **Cache-poisoning guard** | B11 applied — the transient-cached summary and filter sets are validated with `is_array()` + `isset()` + `is_numeric()` on read |
| **Cross-request identity leak closed** | A17's three-stage holder lifecycle, shutdown included, is now FR-016/FR-017 with SC-020 requiring verification on a process-reusing runtime. Had this been missed, the log would have attributed one client's calls to another |
| **Read-only observation of vendor filters** | `ResponseSizeProbe` takes a length and returns `$result` byte-identical, with a per-filter test. No tool result can be altered by the logging path |
| **Blast radius of purge is bounded** | Isolation from F097's table asserted by test in both directions |
| **Privacy plumbing introduced** | First exporter and eraser in a plugin that had none (FR-033/034) — the mechanism is right even where SEC-002 narrows its semantics |
| **Silent-drift detection** | B34 addressed by asserting column **widths**, not merely presence |

## Action plan & next steps

**Before `/speckit-tasks`** — three plan amendments, all small, none structural:

1. **SEC-001**: add a recorded purge/trim event (new `event_type` value or a reserved
   `component_name`); no schema change.
2. **SEC-002**: narrow FR-034 from "removes or de-identifies" to de-identification only.
3. **SEC-003 / SEC-004**: correct the Security Checklist's redaction claim, add the
   preview filter, and pin the three XSS constraints (`wp_json_encode`, no
   `dangerouslySetInnerHTML`, `esc_url()`).

**Deferrable to implementation**: SEC-005 (failure counter + UI state), SEC-006 (documentation
only).

**Project-level, outside this feature**: SEC-007 — run `/speckit-security-review-init`.

No critical or high findings, so `/speckit-security-review-followup` is **not** required. The
three pre-task amendments are better applied directly to `spec.md` and `plan.md` than tracked
as separate remediation tasks.

**Durable memory**: two candidates, both generalising beyond this feature — "an audit log must
record its own destruction" and "subject-data erasure on an audit log de-identifies, it does
not delete". Proposed through the formal capture flow, not written silently.
