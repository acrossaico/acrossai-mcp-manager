# Security Constraints — F096 MCP Activity Log

Distilled from [the plan security review](../../docs/security-reviews/2026-10-16-096-mcp-activity-log-plan.md)
(MODERATE, C:0 H:0 M:4 L:2 I:1) for consumption by
`/speckit-architecture-guard-violation-detection` and `/speckit-tasks`.

## Trust boundaries

| Boundary | Direction | Trust | Constraint |
|---|---|---|---|
| MCP client → adapter → recorder | inbound | **semi-trusted** (authenticated, but content arbitrary) | Everything recorded from a client is attacker-influenceable text: `component_name`, `error_message`, `client_name`, `user_agent`, `input_preview` |
| Recorder → table | internal | trusted code, untrusted keys | Column map and 19 REST params filter against `Schema::columns()` (B7) |
| Table → REST → admin screen | outbound | **highest-value target** | Rendered to a `manage_options` user; React escapes, the exceptions must not |
| Admin → purge / cron → table | destructive | trusted but unaccountable | Deletion is currently unrecorded (SEC-001) |
| Subject request → eraser → table | inbound, unprivileged | **privacy vs integrity tension** | Erasure must de-identify, not delete (SEC-002) |
| Vendor result filters → probe | pass-through | must not mutate | `$result` returned byte-identical, per-filter test |

## Binding constraints (MUST, before implementation)

- **SC-C1** — Deletion of events MUST itself be recorded. Purge, retention trim and ceiling trim
  each write an event *after* deleting, naming actor, time and count, so it survives as the
  first row of the new history. *(SEC-001, CWE-778)*
- **SC-C2** — Personal-data erasure MUST de-identify, never delete: null `user_id`, clear
  `ip_address` and `user_agent`, retain the event, its timing, outcome and server. FR-034's
  "removes or" is to be struck. *(SEC-002, CWE-778)*
- **SC-C3** — Redaction is **key-name based** and MUST NOT be described as covering argument
  content. Free-text values are stored as sent, subject to truncation. The spec's Security
  Checklist claim must be corrected and the ability-logging setting must say arguments are
  recorded. *(SEC-003, CWE-532)*
- **SC-C4** — A `acrossai_mcp_activity_input_preview` filter MUST exist so a site handling
  regulated data can suppress or further scrub previews without disabling the log. *(SEC-003)*
- **SC-C5** — Every value localized into JS MUST use `wp_json_encode()`, never
  `esc_html()`/`esc_attr()` — both are HTML-context escapers and neither is safe inside a JS
  string literal. *(SEC-004, B36)*
- **SC-C6** — `dangerouslySetInnerHTML` MUST NOT appear anywhere in `src/js/activity-log/`, and
  a test MUST assert a scripted `component_name` reaches the screen inert. *(SEC-004, CWE-79)*
- **SC-C7** — Every `admin_url()` MUST be `esc_url()`-wrapped; it is filterable. *(S5)*
- **SC-C8** — All six routes keep an explicit `permission_callback` → `permission_check` →
  `manage_options`. `__return_true` MUST NOT appear in this controller. *(S2; S7/S8 not invoked)*
- **SC-C9** — `$wpdb->prepare()` on every query, explicitly including the keyset comparison and
  the per-`event_type` retention cutoffs. *(S4)*
- **SC-C10** — The purge MUST NOT reach F097's table and F097's cleanup MUST NOT reach this one;
  asserted by test in both directions.

## Recommended constraints (SHOULD, may land during implementation)

- **SC-C11** — Distinguish "no events" from "could not record": count consecutive write
  failures and surface a failing state, so a full disk or B34 drift cannot masquerade as a quiet
  log. *(SEC-005, CWE-778)*
- **SC-C12** — Document in the privacy-export output that source addresses are included, so a
  subject request returns a complete picture. *(SEC-006, CWE-359)*

## Non-constraints (deliberate, do not "fix")

- **No credentials are stored**, so the SHA-256 hashing rule (S3) is satisfied vacuously. Do not
  add a token column to "match" the reference implementation.
