# Implementation Plan: Merge the five connection tabs into one "Connect" tab

**Branch**: `084-connect-tab-merge` | **Date**: 2026-09-07 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `specs/084-connect-tab-merge/spec.md`
**Planning brief**: `docs/planings-tasks/084-connect-tab-merge.md`
**Memory synthesis**: [memory-synthesis.md](./memory-synthesis.md)

## Summary

Collapse five sibling top-level tabs on the per-server Edit screen — `npm`, `clients`,
`ai-connectors`, `n8n`, `wp-cli` — into one **Connect** tab (slug `connect`, priority 20) whose
second-level navigation is driven by a new `?method=` query parameter, ordered Connectors → MCP
Clients → npm → n8n → WP-CLI. The top-level strip goes 11 → 8.

Technically this is a **navigation-topology change with no data**. It introduces one container tab,
one sibling registry that mirrors `ServerTabs\Registry` exactly one level down, and one shared
entry-validation utility extracted so the two registries cannot diverge (constitution §VI). Two of
the five methods are supplied by the paid companion `acrossai-pro`, so the work ships as a **matched
pair** of PRs — per spec Clarifications no compatibility accommodation is built for an un-migrated
companion, which removes the three most complex parts of the original design (the absorption shim,
the second URL shape in the level-2 nav, and a second firing of the tab filter).

Backwards compatibility for the five legacy addresses is achieved by **in-place normalization, never
a redirect**: `admin_enqueue_scripts` fires before render, and both this plugin and the companion
gate asset enqueues on the address the browser actually requested.

## Technical Context

**Language/Version**: PHP 8.1+ (plugin floor); no JavaScript logic changes — SCSS only
**Primary Dependencies**: WordPress 7.0+ (tested to 7.1); `@wordpress/scripts` for the SCSS build. **No new Composer or npm dependencies.**
**Storage**: N/A — navigation state lives entirely in the request. No options, meta, custom tables, schema version, or migration.
**Testing**: PHPUnit (`tests/phpunit/`, WPCS-compliant, `WP_UnitTestCase`); PHPCS (WPCS strict), PHPStan level 8, ESLint. Jest untouched.
**Target Platform**: WordPress admin (`?page=acrossai_mcp_manager&action=edit&server=N`), single-site and multisite
**Project Type**: WordPress plugin, plus one coordinated companion-plugin PR in `acrossai-pro`
**Performance Goals**: No added DB queries. The existing tab filter is applied from **exactly one source location** (`Registry::for_server()`), unchanged by this feature — note that this means it is *applied twice per edit-page render* today, once for the strip via `visible_tabs()` (`Settings.php:687`) and once for the body via `render()` (`:703`), because `Registry` does not memoize. The method filter follows the same pattern from its own single source location.
**Constraints**: URL builders return raw (unescaped) values by contract; legacy addresses resolve without a browser redirect; zero new `add_action`/`add_filter` registrations.
**Scale/Scope**: 8 top-level tabs, 5 second-level methods, up to 16 third-level client pills; 3 new PHP classes + 1 new static helper + 7 file deltas in this plugin, ~5 call sites + 1 helper in the companion.

**Known environment caveat**: the repo-wide WP-dependent PHPUnit suites are broken by F011–F080 API
drift (tracked as T069, out of scope here). New tests for this feature are executed via the scratch
PHPUnit 9.6 + polyfills runner recipe established during F082, not via the repo's pinned
`phpunit ^13.2@dev`.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-checked after Phase 1 design — see Phase 2.*

