# Feature Specification: Merge the five connection tabs into one "Connect" tab

**Feature Branch**: `084-connect-tab-merge`
**Created**: 2026-09-07
**Status**: Draft
**Input**: See `docs/planings-tasks/084-connect-tab-merge.md` (the canonical planning brief). The specify command was invoked with that brief; this spec distils it into user value, requirements, and success criteria.

## Clarifications

### Session 2026-09-07

- Q: Which single noun governs the level-2 concept across the extension point, the URL, the class names, and the docs? → A: **"Method"** everywhere, chosen for long-term consistency over minimising churn. Level 1 stays "tab", level 3 keeps "panel" (companion) and "client" (client picker), so each of the three levels has exactly one unambiguous noun. Consequences: the extension point is named for methods, not panels; the registry class is `Connect\MethodRegistry` (the `Connect` namespace supplies the qualifier, so the short name does not need a prefix and does not collide with the unrelated `Public\Discovery\ConnectionMethodRegistry`); and the shared entry validator is named for registry entries generally, not for tabs, since both the tab registry and the method registry consume it.
- Q: What event removes the accommodation that lets an un-migrated companion keep working? → A: **Build no such accommodation.** The installed base is small and the vendor owns both plugins, so the operator will coordinate the two updates directly. The two plugins become a **matched pair**: the companion release carrying its half of this feature is required alongside the plugin release carrying this one. This removes the host-side absorption shim, the second URL shape in the level-2 navigation, and the duplicated firing of the tab extension point — the three most complex parts of the original design. The reverse pairing (companion updated first, plugin not yet) is still tolerated because it costs the companion only a single capability check; the forward mismatch (plugin updated, companion not) is explicitly **not supported** and its symptoms are recorded under Edge Cases.

## User Scenarios & Testing *(mandatory)*

<!--
  Stories are ordered by operator value. Each is independently testable on the
  per-server Edit screen (?page=acrossai_mcp_manager&action=edit&server=N).
-->

### User Story 1 — One obvious place to answer "how do I connect?" (Priority: P1)

A site administrator opens a server's Edit screen wanting to hook an AI client up to it. Today they
face five sibling tabs — **npm**, **MCP Clients**, **Connectors/Integrations**, **n8n**, **WP-CLI** —
that all answer that same question, spread across an eleven-tab strip with no hint which one applies
to them. After this feature there is a single **Connect** tab; every connection method lives inside
it as a second-level choice, presented in a deliberate order that puts the easiest paths first.

**Why this priority**: This is the feature's whole reason to exist. Without it there is no merge, and
the operator keeps guessing between five doors to the same room.

**Independent Test**: Open any server's Edit screen. Count the top-level tabs — there are 8, not 11,
and exactly one of them is labelled **Connect**. Open it and confirm the five methods appear as a
second-level nav in the order Connectors → MCP Clients → npm → n8n → WP-CLI, and that choosing each
one renders the same content that its old top-level tab rendered.

**Acceptance Scenarios**:

1. **Given** a site with the companion plugin active, **When** the admin opens a server's Edit
   screen, **Then** the tab strip shows 8 tabs with **Connect** in second position (after Overview),
   and none of `npm`, `MCP Clients`, `Connectors/Integrations`, `n8n`, `WP-CLI` appears as a
   top-level tab.
2. **Given** the Connect tab is open, **When** the admin selects each of the five methods in turn,
   **Then** each renders the content its predecessor tab rendered, with no loss of functionality —
   forms submit, config blocks copy, buttons respond.
3. **Given** the Connect tab is open, **When** the admin looks at the second-level nav, **Then** the
   methods appear left-to-right as Connectors, MCP Clients, npm, n8n, WP-CLI.

---

### User Story 2 — Every existing link keeps working (Priority: P1)

Administrators have bookmarked deep links, followed shortcut pills from the servers list, and read
documentation and changelog entries that all reference the old tab addresses. Support articles and
the companion plugin's own navigation also point at them. None of that may break.

