# Feature Specification: Generate-Prompt connect path for MCP clients

**Feature Branch**: `099-mcp-client-setup-prompt`
**Created**: 2026-10-26
**Status**: Draft
**Input**: Planning doc `docs/planings-tasks/099-mcp-client-setup-prompt.md` — Detailed Description
for `/speckit.specify`

## Clarifications

### Session 2026-10-27

- Q: Who may generate a credential and use the prompt panel? → A: Admin-only everywhere; front-end
  embeds show the manual path and no generation control.
- Q: What happens to the previous credential when Generate is used again for the same client and
  server? → A: Mint a new one, keep the old one, and show how many exist with a link to revoke each
  individually.
- Q: Is the generated prompt text translatable? → A: Prose yes; configuration file paths, the
  top-level key and the configuration block never.
- Q: Should the credential-issuance route be rate limited? → A: No limit; record every issuance
  instead.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Hand the AI client the instructions instead of editing files (Priority: P1)

A site administrator wants to connect their AI assistant to this site's MCP server. Today they face
a five-step chore: generate a password, work out which config file their client uses on their
operating system, find the right top-level key inside it, merge a block of JSON into a file that
probably already holds other servers, and restart the client.

Instead, they pick their client, click once to generate a password, and copy a single block of
plain-English instructions that already contains their username, their password, their server
address and the exact configuration block. They paste that into their AI client and the assistant
does the file editing itself.

**Why this priority**: This is the entire feature. The manual path already works; what fails is
that a non-technical administrator stalls at "open or create the config file at
`~/Library/Application Support/…`". Delivering only this story delivers all the value.

**Independent Test**: Navigate to an enabled server's Connect tab, choose the MCP-client method,
pick Claude Code, generate a password, and confirm a copyable instruction block appears containing
the username, the generated password, the server URL and the configuration block. Paste it into a
real client and confirm the client connects.

**Acceptance Scenarios**:

1. **Given** an enabled MCP server and an administrator who has generated a password, **When** they
   select any of the sixteen supported clients, **Then** a copyable instruction block is shown that
   names the config file location for every operating system that client supports, names the
   top-level key, contains the configuration block, and tells the administrator how to make the
   client reload.
2. **Given** an administrator who has **not** yet generated a password, **When** they view the
   instruction block, **Then** the block is shown with a clearly marked placeholder where the
   password belongs, and no credential is present.
3. **Given** an administrator who generates a password, **When** the instruction block updates,
   **Then** the real password appears in it, and the configuration block inside the instructions is
   character-for-character identical to the configuration block shown on the manual path.
4. **Given** an administrator who switches to a different client after generating a password,
   **When** the new client's instructions render, **Then** the previously generated password is not
   carried across, and the placeholder is shown again.

---

### User Story 2 - Keep the manual path for people who want it (Priority: P1)

An administrator who prefers to edit the file themselves, or whose client is not running somewhere
they can paste a prompt, still sees the existing numbered steps: config file location, top-level
key, configuration block, reload instruction.

**Why this priority**: Also P1, because it is a constraint rather than an addition — the new path
must not remove the old one. An administrator mid-way through the manual steps must not find them
gone after an update.

**Independent Test**: On the same screen, confirm both routes are present and independently usable,
and that the manual route's content is unchanged from the previous release.

**Acceptance Scenarios**:

1. **Given** any supported client, **When** the administrator views step 2, **Then** both routes are
   offered and either can be followed to completion.
2. **Given** the manual route, **When** its configuration block, top-level key and reload text are
   compared against the previous release, **Then** they are unchanged.

---

### User Story 3 - Tell the administrator the instructions contain a live credential (Priority: P2)

The instruction block holds a working password in plain text. An administrator pastes it into an AI
assistant, so it enters that assistant's conversation history and, for hosted assistants, leaves
the administrator's machine.

The administrator is told this plainly, next to the copy control, and is given a way to revoke that
credential from the same place.

**Why this priority**: P2 because the feature functions without it, but shipping a one-click
credential-sharing control with no disclosure would be indefensible. It is small and must not be
dropped.

**Independent Test**: Confirm the disclosure is visible without scrolling past the copy control,
and that the revoke affordance reaches the credential list where the newly issued credential is
visible by name.

**Acceptance Scenarios**:

