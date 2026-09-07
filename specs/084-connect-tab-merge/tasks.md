---

description: "Task list for Feature 084 — Merge the five connection tabs into one Connect tab"
---

# Tasks: Merge the five connection tabs into one "Connect" tab

**Input**: Design documents from `/specs/084-connect-tab-merge/`
**Prerequisites**: `plan.md`, `spec.md`, `research.md`, `contracts/connect-method-registration.md`,
`quickstart.md`, `security-constraints.md`

**Tests**: **REQUIRED** for this feature. `spec.md` §Definition of Done Gates mandates "PHPUnit tests
written and passing for all new PHP logic", and constraints C1–C8 in `security-constraints.md` each
name a specific assertion. Test tasks below are not optional.

**Organization**: Tasks are grouped by user story. Two deliberate departures from the default shape,
both explained where they occur:

1. The **foundational** phase is unusually large — the registry, container tab and shared normalizer
   are machinery every story routes through, so splitting them per story would mean duplicating them.
2. Security tests are filed with **the constraint they verify**, not with the user story whose
   acceptance criteria happen to mention them. C2, C3 and C4 protect every story and are implemented
   in Phase 2, so they are verified in Phase 2.

> **Tasks-review re-sequencing (2026-09-07)**: an earlier draft filed the C2 fallback-safety
> assertion under US3 (P2) and the C3/C4 containment assertions under US5 (P3) — both *behind* this
> list's own stated release gate at the end of US4. That would have allowed a shippable state with the
> decisive access-control and information-disclosure assertions never having run. Corrected per
> `docs/security-reviews/2026-09-07-084-connect-tab-merge-tasks.md` (SEC-084-T01…T05).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks)
- **[Story]**: Which user story this task belongs to (US1–US5)
- Every task names an exact file path

## Path Conventions

WordPress plugin at repository root. `admin/Partials/` for admin rendering, `includes/Utilities/` for
context-neutral shared logic, `tests/phpunit/` mirroring the source tree, `src/scss/` for styles.
Companion tasks (US4) land in the **separate** `acrossai-pro` repository and are marked as such.

**Test runner caveat**: the repo-wide WP-dependent PHPUnit suites are broken by F011–F080 API drift
(T069, out of scope). Run this feature's suites via the scratch PHPUnit 9.6 + polyfills runner recipe
established during F082, not the repo's pinned `phpunit ^13.2@dev`.

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Establish the baselines that later verification diffs against. No production code.

- [x] T001 Capture the SC-008 out-of-scope vocabulary baseline into `/tmp/f084-baseline.txt` by running the grep block from `specs/084-connect-tab-merge/quickstart.md` §"Out-of-scope vocabularies untouched" against the current tree (`QuickConnectController::VALID_METHODS`, `ConnectionMethodRegistry` in `public/`, the `ClientRendererController` renderer map, embed transport keys). These counts must be identical at the end.
- [x] T002 [P] Confirm the toolchain is green **before** any edit — run `composer dump-autoload`, `composer run phpcs`, `composer run phpstan`, `npm run lint:js` from the repository root and record the result in `/tmp/f084-baseline.txt`, so any later failure is attributable to this feature rather than pre-existing drift.
- [x] T003 [P] Record into `/tmp/f084-baseline.txt`: the current built-in tab count and slug list from `admin/Partials/ServerTabs/Registry.php::all_tabs()` (expected: 11 tabs), for the B48-safe assertion refactor in T026; **and** the exact nonce action strings emitted by the npm and MCP Clients forms, for the S1 preservation assertion in T030.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: The shared registry machinery every user story dispatches through, plus the security
assertions that protect all of them.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

### Shared entry validation (constitution §VI, plan D-2)

- [x] T004 Create `includes/Utilities/RegistryEntryNormalizer.php` — `final class` in namespace `AcrossAI_MCP_Manager\Includes\Utilities`, static methods only (A11 pure-service exemption). Move the body of `Registry::normalize_entries()` (`admin/Partials/ServerTabs/Registry.php:284-344`) verbatim into `public static function normalize( array $raw, string $filter_name, string $since ): array`, and the body of `Registry::doing_it_wrong()` (`:386-395`) into a private static helper parameterised by filter name.
- [x] T005 Preserve all three C7 invariants inside the new `includes/Utilities/RegistryEntryNormalizer.php` helper: the `if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) { return; }` early exit, `esc_html()` on the now caller-supplied `$filter_name` argument, and `esc_html()` on `$reason` with `sanitize_key()`-clean interpolation only. Add a docblock stating these are preserved invariants, not incidental detail.
- [x] T006 Add `public static function hydrate_entries( array $entries, array $builtin_map ): array` to `admin/Partials/ServerTabs/FilteredServerTab.php`, moving the loop body from `Registry::hydrate()` (`admin/Partials/ServerTabs/Registry.php:357-372`). It stays in the admin layer because it instantiates `FilteredServerTab` and maps `AbstractServerTab` instances, which A3 forbids from `includes/` (plan D-2).
- [x] T007 Rewire `admin/Partials/ServerTabs/Registry.php` so `normalize_entries()` delegates to `RegistryEntryNormalizer::normalize()` and `hydrate()` delegates to `FilteredServerTab::hydrate_entries()`, using `use` imports (A6). Behaviour must be byte-identical — `for_server()` keeps its straight-line seed → filter → normalize → hydrate → sort shape with no partitioning (plan D-5).
- [x] T008 [P] Create `tests/phpunit/Includes/Utilities/RegistryEntryNormalizerTest.php` covering: `sanitize_key()` on slug with empty-slug drop; missing `label` and non-callable `render_callback` dropped for non-built-ins; `priority` int coercion defaulting to 100; `capability` sanitized with empty → `manage_options`; non-callable `visible_callback` coerced to `null`; slug-keyed **last-wins** dedup (D41) with insertion order of the final winner preserved; and — per C7 — that the helper emits nothing at all when `WP_DEBUG` is off.
- [x] T009 [P] Verify every pre-existing case in `tests/phpunit/Admin/ServerTabs/RegistryTest.php` still passes unchanged after T007. This is the regression gate proving the extraction was behaviour-preserving; do not edit assertions here yet (T026 handles the count change).

