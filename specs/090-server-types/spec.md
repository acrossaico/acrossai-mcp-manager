# Feature Specification: Server Types

**Feature Branch**: `090-server-types`
**Created**: 2026-09-15
**Status**: Draft
**Input**: User description: see `docs/planings-tasks/090-server-types.md` (full `/speckit.specify` payload preserved there)

## Clarifications

### Session 2026-09-15

- Q: When an administrator changes a server's type while the standing tool rule is
  "everything" or "nothing", is the rule left alone or reset? → A: Reset to "choose
  individually", so the new type's set takes effect immediately; the confirmation names both
  effects.
- Q: On a bulk Enable where some selected servers have an unmet dependency, does the whole
  action fail or does it partially succeed? → A: Partial success — eligible servers are
  enabled, and the result names each skipped server and why.
- Q: Should the explanatory text an AI client receives be translatable, or fixed English? →
  A: Translatable, like every other string in the plugin — an AI client on a localised site
  receives it in that site's language.
- Q: Is the canonical term for this concept "kind" or "type"? → A: "server type" — matching
  the branch, the planning doc, and the on-screen field label, so one word is used across
  every artifact. All 77 occurrences of "kind" were normalised to "type"; this bullet keeps
  the original wording as the historical record.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Reset restores the right tools for this server (Priority: P1)

A site administrator opens the Tools tab on a server that is meant to expose the AcrossAI
toolsets, experiments with the tool selection, then presses **Reset** to get back to a known
good state. Today Reset gives them the same three built-in tools on every server regardless
of what that server is for, so on an AcrossAI server "reset to defaults" silently produces
the *wrong* defaults and the operator has to rebuild the selection by hand.

After this story, each server records what type of server it is, and Reset restores that
type's tool set.

**Why this priority**: This is the defect the feature exists to fix. Every other story in
this spec is scaffolding that makes this one possible or safe. Shipped alone it already
removes a trap that silently destroys an operator's tool selection.

**Independent Test**: On a server of each type, add and remove some tools, press Reset, and
confirm the restored set matches that server's type rather than a fixed list.

**Acceptance Scenarios**:

1. **Given** a server of the legacy type, **When** the administrator presses Reset on the
   Tools tab, **Then** the tool set returns to the three built-in protocol tools.
2. **Given** a server of the AcrossAI type on a site where the companion add-on is active,
   **When** the administrator presses Reset, **Then** the tool set returns to the add-on's
   toolsets, not the three built-in tools.
3. **Given** any server, **When** the administrator views the Tools tab, **Then** the
   server's type is visible and, where permitted, changeable.
4. **Given** an administrator changes a server's type, **When** they confirm the warning
   that this replaces the current tool selection, **Then** the tool set becomes that type's
   set and the change survives subsequent page loads.
5. **Given** every server that existed before this feature, **When** the plugin updates,
   **Then** each one is recorded as the legacy type and its served tools are unchanged.

---

### User Story 2 - A server cannot be switched on until it can actually work (Priority: P2)

The AcrossAI type of server depends on a companion add-on for its tools. Without that add-on
such a server has nothing to offer. Rather than letting an administrator switch on a server
that will disappoint, the plugin refuses the action and explains what is missing — offering
two ways forward: install the add-on, or change this server to a type that works today.

**Why this priority**: Prevents the most likely bad first experience — switching on the
recommended server on a fresh site and finding it empty. Depends on US1's notion of a type.

**Independent Test**: With the companion add-on inactive, attempt to switch on an
AcrossAI-type server through every available route and confirm each refuses with an
explanation; then switch the server's type and confirm it switches on.

**Acceptance Scenarios**:

1. **Given** the companion add-on is not active, **When** the administrator tries to switch
   on an AcrossAI-type server from the servers list, **Then** the action is refused with a
   message naming the required add-on.
2. **Given** the same state, **When** the administrator tries the same via the bulk action
   or via the guided setup flow, **Then** those routes refuse it too.
3. **Given** a bulk Enable across a selection where some servers are eligible and some have
   an unmet dependency, **When** the administrator runs it, **Then** the eligible servers are
   enabled, the ineligible ones are left off, and the result names each skipped server and
   the reason.
4. **Given** the same state, **When** the administrator views that server, **Then** both
   remedies are offered: install the add-on, or change the server's type.