1. **Given** a generated instruction block, **When** the administrator looks at the copy control,
   **Then** a plainly worded note states that the block contains a live password and where it will
   end up.
2. **Given** an issued credential, **When** the administrator wants it gone, **Then** they can reach
   the revocation list in one step and identify that specific credential by name.

---

### User Story 4 - Both places that show connection steps agree (Priority: P2)

The plugin shows connection steps in two places: an individual server's Connect tab, and the guided
setup wizard. An administrator who sees one and then the other must not be given different
instructions.

**Why this priority**: P2 because either surface alone is usable, but the two currently disagree
about config file locations, and adding a second route to only one of them would widen the gap.

**Independent Test**: Open the same server and client in both surfaces and compare the instruction
block and the config file locations.

**Acceptance Scenarios**:

1. **Given** the same server and client, **When** viewed in both surfaces, **Then** the instruction
   block is identical.
2. **Given** a client that documents different config file locations per operating system, **When**
   viewed in either surface, **Then** every documented location is listed and labelled by operating
   system.
3. **Given** a Windows administrator using the wizard, **When** they read the config file location,
   **Then** they are shown the Windows location and not only the macOS one.

---

### Edge Cases

- **Application Passwords unavailable.** The site may have them switched off by constant or filter,
  or disabled for that specific user. The administrator must be told clearly that passwords cannot
  be created and why — not shown a generic failure.
- **Administrator denied access to this server.** Access control may forbid this user from this
  server. No credential may be issued, and the refusal must be recorded.
- **Access-control package absent.** The feature must continue to work, permitting access, rather
  than refusing everything.
- **Client declares only one config file location.** Some clients keep their config inside the
  project rather than the home directory. The instructions must still read correctly.
- **Client stores its credential under an unusual key.** At least one supported client names its
  environment block differently from the rest; the password must still land in the right place.
  This is a defect today on the existing manual path.
- **No server resolved.** When the screen cannot identify which server is being connected, the
  instruction block must be absent rather than render with a missing address.
- **Copying unavailable.** On an insecure context or older browser, the administrator must still be
  able to select the text manually.
- **A client contributed by another plugin.** A client registered through the existing extension
  point that implements only the required minimum must still produce usable instructions rather
  than a fatal error.
- **Repeated generation.** An administrator may generate a credential more than once for the same
  client and server — most commonly because they did not copy the password the first time and
  cannot retrieve it, since credentials are stored hashed. Earlier credentials survive, so they
  accumulate. Making that accumulation visible and individually reversible is the interface's
  responsibility, not the administrator's problem to discover later.
- **A lower-capability user reaching a front-end embed.** The manual path renders; no generation
  control appears. The embed does not become an error page.

---

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The system MUST offer, for every supported MCP client, a single copyable block of
  instructions that directs an AI client to add this site's MCP server to its own configuration.
- **FR-002**: The instruction block MUST name the configuration file location for **every**
  operating system the client declares, each labelled by operating system, with no detection of the
  administrator's own operating system.
- **FR-003**: The instruction block MUST contain the configuration for that client, and that
  configuration MUST be character-for-character identical to the one shown on the manual path.
- **FR-004**: The configuration described MUST remain the one the plugin already produces today.
  This feature MUST NOT change how clients connect, only who performs the file editing.
  **Scope**: this protects the *transport* — the bridge command, its arguments and the `WP_API_*`
  environment variables. It does not freeze *serialisation*: a client whose configuration file is
  not JSON MUST be given its own format, because handing an operator a format their client cannot
  parse is not "unchanged", it is broken. See research R10.
- **FR-005**: The instruction block MUST include both the username and the password. A configuration
  carrying only a password fails every request with nothing visible to the administrator explaining
  why.
- **FR-006**: The instruction block MUST name the top-level key the configuration belongs under, and
  MUST tell the administrator how to make the client pick up the change.
- **FR-007**: Before a password has been generated, the instruction block MUST show a clearly marked
  placeholder in place of the password and MUST NOT contain a credential.
- **FR-008**: The system MUST NOT transmit a real credential when delivering instruction text. A
  credential MUST only ever reach the page as the direct result of the administrator asking for one.
- **FR-009**: When a password is generated, it MUST be substituted into the instruction block
  correctly for **every** supported client, including any client whose configuration names its
  credential block differently from the others.