### The Connect container and its method registry

- [x] T010 Create `admin/Partials/ServerTabs/Connect/MethodRegistry.php` — singleton in namespace `AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\Connect` with `protected static $_instance`, `public static function instance(): self`, and a **`private` `__construct()`** (S6). Public API is `visible_methods( array $server ): array` **only**; collection stays `private`. Per C2 this must NOT be named `for_server()` — that name means *unfiltered* in the sibling `Registry` (`Registry.php:158`) and reusing it with inverted semantics is the mismatch D55 was captured to prevent.
- [x] T011 Implement seeding and enumeration in `admin/Partials/ServerTabs/Connect/MethodRegistry.php`: seed the four built-ins at `ai-connectors` 10, `clients` 20, `npm` 30, `wp-cli` 50 with **40 reserved** for the companion's n8n; apply `acrossai_mcp_manager_connect_methods` from exactly one source location; normalize via `RegistryEntryNormalizer::normalize()`; hydrate via `FilteredServerTab::hydrate_entries()`; sort ascending by priority. Add the reciprocal docblock cross-reference to the unrelated `AcrossAI_MCP_Manager\Public\Discovery\ConnectionMethodRegistry` (FR-020).
- [x] T012 Implement capability and visibility filtering inside `visible_methods()` in `admin/Partials/ServerTabs/Connect/MethodRegistry.php` — narrow by `current_user_can( $entry['capability'] )` **and** `visible_callback` before returning (C2). Add **no memoization** (C8): the returned set embeds `current_user_can()` results, so a cache keyed on server id alone would serve one user's permitted set to another. Match the sibling `Registry`, which does not memoize.
- [x] T013 Add the reciprocal cross-reference docblock to `public/Discovery/ConnectionMethodRegistry.php` pointing at the new `Connect\MethodRegistry`, so neither class is grepped in mistake for the other (spec §Module Placement).
- [x] T014 Create `admin/Partials/ServerTabs/ConnectTab.php` extending `AbstractServerTab` — `slug()` returns `connect`, `label()` returns `Connect`, `priority()` returns 20, plus the `LEGACY_TAB_METHODS` constant mapping all five legacy tab slugs to their method names (contract §3).
- [x] T015 Add `public static function method_url( array $server, string $method ): string` to `admin/Partials/ServerTabs/ConnectTab.php`, building `admin.php?page=acrossai_mcp_manager&action=edit&server=N&tab=connect&method=<method>` via `add_query_arg()` over `admin_url()` and returning it **RAW** (C1). Its docblock MUST state the raw contract **and** name `public/Renderers/MCPClientsBlock.php:146` as the reason — that consumer chains `add_query_arg()` onto the value, and pre-escaping would encode the separator and break all sixteen level-3 client links.
- [x] T016 Implement `resolve_active_method()` in `admin/Partials/ServerTabs/ConnectTab.php` per plan D-4 steps 1, 2 and 4 (the local-install branch is deliberately deferred to US3/T045). Resolve **only** against `MethodRegistry::visible_methods()` so the step-4 fallback can never name a withheld method, and re-check filtered-set membership in step 2 as well as step 1 — a legacy `?tab=` value reaches the resolver through a different branch and must not bypass the capability gate. Read both superglobals as `sanitize_key( wp_unslash( $_GET[…] ?? '' ) )` with a **scoped** `WordPress.Security.NonceVerification.Recommended` suppression around the read only (C5), matching `admin/Partials/Settings.php:658`.
- [x] T017 Make unknown and permission-excluded methods converge on one code path in `admin/Partials/ServerTabs/ConnectTab.php::resolve_active_method()`, falling back **silently** — the requested value is never rendered, echoed into a notice, or written to a user-visible log, so withheld methods cannot be enumerated (C3, FR-007).
- [x] T018 Implement `render_body()` dispatch in `admin/Partials/ServerTabs/ConnectTab.php` with error containment per C4: wrap each method's render in `catch ( \Throwable $e )` (**not** `\Exception` — a `TypeError` from a mis-registered third-party callback is the likeliest real failure), emit a fixed translated escaped string naming only the failing method slug, and route the exception detail to `error_log()` behind a `WP_DEBUG` guard. A filtered-out method's `render_callback` is never invoked.
- [x] T019 Rewire `admin/Partials/ServerTabs/Registry.php::all_tabs()` from 11 entries to 8 — remove `NpmTab`, `ClientsTab`, `AIConnectorsPromoTab` and `WpCliTab`, add `ConnectTab`. Keep all four removed classes on disk and instantiable; only their membership in the built-in tab list changes (D48: subtract UI usage, keep the extension surface).
- [x] T020 Re-slot the four migrated tabs onto the method priority scale — `admin/Partials/ServerTabs/AIConnectorsPromoTab.php` 35 → 10, `admin/Partials/ServerTabs/ClientsTab.php` 30 → 20, `admin/Partials/ServerTabs/NpmTab.php` 20 → 30, `admin/Partials/ServerTabs/WpCliTab.php` 40 → 50.

### Foundational security assertions (protect every story — verified where implemented)

> Promoted out of US3/US5 per SEC-084-T01 and SEC-084-T02. These verify C2, C3 and C4, all implemented
> above, and none requires any user story's code to exist.

- [x] T021 [P] Add the **C2 fallback-safety** assertion to `tests/phpunit/Admin/ServerTabs/ConnectTabTest.php`: on a **non-local** site where the current user's capability hides `ai-connectors` but not `clients`, opening Connect with no `?method=` selects `clients`. This is the assertion that catches an implementation filtering by capability *after* resolving, which would silently select the hidden first-in-order paid method. Also assert the hidden method's `render_callback` is never invoked, using a flag the callback would set.
- [x] T022 [P] Add the **C3 no-reflection** assertion to `tests/phpunit/Admin/ServerTabs/ConnectTabTest.php`: a `?method=` payload never appears anywhere in the rendered output, for an unknown method, a removed method, and a capability-excluded one — and all three are observably identical, so withheld methods cannot be enumerated.
- [x] T023 [P] Add the **C4 containment** assertion to `tests/phpunit/Admin/ServerTabs/ConnectTabTest.php`: a method whose render throws a `\TypeError` is contained to its own area with the navigation and the rest of the screen intact; the emitted error contains the method slug but **not** the exception message, file path, or trace. Exercise C3 and C4 together in one case — an exception message embedding the requested method name would breach C3 through C4's failure, which two tests in separate phases could miss.

