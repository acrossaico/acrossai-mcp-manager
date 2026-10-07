# Feature Specification: Migrate OAuth + AI Connectors from acrossai-pro

**Feature Branch**: `095-oauth-migration`
**Created**: 2026-10-06
**Status**: Draft
**Input**: User description: "Migrate the OAuth 2.1 server and AI Connectors stack from the companion plugin acrossai-pro into acrossai-mcp-manager, making one-click AI client connection a free capability…"

## Clarifications

### Session 2026-10-06

- Q: Should the data migration run only from the admin-side trigger (matching the plugin's five existing one-shot migrations and BerlinDB's own `admin_init` convention), or also from the OAuth request path to close the upgrade window? → A: Admin-side only — follow the established house pattern exactly, with no deviation. The resulting upgrade window is an accepted, documented risk.
- Q: What data volume should the migration be built for — single-pass, or batched with a persisted cursor? → A: Batched with a persisted cursor, despite measured volumes being small (532 rows total on the most-used install). Chosen for robustness on customer sites whose volumes cannot be observed before they fail.
- Q: What should the operator see when the migration fails, given it is silent when the companion is absent? → A: Silent on success and on skip; on repeated failure surface a Site Health critical plus an error-log entry naming the table and cursor position. Visible only when something is genuinely wrong.
- Q: Who may authorise an AI client — preserve the companion's layered policy, tighten the default for new servers, or restrict to administrators? → A: Preserve the existing policy exactly. The migration stays behaviour-neutral on security; any tightening is a separate change with its own review.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - An existing connection keeps working across the upgrade (Priority: P1)

A site owner already pays for AcrossAI Pro and has Claude connected to their WordPress site. They update the free MCP Manager plugin. Claude keeps working — no reconnection, no re-consent, no broken tools.

**Why this priority**: This is the only scenario that can cause irreversible harm. Access and refresh tokens exist solely as SHA-256 digests in the database; the raw token lives only on the AI client. If the handover alters those digests, every connected client is permanently disconnected and the operator has no way to restore them. Every other story in this feature is additive — this one is destructive if wrong.

**Independent Test**: On a site with acrossai-pro active and a live connected client holding a valid token, upgrade the plugin and issue an MCP tool call without re-authorising. It must succeed.

**Acceptance Scenarios**:

1. **Given** a site with acrossai-pro active and a connected AI client holding a valid access token, **When** MCP Manager is upgraded, **Then** the next MCP tool call from that client succeeds without any re-authorisation prompt.
2. **Given** that same site, **When** the client's access token expires and it presents its refresh token, **Then** a new access token is issued and the old one is rotated out.
3. **Given** a connected client, **When** the operator revokes one token of a family, **Then** the entire token family is invalidated.
4. **Given** the upgrade has completed, **When** the operator inspects the database, **Then** the companion's `acrossai_pro_mcp_*` tables are still present and unmodified.
5. **Given** the upgrade routine is interrupted part-way through copying, **When** the next admin page load runs it again, **Then** it resumes from where it stopped rather than restarting, completes, and produces no duplicate rows.

---

### User Story 2 - A free user connects an AI client without buying anything (Priority: P1)

A site owner installs only the free MCP Manager plugin. They open the Connectors tab, paste one URL into Claude, approve a consent screen, and are connected. At no point are they told to buy, install, or activate an add-on.

**Why this priority**: This is the entire point of the feature. Today this user hits an "ADD-ON — Start free trial" card and cannot proceed. P1 alongside Story 1 because the feature delivers no value without it.

**Independent Test**: On a clean site with only this plugin active, complete the Quick Connect wizard end to end and confirm an AI client can call a tool.

**Acceptance Scenarios**:

1. **Given** a clean site with only MCP Manager active, **When** the operator opens a server's Connectors tab, **Then** the real connector interface is shown — not a promotional card.
2. **Given** that operator, **When** they run the Quick Connect wizard and choose one-click connection, **Then** the wizard proceeds directly to the connector detail screen without any purchase, install, or licence step.
3. **Given** an AI client is pointed at the site's connection URL, **When** the operator approves the consent screen, **Then** the client is connected and its tools are callable.
4. **Given** any screen in the plugin, **When** the operator reads it, **Then** no copy offers a trial, a price, or an add-on for connector functionality.

---

### User Story 3 - The operator manages and revokes connections (Priority: P2)

A site owner reviews which AI clients are connected, which users approved them, and disconnects one.

