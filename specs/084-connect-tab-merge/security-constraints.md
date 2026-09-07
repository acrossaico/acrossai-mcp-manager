# Security Constraints: Feature 084 — Connect tab merge

Binding constraints for implementation and review. Derived from two plan-level security reviews —
`docs/security-reviews/2026-09-07-084-connect-tab-merge-plan.md` (MODERATE, C:0 H:0 M:2 L:3 I:1) and
`…-plan-v2.md` (LOW, C:0 H:0 M:0 L:2, re-review of the amended plan) — plus plan-stage architecture
violation detection, constitution §III, and durable memory (S1, S5, S6, B6, B8, D55, B57).

A reviewer blocks merge on any of these.

---

## Trust boundaries

| Boundary | Direction | Gate |
|----------|-----------|------|
| Browser → `Settings::render_edit_page()` | inbound | Screen-level `manage_options`, already in place; `?tab=` sanitized at `Settings.php:658` |
| Browser → `ConnectTab::resolve_active_method()` | inbound | `?method=` sanitized, then validated against the **capability-filtered** visible set |
| Third-party plugin → `acrossai_mcp_manager_connect_methods` | inbound | `RegistryEntryNormalizer::normalize()` — slug/label/callable validation, capability defaulting, `visible_callback` coercion |
| `render_callback` → page | outbound | `\Throwable` containment with a fixed message |
| `method_url()` → HTML | outbound | `esc_url()` at every output site |

The feature crosses **no** other boundary: no REST route, no DB query, no form, no nonce, no
credential, no upload, no outbound HTTP.

---

## C1 — `method_url()` returns raw; every output site escapes (SEC-084-001, S5, B6, B8)

- `ConnectTab::method_url()` MUST NOT call `esc_url()` internally. Chained consumers
  (`public/Renderers/MCPClientsBlock.php:146`) append query args to the result; pre-escaping encodes
  the separator and breaks all sixteen level-3 client links.
- Its docblock MUST state the raw contract **and** name `MCPClientsBlock:146` as the reason, so a
  future "tidy-up" does not add escaping inside the builder.
- Every site where a `method_url()` value reaches HTML MUST escape at that site with `esc_url()`
  (or `esc_attr()` in an attribute context) — even where it looks redundant. `esc_*` is idempotent;
  "escaped upstream" reasoning is rejected (**B8**).
- The contract MUST carry an **output-site inventory** naming each site and its escaper.
- `quickstart.md` MUST carry a canary grep asserting no unescaped `method_url()` output site exists.

## C2 — Capability filtering precedes resolution AND fallback (SEC-084-002, FR-007)

- `Connect\MethodRegistry::visible_methods()` MUST return a set already filtered by
  `current_user_can( $entry['capability'] )` **and** `visible_callback`, and MUST be the **only**
  public read path; `ConnectTab` MUST NOT be able to obtain an unfiltered list. Collection stays
  `private`.
- The accessor MUST NOT be named `for_server()`. In the sibling `ServerTabs\Registry`, `for_server()`
  (`Registry.php:158`) is the **unfiltered** accessor and `visible_tabs()` (`:198`) is the filtered
  one — reusing the name with the opposite meaning is the contract mismatch that makes this
  constraint fail silently. Flagged CRITICAL by plan-stage violation detection (2026-09-07).
- All four resolution steps — including the **fallback** — MUST draw from that filtered set. A
  restricted user must never land on a method they may not see.
- A `render_callback` for a filtered-out method MUST never be invoked. Pinned by a test using a flag
  the callback would set.
- "Unknown method" and "method excluded by capability/visibility" MUST converge on one code path so
  their observable behaviour is identical — otherwise the difference enumerates withheld methods.

## C3 — Silent fallback; never reflect the requested method (SEC-084-003, B8)

- The requested `?method=` value MUST NOT be rendered, echoed into a notice, or surfaced in any
  user-visible log when it is unknown or excluded. Fall back silently.
- Pinned by a test asserting a `?method=` payload does not appear in the rendered output.

## C4 — Error containment policy (SEC-084-004, FR-013)

- Catch `\Throwable`, not `\Exception` — a `TypeError` from a mis-registered third-party callback is
  the most likely real failure and would otherwise escape containment and white-screen the page.
- Render a fixed, translated, escaped string naming the failing **method slug** only. Never the
  exception message, file path, or stack trace.
- Route detail to `error_log()` behind a `WP_DEBUG` guard, matching the existing
  `Registry::doing_it_wrong()` development-only signalling pattern.

## C5 — Sanitize both superglobal reads (SEC-084-005)

