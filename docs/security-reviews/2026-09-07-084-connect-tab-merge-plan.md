---
document_type: security-review
review_type: plan
assessment_date: 2026-09-07
codebase_analyzed: acrossai-mcp-manager (Feature 084 — Connect tab merge)
total_files_analyzed: 12
total_findings: 6
overall_risk: MODERATE
critical_count: 0
high_count: 0
medium_count: 2
low_count: 3
informational_count: 1
owasp_categories: [A01, A03, A05]
cwe_ids: [CWE-20, CWE-79, CWE-209, CWE-284, CWE-862]
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

# SECURITY REVIEW REPORT — PLAN (Feature 084)

## Executive Summary

Feature 084 is a **navigation-topology change with no data surface**. It adds no REST route, no
database write, no query, no form, no nonce, no credential handling, and no file upload. The attack
surface it introduces is correspondingly narrow: one new request parameter (`?method=`), one new
public static URL builder consumed cross-plugin, and one new extension point that executes
third-party callbacks inside an already-`manage_options`-gated admin screen.

**Overall risk: MODERATE.** No critical or high findings. Two medium findings are both cases where
the plan states the *right* invariant but does not make it **enforceable** — it relies on every
future implementer and reviewer remembering it, in a codebase whose own bug memory (B6, B8) records
that exact failure mode happening before. The remaining findings are a wording ambiguity that could
produce an unsanitized superglobal read, an unspecified error-message policy, and one scoping note
for the companion plugin.

Nothing here blocks proceeding to `/speckit.tasks`. All six findings are cheap to close and should
become explicit tasks rather than review-time reminders.

## Plan Artifacts Reviewed

| Artifact | Role |
|----------|------|
| `specs/084-connect-tab-merge/plan.md` | Primary review target |
| `specs/084-connect-tab-merge/spec.md` | Requirements + §Security Checklist |
| `specs/084-connect-tab-merge/research.md` | Phase 0 decisions |
| `specs/084-connect-tab-merge/contracts/connect-method-registration.md` | The new public API |
| `specs/084-connect-tab-merge/quickstart.md` | Verification recipe |
| `specs/084-connect-tab-merge/memory-synthesis.md` | Selected durable constraints (S1, S5, S6) |
| `.specify/memory/constitution.md` | §III Security First (this repo has no separate `security_constitution.md`) |
| `docs/memory/INDEX.md` | Targeted retrieval — B6, B8, B36, B41 rows |
| `admin/Partials/ServerTabs/Registry.php` | Cross-check: normalization + dedup semantics |
| `admin/Partials/Settings.php` | Cross-check: existing `$tab` sanitization at `:658` |
| `public/Renderers/MCPClientsBlock.php` | Cross-check: the raw-chain-then-escape consumer at `:146-155` |
| `admin/Partials/ServerTabs/AbstractServerTab.php` | Cross-check: existing `server_edit_url()` contract |

---

## Vulnerability Findings

### [MEDIUM] SEC-084-001 — The raw-URL contract is stated but not enforceable

**Location:** `specs/084-connect-tab-merge/plan.md` §Design Decisions D-3 / §Constitution Check "S5 note";
`contracts/connect-method-registration.md` §2
**OWASP Category:** A03:2025-Injection
**CWE:** CWE-79: Improper Neutralization of Input During Web Page Generation (Cross-site Scripting)
**CVSS Score:** 4.3

**Description:** The plan correctly decides that `ConnectTab::method_url()` returns a raw, unescaped
string, and correctly justifies it: `public/Renderers/MCPClientsBlock.php:146` chains
`add_query_arg()` onto the value, and pre-escaping would turn the separator into `&#038;` and break
all sixteen level-3 client links. I verified the existing consumer does escape correctly at output
(`:153`, `esc_url( $url )`), so the pattern in use today is sound.

The gap is that the contract is enforced only by prose. This repo's own bug memory records both
halves of this failing before:

- **B6** — `admin_url()` without `esc_url()` → filterable value injected into an HTML `href` → XSS.
- **B8** — "escaped above" comments do not enforce escaping; re-escape at the output point even when
  it looks redundant, because `esc_*` is idempotent.

