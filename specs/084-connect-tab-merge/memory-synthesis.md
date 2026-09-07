# Memory Synthesis

*Refreshed 2026-09-07 for the **Tasks/Implement** phase — selection shifted from boundary/ownership
entries toward implementation risks and security constraints, and now includes D55 + B57, captured
from this feature's own plan-stage reviews.*

## Current Scope

F084 collapses five per-server tabs (`npm`, `clients`, `ai-connectors`, `n8n`, `wp-cli`) into one
**Connect** tab with a `?method=` level-2 nav. 54 tasks across `admin/Partials/ServerTabs/*` (Registry,
new `ConnectTab`, new `Connect\MethodRegistry`, `FilteredServerTab`), new
`includes/Utilities/RegistryEntryNormalizer`, `Settings`, `MCPServerListTable`, `backend.scss`, plus a
matched companion PR in `acrossai-pro`. No REST, no storage, no schema, no hooks.

## Relevant Decisions

- **D55 / DEC-SIBLING-REGISTRY-NAME-SEMANTICS** — mirror a sibling registry's *shape*, never an
  accessor *name* whose semantics differ; prefer removing the unsafe option to documenting it.
  (Reason: captured **from this feature** — `Registry::for_server()` is unfiltered, so the method
  registry exposes `visible_methods()` as its sole public read path. Governs T010/T012. Status:
  Active. Source: DECISIONS.md.)
- **D41 / DEC-SERVER-TAB-REGISTRY-DEDUP-LAST-WINS** — later filter entries REPLACE earlier same-slug
  ones. (Reason: this is how the companion's real Connectors method replaces the promo card at level
  2; it only works if both registries dedup identically, which is what makes the shared normalizer
  load-bearing rather than cosmetic. Governs T008/T026. Status: Active. Source: DECISIONS.md.)
- **D46 / DEC-LOCAL-DEV-AFFORDANCE-SCHEME-AGNOSTIC** — local affordances fire whenever the site
  *looks* local; the detection helper is the sole gate; no admin toggle. (Reason: T040 must reuse
  `LocalEnvironment::needs_tls_bypass()` verbatim — never a second notion of "local". Status: Active.
  Source: DECISIONS.md.)
- **D48 / DEC-RETIRE-UI-USAGE-KEEP-EXTENSION-SURFACE** — subtract only direct UI usage; keep the
  extension surface. (Reason: T019/T020 keep all four tab classes on disk and instantiable, changing
  only their membership and priority. Status: Active. Source: DECISIONS.md.)
- **DEC-SERVER-TAB-CLASS-HIERARCHY** — template-method base + Registry singleton dispatch + final
  concrete tabs. (Reason: the shape F084 replicates one level down, bounded by D55. Status: Active.
  Source: DECISIONS.md.)

## Active Architecture Constraints

- **A1** — all `add_action`/`add_filter` in `Main.php`. (Reason: F084 adds **zero** hooks; both
  registries only *apply* a filter. Any `add_filter` in the new classes is a review failure.
  Source: ARCHITECTURE.md.)
- **A3** — admin-rendering classes live in `admin/Partials/`. (Reason: the binding constraint behind
  T006 — hydration touches `FilteredServerTab` and `AbstractServerTab`, so it cannot follow
  normalization into `includes/`. Source: ARCHITECTURE.md.)
