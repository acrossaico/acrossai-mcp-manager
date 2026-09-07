---
document_type: security-review
review_type: plan
assessment_date: 2026-09-07
codebase_analyzed: acrossai-mcp-manager (Feature 084 — Connect tab merge, amended plan)
total_files_analyzed: 9
total_findings: 2
overall_risk: LOW
critical_count: 0
high_count: 0
medium_count: 0
low_count: 2
informational_count: 0
owasp_categories: [A03, A09]
cwe_ids: [CWE-79, CWE-1059]
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

# SECURITY REVIEW REPORT — PLAN v2 (Feature 084)

Second pass over the **amended** plan set. Pass 1
(`2026-09-07-084-connect-tab-merge-plan.md`, MODERATE, C:0 H:0 M:2 L:3 I:1) produced six findings;
all in-repo ones were applied, and plan-stage architecture violation detection then forced a further
change to the method-registry accessor naming. This pass has two jobs: **verify the prior findings
actually closed**, and **look for defects introduced by the amendments themselves**.

## Executive Summary

**Overall risk: LOW.** Both mediums from pass 1 are closed, and closed structurally rather than by
adding a sentence — SEC-084-001 gained an enumerated seven-row output-site inventory plus a canary
grep, and SEC-084-002 was resolved by removing the unsafe option entirely (there is now no public
unfiltered accessor to misuse) rather than by documenting a rule. The three lows are closed as
specified.

Two new LOW findings, both introduced by the amendments:

- **SEC-084-007** — the plan's D-2 moves `Registry::doing_it_wrong()` into the shared normalizer but
  does not list the invariants that extraction must preserve. The current implementation carries
  three protections, and a routine "move this method" refactor drops them silently.
- **SEC-084-008** — the plan asserts, in three places, an invariant that is **factually false against
  the current code**: the tab filter does not fire once per render, it fires twice. One of the
  planned verification steps is written as a counting-callback assertion that would therefore fail,
  sending the implementer to debug a non-existent regression.

SEC-084-008 is not a vulnerability. It is in this report because the invariant exists to detect
whether D-5's "no partitioning was reintroduced" guarantee still holds — a security-adjacent
structural check — and a check that fails for the wrong reason gets disabled rather than fixed.

## Plan Artifacts Reviewed

| Artifact | Change since pass 1 |
|----------|--------------------|
| `specs/084-connect-tab-merge/plan.md` | §III and §V verdicts amended; D-3, D-4 rewritten; D-10 added; Phase 2 note added; `visible_methods()` naming rationale added |
| `specs/084-connect-tab-merge/contracts/connect-method-registration.md` | Output-site inventory added (§2); capability-filtering + accessor-naming block added (§1); error-containment policy expanded; §3 and §4 rewritten |
| `specs/084-connect-tab-merge/security-constraints.md` | New — C1–C6, preserved invariants, coverage matrix |
| `specs/084-connect-tab-merge/research.md` | Decision 5 amended (pre-rewrite vs raw) |
| `specs/084-connect-tab-merge/quickstart.md` | Five new canary greps; two new edge cases |
| `specs/084-connect-tab-merge/spec.md`, `memory-synthesis.md` | Unchanged |
| `admin/Partials/ServerTabs/Registry.php`, `admin/Partials/Settings.php` | Read for verification (not modified) |

---

## Verification of Pass-1 Findings

| ID | Severity | Status | Evidence |
|----|----------|--------|----------|
| SEC-084-001 | MEDIUM | **CLOSED** | Contract §2 now carries a seven-row output-site inventory naming each site and its escaper, including the two companion-repo sites; `quickstart.md` gained a `grep -rn "method_url("` review gate; constraint C1 requires the docblock to name `MCPClientsBlock:146` as the reason for the raw contract. Generalised into durable memory as **B54**. |
| SEC-084-002 | MEDIUM | **CLOSED, and hardened beyond the recommendation** | The fix went further than "state where the filter runs". Violation detection found that the originally-proposed name (`for_server()`) means *unfiltered* in the sibling registry, so the design now exposes `visible_methods()` as the **sole** public read path with collection kept `private` and **no unfiltered accessor at all** — the misuse is unrepresentable rather than merely discouraged. Contract §1 and §4, constraint C2, plan D-4. Generalised into durable memory as **D53**. |
| SEC-084-003 | LOW | **CLOSED** | Contract §4 states the fallback is silent and that the requested value is never rendered, logged to a user-visible surface, or reflected; constraint C3; `quickstart.md` grep for `unknown method` / `invalid method` expecting zero matches. |
| SEC-084-004 | LOW | **CLOSED** | Contract §1 mandates `\Throwable` (with the `TypeError` rationale), a fixed translated escaped string naming only the slug, and `error_log()` behind `WP_DEBUG`; plan D-10; constraint C4; two `quickstart.md` greps. |
| SEC-084-005 | LOW | **CLOSED** | "raw" now refers exclusively to the escaping contract in all four artifacts; both reads specified as `sanitize_key( wp_unslash( $_GET[…] ?? '' ) )` with a scoped nonce suppression; constraint C5; `quickstart.md` `$_GET[` grep. Verified no `raw $_GET` phrasing survives anywhere in `specs/084-connect-tab-merge/`. |
| SEC-084-006 | INFO | **CARRIED** (companion repo, correctly) | Constraint C6 records the full predicate and names the existing `MainN8nConnectionsEnqueueTest` as the assertion site. Nothing to close in this repository. |