- **No raw request or response bodies**, ever. The seam does not supply them; do not reconstruct
  them. This is the specific failure mode of the third-party `mcp-tracker` plugin.
- **Full source address retained**, by recorded decision (Clarification Q1), following core's
  treatment of `comment_author_IP`. Anonymisation was considered and declined.
- **Recorders swallow all throwables** by design (FR-013 / A15). SC-C11 makes the silence
  visible; it does not make the recorder throw.

## Security-architecture conflicts

**None.** No constraint above conflicts with Principle I–VII or with A1/A9/A15/A17. SC-C1 adds a
write on a delete path, which is consistent with A15's recorder boundary; SC-C2 narrows a
requirement without touching module ownership.

## Sequencing constraints (from the task review, 2026-10-16)

All 12 constraints above had an owning task; two were owned by a phase shipping *after* the
phase that creates the risk. Coverage was complete, sequencing was not. Recorded as constraints
because they bind any future re-ordering of this task list.

- **SC-C13** — `DELETE /activity` MUST NOT ship in a release that lacks the removal record
  (SC-C1). The purge left US1 and now sits in US4 beside it. *(SEC-T01, CWE-778)*
- **SC-C14** — the personal-data exporter and eraser MUST ship in the same release as the first
  task that records a source address. Clarification Q1 chose the full address **on condition**
  the subject-data path accompanied it; deferring the path keeps the cost and drops the
  mitigation. Both moved into US1. *(SEC-T02, CWE-359)*
- **SC-C15** — the recorder self-ignore and re-entry guard (FR-015) is foundational, not
  governance: the risk begins when the recorder exists, not when retention is built. Moved to
  Phase 2. *(SEC-T03, CWE-674)*
- **SC-C16** — the retention and ceiling DELETEs MUST use `$wpdb->prepare()` with `%d` casts.
  SC-C9 named them explicitly and the task list had carried preparation only on the keyset
  comparison. *(SEC-T04, CWE-89)*
- **SC-C17** — any throwaway handler used for seam verification MUST log tag **keys** and
  duration only, never values. `sanitize_tags()` does not exist during Phase 1, so logging the
  `params` tag writes unredacted tool arguments to `debug.log`. *(SEC-T05, CWE-532)*
- **SC-C18** — the inert-rendering test MUST be parameterised over every client-influenced
  field, not just `component_name`. *(SEC-T06, CWE-79)*

**General rule this established**: a constraint satisfied by a task in a later phase is not
satisfied for any release that ships only the earlier phase. The MVP-first strategy and
phase-ordered security work are in direct tension, and nothing in the task template surfaces it.

## Implementation findings (2026-10-21, from running the tests)

Two things the REST test caught that inspection had not.

**SC-C19 — an `enum` without a `validate_callback` is decoration.**
`WP_REST_Request::has_valid_params()` reads
`if ( null !== $param && ! empty( $arg['validate_callback'] ) )`, so a declared `enum` is never
enforced unless the arg also sets one. The controller had seven enum params that accepted any
string and fell through to the query as empty filters. Fixed by adding
`'validate_callback' => 'rest_validate_request_arg'`. **Any future REST arg in this plugin that
declares `enum`, `minimum`, `maximum` or `pattern` must set `validate_callback` too** — the
schema keyword alone does nothing.

**The XSS defence is deeper than SC-C5/C6 assumed — in our favour.**
BerlinDB's default validator for text and string columns is `wp_kses_data`
(`Kern\Column::get_validation_callback()`), so script tags are stripped at the **storage** layer,
before a value reaches the API or the screen. React escaping rendered text is the second layer,
not the only one. SC-C5 and SC-C6 remain binding — they defend the paths BerlinDB does not cover
(the localized bootstrap payload, and any future PHP-rendered column or export) — but the stored
corpus itself is already clean. The test now asserts the security property (nothing executable
survives, the inner text does) rather than byte-for-byte round-tripping, which would have pinned
a vendor implementation detail.

## Implementation findings (2026-10-21, US4)

Two more that only running the tests could have found.