5. **Given** a server that is already switched on, **When** the companion add-on is
   deactivated, **Then** the server is NOT switched off automatically.
6. **Given** a server stranded by a deactivated add-on, **When** the administrator switches
   it off, **Then** that always succeeds — only switching on is restricted.
7. **Given** the companion add-on is not active, **When** the administrator creates a new
   server, **Then** the AcrossAI type is not offered as a choice.
8. **Given** an administrator is partway through the guided setup with an AcrossAI-type
   server and skipped the add-on step, **When** they reach the step that switches the server
   on, **Then** they are not stuck — the add-on step itself stops them earlier and explains.

---

### User Story 3 - A connected AI client is told what is wrong, not just refused (Priority: P3)

An AI client is connected and working. An administrator deactivates the companion add-on.
The client's next request must not fail silently or drop the connection — the server keeps
answering and advertises a single entry whose description explains that the add-on must be
installed and activated.

**Why this priority**: Narrow but real. The enablement gate in US2 means this can only be
reached by deactivating the add-on *after* a server was switched on. Valuable because the
alternative is an opaque failure for a remote user who cannot see the admin screen.

**Independent Test**: Connect a client to a working AcrossAI-type server, deactivate the
add-on, then list the server's offerings and confirm exactly one self-describing entry.

**Acceptance Scenarios**:

1. **Given** a switched-on AcrossAI-type server, **When** the add-on is deactivated, **Then**
   the server's address still answers rather than becoming unreachable.
2. **Given** that state, **When** an AI client lists what the server offers, **Then** it sees
   exactly one entry whose description names the required add-on.
3. **Given** that state, **When** an AI client invokes that entry, **Then** the response
   carries the same explanation.
4. **Given** that state, **When** the add-on is reactivated, **Then** the explanatory entry
   disappears and the toolsets return.
5. **Given** an administrator had curated a custom tool selection, **When** the add-on is
   deactivated and later reactivated, **Then** their selection is intact — nothing was
   rewritten while the add-on was away.

---

### User Story 4 - Bulk tool selection, matching the Abilities screen (Priority: P4)

The Abilities screen already offers "Enable All", "Disable All" and "Reset to Ability
Defaults", with a summary of the current standing rule. The Tools screen offers only a single
Reset. An administrator managing many tools has to click one at a time.

Crucially the bulk choice must be a *standing rule*, not a one-time sweep: after choosing
"add everything", tools that appear later — because a companion plugin was activated — must
be included automatically rather than requiring the administrator to notice and repeat.

**Why this priority**: Real convenience and consistency, but the plugin is usable without it.
Last because it is the only story that does not unblock another.

**Independent Test**: Choose "add everything", then activate a plugin that contributes a new
tool, and confirm the new tool is included without further action.

**Acceptance Scenarios**:

1. **Given** the Tools screen, **When** the administrator views it, **Then** bulk controls
   and a summary of the current standing rule are present, mirroring the Abilities screen.
2. **Given** the standing rule is "everything", **When** a new tool becomes available later,
   **Then** it is included without the administrator acting again.
3. **Given** the standing rule is "nothing", **When** an AI client lists the server's
   offerings, **Then** no tools are advertised.
4. **Given** the standing rule is "choose individually", **When** the administrator curates,
   **Then** behaviour matches today's exactly.
5. **Given** the standing rule is "everything" or "nothing", **When** the administrator
   changes the server's type, **Then** the rule returns to "choose individually" so the new
   type's set takes effect immediately, and the confirmation states both effects before the
   administrator commits.
6. **Given** the standing rule is "everything" or "nothing" and the administrator does NOT
   change the type, **When** they view the Tools screen, **Then** it states plainly that the
   type's set has no visible effect while that rule is in force.

### Edge Cases

- **Companion add-on absent entirely** (never installed, versus installed-but-deactivated):
  both are treated as "not available"; the offered remedy differs only in wording.
- **A server records a type nobody recognises** (add-on removed that had contributed its own
  type, or a hand-edited database): the server MUST NOT break. It keeps answering, the
  screen shows the recorded name marked unavailable, and tool resolution falls back to the
  legacy type's set.
- **Administrator lacks `manage_options`**: no change — all screens and routes in this
  feature keep their existing capability checks.
- **Upgrade runs twice, or is interrupted partway**: each step must be independently
  repeatable; a half-applied upgrade must heal on the next run rather than error or
  double-apply.