---

## Vulnerability Findings

### [LOW] SEC-084-007 — The `doing_it_wrong()` extraction has no stated preserved invariants

**Location:** `specs/084-connect-tab-merge/plan.md` §Design Decisions D-2;
`admin/Partials/ServerTabs/Registry.php:386-395`
**OWASP Category:** A03:2025-Injection
**CWE:** CWE-79: Cross-site Scripting
**CVSS Score:** 2.7

**Description:** D-2 says the `_doing_it_wrong()` wrapper moves into `RegistryEntryNormalizer`
"parameterised by filter name". That is the right call, but the plan describes the *move* without
listing what the move must preserve. The current implementation carries three distinct protections:

```php
private function doing_it_wrong( string $reason ): void {
    if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
        return;                                    // (1) production gate
    }
    _doing_it_wrong(
        esc_html( self::FILTER_NAME ),             // (2) escaped
        esc_html( $reason ),                       // (3) escaped
        '0.0.7'
    );
}
```

`$reason` is not a constant — it interpolates a third-party-supplied slug
(`sprintf( 'entry "%s" missing label', $slug )`, `Registry.php:305`). That slug is already
`sanitize_key()`-clean by that point, so today the value is protected three times over. But an
extraction that drops (2) or (3) while relocating the method looks harmless in review — the argument
"it's sanitized upstream anyway" is available and wrong for exactly the reason **B8** exists. Dropping
(1) is worse in a different way: it turns malformed third-party registrations into notices rendered
to production administrators.

Post-extraction the wrapper also becomes shared by two registries with two different filter names,
so the `$filter_name` parameter is now caller-supplied and must be escaped on the same terms.

This is the pattern `DEC-F025-TASKS-REVIEW-PRESERVATION-INVARIANT-AND-COVERAGE-MATRIX` exists to
catch: a subtractive/relocating edit on a security-relevant method must enumerate its preserved
invariants.

**Remediation:** Add to the plan's D-2 and to `security-constraints.md` an explicit preserved-invariant
list for the extraction: the `WP_DEBUG` early return, `esc_html()` on **both** arguments (now
including the caller-supplied filter name), and the sanitized-slug-only interpolation policy. Add a
test asserting the extracted helper emits nothing when `WP_DEBUG` is off.

**Spec-Kit Task:** TASK-SEC-084-007

---

### [LOW] SEC-084-008 — A stated structural invariant is false against the current code, and its planned assertion would fail

**Location:** `specs/084-connect-tab-merge/plan.md` §Technical Context "Performance Goals" and
§Design Decisions D-5; `specs/084-connect-tab-merge/quickstart.md` canary-grep block;
`docs/planings-tasks/084-connect-tab-merge.md` TASK-4 verification checklist
**OWASP Category:** A09:2025-Logging & Monitoring Failures (detective control that cannot fire correctly)
**CWE:** CWE-1059: Insufficient Technical Documentation
**CVSS Score:** 2.0

**Description:** The plan asserts in three places that the tab filter fires **exactly once per
render**, and the planning brief turns that into a verification step: *"`Registry::for_server()` still
fires the tab filter **exactly once** per request (assert with a counting callback)"*.

That is not true of the current code, before or after this feature. `Registry::for_server()` applies
the filter on every invocation and there is **no memoization anywhere in `Registry`**. The edit-page
render path invokes it twice:

- `admin/Partials/Settings.php:687` — `$registry->visible_tabs( $server )` for the tab strip, which
  delegates to `for_server()` (`Registry.php:198-207`)