| # | Principle | Verdict | Basis |
|---|-----------|---------|-------|
| I | Modular Architecture | ✅ PASS | The Connect container, its method registry, and the shared normalizer form one self-contained module under `admin/Partials/ServerTabs/`. No sibling module is touched beyond call-site deltas. |
| II | WordPress Standards Compliance | ✅ PASS (gated) | PHPCS/PHPStan-8/ESLint are Phase-3 gates, enforced before merge. WP 7.0+ / PHP 8.1+ floors unchanged. Multisite-safe by construction (per-site admin navigation, no network state). |
| III | Security First | ✅ PASS with constraints | `?method=` and `?tab=` are read as `sanitize_key( wp_unslash( … ) )` at the boundary, and `?method=` is validated against the **capability-filtered** visible set before dispatch (FR-007). Every label and URL is escaped at its output site. No new forms, nonces, queries, credentials, or uploads. Six plan-level findings (2 medium, 3 low, 1 informational) are binding as **C1–C6** in [security-constraints.md](./security-constraints.md); see **S5 note** below. |
| IV | User-Centric Design | ⚠️ PASS with recorded soft conflict | No new data-entry form and no filterable/sortable row set, so the DataForm/DataViews mandate is not engaged. The level-2 row is five sibling links with no filtering, sorting or pagination — navigation chrome of the same kind as the existing top-level tab strip. The v1.1.0 connector-card exception moves down one level with the panels it covers, **neither widened nor withdrawn**. Recorded, not waived — see Complexity Tracking. |
| V | Extensibility Without Core Modification | ⚠️ PASS with a second, non-hook surface recorded | Registration is via the new `acrossai_mcp_manager_connect_methods` filter — no core edit, degrades to the promo card when the companion is absent, companion self-detects an older host (FR-015). **But** the design also adds a direct cross-plugin static call: the companion invokes `ConnectTab::method_url()` to build its own panel URLs, and Module Contract point 4 says integration points are exposed "exclusively via WordPress actions and filters". Recorded rather than waived. It is mitigated by the companion's `HostCapabilities` wrapper behind a `class_exists`/`method_exists` probe (the **F040** pattern), and it has precedent — the companion already extends `AbstractServerTab` directly under `DEC-SERVER-TAB-CLASS-HIERARCHY`. A filter cannot replace it: the companion needs a *return value* (a URL string) at render time, which is what a static call is for. |
| VI | Reusability & DRY | ✅ PASS | Entry normalization is extracted to `includes/Utilities/RegistryEntryNormalizer.php` **before** its second use, not copied. Hydration is shared via a static on the existing adapter class — see Design Decision D-2 for why it cannot live in `includes/`. |
| VII | Definition of Done | ⏳ DEFERRED | Full checklist carried in spec.md §Definition of Done Gates; verified in Phase 3, not at plan time. |

**Architecture & UI Standards cross-check** (from `docs/memory/ARCHITECTURE.md` via memory synthesis):