- **An administrator deliberately changed the recommended server's type**: a later upgrade
  MUST NOT silently revert that choice.
- **The standing tool rule says "everything" while the server's requirement is unmet**: the
  explanatory entry from US3 wins; a server cannot advertise tools that do not exist.
- **Two types both claim to be the default**: resolution is deterministic, and a type whose
  requirement is unmet is never chosen as the default.

---

## Requirements *(mandatory)*

### Functional Requirements

**Recording a server's type**

- **FR-001**: Every MCP server record MUST carry a type.
- **FR-002**: Every server that existed before this feature MUST be recorded as the legacy
  type, and its served tools MUST be unchanged by the upgrade.
- **FR-003**: The plugin-managed recommended server MUST be recorded as the AcrossAI type
  when the upgrade first runs.
- **FR-004**: A later re-run of the upgrade MUST NOT overwrite a type an administrator has
  deliberately changed.
- **FR-005**: The plugin MUST NOT force-correct the recommended server's type on routine
  reconciliation, while continuing to force-correct its name, address and description.

**The catalogue of types**

- **FR-006**: The plugin MUST ship two types: a legacy type whose tools are the three
  built-in protocol tools, and an AcrossAI type that contributes no tools of its own and
  declares a dependency on the companion add-on.
- **FR-007**: Other plugins MUST be able to contribute types, and to replace a type the
  plugin ships under the same name.
- **FR-008**: A type that declares a dependency MUST be reported as unavailable while that
  dependency is unmet.
- **FR-009**: The default type for new servers MUST be one whose dependency is satisfied; the
  legacy type is always available and acts as the floor.
- **FR-010**: An unrecognised recorded type MUST NOT cause a failure anywhere; tool
  resolution falls back to the legacy type's set and the interface marks it unavailable.

**Reset and switching**

- **FR-011**: Reset on the Tools screen MUST restore the tool set of the server's own type.
- **FR-012**: Changing a server's type MUST require confirmation, and that confirmation MUST
  name BOTH effects: the current tool selection is replaced, AND the standing tool rule
  returns to "choose individually".
- **FR-012a**: Changing a server's type MUST reset the standing tool rule to "choose
  individually" when it was "everything" or "nothing", so the new type's set takes effect
  immediately rather than appearing to do nothing.
- **FR-013**: Where a type's set contains tools the server does not currently have, the
  screen MUST offer to apply them, and MUST NOT apply them without the administrator asking.

**Requirement enforcement**

- **FR-014**: Types whose dependency is unmet MUST NOT be offered when choosing a type.
- **FR-015**: A server whose type has an unmet dependency MUST NOT be switchable on, and the
  refusal MUST name what is missing.
- **FR-016**: FR-015 MUST hold on every route that can switch a server on, including bulk
  actions and the guided setup flow.
- **FR-016a**: A bulk enable across a mixed selection MUST partially succeed: eligible
  servers are switched on, ineligible ones are left untouched, and the result MUST name each
  skipped server and the reason. It MUST NOT fail wholesale, and MUST NOT skip silently.
- **FR-017**: Switching a server OFF MUST always be permitted.
- **FR-018**: A server already switched on MUST NOT be switched off automatically when its
  dependency later becomes unmet.
- **FR-019**: Wherever a dependency is unmet, BOTH remedies MUST be offered: satisfy the
  dependency, or change the server's type.
- **FR-020**: The guided setup flow MUST prevent an administrator reaching a step that cannot
  succeed; the dependency step becomes non-skippable when the server in play needs it.

**Runtime behaviour**

- **FR-021**: A server whose type has an unmet dependency MUST still be reachable; the
  feature MUST NOT make its address stop responding.
- **FR-022**: Such a server MUST advertise exactly one entry whose description names the
  required add-on, and invoking it MUST return the same explanation.
- **FR-022a**: That explanatory text MUST be translatable through the plugin's standard
  localisation mechanism, exactly like every other user-facing string. An AI client on a
  localised site therefore receives it in that site's language; no string in this feature is
  exempt from translation.
- **FR-023**: The unmet-dependency state MUST NOT write to stored tool selections; an
  administrator's curation MUST survive a deactivate/reactivate cycle intact.

**Bulk tool selection**

- **FR-024**: The Tools screen MUST offer bulk controls equivalent to the Abilities screen's,
  plus a summary of the current standing rule.
