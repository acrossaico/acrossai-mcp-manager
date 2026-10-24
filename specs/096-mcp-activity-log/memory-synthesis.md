# Memory Synthesis

## Current Scope

F096 — MCP Activity Log. One BerlinDB table `acrossai_mcp_activity_log` holding MCP
requests and ability runs as a parent/child tree; two capture paths; one REST controller;
one DataViews screen mounted twice. Modules: `includes/Database/ActivityLog/`,
`Observability/`, `Abilities/`, `History/`, `REST/`, `admin/Partials/`
(+ `ServerTabs/McpTrackerTab`), `Main.php`, `Activator.php`, `uninstall.php`.
Phase **Plan** — boundaries, ownership and drift risk over migration detail.

## Relevant Decisions

- **D18** — `mcp_adapter_pre_tool_call` is the canonical MCP-boundary *enforcement* hook;
  never fork the adapter. (Reason: F096's five adapter *result* filters are observation,
  not enforcement, and must stay read-only pass-throughs. D18 is not violated but sets the
  "hook the published seam" precedent. Active, DECISIONS.md)
- **D19 / D39** — fail-open observability fires a scoped `do_action()`, fire-and-forget;
  and any such action with a fail-forward requirement MUST use per-listener isolation,
  because native `do_action()` re-raises the first thrown exception and skips later
  listeners. (Reason: the recorders' swallow-and-continue semantics are D19; D39 binds the
  moment F096 fires an action. The two token-estimation filters are unaffected. Active)
- **D60** — personal-data erasers over audit tables de-identify and retain the event; they
  never delete. (Reason: codified here, binding via T078. Active, DECISIONS.md)
- **D25** — the `wp_register_ability_args` callback swap is why A17 exists here. (Reason:
  F096's ability capture sits on that seam. Active)
- **DEC-UNINSTALL-OPT-IN-GATE** — uninstall work runs only behind the opt-in. (Reason:
  FR-031 drops the table inside that gate. Active)

## Active Architecture Constraints

- **A1** — all hook registration lives exclusively in `Main.php` via
  `define_admin_hooks()` / `define_public_hooks()`. (Reason: the spec claimed an exception
  for the capture seams. It does not need one — see Conflict Warnings. Source:
  ARCHITECTURE.md)
- **A17** — request-scoped capture is a **three-hook** pattern: `rest_pre_dispatch` @5 to
  capture, `rest_post_dispatch` @999 to clear, **and `shutdown` @999 as a safety net**.
  Canonical impl `CurrentServerHolder.php`, wired at `Main.php:578-583`. (Reason: both new
  holders are request-scoped. ARCHITECTURE.md:487)
- **A15** — a `Includes\Database\<Module>\Recorder` is singleton-exempt only if
  static-only, each method wrapping one `( new Query() )->add_item([...])` in
  `try/catch (\Throwable)`, failures to `error_log()`, returning void, never Loader-wired.
  Feature classes MUST call it instead of `Query::add_item`. (Reason: `ActivityLog\Recorder`
  is the fourth of this family. Source: ARCHITECTURE.md:403)
- **A4** — new lists use `DataViews`. (Reason: the Activity screen and the per-server tab
  body. Source: ARCHITECTURE.md)
- **A24** — an audit log MUST record its own destruction: purge, retention and ceiling trims
  each write a removal record **after** deleting, naming actor/time/count. (Reason: codified by
  this feature's own security review; now binding on it via T075. Source: ARCHITECTURE.md)
- **A20** — an `uninstall.php` `wp_options` LIKE-sweep MUST exclude a sibling's owned
  namespace. (Reason: F096 adds `acrossai_mcp_activity_*` keys. **Verified at T018: the
  exclusion no longer exists** — F095 took the connector stack back, so the sweep is a plain
  `acrossai_mcp_%` and A20's precondition no longer holds. Our keys are covered. Source:
  ARCHITECTURE.md)

## Accepted Deviations

- **DEV1** — the MCP Manager parent menu may use `WP_List_Table`. (Not claimed by F096;
  the new page uses DataViews. Status: Accepted-Deviation)
- **DEV5** — `ServerTabs/*::render_body()` may use hand-rolled admin HTML instead of
  `DataForm`. (F096 mounts DataViews there instead — stricter than DEV5 permits, so no
  deviation is claimed. Status: Accepted-Deviation)

## Relevant Security Constraints

- **S1** — forms and AJAX endpoints MUST verify a nonce. (Reason: the settings additions.
  CONSTITUTION.md §III)
- **S2** — explicit `permission_callback`; `__return_true` only on public read-only routes,
  with S7 the single OAuth exception. (Reason: all six routes are admin-only. §III)
- **S5** — `admin_url()` MUST be `esc_url()`-wrapped; it is filterable. (Reason: per-row
  and tab links. PROJECT_CONTEXT.md)

## Related Historical Lessons

- **B34** — silent write-loss when a live table drifts from `Schema.php` while the stored
  `db_version` still matches: `add_item()` returns `false` and callers read it as success.
  The F011 phantom-version guard catches only a *missing* table, not a drifted one.
  (Reason: F096's schema-contract test must assert column *widths*, not just presence.)
- **B7** — `Query` writers MUST filter against `Schema::columns()` before persisting or
  forged keys mass-assign. (Reason: the declarative column map and 18 REST collection
  params are untrusted-key surfaces.)
- **B11** — transient-stored arrays MUST be validated on read with `is_array()` +
  `isset()` + `is_numeric()`. (Reason: `summary()` and `filter_options()` are
  transient-cached; a partial write would surface as a fatal on the tiles.)

## Conflict Warnings

Both soft conflicts found at plan time were **resolved in `spec.md` before tasks**, and the four
MODERATE findings from the plan security review were resolved after it. No hard conflicts; no
Security-Architecture Conflict (security-constraints.md records "None").

1. **A17's `shutdown` stage was missing — resolved.** Now FR-016/FR-017 + SC-020, implemented by
   T014 and T049. Omitting it leaks captured auth identity across requests on a persistent SAPI,
   attributing one client's calls to another.
2. **An unneeded A1 exception was claimed — resolved.** The inline `try`/`finally` is not hook
   registration; Hook Registration now reads "no exceptions" and every hook is wired in
   `Main.php` (T015, T023, T057, T065, T073).
3. **New since plan time**: A24 and D60 were written to durable memory from this feature's own
   review, so two of its findings are now constraints on it rather than advice.

Requirements moved 32 → 45 and criteria 14 → 23 across clarify, the memory pass and the security
review. Plan file list gained `Observability/PersonalData.php`, `Query::prune_by_event_type()`
and `enforce_ceiling()`, and two test files — the privacy and retention homes the original list
lacked.

## Retrieval Notes

- Optimizer disabled → markdown-only, index-first. INDEX.md (162 KB) scanned by targeted
  grep, never read whole; ~20 entries considered, 18 selected.
- Read: ARCHITECTURE.md A9/A15/A17/A20 + Boot Flow; BUGS.md B34; DECISIONS.md D18 region;
  INDEX rows for the rest.
- Budget: decisions 5/5, architecture 5/5, deviations 2/3, security 3/3, bugs 3/3,
  worklog 0/2 — all within limits. `full_memory_read_allowed: false` honoured: BUGS.md
  (3132 lines) and DECISIONS.md (3067) never read whole.
- `memory.md` absent for this feature — none to merge.
- Excluded as superseded: D7, D9. D9 ("hand-rolled BerlinDB, no vendor dep") would have
  misled — it predates the vendor adoption this feature builds on.