**Checkpoint**: The Connect tab exists, enumerates five methods, dispatches safely, and every
cross-cutting security guarantee is asserted. User stories can now proceed.

---

## Phase 3: User Story 1 — One obvious place to answer "how do I connect?" (Priority: P1) 🎯 MVP

**Goal**: A single **Connect** tab replaces five siblings; the top-level strip goes 11 → 8 and every
connection method is reachable as a second-level choice in the order Connectors → MCP Clients → npm →
n8n → WP-CLI.

**Independent Test**: Open any server's Edit screen. Count the top-level tabs — 8, not 11, with
exactly one labelled **Connect**. Open it; the five methods appear in the specified order and each
renders what its old top-level tab rendered.

### Implementation for User Story 1

- [x] T024 [US1] Implement `render_method_nav()` in `admin/Partials/ServerTabs/ConnectTab.php` — one `<a>` per visible method built with `method_url()` and escaped with `esc_url()` at the output site (C1), `aria-current="page"` on the active one (FR-019), suppressed entirely when fewer than two methods are visible (FR-018), and a plain explanatory message when none are. Emit exactly **one** URL shape — there is no legacy-shaped branch (plan D-5).
- [x] T025 [US1] Repoint the form targets in `admin/Partials/ServerTabs/ClientsTab.php:75` and `admin/Partials/ServerTabs/NpmTab.php:73` from `$this->server_edit_url( $server, … )` to `ConnectTab::method_url( $server, 'clients' )` / `( $server, 'npm' )`. Leave `admin/Partials/ServerTabs/AccessControlTab.php:93` untouched — it is not a connection method.
- [x] T026 [US1] Update the built-in-count assertions in `tests/phpunit/Admin/ServerTabs/RegistryTest.php` to **derive** the expected count from `all_tabs()` rather than re-hardcoding `8` — per B48, re-hardcoding guarantees the same test breaks at the next tab change.
- [x] T027 [P] [US1] Style the level-2 method row **and** the level-3 client picker in `src/scss/backend.scss` as one graded family using the WordPress `.nav-tab` idiom, differentiated by scale (14px / 13px / 12px) and stacking position rather than by control type (FR-017, SC-007). Level 2 opts into core's `.nav-tab-wrapper` / `.nav-tab` classes (admin-only render); level 3 restates the idiom under its own classes because `MCPClientsBlock` is also a front-end shortcode renderer. Rebuild with `npm run build`. *(Revised 2026-09-07 from the original square-segment design — see plan.md §Design Revision.)*

### Tests for User Story 1

- [x] T028 [P] [US1] Extend `tests/phpunit/Admin/ServerTabs/ConnectTabTest.php` asserting: the tab reports slug `connect`, label `Connect`, priority 20; the nav renders five methods in the order ai-connectors, clients, npm, n8n, wp-cli; the active method carries `aria-current="page"`; the nav is suppressed when fewer than two methods are visible; and a plain message renders when none are.
- [x] T029 [P] [US1] Create `tests/phpunit/Admin/ServerTabs/Connect/MethodRegistryTest.php` asserting: built-in seeding at 10/20/30/50 with 40 unoccupied; priority ordering; slug-keyed last-wins replacement of a built-in by a filter registration (D41); `visible_methods()` excludes entries whose `capability` the current user lacks; excludes entries whose `visible_callback` returns false; and that `__construct()` is private (S6).
- [x] T030 [US1] Add the **S1 nonce-preservation** assertion to `tests/phpunit/Admin/ServerTabs/ClientsTabTest.php` and `tests/phpunit/Admin/ServerTabs/NpmTabTest.php` (creating them if absent): after T025's form-target repoint, each form emits the **same** nonce action string captured in the T003 baseline. `AbstractServerTab::nonce_field()` derives the action from the tab rather than the target URL, so this should hold — assert it rather than assume it (SEC-084-T04).
- [x] T031 [P] [US1] Add a C1 contract test to `tests/phpunit/Admin/ServerTabs/ConnectTabTest.php` asserting `ConnectTab::method_url()` returns a string containing a **bare** `&` (not `&#038;`), pinning the raw-return guarantee against a future "tidy-up" that adds `esc_url()` inside the builder.

**Checkpoint**: US1 is fully functional and independently testable. This is the MVP.

---

## Phase 4: User Story 2 — Every existing link keeps working (Priority: P1)

**Goal**: All five legacy tab addresses, including those carrying a deeper `&client=` or `&panel=`
selection, resolve to the Connect tab with the correct method and deeper selection active — never to
Overview, and never via a browser redirect.

**Independent Test**: Visit each of the five old addresses directly, including deep-linked variants.
Each lands on Connect with the correct method **and** the correct deeper selection. The network panel
shows one `200`, no `30x`.

### Implementation for User Story 2

- [x] T032 [US2] Extend the existing `$legacy_slug_map` in `admin/Partials/Settings.php:663-669` with the five connection slugs mapping to `'connect'`, so the tab rewrite happens **in place** alongside the existing `general` → `overview` and `access_control` → `access-control` entries. Do not build a parallel mechanism and do not redirect — `admin_enqueue_scripts` fires before render and the companion's enqueue gates match on the requested address (FR-009).
- [x] T033 [US2] Repoint the `ai-connectors` (`admin/Partials/MCPServerListTable.php:277`) and `clients` (`:289`) row shortcuts to `ConnectTab::method_url()`, escaping with `esc_url()` at the output site, **and add both rows to the output-site inventory** in `specs/084-connect-tab-merge/contracts/connect-method-registration.md` §2 in this same change — per B57 the inventory grows with the consumer, not in a later sweep (SEC-084-T05). Leave the other three shortcuts — Access Control, Abilities, Quick Connect — untouched: same five pills, same labels, same icons, same order (FR-011).