- **FR-010**: Switching to a different client after generating a password MUST clear the displayed
  credential and restore the placeholder.
- **FR-011**: The manual route MUST remain available and complete: every configuration-file
  location, the top-level key, the configuration itself and the reload instruction MUST all still
  be reachable. Its *presentation* may change — the route split re-ordered these into lettered
  sub-steps under Step 2 (FR-029) — but nothing an operator needs may be dropped. An earlier
  revision of this requirement demanded the content be byte-unchanged; that was written before the
  redesign and did not survive it.
- **FR-012**: Both surfaces that present connection steps MUST show the identical instruction block
  for the same server and client.
- **FR-013**: Both surfaces MUST list configuration file locations for every operating system the
  client declares. Showing one operating system's location to users of another is a defect.
- **FR-014**: Before issuing a credential, the system MUST confirm that Application Passwords are
  available on the site and for the requesting user, and on failure MUST state plainly that
  passwords cannot be created.
- **FR-015**: Before issuing a credential, the system MUST confirm the requesting user is permitted
  access to the server in question, MUST record a refusal when they are not, and MUST continue to
  function — permitting access — when the access-control package is absent.
- **FR-016**: A credential MUST only ever be issued for the requesting user. A request MUST NOT be
  able to name a different user.
- **FR-017**: Responses carrying a credential MUST instruct every cache between the site and the
  browser not to store them.
- **FR-018**: The system MUST retain an identifier for each credential it issues, sufficient to
  revoke that one credential without affecting any other credential belonging to the same user.
- **FR-019**: Credentials issued by this feature MUST appear in the administrator's existing list of
  this plugin's credentials, and their **name MUST identify the client, the server and the date of
  issue**. The name is the durable record of where a credential came from: it persists for as long
  as the credential does, whereas the issuance entry in the activity log ages out on that log's
  retention schedule.
- **FR-020**: The interface MUST state, adjacent to the copy control, **what the credential can do
  and where it will end up** — specifically that it acts as the full account across the whole site
  rather than being limited to this connection, and that pasting it into a hosted assistant sends it
  to that assistant's provider, where it may be retained. Stating only that the block "contains a
  live password" is insufficient: an administrator can reasonably read that as a credential scoped
  to this MCP connection, which it is not. Revocation MUST be one step away from the same panel.
- **FR-021**: Adding this capability MUST NOT break MCP clients contributed by other plugins through
  the existing extension point. A client implementing only the required minimum MUST still produce
  usable instructions.
- **FR-022**: Error messages reaching the administrator MUST be written for this feature. Underlying
  exception text, database error text, storage keys and filesystem paths MUST NOT be exposed.
- **FR-023**: The system MUST NOT add a second way to issue credentials. The existing issuance path
  MUST be reused and brought up to the requirements above.
- **FR-024**: The prompt panel and the credential-generation control MUST be available only to
  administrators. Surfaces reachable by lower-capability users — including front-end embeds — MUST
  show the manual path and MUST NOT offer credential generation. This restriction MUST be applied
  at the panel level; the capability governing the surrounding block MUST continue to come from its
  render context rather than a hardcoded value.
- **FR-025**: Generating a credential MUST NOT revoke or alter any credential issued previously.
  Because credentials therefore accumulate, the interface MUST state how many already exist for the
  server in question and MUST link to where each can be revoked individually.
- **FR-026**: Instruction prose MUST be translatable. Configuration file paths, the top-level key
  and the configuration block MUST NOT pass through translation, and MUST be byte-identical in a
  localized build to an untranslated one.
- **FR-027**: Every credential issuance MUST be recorded, so that the origin of any issued
  credential is answerable after the fact.
- **FR-028**: The credential-issuance route MUST NOT be rate limited. An administrator can already
  create unlimited credentials through WordPress itself, so a limit here would refuse legitimate
  work without removing any capability from the actor it would restrict.
- **FR-029**: Step 2 MUST be the **choice between the two routes**, not the first step of either.
  Each option MUST state what it involves and roughly what it costs the operator, and the prompt
  route MUST be marked as recommended.
- **FR-030**: Only the chosen route MUST be **visible**. Rendering both at once makes them read as
  one long sequence rather than alternatives, which is the problem this split exists to solve.
  Visibility is the requirement, not absence from the document: a route hidden by an attribute whose
  effect is overridden by a style rule has not satisfied this.