`method_url()` is deliberately more widely consumed than `server_edit_url()`: it is `public static`
so the **companion plugin** can call it, and the plan puts new output sites in `ConnectTab`'s nav
renderer, `MCPServerListTable`'s row shortcuts, and two migrated tabs' form targets. Every one of
those is a fresh B6 opportunity, and the raw-return contract is precisely the condition that makes B6
possible.

**Remediation:** Do not change the raw-return decision — it is correct. Make it enforceable:

1. Add to `contracts/connect-method-registration.md` §2 an explicit **output-site inventory**: every
   place a `method_url()` value reaches HTML, and the escaper used there.
2. Add a canary grep to `quickstart.md` that asserts no `method_url()` result reaches output
   unescaped, e.g. any line containing `method_url(` inside a `printf`/`echo`/attribute context must
   also contain `esc_url` or `esc_attr`.
3. Require the `method_url()` docblock to carry the words "RAW — callers MUST `esc_url()` at output"
   **and** name `MCPClientsBlock:146` as the reason, so the next person who "tidies up" by adding
   `esc_url()` inside the builder sees why they must not.

**Spec-Kit Task:** TASK-SEC-084-001

---

### [MEDIUM] SEC-084-002 — Capability filtering must provably precede method resolution and fallback

**Location:** `specs/084-connect-tab-merge/plan.md` §Design Decisions D-4;
`contracts/connect-method-registration.md` §4
**OWASP Category:** A01:2025-Broken Access Control
**CWE:** CWE-862: Missing Authorization
**CVSS Score:** 5.3

**Description:** FR-007 and the resolution order say the right thing — an unknown or
permission-excluded method "falls through to the default and its content is never produced". But the
plan expresses this as a property of `ConnectTab::resolve_active_method()` ("in the **visible** set"),
without pinning **where** the capability filter runs. Two implementations satisfy the prose and only
one is safe:

- *Safe*: `MethodRegistry::for_server()` returns a set already filtered by `current_user_can(
  $entry['capability'] )` **and** `visible_callback`; the tab resolves against that set and can never
  name a method the user may not see, including as the **fallback**.