**Why this priority**: Connecting without being able to disconnect is not a complete capability, and revocation is a security control. P2 because Story 2 is demonstrable without it.

**Independent Test**: Connect a client, revoke it from the admin screen, and confirm its next request is rejected.

**Acceptance Scenarios**:

1. **Given** a connected client, **When** the operator revokes it, **Then** its next request is rejected and it no longer appears as connected.
2. **Given** a pending user approval, **When** the operator approves or denies it, **Then** the decision takes effect on the next authorisation attempt.
3. **Given** a WordPress user is deleted, **When** deletion completes, **Then** their tokens and approvals are removed.

---

### ~~User Story 4 - An automation platform connects with an admin-issued token (Priority: P3)~~ — WITHDRAWN

**Withdrawn 2026-10-07 (T086).** Connectors go free; n8n stays a paid capability in the companion, so this story belongs to the companion's spec. Its acceptance scenarios (token shown once, stored only as a hash, authorised for the bound server only) still hold — they are just not this plugin's to satisfy.

What this plugin still owes n8n, and MUST NOT regress: the `?tab=n8n` legacy address mapping in `ConnectTab::LEGACY_TAB_METHODS`, connect-method priority 40 held reserved and unseeded, the filter excluding `connector_slug = 'n8n'` rows from the connectors panel, and `AccessTokenRepository::MAX_ADMIN_TTL_SECONDS` — which is now the LAST line of defence on admin-issued token TTL rather than a second one, because the controller that also validated it lives in another plugin.

---

### Edge Cases

- **The companion was never installed.** The data migration must detect this and skip silently — no empty tables churned, no errors, no admin notice. This is the common case for new free users.
- **The companion is installed but has no OAuth data.** Migration completes as a no-op.
- **A previously-orphaned table of the same name already exists.** Pre-existing installs may carry abandoned tables from before the companion split. These names are currently on an automatic drop-list; reinstating them while that sweeper is live risks the new tables being deleted. See FR-004.
- **Both plugins briefly own OAuth at once.** The companion must stand down automatically rather than registering duplicate routes, tabs, cron events, or rewrite rules.
- **The operator never visits an admin screen after upgrading.** Migration runs from the admin-side trigger only (FR-011), so on a site updated by auto-update or WP-CLI it does not run until someone next loads wp-admin. Until then the companion has already stood down and connected clients cannot authenticate. **This window is an accepted, documented risk** — see Assumptions. It must be stated in the release notes, not silently absorbed.
- **Multisite.** See Assumptions — out of scope for this increment.
- **A DB query fails mid-migration.** The routine must leave the source data intact and remain safe to re-run from its persisted cursor.
- **The migration stalls in a retry loop.** A batch keeps failing, the cursor stops advancing and the completion flag is never set, so every admin page load silently retries while connected clients stay broken. Because the skip path is also silent, this is indistinguishable from success unless surfaced — hence the Site Health critical in FR-011.
- **An operator uninstalls while the companion is still installed.** Connector options are still owned by the companion at that point and must survive.

---

## Requirements *(mandatory)*

### Functional Requirements

**Ownership transfer**

- **FR-001**: The plugin MUST provide the complete OAuth 2.1 authorization-server capability — authorization, token issuance and refresh, dynamic client registration, discovery metadata, bearer validation, consent, and cleanup — without requiring any other plugin.
- **FR-002**: The plugin MUST provide one-click connection profiles for Claude, ChatGPT, Gemini, Grok and Cursor.
- **FR-003**: Admin-issued bearer tokens for automation platforms (n8n) are explicitly OUT of scope for this plugin. ~~The plugin MUST provide admin-issued bearer tokens for automation platforms, scoped to a single server.~~ **Reversed 2026-10-07 (T086)**: connectors go free, n8n stays a paid capability in the companion. This plugin MUST NOT ship an n8n tab, an admin-token controller, or a global-integration registry. It MUST continue to provide the host-side support n8n depends on: the `?tab=n8n` legacy route mapping, connect-method priority 40 held reserved and unseeded, and the filter keeping `connector_slug = 'n8n'` rows off the connectors panel.

**Safety preconditions**