- `admin/Partials/Settings.php:703` — `$registry->render( $tab, $server )` for the body, which calls
  `for_server()` again (`Registry.php:224`)

A counting callback therefore observes **2**, and the assertion fails against a perfectly healthy
tree. The likely outcome is that an implementer either spends time hunting a regression that does not
exist, or deletes the check — losing the D-5 guarantee it was written to protect (that no entry
partitioning or second filter firing was reintroduced to accommodate an un-migrated companion).

The `quickstart.md` grep is fine and does the right thing —
`grep -c "apply_filters( self::FILTER_NAME" Registry.php` expecting `1` counts **source call sites**,
not runtime firings. Only its accompanying prose is wrong. The enforceable invariant is *"the filter
is applied from exactly one source location"*, which is what D-5 actually needs and what the grep
already checks.

There is a corollary worth settling now rather than discovering later. Because `ConnectTab` calls
`visible_methods()` three times per render (navigation, resolution, dispatch), an implementer may
reach for memoization to compensate. If they do, the cache **must not** be keyed on server id alone:
`visible_methods()` embeds `current_user_can()` results, so a static cache surviving a
`switch_to_blog()` or a user context change would serve one user's permitted method set to another —
turning a performance tweak into an access-control defect. The sibling does not memoize; the safest
default is to match it.

**Remediation:**

1. Correct the invariant in all three locations to "the filter is applied from exactly **one source
   location**", and drop the counting-callback wording from the planning brief's TASK-4 checklist in
   favour of the existing source-count grep.
2. Correct the "Performance Goals" line to state the real behaviour: the tab filter is applied twice
   per edit-page render today (strip + body), unchanged by this feature, and the method filter follows
   the same pattern.
3. Add to `security-constraints.md`: if `visible_methods()` is ever memoized, the cache key MUST
   include the current user and blog — or, preferably, do not memoize, matching `Registry`.

**Spec-Kit Task:** TASK-SEC-084-008

---

## Confirmed Secure Patterns

Re-verified after the amendments, plus what the amendments added.

- **The C2 fix removes the failure mode instead of documenting it.** `visible_methods()` as the sole
  public read path with no unfiltered accessor means a future caller cannot reintroduce the bypass by
  reaching for the "raw" list. This is materially stronger than the remediation pass 1 asked for.
- **The output-site inventory names companion-repo sites.** Cross-plugin escaping obligations are the
  ones this repository's greps structurally cannot see, so listing them in the contract — with the
  companion PR's reviewer owning their rows — is the only mechanism that covers them.
- **Preserved invariants are now written down** for the forms that change location (nonce actions,
  **S1**), for `Settings.php:658`'s sanitization, and for `MCPClientsBlock.php:153`'s `esc_url()`.
  SEC-084-007 is the one extraction still missing its list.
- **The plan records rather than waives.** §III, §IV and §V all carry qualified verdicts naming what is
  being accepted — including §V's newly-recorded direct cross-plugin static call, which pass 1 did not
  flag and which a reader of the original §V row would not have known about.
- **No new attack surface was introduced by the amendments.** Every change was subtractive
  (removing an accessor), additive-documentary (inventory, constraint list, preserved invariants), or
  a wording correction. No new parameter, endpoint, callback, or capability path.
- **Still no** REST route, DB query, form, nonce, credential, upload, outbound HTTP, or redirect.

---

## Action Plan & Next Steps

1. **Apply SEC-084-007 and SEC-084-008** to the plan set — both are documentation-level corrections.
   Applied in this turn.
2. **Proceed to `/speckit.tasks`.** No blocking findings. The coverage matrix in
   `security-constraints.md` now spans C1–C8.
3. **No `/speckit.security-review.followup`** — no critical or high findings across either pass.
4. **Durable memory**: no new generalizable lesson beyond the two already captured this session
   (**D53**, **B54**). SEC-084-007 is an instance of the existing
   `DEC-F025-TASKS-REVIEW-PRESERVATION-INVARIANT-AND-COVERAGE-MATRIX`; SEC-084-008 is an instance of
   the existing **B48** family (an assertion drifting from its source of truth) — both already
   captured, neither needs a new entry.

---

## Memory Hub INDEX.md Row

```text
| docs/security-reviews/2026-09-07-084-connect-tab-merge-plan-v2.md | plan | 2026-09-07 | LOW | C:0 H:0 M:0 L:2 | A03,A09 |
```