- `?tab=` and `?method=` MUST be read as `sanitize_key( wp_unslash( $_GET[…] ?? '' ) )`, matching
  `Settings.php:658`.
- The word "raw" is reserved for the **escaping** contract (C1). Where the plan means "the
  pre-rewrite `?tab=` value", it MUST say pre-rewrite — never "raw".
- Both reads are navigation, not mutation, so they carry a **scoped**
  `WordPress.Security.NonceVerification.Recommended` suppression around the read only — never around
  a wider block.

## C6 — Companion enqueue gate keeps its full predicate (SEC-084-006, companion repo)

- Widening the gate to accept two address forms MUST NOT drop its other conditions. The full
  predicate stays `page === 'acrossai_mcp_manager' && action === 'edit' && current_user_can(…) &&
  is_connect_method_request( … )`.
- Asserted in the companion's existing `MainN8nConnectionsEnqueueTest`.

## C7 — The `doing_it_wrong()` extraction preserves all three protections (SEC-084-007, B8)

`Registry::doing_it_wrong()` (`Registry.php:386-395`) moves into `RegistryEntryNormalizer`
parameterised by filter name. `$reason` is **not** a constant — it interpolates a third-party-supplied
slug (`Registry.php:305`). These MUST survive the move:

1. The `if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) { return; }` early exit. Malformed third-party
   registrations MUST NOT surface as notices to production administrators.
2. `esc_html()` on the filter-name argument — now **caller-supplied** rather than read from
   `self::FILTER_NAME`, so it is newly untrusted-by-shape even though both current callers pass
   constants.
3. `esc_html()` on `$reason`, interpolating `sanitize_key()`-clean values only.

"Sanitized upstream" does not justify dropping 2 or 3 — see **B8**; `esc_*` is idempotent. Add a test
asserting the extracted helper emits nothing when `WP_DEBUG` is off.

## C8 — Do not memoize the registries (SEC-084-008)

`visible_methods()` embeds `current_user_can()` results. Memoizing it on server id alone, or in a
static that survives `switch_to_blog()` or a user-context change, serves one user's permitted method
set to another — an access-control defect wearing a performance-tweak disguise. `Registry` does not
memoize; match it. If memoization is ever genuinely required, the cache key MUST include the current
user and the current blog.

Related, non-security: the invariant "the tab filter is applied from exactly one **source location**"
is a source-call-site count, verified by grep. It is **not** a runtime count — `Registry` does not
memoize, so the filter is applied twice per edit-page render (`Settings.php:687` strip,
`:703` body). Do not rewrite this check as a runtime counting-callback assertion; it would fail on a
healthy tree.

## Preserved invariants (subtractive-edit guard)

Per `DEC-F025-TASKS-REVIEW-PRESERVATION-INVARIANT-AND-COVERAGE-MATRIX`, these exist today and MUST
survive the change unchanged:

- The screen-level `manage_options` gate on the per-server Edit page.
- `Settings.php:658` `sanitize_key( wp_unslash( $_GET['tab'] ) )` and its scoped nonce suppression.
- The nonce actions on the npm and MCP Clients forms — those forms change **location**, not their
  nonce binding. A moved form losing its binding is the regression to watch (**S1**).
- `esc_url()` at `MCPClientsBlock.php:153`.
- `Registry::doing_it_wrong()` firing only under `WP_DEBUG`.
- `Connect\MethodRegistry::__construct()` is `private` (**S6**).

## Explicitly N/A

Nonce verification on new handlers (no new handlers), `permission_callback` (no REST routes),
`$wpdb->prepare()` (no queries), credential hashing (no credentials), upload validation (no uploads),
open-redirect (the feature performs no redirect at all — FR-009).

---

## Coverage matrix

`/speckit.tasks` MUST map each row to a task ID and carry this matrix forward into `tasks.md`.

| Finding | Severity | Constraint | Task |
|---------|----------|-----------|------|
| SEC-084-001 | MEDIUM | C1 | TASK-SEC-084-001 |
| SEC-084-002 | MEDIUM | C2 | TASK-SEC-084-002 |
| SEC-084-003 | LOW | C3 | TASK-SEC-084-003 |
| SEC-084-004 | LOW | C4 | TASK-SEC-084-004 |
| SEC-084-005 | LOW | C5 | TASK-SEC-084-005 |
| SEC-084-006 | INFO | C6 | TASK-SEC-084-006 *(companion repo)* |
| SEC-084-007 | LOW | C7 | TASK-SEC-084-007 |
| SEC-084-008 | LOW | C8 | TASK-SEC-084-008 |