- **FR-004**: The one-shot orphan sweeper that drops the four OAuth table names during normal admin-side operation MUST be deleted, and its invocation unwired, **before** any table of those names is created. Its existing guards (empty-only, run-once) do NOT make it safe — a newly created, still-empty table on a site that has not yet run the sweep is exactly its target.
- **FR-005**: The four OAuth table names MUST **remain** in the uninstall drop-list, and MUST NOT be removed. Their justification changes rather than disappearing: they were listed as orphan cleanup from a previous era, and become the plugin's own tables. Removing them would leak four tables on uninstall; the accompanying comment MUST be rewritten so the next reader does not mistake them for stale orphan entries and delete them. The uninstall path is not part of the live hazard in FR-004 — it runs only at uninstall, never during normal operation.
- **FR-006**: The companion's OAuth capability MUST become inert automatically once this plugin owns OAuth, with no operator action. No route, tab, cron event or rewrite rule may be registered twice.
  **Amended 2026-10-07 (T087).** The original wording said "with no release coordination", and that is false. The companion's probe is all-or-nothing: `bootstrap_oauth_hooks()` returns early as a unit, so its n8n stack (tab, connect method, `AdminTokenController` routes, token audit) goes dormant alongside its OAuth. Since FR-003 was reversed, nothing in this plugin replaces it. **Releasing this plugin alone therefore breaks n8n for every customer who has the companion.** The companion MUST ship first with its n8n registrations lifted out of the OAuth-dormancy block. Stand-down remains automatic for OAuth, discovery, validation and the connectors tab — the coordination requirement is specific to n8n.

**Data continuity**

- **FR-007**: Existing OAuth clients, access tokens, refresh tokens, authorization codes and user approvals MUST remain valid across the upgrade.
- **FR-008**: Stored token digests MUST be transferred byte-for-byte. No re-hashing, normalisation, re-encoding or case change.
- **FR-009**: Token family grouping and per-server binding MUST be preserved, so family revocation and audience checks keep working.
- **FR-010**: The migration MUST copy, never move. Source data MUST remain intact and untouched as a rollback path.
- **FR-011**: The migration MUST copy in batches, persisting a per-table cursor so that an interrupted run resumes from where it stopped rather than restarting. It MUST be idempotent — safe to run repeatedly, producing no duplicates — and MUST NOT depend on a single statement completing within one request. It MUST be triggered from the same admin-side points as the plugin's five existing one-shot migrations — the routine schema-reconciliation pass and activation — and MUST NOT introduce any additional trigger on the front-end or OAuth request path. It MUST use the established completion-option idiom (a dedicated `DONE_OPTION`-style key, read as the early-bail guard and written with autoload disabled), and MUST run after schema reconciliation so the destination tables exist first.
- **FR-012**: The migration MUST be invisible on the happy path — it MUST skip cleanly and silently when the companion's data is absent, and MUST NOT announce successful completion. On repeated failure it MUST become visible: surface a Site Health critical issue naming the affected table and the cursor position it stalled at, and write a matching error-log entry. A stalled migration MUST be distinguishable from a completed one without reading logs, because the failure mode is a quiet retry loop that leaves connected clients broken.

  **Diagnostic output MUST be limited to table name, cursor position and row count.** It MUST NOT include row contents, column values, or raw database error/query text where that text may embed the failing statement. The migration moves credential digests; a diagnostic that echoes the failing row, or the database layer's last error or last query, writes those digests to the error log and potentially to a Site Health panel visible to any user who can view site health.
- **FR-013**: Per-server connector settings and pending-approval records MUST be carried over to the new ownership.
- **FR-014**: The authorisation policy MUST be preserved exactly, with no tightening or loosening. Specifically: being logged in is the floor, not an administrative capability; a connector must first be enabled on that server by an operator; a per-server toggle decides whether non-administrators need approval; unrecognised dynamic-registration clients ALWAYS require approval regardless of that toggle; administrators auto-approve themselves and that self-bypass MUST keep firing its audit event. Issued tokens remain bound to the consenting user and carry only that user's own capabilities. This design carries prior security-review history, so changing it inside a migration is out of scope.

**Contract preservation**

