---
document_type: security-review
review_type: staged
assessment_date: 2026-09-07
codebase_analyzed: acrossai-mcp-manager (Feature 084 — Connect tab merge, implementation)
total_files_analyzed: 14
total_findings: 2
overall_risk: INFORMATIONAL
critical_count: 0
high_count: 0
medium_count: 0
low_count: 0
informational_count: 2
owasp_categories: [A03]
cwe_ids: [CWE-79]
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

# SECURITY REVIEW REPORT — STAGED CHANGES (Feature 084)

## Executive Summary

**Overall risk: INFORMATIONAL.** Zero critical, high, medium or low findings.

Nothing was staged at review time, so this reviews the **working-tree diff** (Mode B) — 14 source
files, of which 2 are new production classes, 1 a new shared utility, and the rest small deltas.

The result is unusually clean, and the reason is structural rather than lucky: the C1–C8 constraints
from the two plan-stage reviews were implemented *and* pinned by tests before this review ran, so the
classes of defect a staged review normally catches were closed upstream. Verification here focused on
confirming the diff actually does what those constraints require, and on the **modified** files —
where a change can regress an existing guarantee without any new code looking wrong.

Two informational notes, neither actionable as a defect.

## Staged Diff Reviewed

Nothing in the index; working-tree scope:

| File | Change |
|------|--------|
| `includes/Utilities/RegistryEntryNormalizer.php` | **NEW** — shared entry validation |
| `admin/Partials/ServerTabs/ConnectTab.php` | **NEW** — container tab, URL builder, resolution, dispatch |
| `admin/Partials/ServerTabs/Connect/MethodRegistry.php` | **NEW** — level-2 registry |
| `admin/Partials/ServerTabs/Registry.php` | Delegates normalize/hydrate; `all_tabs()` 11 → 8 |
| `admin/Partials/ServerTabs/FilteredServerTab.php` | `+ hydrate_entries()` static |
| `admin/Partials/Settings.php` | `$legacy_slug_map` extended |
| `admin/Partials/MCPServerListTable.php` | 2 row shortcuts repointed |
| `NpmTab`, `ClientsTab`, `WpCliTab`, `AIConnectorsPromoTab` | priority ints; 2 form targets |
| `public/Discovery/ConnectionMethodRegistry.php` | docblock cross-reference only |
| `src/scss/backend.scss` | styling |
| `tests/…` (4 files) | new + updated suites |

## Vulnerability Findings

### [INFORMATIONAL] SEC-084-S01 — `hydrate_entries()` is a new public surface that skips validation by contract

**Location:** `admin/Partials/ServerTabs/FilteredServerTab.php` (new static)
**OWASP Category:** A03:2025-Injection
**CWE:** CWE-79
**CVSS Score:** 0.0

**Description:** `hydrate_entries()` was made `public static` so both registries can share it. Its
contract assumes entries are **already normalized**; it performs no validation of its own. A future
caller that passes raw filter output straight to it would construct `FilteredServerTab` instances
around unvalidated entries.

The blast radius is bounded and already documented: `FilteredServerTab`'s own constructor docblock
states *"This class does not re-validate; feed it garbage and you will get an inline error at render
time"*, and both label and slug are `esc_html()`-escaped at every output site regardless. So the
failure mode is a visible inline error, not an injection.

**Remediation:** None required. The `@param` already says "Entries already normalized by
`RegistryEntryNormalizer::normalize()`". Recorded so a future caller is on notice.

**Spec-Kit Task:** none — informational.

---

### [INFORMATIONAL] SEC-084-S02 — Unescaped `printf` argument in the level-2 nav is a literal

**Location:** `admin/Partials/ServerTabs/ConnectTab.php::render_method_nav()`
**OWASP Category:** A03:2025-Injection
**CWE:** CWE-79
**CVSS Score:** 0.0

**Description:** The nav's `printf` passes `%3$s` unescaped:
`$is_active ? ' aria-current="page"' : ''`. Every sibling argument is escaped (`esc_url`, `esc_attr`,
`esc_html`), so a reviewer scanning for unescaped interpolation will stop here.