- **FR-025**: The standing rule MUST support "everything", "nothing" and "choose
  individually", with "choose individually" the default and today's behaviour.
- **FR-026**: "Everything" MUST be a standing rule covering tools that become available
  later, not a one-time sweep.
- **FR-027**: "Everything" and "nothing" MUST take precedence over individual selection.
  While either is in force, the screen MUST state which rule is in effect and what it does,
  so the administrator can see why the individual selection is not what the server serves.
  *(Amended post-implementation — the original wording required a separate notice stating the
  type's set has "no visible effect". Built, then removed: under a standing rule that
  difference IS the rule working, and reporting it as an anomaly made a correct state read as
  broken. Deviation recorded in `plan.md` § Complexity Tracking.)*

**Documentation and internal consistency**

- **FR-028**: The plugin MUST document how another plugin contributes a type, in the same
  style as the existing extension documentation.
- **FR-029**: The existing duplicated "companion add-on missing" notice MUST be consolidated
  into one implementation before a third copy is introduced.

### WordPress Requirements

**PHP Version**: PHP 8.1+
**WordPress Version**: 6.9+
**Multisite**: Single-site only — consistent with the existing per-site server table.
**Required Plugins / Packages**: `wordpress/mcp-adapter`, `berlindb/core`
**Optional Integrations**: `acrossai-abilities-manager` — supplies the AcrossAI type's tools.
The plugin MUST function fully without it; only the AcrossAI type is affected.

### Module Placement

**PHP Class(es)**:
- `includes/Database/MCPServer/ServerTypes.php` → namespace
  `AcrossAI_MCP_Manager\Includes\Database\MCPServer` — context-neutral registry, stateless
  static API per the A11 pure-service exemption.
- `includes/Abilities/SetupRequired.php` → namespace
  `AcrossAI_MCP_Manager\Includes\Abilities` — the explanatory entry from US3.
- Changes to existing classes under `includes/Database/MCPServer/`, `includes/MCP/`,
  `includes/REST/`, `admin/Partials/` and `admin/Partials/ServerTabs/`.

**Hook Registration**: All `add_action`/`add_filter` calls MUST be wired in
`includes/Main.php` via `define_admin_hooks()` / `define_public_hooks()` — none in
constructors.

### Admin UI Requirements

**Pre-approved WP_List_Table exception** (MCP Manager parent menu only):
- The servers list at `?page=acrossai_mcp_manager` continues under its pre-ratified
  exception. This feature adds a disabled-state affordance to its existing Enable action and
  introduces no new table.

The Tools tab and Quick Connect are existing React surfaces; this feature extends them in
place and introduces no new admin screen, so no new DataViews/DataForm surface is created.
The classic "Add New MCP Server" form gains one field within its existing markup.

### REST API Contract

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| `GET` | `/servers/{id}/tools` | `manage_options` | Existing route; response gains the server's type and the standing tool rule |
| `POST` | `/servers/{id}/tools` | `manage_options` | Existing route; accepts an optional type so a change of type and its tool set are one atomic write |
| `POST` | `/servers/{id}/tools/policy` | `manage_options` | Sets the standing tool rule |

**`permission_callback` rule**: all three are mutating or admin-only and MUST check
capability. No `__return_true`.

### Database / Storage

**Custom DB table** (extending the existing one):
- Table: `{wpdb->prefix}acrossai_mcp_servers`
- Two new columns: the server's type, and the standing tool rule.
- Justification: both are per-server attributes of an existing custom-table row; storing them
  in options would split one record across two stores.
- Introduced by a single schema-version increment carrying both columns, paired with its
  upgrade callback in the same commit (D28 three-part contract).

### Security Checklist

- [ ] All form/AJAX handlers verify nonce via `wp_verify_nonce()` / `check_ajax_referer()`
- [ ] All admin page renders check `current_user_can('manage_options')`
- [ ] All REST routes have explicit `permission_callback` — no `__return_true`
- [ ] All user input sanitized at the boundary; the type and the standing rule are validated
      against the registered set and rejected when unknown
- [ ] All output escaped at point of rendering
- [ ] All DB queries use `$wpdb->prepare()`
- [ ] The explanatory entry from US3 discloses only the required plugin's public name — no
      paths, versions, or site configuration
- [ ] The enablement refusal is enforced server-side on every route, never in the interface
      alone

### Key Entities