| Rule | Verdict | Basis |
|------|---------|-------|
| **A1** — all `add_action`/`add_filter` in `Main.php` | ✅ PASS | This feature registers **zero** hooks. Both registries only *apply* a filter from the render path and are lazily instantiated. Any `add_filter` appearing in the new classes is a review failure. |
| **A3** — admin-rendering classes live in `admin/Partials/` | ✅ PASS | `ConnectTab` and `Connect\MethodRegistry` live under `admin/Partials/ServerTabs/`. The normalizer is context-neutral and therefore belongs in `includes/Utilities/` — which is precisely why hydration stays out of it (D-2). |
| **A6** — `use` imports or leading-`\` FQN | ✅ PASS | All new cross-namespace references (`Utilities\RegistryEntryNormalizer` ↔ `ServerTabs`, `Utilities\LocalEnvironment` ↔ `ConnectTab`) use explicit `use` imports. |
| **A9** — shared logic in `includes/Utilities/` as a `final` class | ✅ PASS | `RegistryEntryNormalizer` is `final` with static methods only. |
| **A11** — stateless pure services exempt from the singleton rule | ✅ PASS | `RegistryEntryNormalizer` is static-only under this exemption. `Connect\MethodRegistry` keeps the singleton shape to mirror `ServerTabs\Registry`, with a `private __construct()` (**S6**). |
| **Prefix rule** | ✅ PASS | New filter is `acrossai_mcp_manager_connect_methods`; all classes namespaced under `AcrossAI_MCP_Manager\`. |

**S5 note (`admin_url()` escaping)** — `admin_url()` is filterable, so its output must be
`esc_url()`-wrapped before reaching HTML. `ConnectTab::method_url()` therefore returns a **raw**
string **by contract**, and S5 is satisfied at the *output* site, not inside the builder. This is not
an oversight: `public/Renderers/MCPClientsBlock.php:146` chains
`add_query_arg( 'client', $slug, $context['submit_target_url'] )` onto that value, and escaping
inside the builder double-encodes the ampersand and breaks every level-3 client link. The contract is
stated in the method's docblock and enforced by a test.

Because the builder is `public static` and consumed cross-plugin, it is a wider B6/B8 surface than
the `server_edit_url()` it sits beside. Constraint **C1** therefore requires an enumerated
output-site inventory in the contract plus a canary grep, so the escaping obligation is enforced
rather than remembered.

**Hard conflicts**: none. Memory synthesis found nothing violating a constitution MUST, an
architecture boundary, or an active decision. Two soft conflicts are recorded in Complexity Tracking.

## Design Decisions

Recorded here because each one is a fork the implementer would otherwise re-litigate.

**D-1 — "Method" is the level-2 noun, everywhere.** Fixed in spec Clarifications. Level 1 stays
*tab*, level 2 is *method*, level 3 keeps *panel* (companion) and *client* (client picker). The
extension point, the query parameter, the registry class, and the docs all use one noun per level.
`Connect\MethodRegistry` takes its qualifier from the namespace segment, so the short name needs no
prefix and does not collide with the unrelated `Public\Discovery\ConnectionMethodRegistry` — whose
docblock gets a cross-reference so neither is grepped in mistake for the other.

**D-2 — The shared utility normalizes arrays only; hydration stays in the admin layer.**
`Registry::normalize_entries()` is pure array work and moves wholesale to
`Includes\Utilities\RegistryEntryNormalizer::normalize()`, together with the `_doing_it_wrong()`
wrapper (parameterised by filter name). `Registry::hydrate()` **cannot** follow it: it instantiates
`FilteredServerTab` and maps built-in `AbstractServerTab` instances, both admin-layer types, and A3
forbids admin-specific logic in `includes/`. Since the loop body is otherwise identical for both
levels, it is extracted as `FilteredServerTab::hydrate_entries( array $entries, array $builtin_map )`
— a static on the adapter class that already *is* the wrapper, so it stays in the admin layer and
§VI still holds. This adds one delta beyond the spec's Module Placement list; see Complexity
Tracking.

*Preserved invariants for the `doing_it_wrong()` extraction* (constraint **C7**). The current
implementation at `Registry.php:386-395` carries three protections that a routine "move this method"
refactor drops silently, and `$reason` is not a constant — it interpolates a third-party-supplied
slug (`Registry.php:305`). All three MUST survive, and the newly caller-supplied `$filter_name`
joins them:

1. the `if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) { return; }` early exit — malformed
   third-party registrations must never surface as notices to production administrators;
2. `esc_html()` on the filter-name argument, which is now supplied by the caller rather than read
   from a class constant;
3. `esc_html()` on `$reason`, and interpolation of `sanitize_key()`-clean values only.

"It's sanitized upstream" is not a reason to drop 2 or 3 — that is precisely the reasoning **B8**
rejects, and `esc_*` is idempotent.

**D-11 — Do not memoize the registries** (constraint **C8**). `ConnectTab` calls `visible_methods()`
three times per render (navigation, resolution, dispatch), which invites a memoization tweak.
`visible_methods()` embeds `current_user_can()` results, so a cache keyed on server id alone — or a
static cache surviving a `switch_to_blog()` or a user-context change — would serve one user's
permitted method set to another, turning a performance change into an access-control defect. The
sibling `Registry` does not memoize; match it. If memoization is ever genuinely needed, the key MUST
include the current user and the current blog.

**D-3 — Legacy resolution: one constant, two readers, no redirect.**
`ConnectTab::LEGACY_TAB_METHODS` is the single source of truth mapping each legacy tab slug to its
method name. `Settings::render_edit_page()` consults it to rewrite `$tab` to `connect` (so dispatch
finds the right tab and the strip highlights Connect); `ConnectTab::resolve_active_method()`
consults the same constant against the **pre-rewrite** `?tab=` value to derive which method to open.
Two readers of one constant is not duplication.

*Pre-rewrite, not unsanitized.* Both superglobal reads are
`sanitize_key( wp_unslash( $_GET[…] ?? '' ) )`, matching the existing read at `Settings.php:658`,
with a **scoped** `WordPress.Security.NonceVerification.Recommended` suppression around the read only
(constraint **C5**). The word *raw* in this plan refers exclusively to the escaping contract in D-3;
it never means unsanitized input.

A redirect is forbidden by FR-009 because `admin_enqueue_scripts` fires before render and the
companion's enqueue gates match on the requested address.

**D-4 — Resolution order for the active method** (FR-005/006/007), evaluated once per render against
the **capability- and visibility-filtered** method set:
1. `?method=` present, `sanitize_key()`-clean, and in the filtered set → use it.
2. Otherwise, the pre-rewrite `?tab=` is one of the five legacy slugs → its mapped method, if in the
   filtered set.
3. Otherwise, `LocalEnvironment::needs_tls_bypass()` is true and `clients` is in the filtered set →
   `clients`.
4. Otherwise, the first method in the filtered set, in priority order.

**The filter runs in the registry, not the tab** (constraint **C2**).
`Connect\MethodRegistry::visible_methods()` returns a set already narrowed by
`current_user_can( $entry['capability'] )` and `visible_callback`, and it is the **only** public read
path — so no step above, *including the fallback in step 4*, can name a method the user may not see.
Checking capability after resolution would turn step 4 into a bypass: the first-in-order method is
`ai-connectors`, a paid one.

**Naming is deliberately not mirrored here.** In the sibling, `Registry::for_server()` is the
*unfiltered* accessor (`Registry.php:158`), `visible_tabs()` is the filtered one (`:198`), and
`render()` dispatches off the unfiltered list (`:223-235`). Naming the method registry's accessor
`for_server()` while giving it the opposite meaning is the exact contract mismatch that would make
C2 fail silently — and fail only for a user whose capability excludes the first-in-order method.
So the method registry exposes `visible_methods()` as its sole public read path and keeps collection
private. This is the one place D-6's "mirror the sibling" rule is deliberately broken; see the
contract for the signatures.

Unknown and permission-excluded converge on the same code path, so their observable behaviour is
identical and withheld methods cannot be enumerated. The fallback is **silent** — the requested value
is never rendered, echoed into a notice, or reflected anywhere (constraint **C3**). A filtered-out
method's `render_callback` is never invoked.

Reusing `needs_tls_bypass()` verbatim honours **D46** — the detection helper is the sole gate for
"is this local"; no second notion of local is introduced.

**D-10 — Contained failures reveal nothing.** FR-013's inline error catches `\Throwable` (not
`\Exception` — a `TypeError` from a mis-registered third-party callback is the likeliest real
failure and would otherwise white-screen the page). It renders a fixed, translated, escaped string
naming only the failing method slug; the exception message, file path, and trace go to `error_log()`
behind a `WP_DEBUG` guard, matching `Registry::doing_it_wrong()`'s development-only signalling
(constraint **C4**).

**D-5 — No compatibility layer for an un-migrated companion.** Per Clarifications.
`Registry::for_server()` keeps its straight-line seed → filter → normalize → hydrate → sort shape.
Reviewers reject any reintroduction of entry partitioning, absorbed-slug tracking, or a second URL
shape in the level-2 nav. The forward mismatch (plugin updated, companion not) is out of support and
its symptoms are recorded under spec §Edge Cases.

**D-6 — `MethodRegistry` is a sibling class, not a static on the abstract base.** Soft deviation
from **D35**, resolved in favour of consistency with the immediate sibling `ServerTabs\Registry`,
which already owns tab enumeration. D35's *intent* — exactly one canonical path that fires the
filter, validates, dedups and sorts — is fully honoured: `ConnectTab` consumes the registry and never
re-fires the filter itself.

**D-7 — Test assertions derived, not re-hardcoded.** `RegistryTest` currently asserts a hardcoded
built-in count; this feature takes it 11 → 8. Per **B48**, derive the expectation from `all_tabs()`
rather than re-hardcoding `8`, or the same test breaks again at the next tab change.

**D-8 — Audit greps anchored on code syntax.** Per **B51**, the canary greps for the five retired
slugs must match `'tab' => '…'` and not bare slugs, because the new code legitimately names all five
inside `LEGACY_TAB_METHODS` and in docblocks.

**D-9 — Companion host detection is a capability probe.** Per **F040**, the companion asks
`class_exists()` / `method_exists()` for the new host API rather than comparing version strings, and
registers on the method filter when present, the tab filter when absent (FR-015).

## Project Structure

### Documentation (this feature)

```text
specs/084-connect-tab-merge/
├── spec.md                                    # /speckit.specify + /speckit.clarify output
├── checklists/requirements.md                 # /speckit.specify quality gate (16/16 pass)
├── memory-synthesis.md                        # /speckit.memory-md.plan-with-memory output
├── plan.md                                    # This file
├── research.md                                # Phase 0 output
├── contracts/
│   └── connect-method-registration.md         # Phase 1 — the public extension-point contract
├── quickstart.md                              # Phase 1 — manual + automated verification recipe
├── security-constraints.md                    # /speckit.security-review.plan output (next command)
└── tasks.md                                   # /speckit.tasks output (NOT created here)
```

No `data-model.md`: this feature has **no persistent entities**. The spec's "Key Entities" are
request-lifetime value shapes, and their structure is the registration-entry contract — documented in
`contracts/` where it is actually load-bearing, rather than duplicated into a storage document that
describes no storage.

### Source Code (repository root)

```text
admin/Partials/ServerTabs/
├── ConnectTab.php                    # NEW — container tab: slug 'connect', priority 20,
│                                     #       LEGACY_TAB_METHODS const, static raw method_url(),
│                                     #       resolve_active_method(), render_method_nav()
├── Connect/
│   └── MethodRegistry.php            # NEW — singleton; applies acrossai_mcp_manager_connect_methods;
│                                     #       seeds ai-connectors 10 / clients 20 / npm 30 /
│                                     #       (40 reserved for the companion's n8n) / wp-cli 50
├── Registry.php                      # DELTA — all_tabs() 11→8 (drop the four, add ConnectTab);
│                                     #         normalize_entries()/hydrate() delegate out
├── FilteredServerTab.php             # DELTA — + static hydrate_entries() (see D-2)
├── AbstractServerTab.php             # UNCHANGED — server_edit_url() still serves every other tab
├── NpmTab.php                        # DELTA — priority 20→30; submit_target_url → method_url()
├── ClientsTab.php                    # DELTA — priority 30→20; submit_target_url → method_url()
├── WpCliTab.php                      # DELTA — priority 40→50
└── AIConnectorsPromoTab.php          # DELTA — priority 35→10