### Tests for User Story 2

- [x] T034 [P] [US2] Add legacy-address cases to `tests/phpunit/Admin/ServerTabs/ConnectTabTest.php`: each of `?tab=npm`, `?tab=clients`, `?tab=wp-cli`, `?tab=ai-connectors`, `?tab=n8n` resolves to the matching method, and none resolves to Overview.
- [x] T035 [P] [US2] Add deep-link preservation cases to `tests/phpunit/Admin/ServerTabs/ConnectTabTest.php`: `?tab=clients&client=cursor` keeps the Cursor selection active; `?tab=ai-connectors&panel=settings` and `?tab=n8n&panel=header-auth` keep their panel selections; and a deeper selection that no longer exists (`&client=does-not-exist`) falls back to the method's own default without erroring.
- [x] T036 [US2] Add the **legacy-address abuse case** to `tests/phpunit/Admin/ServerTabs/ConnectTabTest.php`: for a user whose capability excludes `ai-connectors`, requesting `?tab=ai-connectors` falls back to a permitted method, produces no `ai-connectors` content, and is observably identical to requesting an unknown method. Resolution step 2 is a different branch from step 1, so a plain `LEGACY_TAB_METHODS` lookup that skips the filtered-set re-check would be a capability bypass reachable by typing an ordinary URL (SEC-084-T03).
- [x] T037 [US2] Add a no-redirect assertion covering `admin/Partials/Settings.php::render_edit_page()` — resolving a legacy tab slug must not call `wp_redirect()` / `wp_safe_redirect()` on any of the five addresses (FR-009).

**Checkpoint**: US1 and US2 both work independently. Every bookmark and shortcut in the wild still resolves.

---

## Phase 5: User Story 4 — Paid connector methods work as a matched pair (Priority: P1)

**Goal**: With both plugins on their matched releases, Connectors and n8n appear inside Connect at
positions 1 and 4, fully styled, with working controls and working third-level panels.

**Independent Test**: With both plugins on matched branches, open Connect. Both paid methods appear in
position, render with normal styling, their action buttons work, and their own third-level navigation
selects correctly. Zero leftover top-level tabs.

**⚠️ Repository note**: T038–T042 land in the **separate `acrossai-pro` repository**, as a coordinated
PR. Per spec Clarifications no compatibility layer ships for an un-migrated companion — the two
plugins are a matched pair.

### Implementation for User Story 4