- **Server type**: what a server is for. Carries a display name, a description, the tools it
  starts with, an optional dependency on another plugin, and whether it is the default
  choice. Contributed by the plugin and extensible by others; identified by a stable name.
- **Server record**: gains the type it is, and its standing tool rule.
- **Standing tool rule**: whether a server advertises everything available, nothing, or an
  individually chosen set.

---

## Success Criteria *(mandatory)*

### Definition of Done Gates

- [ ] PHPCS validation: zero errors and zero warnings
- [ ] PHPStan level 8: zero errors
- [ ] ESLint: zero errors
- [ ] PHPUnit tests written and passing for all new PHP logic
- [ ] **A real wp-admin page load completes with no fatal error** — static analysis alone is
      insufficient; a prior spike passed both PHPCS and PHPStan while fatally breaking the
      site on load
- [ ] Security checklist above: all applicable items verified
- [ ] All hooks wired in `Main.php` — none in class constructors
- [ ] No code duplication — the companion-missing notice consolidated per FR-029
- [ ] `bin/verify-f021-gates.sh` passes
- [ ] `npm run validate-packages` passes

### Measurable Outcomes

- **SC-001**: On a server of either type, Reset restores that type's tool set — 100% of the
  time, with zero servers receiving another type's defaults.
- **SC-002**: Upgrading an existing site changes what every pre-existing server advertises by
  exactly zero entries.
- **SC-003**: Switching on a server whose dependency is unmet is refused on 100% of routes
  that can switch a server on, and every refusal names the missing add-on.
- **SC-004**: Switching a server off succeeds 100% of the time, including when its dependency
  is unmet.
- **SC-004a**: A bulk enable across a mixed selection switches on 100% of eligible servers
  and names 100% of skipped ones — no silent skips, no wholesale failure.
- **SC-005**: With the dependency unmet, a connected AI client receives exactly one
  self-describing entry, and the server's address answers rather than failing.
- **SC-006**: A deactivate/reactivate cycle of the companion add-on leaves an
  administrator's curated tool selection byte-identical.
- **SC-007**: With the standing rule set to "everything", a tool that becomes available after
  the choice is advertised without any further administrator action.
- **SC-008**: An unrecognised recorded type produces zero failures and zero blank screens;
  the server remains manageable.
- **SC-009**: Re-running the upgrade on an already-upgraded site changes nothing, and never
  reverts an administrator's deliberate change of type.
- **SC-010**: An administrator can reach a working recommended server from a fresh install
  without ever encountering a dead end with no offered next step.

---

## Assumptions

- **The companion add-on is the only source of the AcrossAI type's tools.** This plugin does
  not and must not contain that vocabulary; the add-on contributes it through the published
  filter. Investigated and settled — see the out-of-scope note below.
- **Both "not installed" and "installed but deactivated" count as unmet.** They differ only
  in the wording of the offered remedy.
- **No string in this feature is exempt from translation**, including the text an AI client
  reads. Hardcoding English would leave the only untranslated string in the plugin, which a
  future contributor would "correct" without knowing why.
- **Where a type's dependency is unmet, the explanatory entry takes precedence over the
  standing tool rule.** A server cannot advertise tools that are not registered, so
  "everything" cannot override it.
- **"Reset to type defaults" on a server whose type is unavailable falls back to the legacy
  type's set**, consistent with FR-010.
- **The standing tool rule is operator-owned on every server, including plugin-managed ones**
  — the plugin's routine reconciliation never writes it. A deliberate type change is an
  operator action and does reset it, per FR-012a.
- **Changing a server's type is an administrator action with a confirmation, not a
  reversible preview.** The previous tool selection is not retained for undo.
- **Multisite is out of scope** for this increment, consistent with the existing table.
- **No data migration beyond the two new columns.** No table renames, no option-key changes.

### Out of Scope

- Moving the toolset machinery out of the companion add-on, or into a shared package.
  Investigated in depth and rejected: the toolsets are a thin index over a large ability
  library that stays put, and extraction would introduce new duplicate-registration defects.
- Making the recommended server the default server (retiring the legacy default server for
  new installs; preselecting the recommended one in pickers). Tracked separately as Feature
  091. Note the "should it ship switched on?" half of that question is already answered here:
  it cannot, because of FR-015.
- The shared-package version skew across sibling plugins. Real, but a change in another
  repository.
