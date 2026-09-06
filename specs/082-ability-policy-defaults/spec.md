# Feature Specification: Per-server Ability Policy Defaults

**Feature Branch**: `082-ability-policy-defaults`
**Created**: 2026-09-05
**Status**: Draft
**Input**: See `docs/planings-tasks/082-per-server-ability-policy-defaults.md` (tracks GitHub issue #95). The specify command was invoked with the F082 planning-doc brief as its detailed description; the spec below distils that brief into user-value, requirements, and success criteria.

## Clarifications

### Session 2026-09-05

- Q: When an MCP server row is deleted, what should happen to its per-ability override rows in `acrossai_mcp_server_abilities`? → A: Cascade-delete via a hook — a new TASK adds the cleanup on the existing server-delete code path (no MySQL foreign key; the cascade lives in the plugin's own delete flow).
- Q: What is the exact shape of `$affected_slugs` passed to subscribers of `acrossai_mcp_server_policy_changed`? → A: A map keyed by slug — `[ 'slug/name' => [ 'was' => bool, 'now' => bool ], ... ]` — carrying the per-slug transition. Matches the per-pair `acrossai_mcp_ability_exposure_changed` granularity so audit-log subscribers don't have to re-derive the diff.
- Q: What UX does the Enable All / Disable All confirm prompt use? → A: `@wordpress/components` `<Modal>` with generic copy (not `window.confirm`; not an override enumeration). Matches the DataViews aesthetic of the rest of the Abilities tab; the dev/local-only attestation covers the decision not to enumerate the overrides being cleared.
- Q: What accessibility role/ARIA should the header pill use? → A: `role="status"` with `aria-live="polite"`. Standard ARIA for reactive informative content; matches the WP admin-notice announce pattern; the pill's content changes after Enable All / Disable All fires so screen-reader users need it queued behind current speech, not interrupting.
- Q: How should the policy POST handle concurrent per-row edits on the same server? → A: Last-write-wins — the policy POST clears all overrides unconditionally, no If-Match, no advisory lock. Any concurrent per-row write that arrived just before is silently wiped and is observable via the `acrossai_mcp_server_policy_changed` action's `affected_slugs` map. Follow-up feature can add optimistic concurrency later without breaking the current shape.
- Q: How is the F030 row-only invariant enforced beyond spec text + review comment + regression test? → A: The row-only method is **renamed** from `resolve()` to `resolve_row_only()` (SEC-001 Option A). The name itself broadcasts the semantics — any future consumer who tries to widen the row-only path or migrate the F030 call site has to type the word "row_only" every time they touch it. This makes the invariant grep-visible in the method name (not only in comments and tests) and shifts the safeguard from process-only to fail-loud-on-rename. The sibling three-tier method stays named `resolve_effective()`.

## User Scenarios & Testing *(mandatory)*

<!--
  User stories are prioritized. Each is independently testable — implementing
  only one still delivers observable value on the MCP Manager Abilities tab.
-->

### User Story 1 — "Enable All" that survives future ability registrations (Priority: P1)

A site administrator running a WordPress install with the AcrossAI MCP Manager plugin opens the **Abilities** tab for a given MCP server. They click **Enable All** because they want this server to expose every ability the site currently registers *and* every ability the site may register later (via a plugin update or a mu-plugin drop-in). Today, Enable All snapshots the current registry — anything registered after the click is silently NOT exposed. F082 changes this so the operator's "expose everything" intent persists as a server-level *policy*, and future ability registrations inherit it automatically.

**Why this priority**: The current behaviour is a footgun — operators believe they've enabled every ability but their MCP clients silently miss abilities registered by later plugin updates. This is the exact scenario the feature exists to solve; without it, F082 has no reason to ship.

**Independent Test**: On a server with `policy='per-ability'` and any distribution of registered abilities, click **Enable All** on the Abilities tab, confirm the modal, then register a brand-new ability via a mu-plugin `wp_register_ability()` call with `meta.mcp.public=false`. Reload the Abilities tab — the newly-registered ability appears as *exposed*. Invoke it from an MCP client (e.g. Claude Desktop) — the call succeeds.

**Acceptance Scenarios**:

1. **Given** a server with `policy='per-ability'` and 100 registered abilities of which 30 have `meta.mcp.public=true`, **When** the admin clicks **Enable All** and confirms, **Then** the server's policy is set to `expose`, all per-ability rows for this server are cleared, the tab counter reads "All 100 exposed — default policy: expose. 0 overrides", and the header pill reads "Default policy: Expose every ability by default".
2. **Given** a server at `policy='expose'`, **When** a mu-plugin registers a new ability whose `meta.mcp.public=false`, **Then** a live MCP client invocation of the ability succeeds without any admin action.
3. **Given** the same `policy='expose'` server, **When** the admin toggles a single ability OFF, **Then** exactly one override row is written for that ability, the counter reads "All N exposed — default policy: expose. 1 override", and the "Only overridden" filter surfaces that one ability.

---

### User Story 2 — "Disable All" that survives future ability registrations (Priority: P1)

Mirror of User Story 1, in the opposite direction: an operator wants this server *locked down* — expose nothing today, and continue to expose nothing tomorrow even if a later plugin update registers new abilities with `meta.mcp.public=true`. Today, Disable All snapshots the current registry; later-registered "public" abilities silently leak. F082 makes the lockdown durable via `policy='hide'`.

**Why this priority**: Symmetric with User Story 1. On locked-down servers (staging, restricted deployments), silent leakage of newly-registered public abilities is a compliance-grade problem, not a UX polish item.

**Independent Test**: On any server, click **Disable All**, confirm the modal, then register a mu-plugin ability whose `meta.mcp.public=true`. Reload the tab — the ability appears as *hidden*. Attempt an MCP client invocation — it returns 403 with `acrossai_mcp_ability_not_exposed`.

**Acceptance Scenarios**:

1. **Given** any server, **When** the admin clicks **Disable All** and confirms, **Then** policy is set to `hide`, all overrides are cleared, and the counter reads "All hidden — default policy: hide. 0 overrides".
2. **Given** a `policy='hide'` server, **When** a mu-plugin registers a new ability with `meta.mcp.public=true`, **Then** an MCP client call to that ability returns 403.
3. **Given** the same `policy='hide'` server, **When** the admin toggles a single ability ON, **Then** that one override wins for that ability while everything else remains hidden.

---

### User Story 3 — Backwards compatibility for pre-F082 installs (Priority: P1)

An operator upgrading from a pre-F082 build of the plugin has an existing server with a mix of per-ability override rows. On upgrade they expect **zero behaviour change** — every ability they previously exposed remains exposed; every ability they previously hid remains hidden; no MCP client sees a different set of abilities on day 1 of the upgrade.

**Why this priority**: A migration that silently flips any exposure decision on any existing install is a regression, not a feature. This story is what makes the DB column addition (via BerlinDB `$upgrades`) safe to ship.

**Independent Test**: Take a snapshot of `wp_acrossai_mcp_server_abilities` on a pre-F082 install. Upgrade the plugin. Load the admin, trigger `admin_init@3` reconciliation. Compare `GET /abilities` responses for every server before and after — every `is_exposed` value must match.

**Acceptance Scenarios**:

1. **Given** an existing install with N override rows across M servers, **When** the plugin upgrades and `admin_init` fires, **Then** the `abilities_default_policy` column is added with default `per-ability` on every server row, and `GET /abilities` responses are byte-for-byte identical to the pre-upgrade responses.
2. **Given** a pre-F082 server whose Enable All had produced 30 `is_exposed=1` rows, **When** the upgrade completes, **Then** those 30 rows still win over the (default) server policy — the effective resolver returns `true` for those slugs and `meta.mcp.public` fallback for the rest.
3. **Given** an existing MCP client that was successfully calling a set of abilities, **When** the plugin upgrades, **Then** the same client, unchanged, calls the same set of abilities successfully.

---

### User Story 4 — Per-ability override still works alongside the new default (Priority: P2)

The operator's usual workflow — flipping individual abilities on or off with the per-row toggle, or selecting several and using **Expose selected** / **Hide selected** — must continue to work identically to F017. Overrides ALWAYS win over the server-level default; a `policy='expose'` server with a `is_exposed=0` row for slug X hides X.

**Why this priority**: The per-row surface is what makes fine-grained policy possible. Regressing it would strand operators who want most-abilities-on plus a specific deny list (or vice versa).

**Independent Test**: On a `policy='expose'` server, toggle one ability OFF via the row toggle. Verify the row is written, the counter updates ("All N exposed, 1 override"), the "Only overridden" filter surfaces that one row, and an MCP client call to that ability returns 403.

**Acceptance Scenarios**:

1. **Given** a `policy='expose'` server, **When** the admin toggles ability X OFF, **Then** an `is_exposed=0` row is written for X, the counter reports 1 override, and MCP calls to X return 403.
2. **Given** a `policy='hide'` server, **When** the admin toggles ability Y ON, **Then** an `is_exposed=1` row is written for Y, the counter reports 1 override, and MCP calls to Y succeed.
3. **Given** either policy, **When** the admin uses **Expose selected** / **Hide selected** on 5 abilities, **Then** 5 override rows are upserted via the unchanged per-pair endpoint, and the existing `acrossai_mcp_ability_exposure_changed` action fires once per changed pair.

---

### User Story 5 — UI counter tells the truth (Priority: P2)

The counter above the Abilities table shows the operator how many abilities are effectively exposed. On a `policy='expose'` server with zero override rows, today's row-count-driven counter reads "0 exposed" — a lie, because every ability is effectively exposed. F082 makes the counter read the resolver output, so it reflects reality.

**Why this priority**: A wrong counter erodes operator trust and makes the whole feature feel broken even when the underlying enforcement is correct.

**Independent Test**: On a `policy='expose'` server with no override rows, verify the counter reads "All N exposed — default policy: expose. 0 overrides" (not "0 of N exposed").

**Acceptance Scenarios**:

1. **Given** `policy='expose'` and zero overrides, **When** the tab renders, **Then** the counter reads "All N exposed — default policy: expose. 0 overrides".
2. **Given** `policy='hide'` and one `is_exposed=1` override, **When** the tab renders, **Then** the counter reads "All hidden — default policy: hide. 1 override".
3. **Given** `policy='per-ability'`, **When** the tab renders, **Then** the counter reads "K of N abilities exposed" where K = count of abilities where the effective resolver returns true.

---

### Edge Cases

- **F030 permission-callback bypass**: F030's `PermissionOverrideProcessor::should_bypass()` calls the renamed row-only method `ExposureResolver::resolve_row_only()` (was `resolve()` pre-F082) with empty meta as a row-existence probe. F082 MUST NOT change that call site's return value — widening `resolve_row_only()` to honour server policy would silently widen the F030 bypass to every ability on any `policy='expose'` server. Guarded by (1) the method name itself (rename per SEC-001 Option A), (2) an explicit F030 regression test whose assertion message names the hazard, and (3) a review-gate comment above the call site.
- **F015 AccessControl deny still wins**: If an F015 rule DENIES ability X and F082's policy says `expose` allows X, the F015 deny wins (F015 runs at priority 10, F082's gate runs at priority 20 and short-circuits on `is_wp_error()`).
- **F020 tool-curation still runs**: F020 (priority 30) runs AFTER F082 and can still veto — F082 does not override it.
- **MCP Adapter absent**: The Abilities tab's existing `function_exists('wp_get_abilities')` guard continues to short-circuit the render. Policy state persists but has no user-visible effect until the adapter is available.
- **No-op policy transition**: Setting `policy='expose'` on a server already at `expose` MUST NOT fire the change action a second time and MUST NOT re-issue the overrides DELETE (audit-log spam prevention).
- **Invalid policy string on POST**: `POST /abilities/policy` with a body like `{"policy": "garbage"}` returns HTTP 400 with `acrossai_mcp_invalid_payload`.
- **Non-existent server_id**: `POST /abilities/policy` with an unknown server_id returns HTTP 404 with `acrossai_mcp_server_not_found`.
- **Unauthenticated / under-privileged caller**: `POST /abilities/policy` returns HTTP 401 for logged-out callers and HTTP 403 for logged-in callers without `manage_options` (WP core's `rest_authorization_required_code()` mapping for a false `permission_callback`).
- **Cache coherence**: After a policy POST, the per-request resolver cache is reset so subsequent lookups reflect the new policy within the same request.
- **Client-side merge removed**: The client MUST NOT re-derive `is_exposed` from `override ? override.is_exposed : mcpMeta.public` — it trusts the server-computed value. Leaving the merge in place makes the UI lie on `policy='expose'` servers.
- **Concurrent edits (last-write-wins)**: If admin A adds a per-row override via `POST /abilities` while admin B fires `POST /abilities/policy` on the same server, the policy POST clears every override for the server unconditionally — admin A's write is silently wiped. No If-Match, no advisory lock. The wipe is observable via `acrossai_mcp_server_policy_changed`'s `affected_slugs` map. Optimistic concurrency is deferred to a follow-up feature.
- **Server deletion cascade**: when an MCP server row is deleted, every per-ability override row in `acrossai_mcp_server_abilities` for that `server_id` MUST be deleted in the same transaction / same code path. The cleanup lives inside the plugin's existing server-delete flow (no MySQL foreign key). Without the cascade, orphan rows accumulate and break `has_override` counters on any server that later reuses the same numeric ID.

---

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The system MUST persist a per-server default exposure policy with exactly three permitted values: `per-ability` (default), `expose`, `hide`.
- **FR-002**: Setting a server's policy to `expose` or `hide` MUST clear any existing per-ability override rows for that server, so the new policy is the sole source of truth for effective exposure.
- **FR-003**: Abilities registered AFTER a policy is set MUST inherit that policy at MCP tool-call time — `expose` grants access to new abilities; `hide` denies it — with no admin action required.
- **FR-004**: Per-ability override rows MUST take precedence over the server-level policy: an explicit `is_exposed=0` row hides an ability even on a `policy='expose'` server, and an `is_exposed=1` row exposes an ability even on a `policy='hide'` server.
- **FR-005**: The Abilities tab counter MUST reflect the effective (resolver-computed) exposure for every registered ability, not a raw row count.
- **FR-006**: The MCP tool-call gate at ability-invocation time MUST enforce the effective (three-tier: row → server policy → meta) exposure resolution.
- **FR-007**: The F030 permission-callback bypass MUST remain row-only — the `PermissionOverrideProcessor::should_bypass()` call site MUST return `false` for any (server, slug) pair with no override row, regardless of the server-level policy. This is a security invariant: widening the bypass to honour policy would silently expand permission-callback bypass to every ability on any `policy='expose'` server. Enforcement is layered: (a) the row-only method is renamed `resolve()` → `resolve_row_only()` so the semantics live in the method name itself (SEC-001 Option A — grep-visible, fail-loud-on-rename); (b) `resolve_row_only()` signature + body are frozen from this feature forward; (c) a review-gate comment lives above the call site at `PermissionOverrideProcessor.php:150`; (d) a merge-blocker regression fence test asserts empty-meta returns `false` on a `policy='expose'` server.
- **FR-008**: Existing pre-F082 installs MUST retain byte-for-byte exposure behaviour after the upgrade: every server migrates to `policy='per-ability'`, existing override rows continue to win, and `GET /abilities` responses for every server are unchanged.
- **FR-009**: The REST policy endpoint MUST reject invalid policy strings with HTTP 400 and MUST reject unknown `server_id` values with HTTP 404.
- **FR-010**: The REST policy endpoint MUST require the `manage_options` capability; unauthenticated requests MUST receive HTTP 401 and logged-in under-privileged requests HTTP 403 (WP core's standard `permission_callback` mapping).
- **FR-011**: When the policy changes, the system MUST fire a new server-scoped action carrying, per flipped slug, both the pre-change and post-change effective exposure so audit-log / observability integrations can subscribe. The `$affected_slugs` payload is a map keyed by ability slug — `[ 'slug/name' => [ 'was' => bool, 'now' => bool ], ... ]` — matching the per-pair `acrossai_mcp_ability_exposure_changed` granularity so subscribers do not have to re-derive the diff.
- **FR-012**: The existing per-pair `acrossai_mcp_ability_exposure_changed` action MUST continue to fire on every per-row upsert, with unchanged signature and unchanged call site — companion plugins that subscribe to it MUST see no change.
- **FR-013**: The GET abilities response MUST include the current server policy (top-level) and, per ability item, a boolean indicating whether an explicit override row exists for that (server, slug) pair.
- **FR-014**: The new database column MUST be added via the plugin's existing BerlinDB reconciler on `admin_init@3` (D28 3-part contract) — no data migration script, no manual `ALTER TABLE`, and no seeded rows.
- **FR-015**: No-op policy transitions (setting `expose` on a server already at `expose`, etc.) MUST NOT fire the change action a second time and MUST NOT re-issue the overrides-clearing DELETE.
- **FR-016**: The React client MUST trust the server-computed `is_exposed` value from `GET /abilities` — it MUST NOT re-derive exposure from `override ? override.is_exposed : meta.mcp.public` (that stale client-side merge is removed as part of this feature).
- **FR-017**: F015 (AccessControl, priority 10) and F020 (tool curation, priority 30) MUST remain untouched — no code change, no priority reordering. F082 changes only the F017 gate at priority 20.
- **FR-018**: Deleting an MCP server row MUST also delete every override row in `acrossai_mcp_server_abilities` with that `server_id`, in the same code path as the server-row delete. Implementation lives inside the plugin (no MySQL foreign key). After a server delete + immediate reload, `SELECT COUNT(*) FROM {$wpdb->prefix}acrossai_mcp_server_abilities WHERE server_id = <deleted_id>` MUST return 0.
- **FR-019**: The header pill above the Abilities DataViews table MUST carry `role="status"` and `aria-live="polite"` so screen readers announce policy transitions (after Enable All / Disable All fires) without interrupting current speech. The pill's text content is the sole source of the announcement — no separate `aria-label` is needed.

### WordPress Requirements

**PHP Version**: PHP 8.1+ (matches plugin's current requires; matches acrossai-abilities-manager sibling)
**WordPress Version**: 6.9+
**Multisite**: Single-site (matches F017's scope; per-site tables, per-site policy)
**Required Plugins / Packages**: `berlindb/core: ^3.0.0` (already installed via F010); `wordpress/mcp-adapter` (required for the tool-call gate to observe the effective policy at call time)
**Optional Integrations**: None new — the feature adds one server-scoped action (`acrossai_mcp_server_policy_changed`) that other plugins MAY subscribe to; none are required to.

### Module Placement

**PHP Class(es)**:
- `includes/Database/MCPServer/{Schema,Table,Row}.php` → namespace `AcrossAI_MCP_Manager\Includes\Database\MCPServer` — delta only (new column def, new BerlinDB `$upgrades` entry, new Row public property).
- `includes/Database/MCPServerAbility/ExposureResolver.php` → namespace `AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility` — deltas: (a) **rename** existing `resolve()` → `resolve_row_only()` (SEC-001 Option A — the row-only semantics move into the method name so the invariant is grep-visible), (b) add new sibling static method `resolve_effective()` with three-tier priority, (c) add private `server_policy()` helper + two new per-request caches, (d) extend `_reset_cache_for_tests()` to reset all three caches.
- `includes/REST/AbilitiesController.php` → namespace `AcrossAI_MCP_Manager\Includes\REST` — delta only (new `post_policy()` handler + augmented `get_abilities()` response; existing `post_abilities()` untouched).
- `includes/MCP/AbilityExposureGate.php` → namespace `AcrossAI_MCP_Manager\Includes\MCP` — one-line delta (`resolve()` → `resolve_effective()`).
- `includes/Abilities/PermissionOverrideProcessor.php` → namespace `AcrossAI_MCP_Manager\Includes\Abilities` — two-line delta: (a) update the call site from `ExposureResolver::resolve( $server_id, $slug, array() )` to `ExposureResolver::resolve_row_only( $server_id, $slug, array() )` (part of SEC-001 rename), (b) add the F082 review-gate comment immediately above.

**Hook Registration**: All `add_action`/`add_filter` calls remain in `includes/Main.php`. F082 does not add any new hook registrations (the new REST route is registered via the existing `AbilitiesController::register_routes()` on `rest_api_init`, already wired). Zero constructor-side hook registration.

### Admin UI Requirements

**Existing screen** (React app rendered inside the Abilities tab; F017's DataViews-driven tab):
- Uses `@wordpress/dataviews`, `@wordpress/components`, `@wordpress/element`, `@wordpress/api-fetch`, `@wordpress/i18n`, `@wordpress/hooks` — no generic React libraries.
- F082 delta: adds a header pill above the DataViews table, adds a fourth exposure-filter option ("Only overridden"), rewrites two DataViews bulk-action handlers (Enable All / Disable All) to open a `@wordpress/components` `<Modal>` (title + generic body copy + primary "Confirm" + secondary "Cancel"; no override enumeration — the dev/local-only attestation covers that decision) before firing the policy POST, and rewrites the counter block to read resolver output.
- The confirm modal MUST use `<Modal>` from `@wordpress/components` (not `window.confirm`) so it matches the DataViews aesthetic of the rest of the tab and inherits WP admin focus-trap + Esc-to-close semantics.
- No new admin screen; no new form; no new WP_List_Table.

### REST API Contract

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| `GET` | `/wp-json/acrossai-mcp-manager/v1/servers/(?P<server_id>\d+)/abilities` | `manage_options` | **Existing** — augmented with top-level `abilities_default_policy` and per-item `has_override` boolean; `is_exposed` per item is now resolver-computed (three-tier). |
| `POST` | `/wp-json/acrossai-mcp-manager/v1/servers/(?P<server_id>\d+)/abilities` | `manage_options` | **Existing — unchanged.** Per-pair override upsert. Continues to fire `acrossai_mcp_ability_exposure_changed` per changed pair. |
| `POST` | `/wp-json/acrossai-mcp-manager/v1/servers/(?P<server_id>\d+)/abilities/policy` | `manage_options` | **New.** Body: `{ "policy": "per-ability" \| "expose" \| "hide" }`. Sets the server policy; clears all overrides for the server; fires `acrossai_mcp_server_policy_changed`. Returns the refreshed override rows (always empty after a non-no-op transition), `abilities_default_policy`, and the `affected_slugs` was/now map — NOT the full abilities list; the client issues a follow-up `GET ?include_abilities=1` to repaint (as-built shape, amended 2026-09-06). |

**`permission_callback` rule**: `__return_true` is FORBIDDEN on the new mutating route; it uses the shared `permission_check()` helper that gates on `current_user_can('manage_options')`.

### Database / Storage

**Custom DB table** (existing — additive column only):
- Table: `{$wpdb->prefix}acrossai_mcp_servers` — existing BerlinDB-backed table from F011.
- New column: `abilities_default_policy VARCHAR(16) NOT NULL DEFAULT 'per-ability'`.
- Migration: BerlinDB `$upgrades` array in `MCPServer\Table::$upgrades` (D28 3-part contract), reconciled by `Main::reconcile_database_schemas()` on `admin_init@3` — no manual `ALTER TABLE`, no data migration.
- Justification for column vs. option: single row per server; keyed by the server row's PK; read on every `resolve_effective()` and cached per-request. Option storage would require a separate lookup and complicate atomic updates alongside the existing server row.

**No new table** for F082 — the existing `{$wpdb->prefix}acrossai_mcp_server_abilities` (F017) continues to hold per-ability override rows.

### Security Checklist

- [ ] All form/AJAX handlers verify nonce — REST route uses WP core nonce middleware via `X-WP-Nonce`; React app uses `apiFetch.createNonceMiddleware`.
- [ ] All admin page renders check `current_user_can('manage_options')` — Abilities tab is under the pre-F082 gated admin surface.
- [ ] All REST routes have explicit `permission_callback` — new `POST /abilities/policy` uses shared `permission_check()` gating on `manage_options`.
- [ ] All user input sanitized at system boundary — `server_id` via `absint`; `policy` via WP REST schema `enum` validation.
- [ ] All output escaped at point of rendering — React app uses `@wordpress/element` escaping; no raw HTML from user input.
- [ ] All DB queries use `$wpdb->prepare()` or BerlinDB parameterised queries — no raw interpolation.
- [ ] OAuth tokens / Application Passwords: N/A — F082 introduces no new secrets.
- [ ] File uploads: N/A — no file upload surface.
- [ ] **F082-specific: `ExposureResolver::resolve_row_only()` (renamed from `resolve()` per SEC-001) MUST NOT be widened.** The F030 permission-callback bypass depends on its exact row-only semantics. Enforcement is name-level (any widening now has to happen inside a method whose name itself says "row_only") plus a merge-blocker regression test at `tests/phpunit/Database/ExposureResolverTest::test_resolve_row_only_still_row_only_for_f030()`.

### Key Entities *(include if feature involves data)*

- **Server Ability Policy** — a per-server default that determines the effective exposure of any ability that does not have an explicit override row. Three values (`per-ability`, `expose`, `hide`). Stored on the server row; readable via `Row::abilities_default_policy`.
- **Per-Ability Override Row** — an explicit `(server_id, ability_slug, is_exposed)` triple in `acrossai_mcp_server_abilities` (existing F017 table). Wins over the server policy.
- **Effective Exposure** — the resolver output for `(server_id, ability_slug, meta)`: row-if-exists → server policy → `meta.mcp.public` fallback. Computed by the new `ExposureResolver::resolve_effective()`.
- **Row-only Exposure** — the strict row-existence probe used exclusively by F030's `PermissionOverrideProcessor::should_bypass()`. Returns `(bool) $row->is_exposed` if a row exists for (server_id, ability_slug), else `! empty( $meta['mcp']['public'] )`. Computed by `ExposureResolver::resolve_row_only()` — renamed from `resolve()` in F082 per SEC-001 Option A so the row-only semantics live in the method name itself. Signature + body frozen from F082 forward.

---

## Success Criteria *(mandatory)*

### Definition of Done Gates

All of the following MUST pass before this feature is considered complete:

- [ ] PHPCS validation: zero errors and zero warnings (`vendor/bin/phpcs`).
- [ ] PHPStan level 8: zero errors (`vendor/bin/phpstan`) — enforced per TASK, not deferred to the end.
- [ ] ESLint: zero errors (`npm run lint:js`).
- [ ] PHPUnit tests written and passing for all new PHP logic — including the F030 row-only-bypass regression fence.
- [ ] Security checklist above: all applicable items verified, including the F082-specific `resolve()` freeze.
- [ ] All hooks wired in `Main.php` — no new constructor-side `add_action`/`add_filter`.
- [ ] React deltas in `src/js/abilities.js` use only `@wordpress/*` packages — no generic React libraries introduced (grep-gate holds from F017).
- [ ] No code duplication — the resolver split adds a sibling, not a copy; server-policy lookup is centralised in `ExposureResolver::server_policy()`.
- [ ] All new functions/hooks/classes prefixed with `acrossai_mcp_` (or namespaced under `AcrossAI_MCP_Manager\`).
- [ ] `npm run validate-packages` passes.
- [ ] Full-plugin grep audits pass: ZERO `ExposureResolver::resolve(` call sites remain (the un-suffixed name is fully retired by the SEC-001 rename — see FR-007); exactly ONE `ExposureResolver::resolve_row_only(` call site remains (the F030 probe in `PermissionOverrideProcessor.php`); ZERO client-side merge patterns remain; exactly ONE `do_action( 'acrossai_mcp_ability_exposure_changed' )` remains (existing per-pair fire).

### Measurable Outcomes

- **SC-001**: A site admin can flip a server to "expose every ability by default" in ≤ 3 clicks: Enable All → confirm modal → visible pill change to "Default policy: Expose every ability by default".
- **SC-002**: On a `policy='expose'` server, an ability registered by a mu-plugin AFTER the policy is set is callable from a live MCP client with zero further admin action. Verified end-to-end.
- **SC-003**: On a `policy='hide'` server, an ability registered by a mu-plugin AFTER the policy is set is uncallable from a live MCP client — the call returns 403 with `acrossai_mcp_ability_not_exposed`.
- **SC-004**: Every pre-F082 install upgraded to F082 shows byte-for-byte identical `GET /abilities` responses for every server, verified by diffing pre-upgrade and post-upgrade responses.
- **SC-005**: The F030 permission-callback bypass returns the same answer for an empty-meta row-only call before and after F082 — row-only, unaffected by the server-level policy. The row-only method is renamed from `resolve()` to `resolve_row_only()` (SEC-001 Option A) so the invariant is grep-visible in the method name itself. Enforced by (1) the rename (any widening now happens inside a method whose name broadcasts the semantics), (2) the merge-blocker regression fence test, and (3) the review-gate comment above the F030 call site. All three gates must hold at merge.
- **SC-006**: The Abilities tab counter is truthful on every policy: on `policy='expose'` with zero overrides it reads "All N exposed — default policy: expose. 0 overrides"; on `policy='hide'` with zero overrides it reads "All hidden — default policy: hide. 0 overrides"; on `policy='per-ability'` it reads "K of N abilities exposed" where K = effective-exposure count.
- **SC-007**: The new server-level action `acrossai_mcp_server_policy_changed` fires exactly once per non-no-op policy transition, carries an `affected_slugs` map keyed by ability slug with correct `was`/`now` booleans per entry, and never fires on no-op transitions. Verified by a subscribed test double that asserts both the map shape and the per-slug transition values.

---

## Assumptions

- **Dev/local-only install premise**: the pre-flight attestation in the planning doc records raftaar1191@gmail.com as attesting that no site outside `~/local-sites/` runs this plugin against real MCP server data or real OAuth-issued tokens. This makes the "clear all overrides when policy flips" step safe without a pre-nuke "you are about to delete N overrides" modal. Any production install between attestation and merge invalidates this and requires the modal.
- **Single-site scope**: matches F017's scope. Multisite is out of scope.
- **`wp_get_abilities()` availability**: the Abilities tab render is already gated on `function_exists('wp_get_abilities')`; F082 preserves that guard.
- **MCP Adapter present at tool-call time**: the F017 tool-call gate (which F082 modifies by one line) assumes `wp_get_ability()` is callable. If the adapter is absent, the gate short-circuits — same as F017.
- **F015 and F020 gates are untouched**: F015 (priority 10, AccessControl) and F020 (priority 30, tool curation) do NOT call the resolver and receive zero code changes. F082 only changes the F017 gate at priority 20.
- **BerlinDB reconciler is trusted**: `Main::reconcile_database_schemas()` on `admin_init@3` reliably picks up new `$upgrades` entries and runs the associated callback exactly once per version bump. This is the D28 contract; F082 does not re-verify it beyond adding one PHPUnit test that exercises the D28 3-part contract for the new column.
- **BerlinDB's `Query` supports either `delete_where` or a raw prepared DELETE**: the `post_policy()` handler must clear overrides for the server; the implementation uses whichever surface BerlinDB exposes, with a raw `$wpdb->prepare` DELETE as fallback.
- **No behaviour change to F040 (AI-connectors companion) or F080 (Quick Connect rename)**: F082 is orthogonal to both.
- **Concurrent admin edits are rare and last-write-wins is acceptable**: two admins editing the same server's Abilities tab at the same instant is uncommon in practice; the audit trail via `acrossai_mcp_server_policy_changed`'s per-slug `was`/`now` diff surfaces any silently-wiped concurrent write. If real usage produces friction, a follow-up feature can add optimistic concurrency (If-Match + ETag on the server row) without breaking F082's endpoint contract.
- **Policy transitions are observable via `acrossai_mcp_server_policy_changed` action-fire ONLY — no persistent audit trail** (SEC-003 from the 2026-09-05 plan-phase security review). Subscribers who are not listening at the moment of the fire miss the transition. The dev/local-only attestation covers this for the current merge; any future production install SHOULD register a subscribed audit-log integration (e.g. WP-native logging, Sentry, dedicated audit table) if it needs a forensic record. A follow-up feature can add an `acrossai_mcp_server_policy_history` DB table if usage warrants — F082's action-fire shape is forward-compatible with that addition.

---

## Post-implementation Amendments (2026-09-06, live-testing phase)

Manual testing between `/speckit-architecture-guard-governed-implement` and the Phase-4 review
surfaced one bug and several UX gaps. All were fixed in the working tree before review; this
section records them as spec deltas so the artifacts match the shipped code.

- **AMD-001 — "Reset to Ability Defaults" button (closes the per-ability revert gap).** The
  REST enum always accepted `'per-ability'` but no UI posted it, so an operator who clicked
  Enable All / Disable All had no path back. A third policy button now posts
  `{ "policy": "per-ability" }` through the same confirm-modal flow, with its own modal branch
  ("Reset to each ability's own default?"). Acceptance: on a `policy='expose'` or `'hide'`
  server, click Reset → confirm → pill turns neutral, counter switches to "K of N abilities
  exposed", all overrides cleared, each ability follows its author's `meta.mcp.public`.
- **AMD-002 — FR-015 residual limitation + disabled-state policy buttons.** Because no-op
  suppression skips the override-clearing DELETE, posting the server's *current* policy can
  never clear overrides (e.g. "clear all overrides while staying per-ability" is impossible via
  this route). Mitigation: each of the three policy buttons is disabled while the server is
  already in that mode, so the UI never offers a silent no-op. A relaxation of the no-op check
  (clear when overrides exist) is a possible follow-up.
- **AMD-003 — live toggle refresh (FR-016 companion bug-fix).** The client kept a write-only
  `overrides` state while rendering exclusively from the server-truth exposure map, so toggles
  froze until a full page reload. Fixed: the dead state is deleted; `saveMany()` optimistically
  flips the server-truth map, then silently re-fetches `GET ?include_abilities=1` to reconcile —
  no full-tab spinner, selection/scroll/focus preserved. The response→state mapping is
  consolidated into one `applyServerResponse()` / `refreshFromServer()` pair.
- **AMD-004 — policy panel + styled pill + confirm-modal styling + branded loading overlay.**
  The header is restructured into a bordered "Default policy" panel (pill badge with per-policy
  colors, counter, three policy buttons); Enable All / Disable All moved out of the
  selection-scoped bulk bar. The pill keeps `role="status"` + `aria-live="polite"` (FR-019
  intact). The confirm modal is width-capped with right-aligned actions and a destructive-red
  Confirm on the hide branch. Policy transitions and initial load show a full-screen pulsing
  AcrossAI icon overlay (mirrors the Quick Connect wizard's `qs__initial-loading--overlay`;
  `iconUrl` localized from `assets/quick-connect/icon.svg` in
  `admin/Main.php::maybe_enqueue_abilities_app()`).
- **AMD-005 — Quick Connect Step 5 "Enable all and continue" migrated to the policy flip.**
  `QuickConnectController::apply_step_5()` no longer upserts one override row per ability
  (~N rows, later registrations silently excluded). It now delegates to the shared
  `MCPServer\PolicyTransition::apply()` service (extracted 2026-09-06 per architecture-review
  R1; also used by `AbilitiesController::post_policy()`): set `abilities_default_policy='expose'`,
  clear override rows, reset resolver caches (same-request summary correctness), and fire
  `acrossai_mcp_server_policy_changed` with the FR-011 was/now map — the plugin's single fire
  site for that action. FR-015 no-op parity: a server already at `'expose'` is untouched and
  the action does not fire.