- *Unsafe*: the tab resolves against the full set and checks the capability afterwards. Steps 2, 3 and
  4 of the resolution order then become bypass paths — in particular step 4 ("first visible method in
  priority order") silently becomes "first method", which for the default ordering is
  `ai-connectors`, a **paid** method. A restricted user landing there gets content produced for them
  before any gate runs.

There is a second-order leak in the same area: FR-007 requires an excluded method to be "treated as
unknown". If the excluded case and the unknown case take different code paths, timing or markup
differences let a low-privilege admin enumerate which methods exist but are withheld.

The screen-level `manage_options` gate bounds the blast radius, so this is not high severity — but
the whole point of per-method `capability` is that it can be *narrower* than the screen gate.

**Remediation:**

1. State in the contract that `MethodRegistry::for_server()` returns the **capability- and
   visibility-filtered** set, and that `ConnectTab` never sees an unfiltered list. Make the filtered
   accessor the only public read path.
2. State explicitly that the **fallback** is drawn from the same filtered set.
3. Require excluded and unknown to converge on one code path, so their observable behaviour is
   identical.
4. Add tests: (a) a method whose capability the user lacks is absent from the nav, is not selectable
   by `?method=`, and its `render_callback` is never invoked — asserted with a flag the callback would
   set; (b) when the only visible method is not the first-in-order one, the no-`?method=` default
   selects the visible one.

**Spec-Kit Task:** TASK-SEC-084-002

---

### [LOW] SEC-084-003 — The rejected `?method=` value must never be echoed back

**Location:** `contracts/connect-method-registration.md` §4-§5; `spec.md` §Edge Cases
**OWASP Category:** A03:2025-Injection
**CWE:** CWE-79: Cross-site Scripting (Reflected)
**CVSS Score:** 3.1

**Description:** The spec says an unknown method "falls back to the default method rather than
erroring", which implies nothing is echoed — but no artifact says so outright. The natural,
helpful-feeling implementation is a notice reading *"Unknown connection method 'x'"*, and that
reflects an attacker-influenceable value into admin HTML. `sanitize_key()` makes exploitation hard
(it strips everything outside `a-z0-9_-`), so this is defence-in-depth rather than a live hole — but
"sanitized upstream so it's fine at output" is exactly the reasoning **B8** exists to reject.

It also interacts with SEC-084-002: an error message naming the method distinguishes "unknown" from
"withheld", re-introducing the enumeration leak.

**Remediation:** State in the contract that the fallback is **silent** — the requested value is never
rendered, logged to a user-visible surface, or reflected in any notice. Add a test asserting a
`?method=` payload does not appear in the rendered output.

**Spec-Kit Task:** TASK-SEC-084-003

---

### [LOW] SEC-084-004 — FR-013 error containment has no message policy

**Location:** `spec.md` FR-013; `contracts/connect-method-registration.md` §1 "Error containment"
**OWASP Category:** A05:2025-Security Misconfiguration
**CWE:** CWE-209: Generation of Error Message Containing Sensitive Information
**CVSS Score:** 3.1

**Description:** FR-013 requires a failing method's `render_callback` to degrade to "an inline error"
without specifying what that error may contain. Catching `\Throwable` and printing
`$e->getMessage()` is the path of least resistance, and exception messages routinely carry absolute
filesystem paths, class names, SQL fragments, and third-party API responses. The audience is
`manage_options` users, which limits severity, but this is a **new** contained-failure surface whose
message policy should be decided now rather than discovered in review.

The contract also does not say what is caught. Catching `\Exception` alone misses `\Error` — a
`TypeError` from a mis-registered third-party callback is the single most likely real failure, and it
would escape containment and white-screen the page, defeating FR-013 exactly when it matters.

**Remediation:**

1. Catch `\Throwable`, not `\Exception`.
2. Render a fixed, translated, escaped string that names the failing **method slug** only — never the
   exception message, file, or trace.
3. Route the detail to `error_log()` behind a `WP_DEBUG` guard, matching the existing
   `Registry::doing_it_wrong()` development-only signalling pattern.

**Spec-Kit Task:** TASK-SEC-084-004

---

### [LOW] SEC-084-005 — "raw `$_GET`" is ambiguous and invites an unsanitized read

**Location:** `plan.md` §Design Decisions D-3; `research.md` Decision 5;
`contracts/connect-method-registration.md` §3
**OWASP Category:** A03:2025-Injection
**CWE:** CWE-20: Improper Input Validation
**CVSS Score:** 2.7

**Description:** The word "raw" carries two unrelated meanings across the plan set: *unescaped* (the
`method_url()` return contract) and *pre-rewrite* (the `?tab=` value `ConnectTab` must read, because
`Settings` has already normalized its own copy to `connect`). The plan says `resolve_active_method()`
"re-reads the **raw** `$_GET['tab']`", meaning the second sense — but a reader primed by the URL
contract three paragraphs earlier can reasonably read it as the first, and implement a bare
`$_GET['tab']` access.

For comparison, the code this sits next to gets it right today:
`Settings::render_edit_page():658` reads
`sanitize_key( wp_unslash( $_GET['tab'] ) )`. The new read must match.

**Remediation:** Replace "raw `$_GET['tab']`" with "the **pre-rewrite** `?tab=` value, read as
`sanitize_key( wp_unslash( $_GET['tab'] ?? '' ) )`" in all three artifacts, and reserve the word
"raw" for the escaping contract only. Same treatment for `?method=`. Note that both reads are
nonce-free by nature (navigation, not mutation), so they need the same
`WordPress.Security.NonceVerification.Recommended` suppression already used at `Settings.php:656`,
scoped to the read.

**Spec-Kit Task:** TASK-SEC-084-005

---

### [INFORMATIONAL] SEC-084-006 — The companion's widened enqueue gate must keep its screen and capability conditions

**Location:** `contracts/connect-method-registration.md` §6 (`HostCapabilities::is_connect_method_request()`)
**OWASP Category:** A01:2025-Broken Access Control
**CWE:** CWE-284: Improper Access Control
**CVSS Score:** 0.0 (design note)

**Description:** Today the companion gates its admin JS/CSS on an exact `?tab=ai-connectors` /
`?tab=n8n` string match. `is_connect_method_request()` deliberately widens that to accept **two**
address forms. Widening a gate condition is the moment to confirm the *other* conditions on it are
still present: the companion's enqueue must continue to require the MCP Manager page, the `edit`
action, and the user's capability — not merely that the method string matched. A gate that
degenerates into "does the URL contain `method=n8n`" would load premium admin assets on unrelated
screens, and on those screens the surrounding capability context is not guaranteed.

No evidence of a defect — the companion tree is not part of this PR. Recorded so the companion PR's
reviewer checks the whole predicate rather than just the new disjunct.

**Remediation:** In the companion PR, assert the full predicate in a test:
`page === 'acrossai_mcp_manager' && action === 'edit' && current_user_can(…) && is_connect_method_request( 'n8n' )`.
Reuse the existing `MainN8nConnectionsEnqueueTest` rather than writing a new gate test.

**Spec-Kit Task:** TASK-SEC-084-006 *(companion repository)*

---

## Confirmed Secure Patterns

These were checked and found sound — recorded so a later reviewer does not re-open them.

- **No new authentication, authorization, session, credential, or secret surface.** Constitution §III
  items for nonces, `$wpdb->prepare()`, token hashing, and upload validation are all genuinely N/A;
  the spec's §Security Checklist marks them so accurately rather than reflexively.
- **The existing screen gate is inherited, not re-implemented.** The feature does not introduce a
  second capability check for the page itself, so there is no chance of the two drifting apart.
- **Zero new hooks (A1).** Both registries only *apply* a filter from the render path. There is no
  new `add_action`/`add_filter` that could fire in an unexpected context, and no hook registration
  outside `Main.php`.
- **The raw-then-escape chain is already proven in this codebase.**
  `MCPClientsBlock::render_subnav()` chains `add_query_arg()` onto a raw builder result and escapes
  with `esc_url()` at the `printf` (`:146` → `:153`). The new builder joins an established, correct
  pattern rather than inventing one — which is why SEC-084-001 is about enforcement, not about the
  decision.
- **`?tab=` sanitization already exists and is correct.** `Settings::render_edit_page():658` uses
  `sanitize_key( wp_unslash( … ) )` with a scoped nonce-verification suppression. Extending its
  `$legacy_slug_map` rather than building a parallel mechanism means the new legacy slugs inherit
  that sanitization for free.
- **Shared normalization removes a divergence class.** Both registries validating through one
  `RegistryEntryNormalizer` means the `capability` defaulting (`sanitize_key()`, empty →
  `manage_options`) and the `visible_callback` coercion (non-callable → `null`) cannot drift between
  levels. A second, copied validator would have been a genuine security risk, not just a DRY
  violation.
- **No redirect (FR-009) is a security-neutral choice here.** In-place normalization keeps the
  requested address intact for enqueue gates. It introduces no open-redirect surface because no
  user-supplied value is ever used as a redirect target — the feature performs no redirect at all.
- **`_doing_it_wrong()` stays `WP_DEBUG`-only**, inherited from `Registry::doing_it_wrong()`, so
  malformed third-party registrations do not leak diagnostic text to production admins.

---

## Action Plan & Next Steps

1. **Fold the five in-repo findings into the plan set before `/speckit.tasks`** — SEC-084-001 through
   -005 are all documentation-level fixes to `plan.md`, `research.md`, `contracts/` and
   `quickstart.md`. Applied in this turn.
2. **Carry all six as explicit tasks**, not as review-time reminders. Per
   `DEC-F025-TASKS-REVIEW-PRESERVATION-INVARIANT-AND-COVERAGE-MATRIX`, the `tasks.md` produced by
   `/speckit.tasks` must contain a coverage matrix mapping each finding ID to its remediation task.
3. **No `/speckit.security-review.followup` run required** — that command is for critical/high
   findings, and there are none.
4. **Durable memory**: SEC-084-001 generalises beyond this feature (any `public static` raw URL
   builder consumed cross-plugin multiplies B6/B8 exposure and needs an output-site inventory plus a
   grep gate). Proposed for capture in the governed-plan's memory step.

---

## Memory Hub INDEX.md Row

```text
| docs/security-reviews/2026-09-07-084-connect-tab-merge-plan.md | plan | 2026-09-07 | MODERATE | C:0 H:0 M:2 L:3 | A01,A03,A05 |
```