- **FR-031**: The prompt route MUST be the default, and an unrecognised selection MUST fall back to
  it rather than rendering neither — a stale or hand-edited URL must not produce a dead screen.
- **FR-032**: Switching routes MUST NOT discard a credential the operator has already generated.
  The credential is shown exactly once and cannot be re-fetched, so a switch that reloads the page
  destroys work the operator may not have copied yet.

### WordPress Requirements

**PHP Version**: PHP 8.1+ (the floor is pinned by the BerlinDB dependency)
**WordPress Version**: 6.9+
**Multisite**: Single-site only for this increment. The feature adds no site-scoped storage, so it
inherits whatever multisite behaviour the existing connection screens already have.
**Required Plugins / Packages**: None beyond the plugin itself. WordPress core Application Passwords
must be available for credential generation.
**Optional Integrations**: `wpboilerplate/wpb-access-control` — consulted before issuing a
credential; the feature MUST continue to function when it is absent.

### Module Placement

**PHP Class(es)**:
- `includes/MCPClients/AbstractMCPClient.php` → namespace `AcrossAI_MCP_Manager\Includes\MCPClients`
  — the new capability joins the existing client contract as an additive, defaulted member so that
  clients contributed by other plugins keep working (FR-021).
- `public/Discovery/ConnectionMethodRegistry.php` → namespace
  `AcrossAI_MCP_Manager\Public\Discovery` — the single description of a client that every consumer
  reads; one new field here reaches both surfaces (FR-012).
- `public/Renderers/MCPClientsBlock.php` → namespace `AcrossAI_MCP_Manager\Public\Renderers` —
  renders connection steps for the server Connect tab.
- `admin/Partials/ApplicationPasswords.php` → namespace `AcrossAI_MCP_Manager\Admin\Partials` —
  owns credential issuance; gains FR-014 through FR-019.

**JavaScript**:
- `src/js/quick-connect/steps/Step11_ClientDetail.jsx` — the wizard's connection-step screen.
- `src/js/backend.js` — non-wizard page behaviour, including credential substitution (FR-009).

**Hook Registration**: All `add_action`/`add_filter` calls for this feature MUST be wired in
`includes/Main.php` via `define_admin_hooks()` or `define_public_hooks()`. This feature is expected
to add no new hooks.

### Admin UI Requirements

**Pre-approved exception — existing connection screens**: This feature adds a second panel to an
existing step on screens that already render hand-built markup under previously ratified
exceptions. It introduces no new screen and no filterable or sortable row set, so DataViews and
DataForm are not triggered. The new panel MUST reuse the existing copy-control and code-block
components rather than introducing new patterns.

The wizard surface MUST continue to render text as text, never as markup.

**Capability placement**: the prompt panel and the generation control are administrator-only
(FR-024), but that restriction MUST be expressed as a check on the panel itself. The capability
governing the surrounding block MUST keep coming from the render context, because hardcoding it
there would contradict the renderer contract that the panel sits inside. The practical consequence
is that a front-end embed still renders the block for whoever its context permits, and simply omits
the prompt panel and the generation control.

### REST API Contract

This feature adds **no new route**. It changes the behaviour of one existing route.

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| `POST` | `/wp-json/acrossai-mcp-manager/v1/generate-app-password` | `manage_options` + server access | Issues an Application Password for the requesting user, scoped to one server and named for this plugin. Gains the availability check (FR-014), the access gate (FR-015), the requesting-user lock (FR-016), the no-store requirement (FR-017) and identifier retention (FR-018). |

**`permission_callback` rule**: `__return_true` is permitted ONLY on public read-only routes. This
route is mutating and MUST check capability.

**No throttle**: this route MUST NOT be rate limited (FR-028). Issuance is recorded instead
(FR-027), which answers the same question — where did these credentials come from — without being
able to refuse an administrator part-way through setup.

**Explicitly out of scope**: two separate registrations of this same route currently exist, and only
one of them ever serves a request, so the stricter of the two is unreachable. Choosing which should
win changes a previously ratified security model and MUST be handled as its own feature. This
feature MUST NOT add a third registration (FR-023), and MUST apply its requirements to the
registration that actually serves traffic.

### Database / Storage

**No new persistent storage.** The feature stores nothing of its own. Credentials are created and
stored by WordPress core's Application Passwords, which hashes them. This feature retains only the
non-secret identifier required by FR-018, alongside the existing credential record.

