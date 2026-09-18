---
document_type: security-review
review_type: plan
assessment_date: 2026-09-15
codebase_analyzed: acrossai-mcp-manager (Feature 090 — Server Types)
total_files_analyzed: 13
total_findings: 4
overall_risk: MODERATE
critical_count: 0
high_count: 0
medium_count: 1
low_count: 2
informational_count: 1
owasp_categories: [A01, A05]
cwe_ids: [CWE-1188, CWE-863, CWE-668, CWE-451]
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

> **Retracted, 2026-09-18 — the coarse tool rule is gone.** Anything below that
> describes `tools_default_policy` (the `expose` / `hide` standing rule, its
> column, and `POST /servers/{id}/tools/policy`) was built, merged to `main` in
> schema `1.1.6`, then removed again in schema `1.1.7` before any release.
> `MCP\ToolExposureGate` gates `tools/call` on curated presence rows alone and
> never read the column, so an `expose` server advertised tools it then refused.
> What a server serves is now decided by its curated selection alone; the Tools
> tab's **Enable All** / **Disable All** write that selection directly. The
> `server_type` half of this feature stands as specified. `abilities_default_policy`
> (F082, Abilities tab) is a different feature and is unaffected.


# Security Review — Feature 090 Plan

## Executive Summary

The plan is **secure by design in its core structure**. The requirement gate is enforced
server-side at the data layer rather than in the interface, exposure decisions route through a
single resolver, and the design explicitly refuses to make an unmet dependency into a
denial-of-service (the route keeps answering rather than 404ing a live session).

**One MODERATE finding**: the plan enumerates the enablement and creation write paths from an
incomplete inventory. A **second server-creation path** exists in Quick Connect that the plan
never lists, and it would leave `server_type` unwritten — meaning the enablement gate
evaluates a type the operator did not choose.

No Critical or High findings. The remaining three are containment and hygiene issues worth
fixing during implementation rather than blockers.

**Overall risk: MODERATE** — driven entirely by SEC-001, which is a one-line fix once known.

## Plan Artifacts Reviewed

| Artifact | Read |
|---|---|
| `specs/090-server-types/plan.md` | full |
| `specs/090-server-types/spec.md` | full |
| `specs/090-server-types/research.md` | full |
| `specs/090-server-types/data-model.md` | full |
| `specs/090-server-types/contracts/server-types-filter.md` | full |
| `specs/090-server-types/contracts/rest-tools.md` | full |
| `specs/090-server-types/quickstart.md` | full |
| `specs/090-server-types/memory-synthesis.md` | full |
| `.specify/memory/constitution.md` | §III, §V, §VI, Architecture Standards |
| `docs/memory/INDEX.md` | routing rows (B7, B32, B37, D24, A21) |

Source cross-checked to validate plan claims (not a code review):
`admin/Partials/Settings.php`, `includes/REST/QuickConnectController.php`,
`includes/REST/ToolsController.php`.

> `.specify/memory/security_constitution.md` does **not exist** in this project; §III of the
> main constitution was used as the security baseline.

---

## Vulnerability Findings

### SEC-001 — Incomplete write-path inventory leaves the requirement gate bypassable by default value

- **finding_id**: SEC-001
- **location**: `includes/REST/QuickConnectController.php:631`
- **owasp_category**: A01:2025-Broken Access Control
- **cwe**: CWE-1188: Insecure Default Initialization of Resource (contributing: CWE-863)
- **cvss_score**: 5.3 (Medium)
- **spec_kit_task**: TASK-SEC-001

**Issue.** The plan derives its write-path list from a grep and names three `is_enabled`
writers plus **one** creation path (`admin/Partials/Settings.php:347`). A **second creation
path exists**: `QuickConnectController::…` calls `$query->add_item()` at line 631 with an
explicit eight-key array that does **not** include `server_type`.

The plan itself warns that a row falling through to the column default gets the legacy type
rather than the registry default — but applies that warning only to the path it found.

**Consequence.** An operator selects "AcrossAI" in Quick Connect step 2; the row is written as
`mcp-adapter`. Then:

1. The interface and the stored data disagree about what the server is.
2. `ServerTypes::is_available()` is evaluated against `mcp-adapter`, whose requirement is
   always satisfied — so the enablement gate **does not fire**, and the server is switched on
   without the add-on the operator's chosen type requires.
3. FR-011's Reset restores the wrong type's tools, re-introducing the exact defect 090 exists
   to fix, on the newest servers.

This is a gate bypass by omission, not by attack: no privilege escalation beyond
`manage_options` (already required), which is why it is Medium and not High.

**Required change.** Add `QuickConnectController.php:631` to the plan's Files table and to
TASK-5; both creation paths MUST write `server_type` explicitly from
`ServerTypes::default_slug()` or the validated operator selection. Add a test asserting a
server created through **each** path carries the registry default, not the column default.

---

### SEC-002 — Diagnostic ability is registered globally but intended for one server

- **finding_id**: SEC-002
- **location**: `specs/090-server-types/data-model.md` (SetupRequired entity); planned
  `includes/Abilities/SetupRequired.php`
- **owasp_category**: A01:2025-Broken Access Control
- **cwe**: CWE-668: Exposure of Resource to Wrong Sphere
- **cvss_score**: 3.1 (Low)
- **spec_kit_task**: TASK-SEC-002

**Issue.** The plan says the diagnostic ability is registered "only in that state", which
scopes it by *plugin condition* but not by *server*. `wp_register_ability()` is site-global:
once registered, the ability is visible to every MCP server on the site and to
`mcp-adapter/discover-abilities`, not only to the server whose requirement is unmet.