includes/Utilities/
└── RegistryEntryNormalizer.php       # NEW — final, static-only; normalize() + doing_it_wrong()

admin/Partials/
├── Settings.php                      # DELTA — extend $legacy_slug_map (in place, no redirect)
└── MCPServerListTable.php            # DELTA — repoint the ai-connectors + clients row shortcuts

src/scss/
└── backend.scss                      # DELTA — graded nav-tab family: L2 scales core .nav-tab,
                                      #         L3 restates the idiom one step smaller

tests/phpunit/Admin/ServerTabs/
├── ConnectTabTest.php                # NEW
├── Connect/MethodRegistryTest.php    # NEW
└── RegistryTest.php                  # DELTA — derive counts (D-7)

tests/phpunit/Includes/Utilities/
└── RegistryEntryNormalizerTest.php   # NEW

docs/
├── extending-per-server-tabs.md      # DELTA — priority table + "Adding a Connect method" section
└── README.txt                        # DELTA — changelog + minimum companion version (FR-014)
```

**Companion repository** (`acrossai-pro`, separate coordinated PR — not in this tree):

```text
includes/HostCapabilities.php         # NEW — has_connect_tab(), method_url(),
                                      #       is_connect_method_request()  [F040 probe pattern]
includes/Main.php                     # DELTA — register on the method filter when the host
                                      #         supports it, the tab filter otherwise (FR-015)