### Security Checklist

*(Derived from Constitution §III)*

- [ ] All form/AJAX handlers verify nonce via `wp_verify_nonce()` or `check_ajax_referer()`
- [ ] The surrounding block's capability comes from the renderer's render context — never a
      hardcoded `manage_options`. This is **not** in tension with FR-024: the block's capability
      stays contextual, and administrator-only applies to the prompt panel and generation control
      as an additional check at the panel.
- [ ] The credential route has an explicit `permission_callback`
- [ ] All user input sanitized at the system boundary with the most specific function available
- [ ] All output escaped at the point of rendering with the most specific function available
- [ ] No raw interpolated database queries
- [ ] Application Passwords remain stored hashed by WordPress core; this feature never persists a
      plaintext credential, and never returns one except in direct response to a generation request
- [ ] Responses carrying a credential are marked uncacheable (FR-017)
- [ ] Credential issuance is gated on server access and recorded when refused (FR-015)
- [ ] Credentials are only ever issued for the requesting user (FR-016)
- [ ] The administrator is told that the generated instructions contain a live credential (FR-020)
- [ ] The prompt panel and generation control are administrator-only, checked at the panel and not
      hardcoded into the renderer (FR-024)
- [ ] Generating a credential leaves previously issued credentials intact, and the count plus a
      revocation link are shown (FR-025)
- [ ] Translation cannot alter a configuration file path, a top-level key or the configuration
      block (FR-026)
- [ ] Every issuance is recorded (FR-027)

### Key Entities

- **MCP client**: A description of one AI client — its display name, the configuration file
  locations it uses per operating system, the top-level key its servers live under, the shape of its
  configuration entry, and how it is made to reload. Already exists; gains the instruction block.
- **Connection instructions**: The copyable block of prose handed to the administrator, derived
  entirely from an MCP client plus one server. Holds no state of its own.
- **Issued credential**: An Application Password belonging to the requesting user, named for this
  plugin and the client it was created for, with a retained identifier allowing precise revocation.

---

## Success Criteria *(mandatory)*

### Definition of Done Gates

- [ ] PHPCS validation: zero errors and zero warnings
- [ ] PHPStan level 8: zero errors (clear the result cache first — a warm cache reports errors CI
      never sees)
- [ ] ESLint: zero errors
- [ ] PHPUnit tests written and passing for all new PHP logic, including one asserting that a
      minimally implemented third-party client still produces usable instructions (FR-021)
- [ ] Security checklist above: all applicable items verified
- [ ] All hooks wired in `Main.php` — none in class constructors
- [ ] No new admin screen introduced; the new panel reuses existing components
- [ ] No code duplication — shared logic extracted rather than repeated across the two surfaces
- [ ] All functions, hooks, and classes prefixed with `acrossai_mcp_`
- [ ] `npm run validate-packages` passes
- [ ] Production bundle builds with no unexplained drift
- [ ] The client-contract test suites continue to run without a WordPress bootstrap
- [ ] The rename-gate suite remains green

### Measurable Outcomes

- **SC-001**: An administrator can go from an enabled server to a copied, credential-bearing
  instruction block in **three actions**: choose client, generate password, copy.
- **SC-002**: **All sixteen** supported clients produce an instruction block naming the config
  location, the top-level key, the configuration, and how to reload.
- **SC-003**: For all sixteen clients, the configuration inside the instructions is identical to the
  configuration on the manual path, compared character by character.
- **SC-004**: For all sixteen clients, a generated password is correctly substituted into the
  instructions — including the one client whose credential block is named differently from the
  rest, which is not substituted correctly today.
- **SC-005**: Instruction text delivered before generation contains **zero** credentials.
- **SC-006**: Every client that documents more than one operating-system location shows **all** of
  them, on **both** surfaces. Today the wizard shows one, and for at least three clients it is the
  wrong one for Windows and Linux users.
- **SC-007**: The instruction block for a given server and client is **identical** across both
  surfaces.
- **SC-008**: When Application Passwords are unavailable, the administrator receives a message
  naming that cause in **100%** of cases, rather than a generic failure.
- **SC-009**: A user denied access to a server receives **no** credential, and the refusal is
  recorded.