**SC-C20 — the adapter's `sanitize_tags()` does NOT redact what it looks like it redacts.**
Two compounding problems, both verified in vendor source:
`NullMcpObservabilityHandler` does **not** use `McpObservabilityHelperTrait`, so reaching
`sanitize_tags()` through it silently fails — and the handler's fallback was `return $tags`,
i.e. no redaction at all, invisibly. Worse, even when reached, `sanitize_tags()` only inspects
**top-level** keys: a nested array is JSON-encoded and passed through `redact_sensitive_values()`,
which replaces the sensitive *word* in the string — turning `"api_key":"sk-live-…"` into
`"[REDACTED]":"sk-live-…"`, obscuring the field name and **preserving the secret**.
*Fixed:* the handler now `use`s the trait directly for `is_sensitive_key()` only (one pattern
list, Principle VI) and walks `params` recursively itself, replacing the **value** of any
sensitively-named key at any depth, depth-capped at 10. **Any future consumer of
`sanitize_tags()` in this codebase must not assume it covers nested payloads.**

**SC-C21 — a privacy eraser must write through the Query layer, not raw SQL.**
The eraser originally de-identified a page of rows with one bulk `$wpdb->query( "UPDATE …" )`.
That bypasses BerlinDB's object cache, whose invalidation methods are all `private`, so cached
reads kept serving the **pre-erasure personal data**. The subject's own export still returned
their row after erasure — caught by test. *Fixed:* `Query::update_item()` per row. N statements
instead of 1, which is the right trade: erasure is rare, operator-initiated and already paged by
WordPress, and an eraser that leaves personal data readable from cache is worse than a slow one.
This is the A15 Recorder/Query boundary earning its keep — going around it broke a guarantee.

## Process gap

`.specify/memory/security_constitution.md` does not exist — `/speckit-security-review-init` has
never run. This review's baseline was Principle III plus S1–S9 from `docs/memory/`. 46 prior
reviews share the gap. *(SEC-007)*

---

## Post-implementation review — 2026-10-23

Covers the surfaces Phase 8 introduced (T095–T113). Both findings are LOW; neither blocks.

**SC-C22 — persisting raw `$wpdb->last_error` is new exposure, and is accepted with mitigations.**
`SchemaFailureHealthCheck` stores the database's own error text so a failed migration can be
reported after the fact. That text is not ours: MySQL errors carry schema details, and a
duplicate-key error carries a key **value**. Before Phase 8 this plugin persisted no database
error text anywhere.

Accepted because the alternative — reporting "a migration failed" with no detail — is what made
the failure untraceable in the first place, and the operator needs the error to act. Mitigated
on every axis available: truncated to 500 characters, `esc_html()` on output, `autoload = false`
so it is not loaded on normal requests, swept by uninstall's `acrossai_mcp_%` LIKE pattern, and
cleared as soon as reconciliation finds nothing pending.

**The limit, stated rather than discovered:** the value lands in `wp_options`, and an MCP ability
that reads arbitrary options could return it to an AI client. That path is capability-gated and
lives in a different plugin, so it is not this feature's to close — but it is the reason the
**query text must never be added alongside the error**. An error string is bounded; a failing
query carries whatever was being written.

**SC-C23 — Site Health self-checks are public methods, and must stay read-only.**
`RecordingHealthCheck::run_self_check()` and `SchemaFailureHealthCheck::run_self_check()` are
`public` because WordPress core invokes them through the `site_status_tests` filter; they cannot
be narrowed. They therefore MUST remain side-effect-free and MUST NOT report anything an
administrator could not already read. Both currently satisfy this: they read options and call
`exists()` / `get_table_name()` / `wp_next_scheduled()`, and write nothing.

`SchemaFailureHealthCheck::record_failure()` and `clear_all()` DO write, and are reachable by any
caller that can fire the action or call the singleton. Both are bounded (keyed entries, a cap of
20, `try`/`catch` that swallows) and neither accepts untrusted input in a position that reaches
SQL — the values are stored, never interpolated into a query.

**Not a finding, verified:** `Query::adopt_children()` is `public` but has exactly one caller
(`ActivityObservabilityHandler`), is not reachable from any REST route or hook, and refuses a
zero parent, an empty list, and a self-parent cycle.