**Why this priority**: Silent breakage is worse than the problem being solved. The current screen
sends any unrecognised tab address to Overview with no message, so a stale link would look like the
feature vanished rather than moved.

**Independent Test**: Visit each of the five old addresses directly, including ones that carry a
deeper selection (a specific AI client, a specific connector panel). Each must land on the Connect
tab with the correct method **and** the correct deeper selection already active — never on Overview.

**Acceptance Scenarios**:

1. **Given** a bookmark to any of the five old tab addresses, **When** the admin opens it, **Then**
   the Connect tab opens with the matching method active.
2. **Given** an old address that also names a specific AI client or connector panel, **When** the
   admin opens it, **Then** that deeper selection is preserved and active too.
3. **Given** the admin arrives via an old address, **When** they click any link in the new
   navigation, **Then** they move to the new address form — the interface heals the URL on first
   interaction rather than by bouncing them on arrival.
4. **Given** the servers list, **When** the admin clicks the **Connectors** or **MCP Clients**
   shortcut pill on a row, **Then** they land on the Connect tab with that method active.

---

### User Story 3 — Local developers land where they need to be (Priority: P2)

A developer running the plugin on a local site opens the Connect tab without specifying a method.
Their next real action is almost always copying a client configuration — and on local sites those
configurations carry a special local-only setting plus a warning the operator needs to read. So the
Connect tab opens on **MCP Clients** for them, rather than the first method in the list.

**Why this priority**: A quality-of-life default, not a correctness requirement — the feature is
still complete without it, which is why it sits below the two P1 stories. But it removes a click from
the single most common local-development path and surfaces a safety warning earlier.

**Independent Test**: On a local site, open the Connect tab with no method specified — the MCP
Clients method is active and its local-only warning is visible. Repeat on a non-local site — the
Connectors method is active instead.

**Acceptance Scenarios**:

1. **Given** a local install, **When** the admin opens Connect without naming a method, **Then** the
   MCP Clients method is active.
2. **Given** a non-local install, **When** the admin opens Connect without naming a method, **Then**
   the first method in order (Connectors) is active.
3. **Given** any install, **When** the admin opens Connect **with** a method named, **Then** that
   method wins regardless of whether the site is local.

---

### User Story 4 — Paid connector methods work as a matched pair (Priority: P1)

Two of the five methods — Connectors/Integrations and n8n — are supplied by the separate paid
companion plugin, which also has its own third level of navigation inside them. The two plugins ship
as a **matched pair**: the release carrying this feature requires the companion release carrying its
half. When both are in place, the paid methods appear inside Connect exactly like the free ones, and
every level of their own navigation keeps working.

**Why this priority**: These are purchased features. If they fail to appear, appear twice, or appear
without their styling and working buttons, a paying customer sees their product broken — a P1
correctness concern, not a nicety.

**Independent Test**: With both plugins on their matched releases, open Connect. Both paid methods
appear in position, render with their normal styling, their action buttons work, and their own
third-level navigation still selects correctly.

**Acceptance Scenarios**:

1. **Given** both plugins on their matched releases, **When** the admin opens Connect, **Then** both
   paid methods appear in their specified positions with full styling and working controls.
2. **Given** both plugins on their matched releases, **When** the admin selects a paid method and
   then one of its own third-level panels, **Then** that panel loads and stays selected.
3. **Given** an **older** plugin and the **updated** companion (the reverse-order update, which does
   happen when the paid plugin updates first), **When** the admin opens the Edit screen, **Then** the
   screen behaves exactly as it does today — the companion detects the older host and presents its
   methods the previous way.
4. **Given** the companion is not installed at all, **When** the admin opens Connect, **Then** the
   Connectors method shows the existing upgrade/promotional card and the n8n method is absent.
5. **Given** the companion is installed but its n8n feature is switched off or unlicensed, **When**
   the admin opens Connect, **Then** the n8n method is absent while the other methods are unaffected.

---

### User Story 5 — Companion plugins can still add their own connection methods (Priority: P3)