- **A9** — logic shared by ≥2 modules lives in `includes/Utilities/` as a final class. (Reason: the
  constitutional basis for T004's extraction instead of copying validation. Source: ARCHITECTURE.md.)
- **A11** — stateless pure services are exempt from the singleton rule. (Reason: the normalizer is
  static-only; `MethodRegistry` keeps the singleton shape with a private constructor.
  Source: ARCHITECTURE.md.)
- **A6** — `use` imports or leading-`\` FQN. (Reason: T007/T011's cross-namespace references fail
  silently on bare relative names. Source: ARCHITECTURE.md.)

## Accepted Deviations

- **Constitution §IV connector-picker card exception (v1.1.0)** — connector panels may use hand-rolled
  cards and `.nav-tab-wrapper`. (Reason: they move down one level unchanged; neither widened nor
  withdrawn. Status: Accepted-Deviation.)
- **DEV5** — tab sub-forms may be hand-rolled when ≤3 fields. (Reason: nearest precedent that this
  surface is carve-out territory; not itself engaged, since F084 adds nav chrome, not a form.
  Status: Accepted-Deviation.)
- **DEV1** — parent menu uses `WP_List_Table`, pre-approved and non-extensible. (Reason:
  `MCPServerListTable` is edited in T029; that edit must not widen the deviation.
  Status: Accepted-Deviation.)

## Relevant Security Constraints

- **S5 / B6 / B8** — `admin_url()` is filterable and MUST be `esc_url()`-wrapped before HTML; "escaped
  upstream" reasoning is rejected and `esc_*` is idempotent. (Reason: `method_url()` returns RAW by
  contract so `add_query_arg()` can chain, so S5 is satisfied at each *output* site — enumerated as
  constraint C1. Source: PROJECT_CONTEXT.md, BUGS.md.)
- **S6** — singleton `__construct()` MUST be private. (Reason: applies to `MethodRegistry`, T010.
  Source: PROJECT_CONTEXT.md.)
- **S1** — forms/AJAX MUST verify a nonce. (Reason: T022 moves the npm and clients form *targets*, not
  their nonce actions; a moved form losing its binding is the regression to watch.
  Source: CONSTITUTION.md §III.)

## Related Historical Lessons

- **B57 / cross-plugin raw URL builder needs an output-site inventory** — a raw-return builder consumed
  cross-plugin multiplies B6/B8 exposure across consumers the local greps cannot see. (Reason: captured
  from this feature; drives the seven-row inventory in the contract and the T049 review gate. Never
  resolve a missing `esc_url()` inside the builder.)
- **B48 / count-based test assertions drift** — hardcoded `assertCount( N )` breaks unrelated tests
  when a registry grows. (Reason: T024 takes `RegistryTest` 11→8; derive from `all_tabs()` rather than
  re-hardcoding 8.)
- **B51 / retired-symbol names in comments trip canary greps** — grep cannot tell code from comments.
  (Reason: T049's audit greps must stay anchored on `'tab' => '…'`, because the new code legitimately
  names all five slugs in `LEGACY_TAB_METHODS` and docblocks.)
- *(Worklog quota folded in)* **F040 / cross-plugin `class_exists()` self-disable probe** — capability
  probe, zero data migration. (Reason: T033's `HostCapabilities::has_connect_tab()` reuses it rather
  than comparing version strings.)

## Conflict Warnings

- **Soft — D35 enumeration shape**, now resolved and superseded in practice by **D55**: D35 prescribes
  a canonical static on the abstract base; F084 uses a sibling `MethodRegistry`. Consistency with
  `ServerTabs\Registry` outweighs D35's letter; its intent — one path that fires, validates, dedups
  and sorts — is honoured, and D55 governs the naming half of the deviation.
- **Soft — §IV DataViews vs. the level-2 nav.** Five sibling links, no filter/sort/pagination:
  navigation chrome, not a data grid. Proceed on the reading that already permits the tab strip.
- **No hard conflicts.** §VI is satisfied *because of* T004's extraction — copying validation into the
  second registry would make this a hard conflict.

## Retrieval Notes

- Index-first, markdown-only (`optimizer.enabled: false`). At budget — 5 decisions, 5 architecture,
  3 deviations, 3 security, 3+1 lessons; worklog quota folded into lessons.
- Read: `INDEX.md` (targeted extraction only), `config.yml`, `spec.md`, `plan.md`, `tasks.md`,
  `security-constraints.md`. No full body reads of `DECISIONS/ARCHITECTURE/BUGS/WORKLOG.md` —
  `full_memory_read_allowed: false` respected.
- Phase: **Tasks/Implement** — prioritised security constraints and implementation risks over the
  boundary/ownership entries the Plan-phase pass selected.