- **SC-010**: Every credential this feature issues is findable by name in the administrator's
  existing credential list, and revocable individually without affecting that user's other
  credentials.
- **SC-011**: The manual route still carries **every** fact an operator needs — all declared
  config-file locations, the top-level key, the configuration, and how to reload — verified against
  the previous release by content rather than by markup.
- **SC-012**: A client contributed by another plugin that implements only the required minimum still
  yields usable instructions, with **no** fatal error.
- **SC-013**: The number of ways the system can issue a credential is **unchanged** at one.
- **SC-014**: A user below administrator who can reach a surface showing connection steps sees the
  manual path and **no** generation control, and the screen renders without error.
- **SC-015**: Generating a credential a second time for the same client and server leaves the first
  credential **working**, and the interface shows a count of existing credentials with a link to
  revoke each one individually.
- **SC-016**: In a localized build, the configuration file paths, the top-level key and the
  configuration block are **byte-identical** to an untranslated build, while the surrounding prose
  is translated.
- **SC-017**: **100%** of credential issuances are recorded and attributable after the fact.
- **SC-018**: Selecting one route leaves the other **not visible** — asserted against the rendered
  page *and* the built stylesheet, because markup alone cannot prove visibility.
- **SC-019**: Switching routes completes **without a page load**, and a credential generated before
  the switch is still present after it.

---

## Assumptions

- WordPress core Application Passwords are available on the target site. Where they are not, the
  feature degrades to a clear explanation rather than a broken screen. The plugin does not check
  this today, which is why FR-014 is stated as a requirement rather than left as an assumption.
- The administrator's AI client can edit local files on their behalf. Clients that cannot are served
  by the manual route, which is why FR-011 retains it.
- The access-control package may or may not be active. The feature functions either way, permitting
  access when it is absent, consistent with the existing recorded decision on graceful degradation.
- The configuration shape the plugin produces today is correct and stays unchanged (FR-004). A
  shorter configuration requiring no local bridge is viable for fifteen of the sixteen clients and
  is recorded in the planning doc as possible future work, explicitly **not** part of this
  increment.
- The generated instructions will carry a live credential into a third-party assistant's history.
  This is accepted as inherent to the feature, mitigated by using a narrowly scoped, individually
  revocable credential (FR-018) and by disclosing it (FR-020).
- Local development environments may drop the authorization header before WordPress sees it, making
  a correct configuration appear broken. This affects verification, not the feature.
- Multisite behaviour is inherited, not extended. No site-scoped storage is added.
- The decision about which of the two existing credential-route registrations should win is deferred
  to its own feature. This increment hardens the one that serves traffic and adds none (FR-023).
- **An Application Password is not scoped to this plugin.** It authenticates as the user across the
  whole REST API, so it can do anything that user can do. Because FR-024 restricts generation to
  administrators, every credential this feature issues is an administrator credential. That is why
  FR-020 must describe the credential's reach rather than its existence, and why FR-018's
  per-credential revocation matters more here than it would for a scoped token.
- **FR-024 and FR-020 are coupled and MUST be revisited together.** Restricting generation to
  administrators reduces who can mint, but guarantees that everything minted is maximally
  privileged. Any later increment that broadens access below administrator MUST revisit FR-020's
  disclosure at the same time — and FR-028's no-throttle decision with it, since that rests on the
  actor already holding administrator powers.
- **Issuance provenance lives in the credential name, not the log.** FR-027's activity entry is a
  convenience with ordinary retention; FR-019's name is the durable record. The log's retention is
  deliberately left unchanged — it is keyed by event kind by design, and carving out an exception
  for one source would cut against that for no gain the name does not already deliver.
- Constitution §III's consent-surface exception, which would permit issuing a self-scoped credential
  to a user below administrator on their own consent, is **deliberately not used** by this feature.
  Access is administrator-only (FR-024). A later increment may revisit this; if it does, FR-028's
  no-throttle decision should be reconsidered at the same time, because it rests on the actor
  already holding administrator powers.
- Credentials accumulate by design (FR-025). The interface is responsible for surfacing that; no
  automatic cleanup, expiry or replacement is in scope for this increment.
- Recording issuance (FR-027) relies on the activity log delivered by Feature 096. Whether an
  issuance is recorded as a request event or as its own event type is an implementation decision
  for `/speckit.plan`, not a requirement here.