**Consequence.** A healthy, unrelated server could advertise or surface an entry saying a
plugin is missing, which is confusing rather than dangerous — the ability returns a static
message and carries no privilege. Impact is limited to information presentation, hence Low.

**Required change.** Scope it explicitly: mark it with the same meta the toolset dispatchers
use to stay out of the general abilities surface, and ensure it enters only the composed tool
list of servers whose own requirement is unmet — never the generic discovery catalogue. State
in the plan that it must be excluded from `ToolAbilities` and from discover results.

---

### SEC-003 — `tools_default_policy = 'all'` auto-exposes tools added later, with no review step

- **finding_id**: SEC-003
- **location**: `specs/090-server-types/data-model.md` (precedence chain); FR-026
- **owasp_category**: A05:2025-Security Misconfiguration
- **cwe**: CWE-1188: Insecure Default Initialization of Resource
- **cvss_score**: 3.7 (Low)
- **spec_kit_task**: TASK-SEC-003

**Issue.** `'all'` is deliberately a standing rule: tool-level abilities registered *after* the
operator chose it are included automatically. That is the feature's stated purpose (FR-026),
but it means installing any plugin that contributes a tool-level ability silently widens what
an AI client can see on every server set to `'all'`.

**Mitigating factors — these are why this is Low and not Medium:**

- It mirrors the already-accepted `abilities_default_policy = 'expose'` semantics, so it
  introduces no new *class* of risk.
- **Exposure ≠ authorization (D24).** Each ability still runs its own `permission_callback`,
  so the blast radius is discovery, not execution.
- `'per-tool'` is the default; `'all'` is an explicit operator choice.

**Required change.** None blocking. The Tools tab MUST state that `'all'` is a standing rule
covering future additions — not merely that it overrides the type's set. An operator choosing
it should understand they are accepting future tools sight-unseen.

---

### SEC-004 — Model-facing text is locale-dependent and site-controlled

- **finding_id**: SEC-004
- **location**: FR-022a; clarification Q3
- **owasp_category**: A05:2025-Security Misconfiguration
- **cwe**: CWE-451: User Interface Misrepresentation of Critical Information
- **cvss_score**: 0.0 (Informational)
- **spec_kit_task**: TASK-SEC-004

**Issue.** Clarification Q3 made the diagnostic text translatable. The string an **AI client**
consumes therefore comes from a site-controlled `.mo` file rather than from plugin source. A
wrong or malicious translation could misdescribe the site's state to a model that may act on
it.

**Assessment.** Informational only. Translation catalogues are trusted site content, an
attacker who can write them already has filesystem access, and the same is true of every other
string. Recorded because it is a **non-obvious consequence** of a decision made this session:
model-facing text now has a different trust path than developer-authored text.

**Required change.** None. Do not hardcode English — that would make it the only untranslated
string in the plugin, which a future contributor would "fix" without knowing why.

---

## Confirmed Secure Patterns

These were checked and found sound — recorded so a later review does not re-litigate them.

| Pattern | Evidence |
|---|---|
| **No mass assignment on server update** | `Settings.php:474-480` builds `$data` from an explicit five-key allow-list. `is_enabled` and `server_type` are absent, so neither can be set by a forged POST key. Satisfies **B7**. |
| **Disabled-by-default preserved** | Both creation paths pass `'is_enabled' => 0` (`Settings.php:347`, `QuickConnectController.php:631`). **A21**'s boundary rule holds; 090 adds a condition without weakening the existing safety layer. |
| **Gate is server-side, not UI-only** | `ServerTypes::enablement_error()` is consulted at the data-write layer on every enable path; the disabled button is presentation only. |
| **Single authorization-adjacent resolver** | `is_available()` is the one source for "requirement met"; selection, enablement and runtime all call it rather than re-deriving. Satisfies **B32**. |
| **Unmet dependency is not a denial of service** | `create_server()` is never skipped; the route keeps answering and explains itself. Prevents an operator action from silently killing live client sessions. |
| **Minimal disclosure** | The diagnostic names only the required plugin's public name — no paths, versions, or site configuration. |
| **Enum validation on both new columns** | Written only after validation against `ServerTypes::all()` / the three-value policy enum; unknown values rejected rather than coerced. |
| **Permissive read, strict write** | An unrecognised stored type degrades safely (`get()` → `null`, fall back to `mcp-adapter`) instead of fataling — availability preserved without loosening writes. |
| **No new trust boundary** | Every surface is behind `manage_options`; the feature introduces no anonymous or lower-privilege entry point. |

---

## Action Plan & Next Steps

1. **Fix SEC-001 before implementation** — add the second creation path to the plan's Files
   table and TASK-5. One omitted line in an inventory, but it defeats the gate on exactly the
   servers most likely to want it.
2. **Address SEC-002 during implementation** — scope the diagnostic ability so it cannot leak
   into the general discovery catalogue.
3. **SEC-003 / SEC-004** — copy and documentation only; no code change.
4. **Durable Memory Preservation** — SEC-001 is an instance of a reusable lesson ("a grep-built
   write-path inventory is not an inventory"), and the vendor-ordering constraint from R2
   bounds what D18 can be used for. Both are capture candidates.
5. No Critical or High findings, so `/speckit.security-review.followup` is **not** required.

---

## Memory Hub INDEX.md Row

```text
| specs/090-server-types/security-constraints.md | plan | 2026-09-15 | MODERATE | C:0 H:0 M:1 L:2 | A01,A05 |
```