- **FR-015**: Every externally observable identifier MUST remain byte-identical: REST namespace and route paths, the `.well-known` discovery URLs, the authorize and token endpoint paths, the scheduled-cleanup event name, the token issued/revoked/denied event names, the trusted-proxy and connector-profile extension points, and the connector option keys. These are live contracts with already-connected clients; any drift silently breaks production connections.
- **FR-016**: Discovery documents MUST continue to be served at the same URLs with the same shape.

  **The plugin MUST additionally verify that the discovery documents actually served are its own**, not merely that the paths respond. Both documents are registered as top-priority rewrite rules, so whichever implementation registers first wins for the whole site, and a competing one redirects clients to a different authorization endpoint. The existing site-health check MUST therefore compare the served document's issuer and resource values against this plugin's expected values and report a failure when they differ. It MUST NOT attempt to enumerate competing implementations by name — the current guard recognises exactly one, and shipping free multiplies co-installation with implementations it has never heard of. Verifying our own output is bounded; enumerating everyone else's is not.

**User-facing replacement**

- **FR-017**: The promotional placeholder occupying the Connectors slot MUST be removed and replaced by the real connector interface, in the same navigation position, so the change is visually seamless.
- **FR-018**: The Quick Connect wizard MUST route the operator from choosing one-click connection straight to the connector detail screen. **Both** intermediate steps — the promotional step and the add-on-setup step — MUST be deleted outright, together with the licence gating that decides whether to show them: the paid marking on the method grid, the wizard router's skip predicate, and the licence check in the Quick Connect REST controller. Neither step is repurposed.
- **FR-019**: This feature MUST NOT introduce any replacement promotion for the paid offering. Removing the wizard's paid-gating also removes the product's main in-wizard surface for the Abilities library; re-establishing that is deliberately deferred to its own feature rather than designed inside this migration.
- **FR-020**: No screen, wizard step, notice, or built asset may contain copy offering a trial, price, or add-on purchase for connector functionality.

**Hygiene**

- **FR-021**: All user-facing strings in the migrated code MUST be re-attributed to this plugin's text domain.
- **FR-022**: All event registrations for migrated code MUST be wired centrally, per the project's hook-registration rule — no self-registration inside feature classes.
- **FR-023**: Companion-specific wiring — licence gates, host-dependency probes, and companion constants — MUST NOT be carried across.
- **FR-024**: Where the migrated code duplicates an existing utility in this plugin, one implementation MUST be kept and all callers repointed.

**Explicit non-goals**

- **FR-025**: No code may be deleted from the companion plugin in this feature.
- **FR-026**: The companion's data MUST NOT be dropped or altered.
- **FR-027**: The connector option namespace MUST remain excluded from this plugin's uninstall sweep, because the companion owns those options. **Amended 2026-10-07**: the original rationale said "until its OAuth is stripped in a follow-up", implying a transitional state. Under the option C split the companion keeps its `oauth_tokens` and `oauth_clients` tables **permanently** to serve n8n, so this exclusion is co-ownership, not a temporary measure, and MUST NOT be removed on the assumption that the follow-up release retires it.
- **FR-028**: The declared PHP and WordPress minimums MUST NOT change.

### WordPress Requirements