Third-party developers can already add tabs to the Edit screen through a documented extension point.
Once connection methods live one level down, they need an equivalent, documented way to contribute a
method inside Connect — with the same rules for ordering, permissions, conditional visibility, and
error containment they already know.

**Why this priority**: Extensibility parity matters for the ecosystem but no shipped functionality
depends on it, so it trails the correctness stories.

**Independent Test**: Register a throwaway method through the new extension point and confirm it
appears in the Connect navigation at its requested position, respects a permission requirement,
respects a conditional-visibility rule, and that a deliberately failing method shows an inline error
instead of breaking the page.

**Acceptance Scenarios**:

1. **Given** a third-party plugin registering a method, **When** the Edit screen renders, **Then**
   the method appears in the Connect navigation at its requested position.
2. **Given** a registered method whose required permission the current user lacks, **When** the
   screen renders, **Then** the method is hidden and its content is never produced.
3. **Given** a registered method whose rendering fails, **When** the screen renders, **Then** an
   inline error appears in place of that method's content and the rest of the screen still works.
4. **Given** a method registered with the same name as a built-in one, **When** the screen renders,
   **Then** the later registration replaces the built-in — the mechanism that lets the paid companion
   replace the free promotional card.

---

### Edge Cases

- **Companion absent**: Connectors shows the existing promotional card with no third level; n8n does
  not appear. No errors, no empty containers.
- **Companion present but n8n disabled or unlicensed**: n8n is absent; the other four methods are
  unaffected.
- **Companion present but not yet updated** (out of support, recorded so the symptoms are recognised
  rather than diagnosed from scratch): the two paid methods appear as leftover top-level tabs instead
  of inside Connect, and the Connectors method inside Connect shows the free promotional card rather
  than the purchased panels. The most confusing visible symptom is that **"Connectors/Integrations"
  then appears twice on the same screen** — once as an orphan top-level tab supplied by the
  un-migrated companion, and once as the promotional card inside Connect. Nothing fatals and no data
  is affected; the fix is to update the companion. Deliberately not defended against per
  Clarifications — the operator coordinates both updates.
- **Only one method visible** (e.g. a heavily permission-restricted user): the second-level
  navigation is suppressed entirely rather than rendering a single lonely control.
- **No method visible at all**: the tab shows a plain explanatory message, not an empty panel.
- **Unknown method requested** (typo, stale link, removed third-party method): falls back to the
  default method rather than erroring or showing a blank screen.
- **A method the user lacks permission for is requested directly**: treated as unknown — falls back
  to the default; the restricted content is never produced.
- **A method's rendering throws**: contained to that method's area as an inline error; the tab
  navigation and the rest of the screen keep working.
- **Old address plus a deeper selection that no longer exists** (e.g. a removed connector): the
  method opens on its own default deeper selection instead of erroring.
- **Third-party tabs positioned relative to the removed tabs**: they continue to appear and function;
  their left-to-right position may shift, which is documented as expected.
- **Screen reader / keyboard use**: the active method is programmatically identifiable, and all
  methods are reachable by keyboard in visual order.

---

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The Edit screen MUST present a single top-level **Connect** tab that contains all five
  connection methods, and MUST NOT present any of the five as a top-level tab.
- **FR-002**: The Connect tab MUST present its methods in the fixed order Connectors → MCP Clients →
  npm → n8n → WP-CLI, independent of which plugin supplies each one.
- **FR-003**: Selecting a method MUST render exactly the content its predecessor tab rendered, with
  no loss of behaviour (forms submit, configuration blocks copy, buttons respond).
- **FR-004**: Each method MUST be addressable by a stable link so an administrator can bookmark or
  share a direct path to it.
- **FR-005**: When no method is requested, the tab MUST open the **MCP Clients** method on local
  installs and the first method in order on all other installs.
- **FR-006**: An explicitly requested method MUST always win over the local-install default.
- **FR-007**: A requested method that is unknown, or that the current user may not see, MUST fall
  back to the default method without an error and without producing the restricted content.
