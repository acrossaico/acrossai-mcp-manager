---
document_type: security-review
review_type: staged
assessment_date: 2026-09-17
codebase_analyzed: acrossai-mcp-manager (F090 — architecture-review remediation)
total_files_analyzed: 28
total_findings: 4
overall_risk: LOW
critical_count: 0
high_count: 0
medium_count: 0
low_count: 1
informational_count: 3
owasp_categories: [A05, A08]
cwe_ids: [CWE-706, CWE-1188, CWE-565]
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

# SECURITY REVIEW REPORT — STAGED CHANGES

## Executive Summary

**No Critical, High or Medium findings.** Every behavioural change in this diff moves in the
**fail-closed** direction: `registered_only()` narrows what a server serves, `pool_for()` is a
subset of the previous `ToolAbilities::get_slugs()`, and the plugin installer now errors rather
than guessing a path.

One LOW was found during review and **fixed before the commit** (SEC-090R-001). Three
informational notes are recorded below.

`.specify/scripts/bash/detect-changed-files.sh` is absent from this repo; scope was taken from
`git diff --cached`.

## Staged Diff Reviewed

28 files. Security-relevant code: `ServerTypes.php` (+175), `QuickConnectController.php` (+52),
`ToolPolicy.php`, `AbilitiesManagerPromoCard.php`, `ToolsController.php`, and the new
`ServerTypesTest.php`. `Schema.php` and `ToolsController.php` are otherwise comment-only;
`build/*` is generated output.

## Vulnerability Findings

### [LOW] Directory match could activate a secondary plugin file — fixed in this diff

**Location:** `includes/REST/QuickConnectController.php:450`
**OWASP Category:** A05:2025-Security Misconfiguration
**CWE:** CWE-706: Use of Incorrectly-Resolved Name or Reference
**CVSS:** 2.6

The new resolver returned the FIRST `get_plugins()` entry matching `$slug . '/'`. A plugin
directory may contain more than one file carrying a plugin header, so `activate_plugin()` could
have activated a secondary file rather than the real entry point. Not reachable today —
`INSTALLABLE_PLUGIN_SLUGS` holds two plugins we ship, neither with a second header file — but it
is a behaviour change from the previous exact-key lookup and would become reachable the moment a
third slug is allow-listed.

**Remediation (applied):** prefer the candidate whose basename matches the slug, falling back to
the first directory match. Expressed by INSPECTING `get_plugins()` rather than constructing
`slug/slug.php`; the first attempt at the fix built that path as a guarded preference and tripped
the new CI gate, correctly. See BUGS.md B61 — evading the gate with `sprintf()` would have passed
CI while restoring the defect.

**Spec-Kit Task:** TASK-SEC-090R-001 — complete.

### [INFO] `expose`/`hide` renamed from `all`/`none` with no data migration

**Location:** `includes/Database/MCPServer/ToolPolicy.php:61-74`

A row still storing `'all'` matches neither branch of the precedence chain and falls through to
the per-tool composition — **fail-closed**: fewer tools exposed, never more. Verified no live rows
are affected (both servers read `per-tool`), and the column shipped inside this same unreleased
feature, so no released site can hold the old vocabulary.

### [INFO] `ServerTypes::plugin_is_active()` widened from private to public

**Location:** `includes/Database/MCPServer/ServerTypes.php:483`

Deliberate, so `AbilitiesManagerPromoCard` delegates instead of re-deriving (DECISIONS.md D58).
A read-only predicate over `active_plugins`, a public option — no capability check warranted, no
state mutated, and it discloses nothing `is_plugin_active()` did not already expose to any caller.

### [INFO] A type's `tools` can remove slugs from other types' pools

**Location:** `ServerTypes::pool_for()`

Any plugin filtering `acrossai_mcp_server_types` can claim another plugin's slugs and thereby
subtract them from every other type's pool. Denial-of-capability, not privilege escalation, and it
requires code already running on the site. Documented as a contract rule ("claim only slugs your
own plugin registers") in `docs/extending-server-types.md` §2.1 and in the filter contract.

## Confirmed Secure Patterns

- **Input validation unchanged at the boundary.** `$slug` is `sanitize_key()`-ed AND checked
  against `INSTALLABLE_PLUGIN_SLUGS` before any filesystem or network call. The new resolver
  consumes an already-validated value; no traversal is reachable through `$slug . '/'`.
- **Error hygiene preserved.** The new failure path logs internally and returns the same generic
  operator-facing message as its siblings — no path, version or upgrader detail surfaced.
  Consistent with the class docblock's TASK-SEC-003 constraint.
- **Fail-closed composition.** `registered_only()` wrapping `compose_for_row()` can only REMOVE
  slugs; `pool_for()` is a subset of the previous `ToolAbilities::get_slugs()`.
- **No admin bootstrap in the transport path.** Dropping `wp-admin/includes/plugin.php` from
  `ServerTypes` reduces what loads on every MCP and REST request (A3, defence in depth).
- **REST surface unchanged.** No new routes; `type_pool` and `server_types` are additive response
  fields behind the existing `manage_options` `permission_callback`.
- **Test isolation.** `ServerTypesTest` backs up and restores `active_plugins` in
  `set_up`/`tear_down` and removes its filters. Option writes are in-transaction and roll back,
  unlike the DDL case BUGS.md B53 records.

## Action Plan

1. ~~SEC-090R-001~~ — fixed and re-staged before commit.
2. Nothing blocking. Gates green: PHPCS clean, PHPStan level 8 zero errors, ESLint clean, all
   F021 governance gates pass, live MCP request verified against the refactored path.
