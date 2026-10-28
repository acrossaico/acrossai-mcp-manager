---
document_type: security-review
review_type: staged
assessment_date: 2026-10-28
codebase_analyzed: acrossai-mcp-manager / 68d5f9d..eeac447 (PR #194)
total_files_analyzed: 17
total_findings: 5
overall_risk: LOW
critical_count: 0
high_count: 0
medium_count: 1
low_count: 2
informational_count: 2
owasp_categories: [A01, A03, A09]
cwe_ids: [CWE-1286, CWE-20, CWE-532, CWE-522]
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

# SECURITY REVIEW REPORT — SHIPPED DIFF (68d5f9d..eeac447)

## Executive Summary

Nothing was staged at review time — the work merged as PR #194 — so the audit set is that commit
range rather than the index.

**No critical or high findings.** The 22 constraints from the plan and task reviews were all
implemented as specified, and the hardening this feature added to the credential route is a net
improvement on what existed before it: availability checks, the D33 access gate failing open, the
requesting-user lock, no-store headers, UUID retention and issuance recording were all absent from
that route in 0.4.5.

The findings below are ones a document review could not have reached, because they live in code
written after those reviews: a TOML emitter, a substring match used for counting, and a
client-side route toggle.

## Diff Reviewed

17 non-build source files. Credential path: `admin/Partials/ApplicationPasswords.php`. Contract and
serialisation: `includes/MCPClients/{AbstractMCPClient,CodexClient}.php`. Shared utilities:
`includes/Utilities/{CredentialNaming,OperatingSystemLabels}.php`. Producer:
`public/Discovery/ConnectionMethodRegistry.php`. Renderers: `public/Renderers/MCPClientsBlock.php`,
`src/js/backend.js`, `src/js/quick-connect/{components/CodeBlock.jsx,steps/Step11_ClientDetail.jsx}`.
Plus `tests/bootstrap.php` and six test files.

## Vulnerability Findings

### [MEDIUM] SEC-S-001 — Credential counting uses substring matching, so one server can count another's credentials

**Location:** `includes/Utilities/CredentialNaming.php` — `is_for_server()`
**OWASP Category:** A01:2025-Broken Access Control
**CWE:** CWE-1286: Improper Validation of Syntactic Correctness of Input
**CVSS:** 4.3

**Description:** `is_for_server()` decides membership with
`false !== strpos( $name, $server_name )`. Credential names are built as
`AcrossAI MCP Manager — <server> — <client> — <date>`, so a server whose display name is a substring
of another's matches both. A site with servers named `Test` and `Test Server` will count every
`Test Server` credential toward `Test`.

The consequence is a wrong number beside the Copy button, and it is wrong in the direction that
matters: it **overstates** how many credentials exist, and the whole reason FR-025 requires that
number is to make accumulation visible and honest. An operator who sees an inflated count may
believe they already revoked something they did not.

Not an access-control hole — the count is derived from the caller's own credentials, so nothing
leaks across users — but it is an integrity defect in a figure the operator is asked to act on.

**Remediation:** Match on a delimited segment rather than a bare substring: the name already uses
` — ` as a separator, so splitting on it and comparing the server field exactly removes the
ambiguity. Server names containing the separator should be rejected or encoded at write time.

**Spec-Kit Task:** TASK-SEC-020

---

### [LOW] SEC-S-002 — TOML escaping omits the control characters the format requires escaping

**Location:** `includes/MCPClients/CodexClient.php` — `toml_escape()`
**OWASP Category:** A03:2025-Injection
**CWE:** CWE-20: Improper Input Validation
**CVSS:** 2.6

**Description:** `toml_escape()` handles `\`, `"`, `\n`, `\r` and `\t`. The TOML specification also
requires escaping the remaining control characters (U+0000–U+0008, U+000B–U+000C, U+000E–U+001F,
U+007F) inside basic strings. A value containing one would produce a file Codex cannot parse.

Exploitability is close to nil in practice: the three interpolated values are the REST URL from
`rest_url()`, the username from `wp_get_current_user()->user_login` (WordPress constrains the
character set), and a core-generated password. None can currently carry a raw control character.

It is recorded because the function is a general-purpose escaper by its name and signature, and the
next value routed through it may not come from those three sources.

**Remediation:** Add the remaining control-character range, or restrict the function's contract in
its docblock to the values it is actually safe for.

**Spec-Kit Task:** TASK-SEC-021

---

### [LOW] SEC-S-003 — Issuance records carry an operator-supplied label

**Location:** `admin/Partials/ApplicationPasswords.php` — `record_issuance()`
**OWASP Category:** A09:2025-Security Logging and Monitoring Failures
**CWE:** CWE-532: Insertion of Sensitive Information into Log File
**CVSS:** 2.0

**Description:** The issuance record's `input_preview` holds the credential name, which now includes
a caller-supplied `name` parameter (`sanitize_text_field`) and `client` slug (`sanitize_key`). An
administrator therefore controls part of what is written to the activity log.

Both are sanitised at the boundary and the value is JSON-encoded before storage. The credential
itself is explicitly excluded, which was SEC-T-002's requirement and is asserted by a test that
scans every written row for any substring of the issued password.

Noted for completeness: operator-influenced content in an audit record is worth knowing about even
when correctly sanitised.

**Remediation:** None required.

---

### [INFORMATIONAL] SEC-S-004 — The route toggle writes a server-rendered href to history

**Location:** `src/js/backend.js` — route toggle handler

**Description:** The handler calls `window.history.replaceState( {}, '', card.href )` using the
`href` of a server-rendered anchor built with `esc_url( add_query_arg(…) )`. The value is not
attacker-controlled: reaching it would require injecting markup into the admin page, at which point
`replaceState` is not the problem.

The handler also `preventDefault()`s only when both a route target and at least one panel are
present, so with nothing to toggle the link navigates normally. That is the correct failure
direction.

**Remediation:** None.

---

### [INFORMATIONAL] SEC-S-005 — The feature's central risk is unchanged and correctly disclosed

**Location:** `public/Renderers/MCPClientsBlock.php` — `render_prompt_credential_disclosure()`

**Description:** The prompt embeds a live Application Password, which is **not scoped to MCP** — it
authenticates as the user across the whole REST API — and FR-024 makes the feature
administrator-only, so every credential it issues is an administrator credential exported into a
third party's conversation history.

This was SEC-099-001 at plan time and remains the dominant risk. It is inherent to the feature
rather than a defect, and the mitigations specified are all present in the shipped code: the
disclosure names the credential's **reach** rather than its existence, revocation sits in the same
panel, and the credential's name carries durable provenance so the right one can be revoked.

**Remediation:** None. Revisit if access is ever broadened below administrator — FR-028's
no-throttle decision is coupled to that and must be reconsidered at the same time.

## Confirmed Secure Patterns

| Pattern | Evidence |
|---|---|
| Credential never enters the DTO | `get_clients()` builds `setupPrompt` with an empty token; asserted by `SetupPromptDtoTest` |
| Credential never enters a log row | `record_issuance()` passes UUID and name only; a test scans every row for the password |
| Issued only for the caller | `get_current_user_id()`; a request-supplied id is never read |
| Availability refused with a stated cause | `application_password_blocker()` — three distinct error codes |
| D33 gate present and failing **open** | The fail-open branch is explicitly tested, which is the branch most often written backwards |
| No-store on a credential-bearing response | `CacheHeaders::apply_to_rest_response()` |
| Precise revocation possible | UUID retained (B72); verified live by deleting one credential and leaving 18 intact |
| Output escaped at the boundary | `esc_textarea()` for payloads, `esc_html()` for labels, `wp_kses()` with an `a[href]`-only allowlist for the one link |
| Third-party client classes treated as untrusted | `HostileMetadataClient` escaping test still green |
| Navigation input validated against a whitelist | `resolve_setup_path()` — `sanitize_key()` then compared to two known values, unknown falls back |
| Deterministic field ids | Replaced `wp_rand()`, which had broken a byte-identity invariant that exists so capability-excluded methods cannot be enumerated |
| No new dependencies | No composer or npm additions in the diff |
| No rate limiter, deliberately | FR-028, with a test asserting none is wired so the decision cannot be silently reversed |