admin/Main.php                        # DELTA — 2 enqueue gates accept both address forms
admin/ServerTabs/AIConnectorsTab.php  # DELTA — panel_url() built on the host's method_url()
admin/ServerTabs/N8nTab.php           # DELTA — same
README.txt                            # DELTA — minimum host version (FR-014)
```

**Structure Decision**: The feature slots entirely into the existing `admin/Partials/ServerTabs/`
module, adding one nested `Connect/` namespace segment for the level-2 registry — deliberately
mirroring the level-1 topology one directory down so the two registries are visibly siblings rather
than two inventions. The only file placed outside that module is the context-neutral normalizer,
which A9 puts in `includes/Utilities/`. No new top-level directory, no new REST controller, no new
build entry point.

## Phases

### Phase 0 — Research

**Status**: complete → [research.md](./research.md)

Five questions carried unresolved out of the spec, all resolved against the current tree rather than
assumed:

1. What exactly is safe to extract from `Registry` without breaching A3? → **D-2**
2. Which call sites depend on the URL builder returning a raw string? → one, confirmed by reading
   `public/Renderers/MCPClientsBlock.php:146`.
3. How many `server_edit_url()` call sites need repointing? → **two** (`ClientsTab`, `NpmTab`);
   `AccessControlTab`'s third call site is not a connection tab and stays put.
4. What is the exact before/after built-in tab count? → **11 → 8**, enumerated from `all_tabs()`.
5. Where does legacy tab-slug rewriting already happen? → `Settings::render_edit_page():663`,
   an existing two-entry `$legacy_slug_map` this feature extends rather than parallels.

No **NEEDS CLARIFICATION** markers remain in Technical Context.

### Phase 1 — Design & Contracts

**Status**: complete.

- **Contract**: [contracts/connect-method-registration.md](./contracts/connect-method-registration.md)
  — the `acrossai_mcp_manager_connect_methods` entry shape, the `ConnectTab::method_url()` signature
  and its raw-return guarantee, the resolution order, and the companion's host-probe surface. This is
  the cross-plugin API and the only new public interface the feature exposes.
- **Data model**: not applicable — see the note under *Documentation* above.
- **Security constraints**: [security-constraints.md](./security-constraints.md) — **C1–C6**, binding
  on implementation and review, with the coverage matrix `/speckit.tasks` must carry forward. Full
  report at `docs/security-reviews/2026-09-07-084-connect-tab-merge-plan.md`.
- **Quickstart**: [quickstart.md](./quickstart.md) — the manual verification path plus the canary
  greps and automated gates.
- **Agent context**: `CLAUDE.md` carries no `<!-- SPECKIT START/END -->` markers in this repo
  (`AGENTS.md` is the tooling source of truth and is not Speckit-managed), so no marker update was
  performed. Recorded here rather than silently skipped.

### Phase 2 — Post-Design Constitution Check

Re-evaluated after the Phase 1 contract was written. **No verdict changed.** Two notes:

- §VI stayed PASS **because of** the D-2 split. Had the contract instead specified two independent
  validators — one per registry — this would have become a *hard* conflict, not a soft one. The
  shared normalizer is load-bearing, not cosmetic: **D41**'s last-wins dedup is exactly what lets the
  companion's real Connectors method replace the built-in promo card, and it only works if both
  registries dedup identically.
- §IV's soft conflict is unchanged by the design: the contract adds no filtering, sorting, or
  pagination to the level-2 row, so it stays navigation chrome.
- §III moved from PASS to **PASS with constraints** after the plan-level security review. Nothing in
  the design was found unsafe; two medium findings were cases where the plan stated the right
  invariant without making it enforceable (the raw-URL escaping obligation, and *where* the
  capability filter runs). Both are now pinned as C1/C2 with tests and a grep gate, and D-3/D-4/D-10
  above were amended accordingly.

### Phase 3 — Task breakdown

Out of scope for this command. Next: `/speckit.security-review.plan`, then `/speckit.tasks`.

## Design Revision — level-2/3 navigation styling (2026-09-07)

The first implementation styled level 2 as a joined segmented control (square, shared border, blue
underline) and left level 3 as round filled pills, on the reasoning that three *identical* strips
would read as a rendering fault. Reviewed against the running screen, that reasoning was wrong in
practice: it produced three rows that each looked like a **different kind of control**, so the screen
read as three unrelated widgets rather than one hierarchy.

Revised to a single graded family, all three tiers using the WordPress `.nav-tab` idiom and
differentiated by scale (14px → 13px → 12px) and stacking position:

- **Level 1** — WP core `.nav-tab`, untouched.
- **Level 2** — opts into core's `.nav-tab-wrapper` / `.nav-tab` classes directly. It renders only in
  wp-admin, so it inherits core's focus, hover and responsive behaviour for free.
- **Level 3** — restates the same idiom under its own `.acrossai-client-tab*` classes. It MUST NOT
  take core admin classes: `Public\Renderers\MCPClientsBlock` is also reachable from the front end
  via `[acrossai_mcp_clients_block]` (`ClientRendererController.php:181`), and a public renderer
  must not depend on admin-only styling. (That block is already unstyled on the front end —
  `backend.scss` has never loaded there — a pre-existing gap F084 neither introduces nor closes.)

FR-017 and SC-007 were amended to match; the old wording required level 2 to look *unlike* its
neighbours, which forbade this. Constitution §IV is unaffected and slightly better served: the
v1.1.0 connector-card exception names `.nav-tab-wrapper` explicitly.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|--------------------------------------|
| **Soft, §IV** — the level-2 method row is WP-core tab navigation rather than a DataViews surface | Five sibling links with no filtering, sorting, or pagination; the row now uses WordPress core's own `.nav-tab-wrapper` / `.nav-tab` classes, exactly like the tab strip above it | DataViews expresses a filterable/sortable row set. Forcing a five-item nav row through it produces visible layout gymnastics for zero operator benefit. The v1.1.0 connector-card exception explicitly sanctions `.nav-tab-wrapper` markup on this surface, so adopting core's tab classes sits **inside** that carve-out rather than stretching it — a stronger position than the hand-rolled segmented control this replaced (see the design-revision note below). |
| **Soft, D35** — enumeration lives on a sibling `MethodRegistry` class rather than a canonical static on the abstract base | Consistency with `ServerTabs\Registry`, the immediate sibling that already owns enumeration one level up; the two registries should read as the same pattern at two depths | Putting method enumeration on `AbstractServerTab` would make the base class know about a specific tab's internals, inverting the dependency. D35's intent (one path that fires, validates, dedups, sorts) is preserved verbatim. |
| **Scope, spec delta list** — `FilteredServerTab` gains a static `hydrate_entries()`, a delta the spec's Module Placement did not enumerate | Hydration is genuinely shared by both registries, but it touches admin-layer types (`AbstractServerTab`, `FilteredServerTab`) that A3 forbids from `includes/` | Copying the hydration loop into `MethodRegistry` is a straight §VI violation. Moving it into the normalizer is a straight A3 violation. Adding a fourth new class for twelve lines is worse than a static on the adapter class that already performs the wrapping. |