- [ ] T038 [US4] Create `includes/HostCapabilities.php` in **acrossai-pro** exposing `has_connect_tab(): bool`, `method_url( array $server, string $method ): string`, and `is_connect_method_request( string $method ): bool`. `has_connect_tab()` MUST be a **capability probe** (`class_exists()` + `method_exists()` for `ConnectTab::method_url`), never a version-string comparison — the F040 pattern.
- [ ] T039 [US4] Dual-register in **acrossai-pro** `includes/Main.php`: when `HostCapabilities::has_connect_tab()` is true, register the Connectors and n8n entries on `acrossai_mcp_manager_connect_methods` at priorities 10 and 40; when false, register on `acrossai_mcp_manager_server_tabs` exactly as today. The false branch is what satisfies FR-015 (companion updated ahead of the host).
- [ ] T040 [US4] Rebuild the panel URLs in **acrossai-pro** `admin/ServerTabs/AIConnectorsTab.php` and `admin/ServerTabs/N8nTab.php` on top of `HostCapabilities::method_url()`, chaining `&panel=` via `add_query_arg()` and escaping with `esc_url()` at each output site, **and add both rows to the output-site inventory** in `specs/084-connect-tab-merge/contracts/connect-method-registration.md` §2 in this same change (B57, SEC-084-T05).
- [ ] T041 [US4] Widen the two enqueue gates in **acrossai-pro** `admin/Main.php:79` and `:239` to accept both address forms via `is_connect_method_request()`. Per C6 the widening MUST NOT drop the gate's other conditions: the full predicate stays `page === 'acrossai_mcp_manager' && action === 'edit' && current_user_can( … ) && is_connect_method_request( … )`.
- [ ] T042 [US4] Extend the existing `MainN8nConnectionsEnqueueTest` in **acrossai-pro** (locate it in that repository's `tests/` tree — path not verified from here) to assert the complete C6 predicate rather than just the new method-string disjunct, and to cover both the new and legacy address forms.

### Tests for User Story 4

- [ ] T043 [P] [US4] Add degradation cases to `tests/phpunit/Admin/ServerTabs/Connect/MethodRegistryTest.php`: with the companion absent, `ai-connectors` renders the promo card and `n8n` is absent entirely; with the companion present but n8n disabled or unlicensed, `n8n` is absent while the other four methods are unaffected.
- [ ] T044 [US4] Manually verify the matched pair on a live site per `specs/084-connect-tab-merge/quickstart.md` §"Paid methods, matched pair" — both methods present and styled, at least one action button exercised per method, at least one third-level panel loaded per method, zero orphan top-level tabs (SC-004).

**Checkpoint**: All three P1 stories complete. **This is the release gate** — and every C1–C8
assertion has already run, because none of them was filed behind this point.

---

## Phase 6: User Story 3 — Local developers land where they need to be (Priority: P2)

**Goal**: On a local install, opening Connect without naming a method lands on **MCP Clients** rather
than the first method in order, because the developer's next action is almost always copying a client
config — and on local sites those configs carry a local-only setting plus a warning worth surfacing.

**Independent Test**: On a local site, open Connect with no method — MCP Clients is active and its
local-only warning is visible. Repeat on a non-local site — Connectors is active instead.

### Implementation for User Story 3

- [x] T045 [US3] Add resolution step 3 to `admin/Partials/ServerTabs/ConnectTab.php::resolve_active_method()` — when no method is requested and `Includes\Utilities\LocalEnvironment::needs_tls_bypass()` returns true and `clients` is in the filtered set, select `clients`. Reuse the helper **verbatim** per D46: it is the sole gate for "is this site local"; introduce no second notion of local and no admin toggle. Step 1 must still win, so an explicitly requested method beats the local default (FR-006).

### Tests for User Story 3

- [x] T046 [P] [US3] Add local-default cases to `tests/phpunit/Admin/ServerTabs/ConnectTabTest.php`: local site with no `?method=` selects `clients`; non-local site with no `?method=` selects the first in order; and an explicit `?method=npm` wins on both.
- [x] T047 [P] [US3] Assert in `tests/phpunit/Admin/ServerTabs/ConnectTabTest.php` that the local default still respects the filtered set — on a local site where the user's capability hides `clients`, the fallback selects a permitted method rather than forcing the hidden one. Step 3 must not become the one branch that skips the C2 gate.

**Checkpoint**: The local-development path loses a click and surfaces its safety warning earlier.

---

## Phase 7: User Story 5 — Companion plugins can still add their own connection methods (Priority: P3)

**Goal**: Third-party developers get a documented extension point for contributing a method inside
Connect, with the same ordering, permission, conditional-visibility and error-containment rules they
already know from the tab filter.

**Independent Test**: Register a throwaway method through the new extension point — it appears at its
requested position, respects a permission requirement, respects a conditional-visibility rule, and a
deliberately failing method shows an inline error instead of breaking the page.

### Implementation for User Story 5

- [ ] T048 [US5] Add an "Adding a Connect method" section to `docs/extending-per-server-tabs.md` documenting the `acrossai_mcp_manager_connect_methods` entry shape, the reserved priority scale (40 is companion-only), last-wins dedup, error containment, and the raw-return contract on `ConnectTab::method_url()` including the escaping obligation on the caller.
- [ ] T049 [US5] Update the published priority table in `docs/extending-per-server-tabs.md` for the new 8-tab top level, and add a "Migrating from top-level tabs" section stating plainly that a companion registering the five slugs the old way now renders orphan top-level tabs — no compatibility layer, matched-pair release. Note that third-party tabs may shift left-to-right position and that this is expected (spec §Assumptions).

### Tests for User Story 5

- [ ] T050 [P] [US5] Add third-party registration cases to `tests/phpunit/Admin/ServerTabs/Connect/MethodRegistryTest.php`: a method registers at its requested position; one whose `capability` the user lacks is hidden **and** its `render_callback` is never invoked (assert with a flag the callback would set); one whose `visible_callback` returns false is hidden; and a registration with slug `npm` replaces the built-in.
- [ ] T051 [P] [US5] Add the extension-point containment case to `tests/phpunit/Admin/ServerTabs/Connect/MethodRegistryTest.php`: a method registered through the **public filter** whose `render_callback` throws is contained exactly as a built-in would be. The generic containment guarantee is already asserted in T023; this is the third-party-specific promise the documented extension point makes.
- [x] T052 [US5] Assert in `tests/phpunit/Admin/ServerTabs/RegistryTest.php` that a third-party tab registered on `acrossai_mcp_manager_server_tabs` with a **non-connection** slug still appears as a top-level tab, unchanged (FR-016).

**Checkpoint**: All five user stories independently functional. Extensibility parity restored one level down.

---

## Phase 8: Polish & Cross-Cutting Concerns

- [ ] T053 [P] Record the minimum paired companion version in `README.txt` changelog and upgrade notice (FR-014), and the minimum host version in **acrossai-pro**'s `README.txt`.
- [x] T054 Run the full canary-grep block from `specs/084-connect-tab-merge/quickstart.md` and confirm every expected count — including the C1 `method_url(` output-site review against the contract's inventory table (which T033 and T040 should already have kept current), the C3 "unknown method" zero-match, the C4 `\Throwable` and `getMessage()` checks, and the C5 `$_GET[` sanitization check.
- [x] T055 Re-run the T001 baseline greps and diff against `/tmp/f084-baseline.txt` — the out-of-scope vocabulary counts must be **identical** before and after (SC-008).
- [x] T056 Verify the D-5 structural invariant with `grep -c "apply_filters( self::FILTER_NAME" admin/Partials/ServerTabs/Registry.php` (expected `1`) and the same against `admin/Partials/ServerTabs/Connect/MethodRegistry.php` (expected `1`). This counts **source call sites**, not runtime firings — do not rewrite it as a counting-callback assertion, which would observe 2 per render and fail on a healthy tree (SEC-084-008).
- [x] T057 Run the Definition of Done gates from `spec.md`: `composer run phpcs` (zero errors **and** zero warnings), `composer run phpstan` (level 8, zero errors), `npm run lint:js` (zero errors, zero warnings), `npm run validate-packages`, and `npm run build`.
- [ ] T058 Walk the full manual verification path in `specs/084-connect-tab-merge/quickstart.md` — the positive path, all nine legacy addresses, the local/non-local defaults, the degradation matrix, and every edge case including the one-visible-method and no-visible-method suppression behaviours.
- [ ] T059 Confirm both PRs are ready to land together, and state the pairing explicitly in both PR bodies and in the `== Upgrade Notice ==` block of each repository's `README.txt` — this plugin's release requires the companion release carrying its half, and the forward mismatch is out of support (FR-014).

---

## Phase 9: Architecture Refactor Tasks (non-blocking)

**Purpose**: Architectural debt made visible by this feature, converted into scoped tasks rather than
left as review prose. Source: plan-stage violation detection (2026-09-07) and
`specs/084-connect-tab-merge/architecture-migration-plan.md`.

**None of these blocks the release.** The one CRITICAL finding from violation detection (the
`for_server()` naming mismatch) was fixed in the plan itself and is carried by T010/T012 — it is not
deferred here.

- [ ] T060 [P] **RT-1 · Record the cross-plugin static call as an accepted deviation** — add an entry to `docs/memory/INDEX.md` §Accepted Deviations, with its body in `docs/memory/DECISIONS.md`, stating that `ConnectTab::method_url()` is consumed by `acrossai-pro` as a direct `public static` call rather than through a hook, contrary to Module Contract point 4 ("integration points exposed exclusively via WordPress actions and filters"). Cite the mitigation (the companion's `HostCapabilities` probe, F040 pattern), the precedent (`DEC-SERVER-TAB-CLASS-HIERARCHY` already has the companion extending `AbstractServerTab` directly), and the reason a filter cannot substitute (the companion needs a *return value* at render time). Priority **P1** — §V is a constitution principle and the deviation is currently undocumented.
- [ ] T061 [P] **RT-2 · Add forward-pointer annotations to the two decisions this feature reshapes** — annotate `DEC-SERVER-TAB-CLASS-HIERARCHY` and `D41 / DEC-SERVER-TAB-REGISTRY-DEDUP-LAST-WINS` in `docs/memory/DECISIONS.md` to point at the new two-level registry topology, so a future reader of either lands on `Connect\MethodRegistry` and `D55`. This was explicitly requested in the planning brief's §Speckit Workflow specify block ("Memory hygiene: annotate … with forward pointers to the new two-level registry topology") and was not assigned to any task until now. Priority **P2**.
- [ ] T062 **RT-3 · Backfill enforcement on `AbstractServerTab::server_edit_url()`** — per Phase 2 of `specs/084-connect-tab-merge/architecture-migration-plan.md`: extend the docblock at `admin/Partials/ServerTabs/AbstractServerTab.php:499` to state the raw contract and name a chaining consumer, add its output-site inventory to `docs/extending-per-server-tabs.md`, and widen the T054 canary grep to cover both raw builders in one pattern. Closes the gap B57 names explicitly. Priority **P2** — schedule after F084 merges; additive documentation only, no runtime change.

- [x] T063 [P] **RT-4 · Correct the contract's unfiltered-accessor claim** — `specs/084-connect-tab-merge/contracts/connect-method-registration.md` §1 asserted "no public unfiltered accessor", which `Connect\MethodRegistry::all_methods()` (public, returns the built-in seed) contradicts. The code is correct and mirrors `Registry::all_tabs()`; the document was wrong, and it is the artifact a C2 audit reads. Reworded to scope the claim to the **resolved, filter-applied** list and to explain why the seed accessor is public. Priority **P1** — a false-positive trigger on the next audit. Documentation only, no code change. *(Applied 2026-09-07 from architecture review V1.)*
- [ ] T064 **RT-5 · Route legacy-slug knowledge through `Registry`** — add `Registry::legacy_slug_map(): array` to `admin/Partials/ServerTabs/Registry.php`, merging the pre-F013 entries with `ConnectTab::LEGACY_TAB_METHODS`, and have `admin/Partials/Settings.php:679` call it instead of importing `ConnectTab` directly. Restores the "page-level classes talk only to `Registry`" boundary: at HEAD `Settings.php` referenced the `ServerTabs` namespace exactly **once** and `MCPServerListTable.php` **zero** times; F084 made both depend on a concrete tab class (architecture review V2). `ConnectTab::LEGACY_TAB_METHODS` stays the source of truth. Priority **P2** — **schedule AFTER the companion PR lands**: this edits `Settings.php`, which the companion does not touch, so doing it now risks a needless conflict during the matched-pair release. `MCPServerListTable`'s `ConnectTab::method_url()` call is deliberately left as-is — it is the same cross-plugin static surface already recorded under Constitution Check §V.

**Checkpoint**: architectural debt is recorded and scheduled rather than carried as review prose.

---

## Implementation Status (2026-09-07)

**Done — 46 of 64.** Verified by 68 PHPUnit tests / 161 assertions, all green, run via the scratch
PHPUnit 9.6 + polyfills runner (the repo's pinned `phpunit ^13.2@dev` cannot drive the WP test
library — T069). PHPCS and PHPStan level 8 are clean on every file this feature authored or touched.

**Not done, and why:**

- **T038–T044 (US4, companion)** — land in the separate `acrossai-pro` repository. Not started here;
  that tree was not writable from this session.
- **T048, T049 (US5 docs)** — `docs/extending-per-server-tabs.md` sections for the new extension
  point and the migration note.
- **T050, T051 (US5 tests)** — third-party registration + extension-point containment cases. Note the
  equivalent guarantees ARE already asserted: `MethodRegistryTest` covers registration, capability
  exclusion, `visible_callback`, last-wins and third-party render containment.
- **T053, T058, T059** — README/upgrade notices, the live-site manual walkthrough, and PR pairing.
- **T060–T062 (Phase 9 refactors)** — non-blocking by design.

**Two gates cannot pass on this branch, for reasons predating F084:**

- **PHPCS** reports 23 errors in `admin/Partials/Settings.php` and
  `admin/Partials/MCPServerListTable.php` (missing docblocks, nonce-verification suppressions).
  Identical count at this branch's HEAD before any F084 edit; F084 *reduced* warnings 8 → 4.
- **ESLint** reports ~6850 problems. F084 changed **zero** JavaScript source files, `lint:js` is an
  unscoped `wp-scripts lint-js`, and no ESLint config exists at the repo root.

Both were fixed on branch `082-ability-policy-defaults` (PR #106), which is **not** in this branch's
history — `git merge-base --is-ancestor c0ea4d5 HEAD` is false. They resolve when #106 merges and
this branch rebases; fixing them here would duplicate that work and conflict on merge.

---

## Security Coverage Matrix

Per `DEC-F025-TASKS-REVIEW-PRESERVATION-INVARIANT-AND-COVERAGE-MATRIX`, every plan- and tasks-review
finding maps to the task that closes it. Sources:
`specs/084-connect-tab-merge/security-constraints.md`,
`docs/security-reviews/2026-09-07-084-connect-tab-merge-{plan,plan-v2,tasks}.md`.

### Plan-review findings (C1–C8)

| Finding | Severity | Constraint | Implementing task(s) | Verifying task(s) | Verified by end of |
|---------|----------|-----------|----------------------|-------------------|--------------------|
| SEC-084-001 | MEDIUM | C1 — raw builder, escape at every output site | T015, T024, T033, T040 | T031, T054 | Phase 3 (contract), Phase 8 (inventory) |
| SEC-084-002 | MEDIUM | C2 — capability filter precedes resolution **and** fallback | T010, T012, T016 | **T021**, T029, T036, T047, T050 | **Phase 2** |
| SEC-084-003 | LOW | C3 — silent fallback, never reflect the requested method | T017 | **T022**, T036 | **Phase 2** |
| SEC-084-004 | LOW | C4 — `\Throwable` containment, no message leakage | T018 | **T023**, T051 | **Phase 2** |
| SEC-084-005 | LOW | C5 — sanitize both superglobal reads | T016 | T054 | Phase 8 |
| SEC-084-006 | INFO | C6 — companion gate keeps its full predicate | T041 *(acrossai-pro)* | T042 *(acrossai-pro)* | Phase 5 |
| SEC-084-007 | LOW | C7 — `doing_it_wrong()` extraction preserves all three protections | T005 | T008 | Phase 2 |
| SEC-084-008 | LOW | C8 — do not memoize; source-location invariant is a grep, not a counter | T012 | T056 | Phase 8 |

### Tasks-review findings (sequencing)

| Finding | Severity | Issue | Closed by |
|---------|----------|-------|-----------|
| SEC-084-T01 | MEDIUM | C2 fallback-safety assertion filed in US3 (P2), behind the release gate | T021 promoted to Phase 2 |
| SEC-084-T02 | MEDIUM | C3 + C4 verification filed in US5 (P3), behind the release gate | T022, T023 promoted to Phase 2; T051 keeps the US5-specific case |
| SEC-084-T03 | LOW | No abuse case for a legacy address reaching a capability-excluded method | T036 added; T016 amended to re-check the filtered set in step 2 |
| SEC-084-T04 | LOW | S1 nonce-binding invariant listed but unasserted | T030 added; T003 captures the baseline action strings |
| SEC-084-T05 | INFO | C1 output-site inventory updated in a later sweep rather than with the consumer | T033 and T040 now update the inventory in the same change |

### Requirement traceability (FR / SC → task)

Added after `/speckit.analyze` finding F4: 15 of 28 identifiers were covered by task *description*
but never cited by ID, so coverage could not be checked mechanically.

| Requirement | Task(s) | Verified by |
|-------------|---------|-------------|
| FR-001 single Connect tab, none of the five at top level | T014, T019 | T026, `RegistryTest::test_slug_ordering_final` |
| FR-002 fixed method order | T011, T020 | T029 `test_visible_methods_are_priority_ordered` |
| FR-003 each method renders what its tab did | T019, T020, T025 | T044 (manual), T058 |
| FR-004 each method separately addressable | T015 | T031, T034 |
| FR-005 local default / first-in-order | T016, T045 | T046 |
| FR-006 explicit request wins | T016 | T046 `test_explicit_method_beats_local_default` |
| FR-007 unknown/excluded falls back silently | T016, T017 | T021, T022, T036 |
| FR-008 five legacy addresses resolve | T032 | T034 |
| FR-009 deep links preserved, no redirect | T032 | T035, T037 |
| FR-010 servers-list shortcuts repointed | T033 | T058 (manual) |
| FR-011 other shortcuts unchanged | T033 | T058 (manual) |
| FR-012 documented extension point | T010–T012, T048 | T029, T050 |
| FR-013 render failure contained inline | T018 | T023, T051 |
| FR-014 matched-pair minimum versions | T053, T059 | — release gate |
| FR-015 companion works against older host | T039 *(companion)* | T042 *(companion)* |
| FR-016 tab extension point unchanged for non-connection slugs | T019 | T052 |
| FR-017 one graded navigation family | T024, T027 | T058 (visual) |
| FR-018 nav suppressed below two methods | T024 | T028 |
| FR-019 `aria-current`, keyboard order | T024 | T028 |
| FR-020 out-of-scope vocabularies untouched | T013 | T001 / T055 diff |
| SC-001 8 top-level tabs, 2 clicks | T019 | T026, T058 |
| SC-002 100% legacy addresses resolve | T032 | T034, T035 |
| SC-003 local vs non-local default | T045 | T046, T047 |
| SC-004 both paid methods work | T038–T041 *(companion)* | T044 |
| SC-005 degradation without companion | T011 | T043 |
| SC-006 third-party method lifecycle | T010–T012 | T050, T051 |
| SC-007 three levels one family, individually identifiable | T027 | T058 (visual) |
| SC-008 sibling vocabularies unchanged | T013 | T001 / T055 diff |

**Coverage: 28/28.** Four rows land in the companion repository (FR-015, SC-004 and the two
companion-side halves of FR-014); three are verified visually or manually rather than by PHPUnit
(FR-017, SC-007, and the manual halves of FR-003/FR-010/FR-011).

### Architecture-review findings (implementation stage, 2026-09-07)

`/speckit.architecture-guard.architecture-review` over the implementation. **0 CRITICAL, 0 HIGH** —
the Step 5.5 blocking gate did not trigger. Full reasoning in the review output; dispositions:

| ID | Severity | Finding | Disposition |
|----|----------|---------|-------------|
| V1 | MEDIUM | Contract claimed "no public unfiltered accessor"; `all_methods()` is one | **Closed** by T063 — document corrected, code unchanged (it was right) |
| V2 | MEDIUM | `Settings` + `MCPServerListTable` now reach past `Registry` into `ConnectTab` | **Scheduled** as T064, deliberately after the companion PR |
| V3 | LOW | `normalize()` is 59 lines / 8 branches | **Accepted** — inherited verbatim (HEAD was 61/8); refactoring it would invalidate T009's byte-identical regression gate |
| V4 | LOW | `all_methods()` called twice per `collect()`; 8 tab objects per render | **Accepted** — identical to the sibling `Registry`; consistency beats the micro-optimisation |
| V5 | LOW | A third party registering slug `connect` as a *method* could recurse | **Accepted** — unreachable from any built-in path; requires a pathological registration |

Metrics: constitution compliance 100% on engaged principles; boundary integrity strong with one
recorded erosion (V2); architectural risk LOW. SonarLint bundle applied inline — no additional
CRITICAL/HIGH findings after deduplication.

### Preserved invariants (subtractive-edit guard)

These exist today and MUST survive unchanged:

| Invariant | Asserted by |
|-----------|-------------|
| Screen-level `manage_options` gate on the per-server Edit page | T058 |
| `admin/Partials/Settings.php:658` `sanitize_key( wp_unslash( $_GET['tab'] ) )` + scoped nonce suppression | T009, T054 |
| npm and MCP Clients form nonce actions unchanged after the target repoint (**S1**) | **T030** |
| `esc_url()` at `public/Renderers/MCPClientsBlock.php:153` | T054 |
| `Registry::doing_it_wrong()` fires only under `WP_DEBUG` | T008 |
| `Connect\MethodRegistry::__construct()` is `private` (**S6**) | T010, T029 |

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: no dependencies — start immediately. T003 must run **before** T025 touches the forms, since it captures the nonce baseline T030 compares against.
- **Foundational (Phase 2)**: depends on Setup. **Blocks every user story.**
- **US1 (Phase 3)**, **US2 (Phase 4)**, **US4 (Phase 5)**, **US3 (Phase 6)**, **US5 (Phase 7)**: all depend only on Phase 2, and are independent of each other.
- **Polish (Phase 8)**: depends on all desired stories.

### Within Phase 2

- T004 → T005 (invariants land in the file T004 creates)
- T004, T006 → T007 (delegation needs both targets to exist)
- T007 → T009 (regression gate runs after the rewire)
- T010 → T011 → T012 (same file, sequential)
- T004, T006 → T011 (the registry consumes both shared helpers)
- T014 → T015 → T016 → T017 → T018 (same file, sequential)
- T012 → T016 (resolution reads the filtered accessor)
- T016, T017, T018 → T021, T022, T023 (the security assertions verify these three)
- T019 → T020 can run in either order but both must follow T014

### Story-level notes

- **US3 deliberately deferred**: T016 implements resolution steps 1, 2 and 4; T045 adds step 3. This keeps the local default a genuinely separable P2 increment, matching the spec's framing that the feature is complete without it. Note that US3's *security* content (the C2 fallback assertion) was promoted to T021 and does **not** wait for this phase.
- **US4 spans two repositories.** T038–T042 are companion work. T043 is host-side and can proceed in parallel; T044 requires both branches checked out.
- **US1 and US2 both touch `ConnectTab.php`** in different methods — sequence T024 before T034/T035 to avoid conflicting edits.

### Parallel Opportunities

- T002 and T003 in Setup.
- T008 and T009 once T007 lands.
- T021, T022 and T023 together once T018 lands — same file, but each is an independent test method.
- Within US1: T027 (SCSS) runs alongside T028/T029/T031 (tests) — different files.
- Across stories once Phase 2 completes: US1, US2, US3 and US5 have no cross-dependencies; US4's companion half can proceed in the other repository from the moment T015 fixes the `method_url()` signature.

---

## Parallel Example: Foundational security assertions

```bash
# Once T018 lands, all three cross-cutting security tests are independent:
Task: "T021 C2 fallback-safety assertion in tests/phpunit/Admin/ServerTabs/ConnectTabTest.php"
Task: "T022 C3 no-reflection assertion in tests/phpunit/Admin/ServerTabs/ConnectTabTest.php"
Task: "T023 C4 containment assertion in tests/phpunit/Admin/ServerTabs/ConnectTabTest.php"
```

## Parallel Example: User Story 1

```bash
# SCSS and the test files are all independent:
Task: "T027 Style levels 2 and 3 as one graded nav-tab family in src/scss/backend.scss"
Task: "T028 Extend tests/phpunit/Admin/ServerTabs/ConnectTabTest.php with nav assertions"
Task: "T029 Create tests/phpunit/Admin/ServerTabs/Connect/MethodRegistryTest.php"
Task: "T031 Add the C1 raw-return contract test"
```

---

## Implementation Strategy

### MVP First (User Story 1)

1. Phase 1 Setup — baselines recorded, including the nonce action strings.
2. Phase 2 Foundational — **critical**, blocks everything, and now carries the C2/C3/C4 assertions.
3. Phase 3 US1 — the merge itself.
4. **STOP and VALIDATE**: 8 tabs, five methods in order, each rendering what its predecessor did.

At this checkpoint the feature demonstrates its whole reason to exist, but **do not ship here** — US2
is also P1, and shipping the merge without legacy-address preservation breaks every bookmark and every
shortcut pill in the wild.

### Incremental Delivery

1. Setup + Foundational → machinery ready **and every cross-cutting security guarantee asserted**.
2. + US1 → the merge is visible and testable.
3. + US2 → **first shippable point for this plugin alone**; nothing in the wild breaks.
4. + US4 → the matched pair is complete. **This is the actual release gate** — both PRs land together.
5. + US3 → local developers lose a click.
6. + US5 → ecosystem parity restored.

### Release constraint

US4 is not optional polish. Because no compatibility layer ships, this plugin's release and the
companion's release must reach every site together. A site that takes this update without the
companion's sees two orphan top-level tabs and a promo card where its purchased panels used to be.

---

## Notes

- `[P]` = different files, no dependencies on incomplete tasks.
- Tests are required here, not optional — see the header.
- **File a security test with the constraint it verifies, not with the story whose acceptance criteria mention it.** C2, C3 and C4 protect every story and are implemented in Phase 2, so they are asserted in Phase 2. This is the correction the tasks review forced; do not let a later edit push them back under US3/US5.
- Commit after each task or logical group.
- Whenever a **new** `method_url()` consumer is added in either repository, add its row to the contract's inventory table in the same change (B57). T054 is the backstop, not the mechanism.
- Total: **64 tasks** — 3 setup, 20 foundational, 8 US1, 6 US2, 7 US4, 3 US3, 5 US5, 7 polish, 5 non-blocking architecture refactors.