- **FR-008**: All five previous tab addresses MUST continue to resolve to the Connect tab with the
  corresponding method active.
- **FR-009**: Legacy addresses MUST preserve any deeper selection they carry (a specific AI client, a
  specific connector or n8n panel), and MUST be resolved without redirecting the browser, so that any
  behaviour keyed to the address the browser actually requested continues to function.
- **FR-010**: Shortcut links elsewhere in the admin that pointed at the merged tabs — specifically the
  **Connectors** and **MCP Clients** row shortcuts on the servers list — MUST point at the
  corresponding method inside Connect.
- **FR-011**: The servers-list row shortcuts MUST otherwise be unchanged: the same five shortcuts,
  same labels, same icons, same order, with the non-merged shortcuts (Access Control, Abilities,
  Quick Connect) untouched.
- **FR-012**: The system MUST provide a documented extension point through which other plugins
  register a connection method, supporting position, required permission, conditional visibility, and
  same-name replacement of a built-in method.
- **FR-013**: A registered method that fails to render MUST be contained to its own area as an inline
  error, leaving the navigation and the rest of the screen usable.
- **FR-014**: The two plugins ship as a **matched pair**. This release MUST declare the companion
  release that carries its half of the feature as the minimum supported companion version, in
  user-visible release notes for both plugins. No compatibility accommodation is built for an
  un-migrated companion; that pairing is out of support and its symptoms are recorded under Edge
  Cases.
- **FR-015**: The companion updated for this feature MUST continue to work against an older version
  of this plugin, presenting its methods the previous way. (Retained because the paid plugin may
  update first, and detecting the older host costs the companion a single capability check.)
- **FR-016**: The existing top-level tab extension point MUST keep working unchanged for tabs that
  are not connection methods.
- **FR-017**: All three stacked navigation rows — the tab strip, the connection-method row, and the
  in-method picker — MUST share one visual family, matching the platform's existing tab styling, and
  MUST be told apart by scale and position within that family rather than by using a different kind
  of control per row. Up to three rows stack on this screen and they must read as one hierarchy, not
  as three unrelated widgets.
- **FR-018**: The second-level navigation MUST be suppressed when fewer than two methods are visible.
- **FR-019**: The active method MUST be programmatically identifiable to assistive technology, and
  all methods MUST be reachable by keyboard in their visual order.
- **FR-020**: Vocabularies belonging to other subsystems that use similar-looking names — the
  connection-method discovery listing, the setup wizard's method values, the public renderer
  identifiers, and the embed transport identifiers — MUST be left unchanged.

### WordPress Requirements