**PHP Version**: 8.1+ (the plugin's declared `Requires PHP`). Note: the spec template text says "PHP 8.0+ / 7.4 minimum" — that is stale relative to the shipped header; see Assumptions.
**WordPress Version**: 7.0+ as currently declared (see Assumptions — the declared floor is under separate review and is not changed here).
**Multisite**: Single-site only for this increment — the migrated code carries no network-wide table or option handling.
**Required Plugins / Packages**: `berlindb/core` (already a dependency), `wordpress/mcp-adapter` (already bundled). **No new Composer dependency is introduced** — the OAuth implementation is self-contained.
**Optional Integrations**: `acrossai-pro` — may be present and active, absent, or present but inactive. All three MUST work. When present it supplies the migration's source data and then stands down.

### Module Placement

**PHP Class(es)**:
- `includes/OAuth/**` → namespace `AcrossAI_MCP_Manager\Includes\OAuth` — protocol logic, context-neutral
- `includes/Database/{OAuthClients,OAuthTokens,OAuthAuthCodes,ConnectorApprovedUsers}/**` and `includes/Database/Support/**` → namespace `AcrossAI_MCP_Manager\Includes\Database\…` — storage modules alongside the five existing ones
- `includes/Connectors/**` and the five connector profiles → namespace `AcrossAI_MCP_Manager\Includes\Connectors`
- `includes/Discovery/DiscoveryConnectorAdapter.php` → namespace `AcrossAI_MCP_Manager\Includes\Discovery`
- `admin/Partials/ServerTabs/{AIConnectorsTab,N8nTab}.php` → namespace `AcrossAI_MCP_Manager\Admin\Partials\ServerTabs` — renders admin HTML and enqueues assets
- `templates/oauth/{consent,message}.php` — operator/end-user facing templates

**Hook Registration**: All `add_action`/`add_filter` calls for this feature MUST be wired in `includes/Main.php` via `define_admin_hooks()` or `define_public_hooks()`. The companion currently registers these inside a bootstrap method of its own; those registrations are rehomed, not copied.

### Admin UI Requirements

**Pre-approved Connector picker card exception** (AI Connectors tab only, constitution v1.1.0):
- The AI Connectors tab and its nested Level 2 + Level 3 panels MAY use hand-rolled card sections, `.nav-tab-wrapper` markup, and a `widefat striped` table for the Connections panel. The migrated tab falls squarely inside this pre-ratified exception, so **no DataViews/DataForm rewrite is required**.
- Any future admin UI whose data model is a filterable/sortable row set MUST still use DataViews/DataForm.

### REST API Contract

All routes keep the existing namespace `acrossai-mcp-manager/v1` — **unchanged**, because connected clients already call it.

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| `POST` | `/oauth/register` | Public (DCR) | RFC 7591 dynamic client registration |
| `POST` | `/oauth/generate-client` | `manage_options` | Operator-created client credentials |
| `GET`/`POST` | `/oauth/connector-settings` | `manage_options` | Per-server connector configuration |
| `POST` | `/oauth/revoke-client-tokens`, `/oauth/revoke-grant`, `/oauth/revoke-connector-tokens`, `/oauth/revoke-server-tokens`, `/oauth/revoke-server-approval`, `/oauth/revoke-user-approval`, `/oauth/revoke-client-tokens-all-servers`, `/oauth/delete-client` | `manage_options` | Revocation surface |
| `POST` | `/oauth/approve-pending-consent`, `/oauth/deny-pending-consent`, `/oauth/approve-server-pending`, `/oauth/deny-server-pending` | `manage_options` | Approval workflow |
| `GET`/`POST` | `/oauth/server-settings` | `manage_options` | Per-server OAuth settings |
| ~~`POST`~~ | ~~`/servers/{server_id}/n8n/bearer/token`~~ | — | **Not served by this plugin** — withdrawn 2026-10-07 (T086). The companion serves it from this same namespace. |

**Non-REST endpoints** (served via rewrite rules, not the REST API, and equally contractual):
`/.well-known/oauth-authorization-server`, `/.well-known/oauth-protected-resource`, `/.well-known/oauth-protected-resource/{server}`, `/authorize`, `/token`.

**`permission_callback` rule**: `__return_true` is permitted ONLY on the genuinely public protocol endpoints — discovery metadata, dynamic client registration, and the token endpoint, which authenticate by their own protocol means rather than by WordPress capability. Every administrative route MUST check `manage_options`.

### Database / Storage

**Custom DB tables** (four, migrating from the companion):

| Purpose | Table |
|---|---|
| Registered OAuth clients | `{wpdb->prefix}acrossai_mcp_oauth_clients` |
| Access + refresh tokens | `{wpdb->prefix}acrossai_mcp_oauth_tokens` |
| Authorization codes | `{wpdb->prefix}acrossai_mcp_oauth_auth_codes` |
| Per-user connector approvals | `{wpdb->prefix}acrossai_mcp_connector_approved_users` |

- **Justification**: tokens require indexed lookup by digest on every authenticated request, expiry sweeps, and family-grouped revocation. Options/meta cannot serve an indexed, high-cardinality, frequently-expired row set.
- **Lifecycle**: created and upgraded through the same path as the plugin's five existing storage modules, on both activation and the routine schema-reconciliation pass — admin-side only, matching both the house pattern and BerlinDB's own convention of hooking schema work to `admin_init` alone.
- **Migration gate**: a completion option records that the copy has finished, following the established `DONE_OPTION` idiom — read as the early-bail guard, written with autoload disabled. Alongside it, a per-table cursor records progress so a run interrupted by a request timeout resumes on the next admin page load instead of restarting (FR-011). The completion flag is set only once every source table is fully drained. Ordering within the admin-side pass is load-bearing: schema reconciliation must create the destination tables before the copy runs, exactly as the existing backfills run after seeding.
- **Observed volume** (acrossai.co, 2026-10-06): 11 clients, 520 tokens, 0 auth codes, 1 approval — 532 rows total. Auth codes sit at zero because they are single-use and short-lived, and the expiry-sweep cron keeps the token table trimmed to live grants, so these tables are bounded rather than growing without limit. Batch size is a planning-phase detail; this measurement is the baseline for choosing it, not a cap to design to.

**Options / meta**: per-server connector settings, approved users and pending approvals keep their existing option key shapes (FR-015). Per-server settings and pending-user meta keys are re-attributed to this plugin's prefix (FR-013).

### Security Checklist

*(Derived from Constitution §III)*

- [ ] All form/AJAX handlers verify nonce via `wp_verify_nonce()` or `check_ajax_referer()`
- [ ] All admin page renders check `current_user_can('manage_options')`
- [ ] All REST routes have explicit `permission_callback` — public only on protocol endpoints that authenticate by other means (see REST section)
- [ ] All user input sanitized at system boundary with the most-specific function
- [ ] All output escaped at point of rendering with the most-specific function
- [ ] All DB queries use `$wpdb->prepare()` — including the migration's cross-table copy
- [ ] OAuth tokens stored hashed (SHA-256 minimum) — never plaintext; the migration preserves digests without ever handling a raw token
- [ ] Redirect-URI validation rejects unregistered URIs; loopback-port tolerance does not weaken host matching
- [ ] PKCE S256 required; a missing or downgraded challenge is rejected
- [ ] Authorization codes are single-use
- [ ] Refresh-token rotation invalidates the whole family on reuse
- [ ] Rate limiting active on token and registration endpoints
- [ ] Tokens are audience-bound — a token for one server cannot act on another
- [ ] Bearer rejection returns `401` with a correct `WWW-Authenticate` challenge

### Key Entities *(include if feature involves data)*

- **OAuth Client**: an AI application registered to connect to this site. Has an identity, a display attribution, permitted redirect targets, and a registration origin (self-registered vs operator-created).
- **Token**: a credential held by a client. Stored only as a digest. Belongs to a family (for rotation and bulk revocation), is bound to one server, has a type and an expiry.
- **Authorization Code**: a short-lived, single-use artefact exchanged for a token, bound to a challenge and a redirect target.
- **Connector Approval**: a record that a particular WordPress user approved a particular connector on a particular server; may be pending, approved, or revoked.
- **Connector Profile**: the per-client-vendor description (Claude, ChatGPT, Gemini, Grok, Cursor) of how that vendor expects to connect.

---

## Success Criteria *(mandatory)*

### Definition of Done Gates

- [ ] PHPCS validation: zero errors and zero warnings (`vendor/bin/phpcs`)
- [ ] PHPStan: zero errors at the repository's configured level (`phpstan.neon.dist` is currently `level: 5`; the template DoD cites level 8 — reconcile, do not silently lower)
- [ ] ESLint: zero errors (`npm run lint:js`)
- [ ] PHPUnit tests written and passing for all new PHP logic, including the ported companion suites
- [ ] Security checklist above: all applicable items verified
- [ ] All hooks wired in `Main.php` — none in class constructors
- [ ] Admin UI uses DataForm/DataViews, except the pre-approved AI Connectors card exception
- [ ] No code duplication — the duplicated cache-header utility reconciled to one implementation
- [ ] All functions, hooks, and classes prefixed with `acrossai_mcp_`
- [ ] `npm run validate-packages` passes
- [ ] Zero occurrences of the companion's namespace, constants, text domain, licence gate, or ownership probe remain in this plugin — except the migration's documented source references

### Measurable Outcomes

- **SC-001**: 100% of pre-upgrade OAuth grants remain usable after upgrade — zero connected clients require re-authorisation.
- **SC-002**: A site owner with no paid add-on can go from a fresh install to a working AI client connection without encountering any purchase, trial, install, or licence step.
- **SC-003**: Row counts for clients, tokens, auth codes and approvals match exactly between source and destination after migration.
- **SC-004**: Running the upgrade twice produces identical results to running it once — no duplicate rows, no repeated work.
- **SC-005**: On a site that never had the companion, upgrade completes with zero errors and zero admin notices about migration.
- **SC-006**: Zero externally observable identifiers change **for capabilities this plugin retains** — a byte-level comparison against `contracts/pre-move-surface.txt` is identical except for the one deliberate removal recorded there. **Narrowed 2026-10-07**: as originally written this was unsatisfiable, because the inventory was captured before T086 and includes `POST /servers/{server_id}/n8n/bearer/token`, which this plugin no longer serves. That endpoint moving back to the companion is a scope decision, not identifier drift.
- **SC-007**: With both plugins active, every route, tab, scheduled event and rewrite rule is registered exactly once.
- **SC-008**: All five connector vendors complete a connection end to end. **Narrowed 2026-10-07 (T086)**: the automation-token path belongs to the companion and is verified in its release, not this one.
- **SC-009**: Zero promotional copy for connector functionality remains in any screen or built asset.
- **SC-010**: The four storage tables survive upgrade on a site that has never run the legacy cleanup sweep.
- **SC-011**: A migration that has stalled is identifiable from Site Health alone, without reading logs or inspecting the database; a migration that completed or was correctly skipped produces no operator-visible output at all.
- **SC-012**: No credential digest appears in any diagnostic output — a search of the error log and Site Health output for a 64-character hexadecimal string returns nothing, including after a forced migration failure.
- **SC-013**: On a site where a competing implementation also claims the discovery paths, the operator is told so — Site Health reports a failure whenever the served discovery documents do not name this plugin's own endpoints, regardless of which implementation won the rewrite-rule race.

---

## Assumptions

- **Live customer data is assumed to exist.** The spec treats the companion's token tables as production data held by paying customers, which is what makes copy-never-move and FR-007 load-bearing. The planning document carries a pre-flight attestation to confirm or refute this before the data layer is built; if no production install exists, the migration's risk rating drops but its design does not change.
- **The companion stays installed and dormant for this release.** It is not modified or removed here. Its automatic stand-down is relied upon rather than a coordinated release.
- **Connector functionality becomes free permanently.** Once shipped under GPL this is effectively irreversible; the paid offering becomes the Abilities library and access control.
- **No new third-party dependency.** The OAuth implementation is self-contained — deliberately, since an off-the-shelf server library would add a large vendored surface, require storing private signing-key material, and raise the PHP floor.
- **The declared version floors are unchanged here.** Two separate findings — that the WordPress minimum appears to be undocumented drift, and that the plugin file declares a header WordPress does not read, leaving the WordPress minimum unenforced — are out of scope and tracked separately.
- **Multisite is out of scope** for this increment.
- **The spec template's stated PHP floor is stale.** It says "PHP 8.0+ (plugin supports 7.4 minimum)"; the shipped header declares 8.1. This spec follows the shipped header.
- **The pre-approved AI Connectors card exception applies**, so the migrated tab's hand-rolled card UI needs no DataViews rewrite.
- **The connector surface is ON by default** (decided 2026-10-06, planning phase; see FR-013). A site owner installs the plugin and can connect an AI client — the capability is not gated behind a setting they must first discover and enable. Constitution §III's consent-surface exception asks for a default-OFF operator gate; the judgement taken is that the rule is miscalibrated for a credential that is strictly self-scoped, not that this feature is non-compliant with a sound rule. Risk is bounded by design rather than configuration: tokens bind to the consenting user and carry only that user's own capabilities, unrecognised dynamic-registration clients always require admin approval regardless of any toggle, and a connector must be enabled per-server first. Operators who want it closed already have the per-server approval toggle — only its default differs. Two follow-ups are required and tracked outside this feature: amend §III condition 3 to apply only where the credential could exceed the consenting user's authority, and correct `FrontendAuth.php:14`, which claims default-OFF while `Activator.php:147` makes it ON.
- **An upgrade window is accepted** (resolved 2026-10-06, FR-010). Because migration runs admin-side only, a site updated by auto-update or WP-CLI will not migrate until someone next loads wp-admin — and WordPress does not fire activation hooks on update, so activation does not cover this. During that window the companion has already stood down and connected clients cannot authenticate. This was chosen over adding an OAuth-path trigger because it keeps a single code path consistent with the plugin's five existing one-shot migrations and with BerlinDB's own `admin_init`-only convention. Mitigation is documentation, not code: the release notes MUST tell operators to load wp-admin once after updating. If field reports show this biting real sites, revisit by adding the OAuth-path trigger as its own change.
- **The wizard's paid-gating is deleted, not repurposed** (resolved 2026-10-06, FR-016/FR-017). Both the promotional step and the add-on-setup step go, and nothing replaces them in this feature. The accepted consequence is that the product temporarily loses its main in-wizard surface for discovering the paid Abilities library; re-establishing that is a deliberate follow-up rather than something designed inside a data migration. Both options considered did identical code work — this one simply records the gap on purpose instead of losing it silently.