It is a **hardcoded string literal** with no interpolated data, chosen by a boolean the code controls.
There is nothing to escape.

**Remediation:** None. Recorded to pre-empt the same question on every future review of this method.

**Spec-Kit Task:** none — informational.

---

## Confirmed Secure Patterns

Each verified against the diff, not assumed from the plan:

- **No dangerous primitives introduced.** A grep of all three new files for `$wpdb`, `wp_remote_*`,
  `file_get_contents`, `fopen`, `exec`, `shell_exec`, `eval`, `unserialize`, `extract`, `$_POST`,
  `$_REQUEST`, `wp_redirect`, `update_option`, `set_transient` returns **zero** matches.
- **No new dependencies.** `composer.json`, `composer.lock`, `package.json` and `package-lock.json`
  are all unmodified — no supply-chain surface added.
- **C5 — input validation at the boundary.** Both superglobal reads funnel through one private
  `read_query_key()` doing `sanitize_key( wp_unslash( … ) )`, with the
  `WordPress.Security.NonceVerification.Recommended` suppression scoped to the read alone. Matches
  the pre-existing read at `Settings.php:658`.
- **C1 — escaping at every output site.** `method_url()` returns raw by contract; the four host-side
  output sites each escape (`ConnectTab.php:245` `esc_url`, the shared `MCPServerListTable` `printf`
  `esc_url`, and `MCPClientsBlock.php:155` `esc_url` on the chained value). Pinned by a test asserting
  the builder returns a bare `&`.
- **C2 — authorization before content.** `visible_methods()` filters on
  `current_user_can( $entry['capability'] )` *before* hydration, so an excluded method never becomes
  an object, never reaches the nav, and cannot be selected as the fallback. Three tests cover it,
  including the legacy-address branch, which reaches the resolver by a different path.
- **C3/C4 — no information disclosure on failure.** The contained-error string names only the method
  slug and is `esc_html()`-wrapped; exception message, file, line and class go to `error_log()` and
  nowhere else. Asserted directly (`SECRET_DETAIL`, `/var/www` and `TypeError` all absent from output).
- **S1 preserved — the moved forms kept their nonce binding.** `nonce_action` is passed explicitly by
  both tabs and derives from `$server['id']`, structurally independent of `submit_target_url`. The
  diff confirms only the target changed. Asserted on the renderer context, with a positive check that
  the URL genuinely moved, so the test cannot pass vacuously.
- **No open-redirect surface.** The feature performs no redirect at all; a test asserts `wp_redirect`
  fires zero times across all five legacy addresses.
- **Third-party labels are escaped at output.** `label()` is validated non-empty but not sanitized
  during normalization — correctly, since it is display text — and every render site wraps it in
  `esc_html()`, matching how `SettingsRenderer` handles level-1 labels.
- **Array-key lookups on sanitized values.** `Settings.php`'s extended `$legacy_slug_map` is keyed by
  a class constant and looked up with an already-`sanitize_key()`-ed `$tab`. No injection surface.

---

## Action Plan & Next Steps

1. **No remediation required.** Both findings are informational and neither warrants a task.
2. **No `/speckit.security-review.followup`** — that path is for critical/high findings; there are
   none across all three reviews of this feature (plan MODERATE → plan-v2 LOW → tasks MODERATE →
   staged INFORMATIONAL).
3. **Durable memory**: no new capture. Nothing here generalizes beyond D55, B57 and B55, already
   registered this session.
4. **Outstanding security work is entirely in the companion PR**: C6 (T041) and its assertion (T042)
   live in `acrossai-pro` and are the only unverified constraint in the matrix.

---

## Memory Hub INDEX.md Row

```text
| docs/security-reviews/2026-09-07-084-connect-tab-merge-staged.md | staged | 2026-09-07 | INFORMATIONAL | C:0 H:0 M:0 L:0 I:2 | A03 |
```