**PHP Version**: PHP 8.1+ (matches the plugin's current requirement)
**WordPress Version**: 7.0+ (`Requires at least` in `README.txt`; tested to 7.1)
**Multisite**: Supported — this feature is per-site admin navigation and introduces no network-level state.
**Required Plugins / Packages**: None new. `wordpress/mcp-adapter` remains a dependency of the surrounding feature area, unchanged by this work.
**Optional Integrations**: `acrossai-pro` — supplies the Connectors and n8n methods. MUST degrade gracefully when absent (promotional card for Connectors, no n8n), when present but older than this feature, and when present but unlicensed or with n8n disabled.

### Module Placement

**PHP Class(es)** *(names follow the level-2 terminology fixed in Clarifications — "method", never "panel")*:
- `admin/Partials/ServerTabs/ConnectTab.php` → namespace `AcrossAI_MCP_Manager\Admin\Partials\ServerTabs` — the container tab: renders admin HTML for the second-level navigation and dispatches to the active method.
- `admin/Partials/ServerTabs/Connect/MethodRegistry.php` → namespace `AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\Connect` — collects, validates, orders and filters the registered methods. The `Connect` namespace segment is the qualifier, so the short class name stays unprefixed; its docblock MUST cross-reference the unrelated `AcrossAI_MCP_Manager\Public\Discovery\ConnectionMethodRegistry` (a different layer, untouched by this feature per FR-020) so neither is grepped in mistake for the other.
- `includes/Utilities/RegistryEntryNormalizer.php` → namespace `AcrossAI_MCP_Manager\Includes\Utilities` — context-neutral shared validation of registration entries, used by both the existing tab registry and the new method registry (constitution §VI: extracted before its second use). Named for registry entries rather than tabs precisely because half its callers register methods, not tabs.
- Deltas only, no new classes: `admin/Partials/ServerTabs/{Registry,NpmTab,ClientsTab,WpCliTab,AIConnectorsPromoTab}.php`, `admin/Partials/Settings.php`, `admin/Partials/MCPServerListTable.php`.

**Hook Registration**: This feature registers no new WordPress hooks. Both registries are lazily
instantiated from the existing render path, so constitution §A1 (all `add_action`/`add_filter` in
`includes/Main.php`) holds by construction. The new extension point is a filter *applied* inside the
registry, not a callback registered by this plugin.

### Admin UI Requirements

**Existing screen** (the per-server Edit page — no new screen is created):

- **Pre-approved WP_List_Table exception** applies unchanged: the `?page=acrossai_mcp_manager` parent
  screen keeps its list table; this feature does not extend that exception.
- **Pre-approved Connector picker card exception** (constitution v1.1.0) continues to cover the paid
  Connectors method and its nested panels, which move one level down but are otherwise untouched.
- The second-level navigation is a small set of sibling links (5 or fewer, no filtering, sorting or
  pagination), matching the same narrow carve-out as the existing in-tab client picker it will sit
  above. It MUST adopt the platform's standard tab styling — the same control the top-level tab strip
  uses — rendered one step smaller, and the third-level picker follows one step smaller again
  (FR-017). The second level may use the platform's own tab classes directly because it renders only
  in the admin; the third level MUST NOT, because it is emitted by a public renderer that is also
  reachable from the front end, and so restates the same styling under its own class names.
- No new data-entry form is introduced, so the DataForm mandate is not engaged by this feature.

### REST API Contract

This feature adds, removes, and modifies **no** REST routes. Navigation state is expressed entirely
through admin page addresses.

### Database / Storage

**No persistent storage.** Navigation state lives in the request; nothing is written to options,
meta, or custom tables. No schema change, no migration, no activation-hook work.

### Security Checklist

*(Derived from Constitution §III — verify all that apply to this feature)*

- [ ] All form/AJAX handlers verify nonce — unchanged; this feature moves existing forms without
      altering their nonce handling.
- [x] All admin page renders check `current_user_can('manage_options')` — inherited from the existing
      Edit screen gate; additionally each registered method carries its own required permission,
      checked before its content is produced (FR-007, FR-012).
- [ ] All REST routes have explicit `permission_callback` — N/A, no REST surface in this feature.
- [x] All user input sanitized at system boundary — the requested method name arrives from the page
      address and MUST be sanitized before use, and validated against the set of visible methods.
- [x] All output escaped at point of rendering — navigation labels and addresses are escaped where
      emitted; address builders return raw values by contract and MUST NOT pre-escape (doing so
      corrupts any address built on top of them).
- [ ] All DB queries use `$wpdb->prepare()` — N/A, this feature issues no queries.
- [ ] OAuth tokens / Application Passwords stored hashed — N/A, no credential handling.
- [ ] File uploads validated — N/A, no upload surface.

### Key Entities

- **Connection method** — one way to connect an AI client to a server (Connectors, MCP Clients, npm,
  n8n, WP-CLI). Has a stable name, a display label, a position in the order, a required permission,
  an optional visibility condition, and content to render. May be supplied by this plugin or by
  another plugin.
- **Method registry** — the ordered, validated, permission-filtered collection of connection methods
  for a given server, assembled fresh per page render. Later registrations replace earlier ones of
  the same name.
- **Legacy address mapping** — the fixed relationship between each of the five previous tab addresses
  and the method it now names; the single source of truth for backwards compatibility.

---

## Success Criteria *(mandatory)*

### Definition of Done Gates

All of the following MUST pass before this feature is considered complete:

- [ ] PHPCS validation: zero errors and zero warnings (`vendor/bin/phpcs`)
- [ ] PHPStan level 8: zero errors (`vendor/bin/phpstan`)
- [ ] ESLint: zero errors (`npm run lint:js`)
- [ ] PHPUnit tests written and passing for all new PHP logic
- [ ] Security checklist above: all applicable items verified
- [ ] All hooks wired in `Main.php` — none in class constructors (this feature adds none)
- [ ] Admin UI carve-outs cited rather than extended (no new DataForm/DataViews obligation triggered)
- [ ] No code duplication — the shared registration-entry validation is extracted to
      `includes/Utilities/`, not copied
- [ ] All functions, hooks, and classes prefixed with `acrossai_mcp_` (or namespaced under
      `AcrossAI_MCP_Manager\`)
- [ ] `npm run validate-packages` passes
- [ ] The companion plugin's coordinated change is merged or explicitly scheduled, and the minimum
      companion version is recorded in both plugins' readme files

### Measurable Outcomes

- **SC-001**: The per-server Edit screen presents **8** top-level tabs where it previously presented
  **11**, with all five connection methods reachable within two clicks of opening the screen.
- **SC-002**: **100%** of the five legacy tab addresses — including variants carrying a deeper
  selection — resolve to the correct method with the correct deeper selection active; **zero** land
  on Overview.
- **SC-003**: On a local install, opening Connect without naming a method lands on MCP Clients
  **100%** of the time; on a non-local install it lands on Connectors **100%** of the time; an
  explicitly named method wins in **100%** of cases on both.
- **SC-004**: With both plugins on their matched releases, **both** paid methods appear inside
  Connect, **zero** leftover top-level tabs appear, and every interactive control in those methods
  responds — verified by exercising at least one action button per method and by loading at least one
  third-level panel per method.
- **SC-005**: With the companion updated ahead of this plugin, the Edit screen behaves exactly as it
  does today; with the companion absent, the remaining methods still render with the Connectors
  promotional card in place.
- **SC-006**: A newly registered third-party method appears at its requested position, is hidden when
  its permission or visibility condition excludes it, and — when made to fail — degrades to an inline
  error while the rest of the screen stays usable, in **100%** of trials.
- **SC-007**: The three navigation levels read as one consistent family and remain individually
  identifiable: an observer can tell which row is the tab strip, which is the method row, and which
  is the in-method picker — by their relative scale and stacking order — without interacting with the
  page, and without any row using a visually unrelated control style.
- **SC-008**: Names belonging to other subsystems are untouched — a search for the discovery listing
  values, the wizard's method values, the renderer identifiers, and the embed transport identifiers
  returns the same results before and after the change.

---

## Assumptions

- **Both plugins ship as a matched pair.** This is a coordinated change across the free plugin and
  the paid companion, released together. Per Clarifications, no compatibility layer is built for an
  un-migrated companion: the installed base is small, the vendor owns both plugins, and the operator
  coordinates the two updates. The reverse order (companion first) is tolerated.
- **Old addresses keep working indefinitely** within this release line; they are not scheduled for
  removal by this feature.
- **Third-party tabs may shift position.** Freeing the positions previously occupied by the five
  merged tabs can move a third-party tab's left-to-right placement. No third-party tab breaks; the
  shift is documented in the published extension guide as expected.
- **Local-install detection is the existing one.** This feature reuses the plugin's current
  local-environment detection rather than introducing new rules for what "local" means.
- **The paid methods' own internal navigation is out of scope.** Their third-level panels move down a
  level intact; their content, defaults, and behaviour are unchanged by this feature.
- **`EmbedsTab` and `WidgetsTab` are out of scope.** They exist as classes but are not currently part
  of the built-in tab set, so they are unaffected.
- **No data, no migration.** Nothing is stored, so there is no upgrade path, no rollback data risk,
  and reverting the feature restores the previous navigation exactly.
