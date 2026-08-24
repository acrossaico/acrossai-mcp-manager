# Decisions

## Bulk Supersession Notice — Feature 040 (2026-07-31)

**All OAuth 2.1 / DCR / connector-profile / OAuth-BerlinDB decisions below are SUPERSEDED by Feature 040** (`docs/planings-tasks/040-migrate-ai-connectors-to-companion.md`). The OAuth stack, connector-profile registry, and 4 BerlinDB OAuth-table modules moved wholesale to the `acrossai-ai-connectors` companion plugin (v0.5.0+). Every decision entry that references `AuthorizationController`, `TokenController`, `ClientRegistrationController`, `ConnectorAdminController`, `DiscoveryController`, `OAuthRouter`, `PKCE`, `TokenValidator`, `Cleanup`, `BearerChallengeHeader`, `UserLifecycle`, `AbstractConnectorProfile`, `ConnectorProfileRegistry`, `ConnectorSettings`, `AIConnectorsTab`, or the four tables (`wp_acrossai_mcp_oauth_clients`, `_tokens`, `_auth_codes`, `wp_acrossai_mcp_connector_approved_users`) is now companion territory — the entry body remains preserved as historical record per PATTERN-MEMORY-SUPERSESSION-VS-ANNOTATION, but new work on those subsystems happens in the companion repository.

Specifically superseded (non-exhaustive): D27 (client_secret_post softening), D31 (F032 OAuth server_id first-class), DEC-BERLINDB-OAUTH-*, DEC-DCR-*, DEC-CONNECTOR-PROFILE-*, DEC-F021-*, DEC-F024-*, DEC-F027-*, DEC-F029-*, DEC-F030-*, DEC-F032-*, DEC-F034-*, DEC-OAUTH-BUILTIN-TAB-NOT-FILTER, and any DEC-* entry tagged `oauth` / `dcr` / `connector-profile`.

Decisions that are NOT superseded and remain active: everything about MCP servers, MCP adapter integration, the tab framework itself (`AbstractServerTab`, `Registry`'s filter API), `mcp_servers` table, access control, abilities, tools, embeds, npm/clients tabs, and the F035 discovery API (its `get_ai_connectors()` method was modified per FR-019 but the rest of F035 is intact).

## Entry Lifecycle

Each decision follows this lifecycle:

```
Active → Needs Review → Superseded → (pruned)
```

- **Active**: The decision is current and must be honored by all features and AI agents.
- **Needs Review**: Implementation reality or new context suggests this decision may be outdated. It should still be honored until reviewed and explicitly changed.
- **Superseded**: A newer decision has replaced this one. Keep it for historical context until the next audit, then consider pruning.
- **Pruned**: During an audit, remove superseded entries that no longer provide historical value. This keeps the file focused.

### When to change status

| Current Status | Change To    | When                                                                                                       |
| -------------- | ------------ | ---------------------------------------------------------------------------------------------------------- |
| Active         | Needs Review | Verified implementation or tests contradict the decision, or recurring features follow a different pattern |
| Active         | Superseded   | A newer decision explicitly replaces this one                                                              |
| Needs Review   | Active       | Team confirms the decision still holds after review                                                        |
| Needs Review   | Superseded   | Team confirms a replacement decision                                                                       |
| Superseded     | _(remove)_   | Audit finds no remaining historical value                                                                  |

### Rules

- Never delete an Active decision without replacing or superseding it.
- Never silently ignore a decision. If it feels wrong, mark it Needs Review and resolve it.
- Keep at most 3–5 Superseded entries for context. Prune older ones during audits.

---

## Template

### YYYY-MM-DD - Decision title

**Status**
Active | Superseded | Needs review

**Why this is durable**
What cross-feature choice is likely to matter again?

**Decision**
What was decided and what boundary does it create?

**Tradeoffs**
What was gained, what was made harder, and when should this be reconsidered?

**Future mistake prevented**
What likely incorrect approach does this rule out?

**Evidence**
Diff, tests, review, incident, or repeated implementation evidence.

**Where to look next**
Files, modules, or specs future maintainers should inspect.

---

### 2026-05-29 — PLUGIN_NAME_SLUG defined as literal string

**Status**
Active

**Context**
`define_constants()` in `includes/Main.php` runs before any properties are
set in the constructor. `$this->plugin_name` is null at that call site.

**Decision**
`ACROSSAI_MCP_MANAGER_PLUGIN_NAME_SLUG` MUST be defined as the literal string
`'acrossai-mcp-manager'`, not `$this->plugin_name`.
`get_plugin_name()` continues returning `$this->plugin_name` (not the constant)
to avoid touching every caller and to prevent ordering bugs in test bootstraps
that don't load the full plugin. Both hold the same value — the return source
is an explicit design choice.

**Rationale**
`define()` silently accepts null. Using `$this->plugin_name` (null) would
define the constant as an empty string — a silent misconfiguration.

**Alternatives rejected**
- Reorder constructor so `$this->plugin_name` is set first — rejected; it
  changes the boot contract (define_constants always runs first, FR-001).
- Return the constant from `get_plugin_name()` — rejected; changes every
  caller and introduces an ordering dependency in test bootstraps.

---

### 2026-05-29 — Rewrite rules registered immediately at activation

**Status**
Active

**Context**
`FrontendAuth` and `ClaudeConnectors` handler classes do not exist at plugin
activation time (they are implemented in Phases 3 and 6 respectively).

**Decision**
Register rewrite rules with placeholder query vars at activation using literal
path strings (not class constants). Requests to these paths return a graceful
WordPress 404 until the handler classes are implemented. No deferral, no
conditional registration.

**Rationale**
Deferral approaches (deferred registration, or conditional on class_exists)
would require a separate rewrite flush later and could leave site permalinks
in an inconsistent state. Registering immediately ensures routes are always
present from first activation.

**Alternatives rejected**
- Defer registration until handler class is registered via a hook — rejected;
  adds complexity, requires an extra flush event later.
- Use class constants (`FrontendAuth::PAGE_SLUG`) — rejected; classes don't
  exist at activation time, causing a fatal.

---

### 2026-05-29 — Compat.php placed in includes/ not includes/Utilities/

**Status**
Active

**Context**
Constitution Principle I says shared logic MUST go in `includes/Utilities/`.
`Compat.php` is a shared utility. However, the boot flow loads Compat BEFORE
other Utilities/ classes are available (it provides PHP 7.4 polyfill guards
used by early-loading code).

**Decision**
`includes/Compat.php` with namespace `AcrossAI_MCP_Manager\Includes` is an
accepted exception to Principle I. This is documented as DEV2 in INDEX.md.

**Rationale**
`Compat` is a boot-time compat shim, not a feature-level utility. Placing it
in `Utilities/` would create an autoloader ordering dependency at PHP < 8.0.
The existing source (`src/Core/Compat.php`) confirms the `Core` placement
pattern.

**Alternatives rejected**
- `includes/Utilities/Compat.php` — rejected; autoloader resolves Utilities/
  after includes/ classes in some edge cases on PHP 7.4.

---

### 2026-05-29 — class_exists() guards in Activator are always silent no-op

**Status**
Active

**Note (Feature 011)**: Scope narrowed — this rule no longer applies to the four Database\{Module}\Table::instance()->maybe_upgrade() calls in Activator per FR-016 (defensive class_exists would mask a real regression after FR-011's autoload fix). Still active for other class_exists patterns.

**Context**
DB Query classes (`MCPServer\Query`, `CliAuthLog\Query`,
`ConnectorAuditLog\Query`) do not exist until Phase 4.

**Decision**
Every DB call in `Activator::activate()` MUST be guarded with
`class_exists( ClassName::class )`. If the class is absent, the call is
silently skipped — no log entry, no wp_options flag, no admin notice.

**Rationale**
Silent skip prevents activation failure on fresh installs where Phase 4 has
not yet been merged. Logging a non-fatal notice would confuse administrators.

**Alternatives rejected**
- Hard-code the check and throw on failure — rejected; breaks fresh installs.
- Use `try/catch` — rejected; `class_exists` is cleaner and has no
  performance overhead.

---

### 2026-05-29 — PHPCS Baseline Exceptions in phpcs.xml.dist [Feature-001]

**Status**
Active

**Why this is durable**
Baseline exclusions are required for all phases until structural refactoring of the boilerplate is done.

**Decision**
Five rule groups are intentionally suppressed in `phpcs.xml.dist`: filename casing (`WordPress.Files.FileName`), `$_instance` underscore prefix (`PSR2.Classes.PropertyDeclaration.Underscore`), file docblocks (`Squiz.Commenting.FileComment`), `namespace Public` reserved keyword (`PHPCompatibility.Keywords.ForbiddenNames`, `Universal.NamingConventions.NoReservedKeywordParameterNames`), and PSR12 file-header order. Also suppressed: `CommentedOutCode.Found` and `InlineComment.InvalidEndChar` for stub pattern comments.

**Tradeoffs**
Allows PHPCS to exit 0 without renaming all PascalCase files or restructuring `namespace Public`. Cannot be removed until `public/Main.php` is renamed or the namespace changed.

**Future mistake prevented**
Do not attempt to "fix" these PHPCS violations inline — they require renaming files or restructuring namespaces, both of which are out of scope during migration phases.

**Evidence**
Feature 001 clarification Q4 (2026-05-29). All 6 modified files pass PHPCS exit 0.

**Where to look next**
`phpcs.xml.dist` — the `<rule ref="WordPress-Extra">` block contains all exclusion comments.

---

### 2026-05-29 — Activator Uses use Imports for DB Class References [Feature-001]

**Status**
Active

**Why this is durable**
Any file in namespace `AcrossAI_MCP_Manager\Includes` that references sub-namespace classes must use `use` imports — see BUGS.md B1.

**Decision**
All DB class references in `Activator.php` MUST use top-of-file `use … as` aliases (e.g. `use … MCPServer\Query as MCPServerQuery`). Bare relative names inside the `Includes` namespace produce a double-`Includes` FQN silently. Inline FQN strings in `class_exists()` are forbidden.

**Tradeoffs**
Slightly more verbose at file top; eliminates silent activation failures.

**Future mistake prevented**
Never write `class_exists( Includes\SomeClass::class )` inside a file in `AcrossAI_MCP_Manager\Includes`.

**Evidence**
BUGS.md B1 pattern. Feature 001 Activator.php lines 4-6 use correct `use` imports.

**Where to look next**
`includes/Activator.php` lines 1-10; any new file added to `includes/`.

---

### 2026-05-29 — Activator Does Not Call insert_default_server() [Feature-001]

**Status**
Superseded (Feature 011)

Active

**Why this is durable**
This separates Phase 2 activation responsibility from Phase 4 data-seeding responsibility.

**Decision**
Default MCP server row insertion is a Phase 4 concern deferred to `MCPServerQuery::maybe_create_table()` internal logic. `Activator::activate()` MUST NOT call any `insert_default_server()` method — it does not exist in Phase 1 and will not be an Activator responsibility.

**Tradeoffs**
Activator is simpler; data seeding is colocated with schema creation.

**Future mistake prevented**
Do not add `insert_default_server()` or similar seeding calls to `Activator.php`.

**Evidence**
Feature 001 clarification Q1 (2026-05-29). Spec FR-009 updated to reflect this.

**Where to look next**
`includes/Activator.php::activate()`, `includes/Database/MCPServer/Query.php::maybe_create_table()` (Phase 4).

---

### 2026-05-29 — AccessControl Stub Targets wpb-access-control Vendor Package [Feature-001]

**Status**
Active

**Why this is durable**
Phase 7 implementation MUST use the vendor package, not an internal wrapper class.

**Decision**
The `AccessControl` hook stub in `define_admin_hooks()` uses `\WPBoilerplate\AccessControl\AccessControlManager` from `wpboilerplate/wpb-access-control ^1.0`. Phase 7 MUST consume this package directly via Composer.

**Tradeoffs**
Creates a Composer dependency on a vendor package. Ensures no internal class diverges from the package.

**Future mistake prevented**
Do not create `AcrossAI_MCP_Manager\Includes\AccessControl\AccessControlManager` as an internal class — use the vendor package FQN.

**Evidence**
Feature 001 clarification Q2 (2026-05-29). Main.php stub line ~294.

**Where to look next**
`includes/Main.php::define_admin_hooks()`, `composer.json` (Phase 7 — add `wpb-access-control ^1.0`).

---

### 2026-06-17 — BerlinDB-style Query Interface Hand-Rolled Without the Vendor [Feature-002]

**Status**
Superseded (Feature 011)

Active

**Why this is durable**
Future custom-table needs in this plugin (and sister plugins in the same stack) should follow this minimal pattern rather than pulling `berlindb/core` into composer.

**Decision**
When a custom DB table needs a Query-style instance interface (`query()`, `add_item()`, `update_item()`, `delete_item()`), build it hand-rolled as four PHP classes per table — `Schema` (column metadata), `Table` (dbDelta lifecycle + `maybe_create_table()`), `Row` (typed value object with `to_array()`), `Query` (singleton-style static `maybe_create_table()` + per-call instance methods). All DB I/O uses `$wpdb->prepare()` internally. The contract is the **interface**, not the BerlinDB library. Spec/plan may refer to "BerlinDB Query classes" — read this as shorthand for the four-method interface.

**Tradeoffs**
- Pro: zero new composer deps; no vendor lock-in; ~200 lines per table is manageable
- Con: must re-implement BerlinDB conveniences (caching, type coercion, query introspection) if needed later

**Future mistake prevented**
Do not add `berlindb/core` to `composer.json` just because the spec says "BerlinDB". Read the FR-022 interface clause as authoritative — the library name is shorthand.

**Evidence**
Feature 002 Q4 clarification (2026-06-17). Implementation: `includes/Database/{MCPServer,CliAuthLog}/{Schema,Table,Row,Query}.php`. Q4 entry in `specs/002-admin-ui/spec.md`.

**Where to look next**
`includes/Database/MCPServer/Query.php` for the canonical reference implementation. Future custom tables: copy that 4-file pattern.

---

### 2026-06-17 — Minimal-Port Deferral Pattern for Multi-Class Dependencies [Feature-002]

**Status**
Active

**Why this is durable**
When migrating a class whose source dependencies include other un-ported classes, a partial port with deferred work is preferable to either porting the entire dependency tree (scope creep) or stubbing the class (regression).

**Decision**
A "minimal port" ships the subset of the source class's API needed to satisfy the user-story FRs and stubs / omits the parts that depend on un-ported sibling classes. The pattern requires:
1. The deferred functionality is **explicitly documented** in the new class's docblock (which dependencies are missing, which FRs they unlock)
2. A **follow-up task ID is reserved** in tasks.md (or a follow-up phase identified)
3. The current implementation does NOT silently fail or throw when the missing functionality is invoked from the UI — either the UI excludes the call site, or the method returns a graceful response

**Tradeoffs**
- Pro: unblocks user-story delivery; surfaces the deferred work in tracker
- Con: future readers need to consult docblock to understand why the class is smaller than the source; carries risk of "minimal" becoming permanent

**Future mistake prevented**
Do not block a Phase N port on Phase N+1 deliverables. Do not stub a class as a placeholder either — partial port + explicit deferral is the third path.

**Evidence**
Feature 002 T025 (2026-06-17): `admin/Partials/ApplicationPasswords.php` ships 2 of 3 source REST endpoints, no `Includes\MCPClients\*` (7 classes) — the deferred MCPClient namespace is noted in the class docblock and tracked as RT-3 in `specs/002-admin-ui/governance-summary.md` follow-ups.

**Where to look next**
`admin/Partials/ApplicationPasswords.php` docblock for the canonical "what was deferred" note format.

---

### 2026-06-18 — Phase X.0 Absorption Pattern for Missing Prerequisites [Feature-004]

**Status**
Active

**Why this is durable**
This pattern has been used twice in two phases (Phase 2.0 absorbed the BerlinDB Query layer prereq; Phase 4.0 absorbed the PHPUnit harness prereq). Without naming the pattern, future phases will rediscover it ad-hoc — or worse, block waiting for "someone else" to ship the prereq.

**Decision**
When a Spec-Kit phase's P0 gate (T004 or equivalent) fails because a prerequisite shared-infrastructure piece (DB layer, test harness, build pipeline, etc.) doesn't yet exist, the implementing phase MUST absorb the prerequisite setup as a sub-phase called **"Phase X.0"** — not stop and wait for a separate phase to ship it.

The sub-phase:
- Is documented inline in the implementing phase's tasks.md (typically renaming T005-T007 to become the harness setup)
- Is committed in the same PR as the consuming phase's implementation
- MUST stay minimal — set up what's needed for THIS phase's work, no more
- MUST NOT bundle scope creep ("while we're setting up the test harness, let me also add a JS test runner")
- SHOULD note in the commit message that the sub-phase work UNBLOCKS sibling deferred tasks in other phases when applicable

**Examples**:
- **Phase 2.0** (2026-06-17): set up BerlinDB-style Query layer for `MCPServer` + `CliAuthLog` tables because the Admin UI in Phase 2 needed them. 8 files, ~828 lines. Unblocked Phase 2 itself.
- **Phase 4.0** (2026-06-17): set up PHPUnit harness (`phpunit.xml.dist`, `tests/bootstrap.php`, `.phpunit.cache/` gitignore entry) because the MCPClients tests required it. ~60 lines. Unblocked Phase 4 AND Phase 2's 14 previously-deferred test tasks.

**Tradeoffs**
Gained: phases ship in finite time without coordination-deadlocked dependencies; sub-phase work surfaces as scope in the commit log.
Reconsider: if the prereq is bigger than the consuming phase (e.g., setting up a full CI/CD pipeline to land one test), it's no longer a "Phase X.0" candidate — it deserves its own dedicated phase number.

**Future mistake prevented**
Don't write "TODO: shared infrastructure must exist before this phase begins" and stall the phase. Absorb it as X.0 and move forward.

**Evidence**
Feature 002 commit `cc536f7` (Phase 2.0 Query layer); Feature 004 commit `d979391` (Phase 4.0 PHPUnit harness).

**Where to look next**
`specs/002-admin-ui/spec.md` Clarifications section (Q4) for the Phase 2.0 sub-phase precedent; `specs/004-mcp-clients/governed-implementation-summary.md` for the Phase 4.0 "side benefit" framing of unblocking sibling phases.

---

### 2026-06-25 — Bulk Task-Status Updates Must Be Re-Audited for Environment Gates [Feature-005]

**Status**
Active

**Why this is durable**
At the end of Phase 5 governed-implement (2026-06-23), all 90 `tasks.md` checkboxes were marked `[x]` via a single `sed` invocation. The next governance pass (`/speckit-analyze`) flagged this as K2-HIGH: T082 (`vendor/bin/phpunit --testsuite=oauth`) wasn't actually run — it requires a WP-PHPUnit DB; T085 (`npm run validate-packages`) wasn't run; T086 (manual quickstart walk) wasn't done; T087 (flip spec DoD checkboxes) was claimed but the spec checkboxes were still `[ ]`; T088 (data-model hand-off note) wasn't written. **Five false-`[x]` claims in one shot.** Honest task status is the foundation of every downstream review (analyze, verify, security-review, refactor-generator).

**Decision (D12)**
After ANY bulk task-status mutation (sed/awk/find-replace marking ≥3 tasks `[x]` at once), the implementer MUST:
1. Re-read every newly-`[x]` task and triage into three buckets: **(a) verified now with evidence in this session**, **(b) environment-dependent / requires manual action**, **(c) documentation-only edit not yet performed**
2. Revert bucket (b) and (c) to `[ ]` with an inline deferral note explaining what's blocking (e.g. `(deferred: requires WP-PHPUnit harness — provision via bin/install-wp-tests.sh)`)
3. Run the canonical post-implementation gates in the session: PHPCS, PHPStan, the language-equivalent regression suite that DOESN'T require external setup. If any fail, revert their corresponding tasks too.

This applies to bulk updates only. Single-task updates done as work completes are exempt — those are inherently honest.

**Tradeoffs**
Gained: downstream reviews (analyze, verify, security-review) start from honest data; PR descriptions don't carry false-positive completion claims; merge gates fail loudly when they should.
Reconsider: if all DoD gates ever become hermetic (run entirely in a Docker container with the test DB baked in), the manual triage step is no longer needed.

**Future mistake prevented**
Don't claim "all 90 tasks complete" when 5 of them required environments you don't have. The next reviewer will catch it; better to catch it yourself in the same turn.

**Evidence**
- Phase 5 implementation summary (2026-06-23) marked all 90 tasks `[x]` via sed
- `/speckit-analyze` K2 finding (2026-06-24) caught T082/T085/T086/T087/T088 as false-`[x]`
- Reverted to `[ ]` with explicit deferral notes on 2026-06-24

**Where to look next**
`specs/005-oauth-connectors/tasks.md` lines 310-321 for the deferral-note format pattern.

### 2026-06-30 — Constitution-level Formalization vs. Accepted Deviation Registration [Feature-007]

**Status**
Active

**Why this is durable**
Feature-007 (FrontendAuth) needed to broaden authorization from `manage_options` to "any logged-in user" because the user consents on their own behalf to issue a credential scoped to themselves. The deviation was documented in plan §Complexity Tracking + spec §Assumptions + security-constraints.md, but the constitution itself was unchanged. The 2026-06-30 architecture review flagged this as a CRITICAL violation by strict reading. Resolution: amend Constitution §III to add a formal "Consent-surface exception" with 5 binding conditions, citing Feature-007 as the canonical instance. The same pattern applies to OAuth consent (Feature-005, retroactively) and any future device-grant flow. Future authors get a constitutional reference instead of re-deriving the spec/plan paperwork per feature.

**Decision**
When a feature-local deviation describes a GENERALIZABLE pattern (applies to ≥2 existing features OR has a forward-looking surface that the spec-kit team can name), formalize the exception in `.specify/memory/constitution.md` rather than registering it as an Accepted Deviation in `docs/memory/INDEX.md`. Accepted Deviations are for ONE-OFF carve-outs (e.g. DEV2 boot-time `Compat.php` placement, DEV3 bidirectional Phase 6 ↔ Phase 7 coupling pending T044). Constitution amendments are for reusable patterns. The constitution paragraph MUST include binding conditions (not bare permissive language) so the exception cannot collapse into a generic loophole — at minimum, conditions covering (a) precondition gate, (b) scope binding, (c) operator opt-in, (d) citation requirement, (e) data-source authoritativeness.

**Tradeoffs**
- Gained: future audits find the exception at the canonical source; class docblocks have a single citation target; cross-feature reuse is encouraged; ad-hoc per-feature exception paperwork is replaced with a constitutional reference.
- Made harder: constitution edits are higher-ceremony than INDEX.md row adds — must include binding conditions to prevent the exception from becoming a generic loophole; requires `/speckit-constitution` flow (or equivalent direct amendment) rather than a one-line INDEX.md add.
- Reconsider: if the exception's conditions ever expand to include "any logged-in user is consenting" without the 5 binding constraints, the exception has become a loophole and the constitution should be re-tightened. Also reconsider if a future feature's deviation matches an existing constitution exception but with different conditions — that's a signal to split the exception, not stretch it.

**Evidence**
- 2026-06-30 architecture-review V1 finding (CRITICAL): `docs/security-reviews/2026-06-30-007-frontend-cli-auth-plan.md` and the architecture-review report this turn
- 2026-06-30 constitution amendment: `.specify/memory/constitution.md` §III "Consent-surface exception" paragraph
- 2026-06-30 DEV3 registration (counter-example for one-off coupling acceptance): `docs/memory/INDEX.md` Accepted Deviations table
- Feature-007 sites that now cite the exception: `public/Partials/FrontendAuth.php` class docblock, `specs/007-frontend-cli-auth/spec.md` FR-007.4

**Where to look next**
`.specify/memory/constitution.md` §III for the canonical exception text and the 5 binding conditions. Compare against `docs/memory/INDEX.md` DEV1/DEV2/DEV3 to see what shape qualifies as one-off vs. generalizable.

### 2026-07-01 — Cross-Phase State Observation via Public-Static Predicate on the Owning Module [Feature-008]

**Status**
Active

**Why this is durable**
When Phase N needs to observe state owned by Phase M (a query var value, a transient's payload, an owning-module identity check), the design question "should I inspect M's internals directly or ask M to publish an interface" recurs. Two evidenced resolutions to date:

- Feature-007 / 2026-06-30 (SEC-001 fix): `FrontendAuth` needed to know the transient's authoritative `server_id` for the consent UI. Resolution: `CliController::peek_pending_server( string $auth_code ): ?string` published as public static on the OWNING module (Phase 6). `FrontendAuth` consumes via `use AcrossAI_MCP_Manager\Includes\REST\CliController;` — never touches transients directly.
- Feature-008 / 2026-07-01 (FR-020 fix): `public/Main` needed to know if the current request is on the OAuth authorize surface. Resolution: `ClaudeConnectors::is_authorize_page(): bool` published as public static on the OWNING module (Phase 5). `public/Main` consumes via `use AcrossAI_MCP_Manager\Includes\OAuth\ClaudeConnectors;` — never duplicates the `'authorize'` / `'acrossai_mcp_oauth'` magic strings.

Both cases were memory-informed decisions during the plan phase (the memory-synthesis for each explicitly steered the consumer AWAY from duplicating the check).

**Decision**
When Phase N needs to observe state owned by Phase M, Phase M publishes the observation as a public static predicate on its own class. Phase N consumes via `use` import. The predicate MUST satisfy A11's pure-stateless-service exemption (no instance state, no hook registration, no side effects, idempotent — safe to call multiple times per request). The consuming module MUST NOT duplicate the magic strings (query var names, transient key prefixes) that Phase M uses internally — always route through the published predicate. If the predicate needs to return richer information than a bool (server_id, user_id, session token), return a `?string` / `?array` shape and apply B11 defensive read on the way out.

**Tradeoffs**
- Gained: single source of truth for cross-phase state coupling. If Phase M renames its query var or restructures its transient shape, the change lives in one place. Consumers never break silently due to magic-string drift. Reviewers audit ONE predicate location rather than N call sites.
- Made harder: Phase N acquires a hard dependency on Phase M's public static API. If Phase M is deleted or reorganized, Phase N breaks at compile time — which is preferable to silent runtime drift. For features with an accepted deviation like DEV3 (bidirectional coupling deferral), this pattern IS the fix that unblocks the deferral.
- Reconsider: if the predicate needs instance state (rare — most cross-phase observations are pure reads), it's no longer A11-eligible and belongs as an instance method. But then reconsider whether cross-phase publication is the right shape at all — instance state usually implies the observation should happen inside the owning module rather than being exposed.

**Evidence**
- Feature-007 / 2026-06-30 SEC-001 fix: `includes/REST/CliController.php` — `peek_pending_server()` published; consumed by `public/Partials/FrontendAuth.php::handle_cli_auth`
- Feature-008 / 2026-07-01 FR-020 fix: `includes/OAuth/ClaudeConnectors.php` — `is_authorize_page()` published; consumed by `public/Main.php::enqueue_styles/scripts`
- Both predicates' docblocks cite each other and A11 + B11 as precedent

**Where to look next**
Read the two published predicates as a pair — the short form (`is_authorize_page`) shows the simplest shape (bool return, no defensive read); the long form (`peek_pending_server`) shows the full pattern with B11 defensive triple-check on a shape-returning payload. Both use zero side effects; both include their consumer's FR identifier in the docblock so reviewers can trace the call graph via grep.

---

### 2026-07-02 — Shared Package Bootstrap in Plugin Entry File (Accepted A1 Deviation) [Feature-010]

**Status**
Active

**Why this is durable**
When a plugin consumes a shared vendor package that OWNS a cross-plugin resource (e.g., a shared parent admin menu, a shared REST route namespace, a shared taxonomy), the vendor package's own bootstrap MUST live in the plugin's ENTRY FILE (`<slug>.php`), not routed through the plugin's Loader. Two evidenced instances across the AcrossAI codebase family:

- `acrossai-abilities-manager` Feature 038 (2026-06 / 2026-07) — `DEC-EXTERNAL-PACKAGE-HOOK-CTOR` scope extension. `\AcrossAI_Main_Menu\SettingsPage` bootstrapped from `acrossai-abilities-manager.php` on `plugins_loaded` priority 0. Established the pattern.
- `acrossai-mcp-manager` Feature 010 (2026-07-02) — FR-029 / FR-030 / FR-031. Same package (`acrossai-co/main-menu 0.0.10`, one patch higher pin), same bootstrap shape, mirrored into `acrossai-mcp-manager.php`.

The Loader is per-plugin-instance and its lifecycle is single-plugin-scoped; the shared resource must be canonically owned regardless of any single consumer's Loader lifecycle. Blindly applying A1 to the shared-package bootstrap causes coordination races where multiple consumer plugins each try to own the resource. Per D13's rule ("escalate to constitution.md when the deviation describes a generalizable pattern ≥2 features"), the pattern IS generalizable across the codebase family and warrants durable registration.

**Decision**
Accept a **scoped deviation from A1** for external-package cross-plugin-resource bootstrap. The deviation is limited to ONE `add_action` call per shared resource, in the plugin's entry file, and MUST be gated by BOTH:

  (a) `did_action('<resource>_bootstrapped')` idempotency guard (prevents duplicate construction across sibling plugins consuming the same package)
  (b) `class_exists( <package\\entrypoint>::class )` defense-in-depth guard (Constitution §V Integration Resilience — graceful degradation when the package is absent)

After successful construction, the bootstrap fires `do_action('<resource>_bootstrapped')` so subsequent sibling plugins' guards short-circuit. The deviation MUST be DOCUMENTED at the call site with a docblock referencing this D-entry AND the matching DEV row in `docs/memory/INDEX.md`.

**Tradeoffs**
- Gained: correct cross-plugin coordination for shared vendor-owned resources. Deterministic single-boot per resource regardless of plugin activation order or jetpack-autoloader version resolution. Consumer plugins can be added/removed without breaking the shared resource lifecycle.
- Made harder: newcomers must recognize this pattern isn't a general A1 escape hatch. The scoped guards (a) + (b) are load-bearing — omitting either breaks the coordination contract. Reviewers must audit that the deviation is scoped to ONE bootstrap per resource, not spread across the entry file.
- Reconsider: if a future feature attempts to apply this pattern to a NON-cross-plugin resource (e.g., an internal admin surface, a plugin-scoped REST route), that use is OUT OF SCOPE and violates A1. The A1 escape hatch is specifically for vendor packages that own cross-plugin resources — not for local shortcuts around the Loader.

**Evidence**
- `acrossai-abilities-manager/acrossai-abilities-manager.php:142–154` — reference implementation (`\AcrossAI_Main_Menu\SettingsPage` bootstrap with `did_action('acrossai_main_menu_bootstrapped')` guard)
- `acrossai-abilities-manager/acrossai-abilities-manager.php:82–96` — sibling FR-030-analog pattern (pre-activation vendor autoload guard on `activate_<plugin>` priority 1)
- `acrossai-mcp-manager/acrossai-mcp-manager.php` — Feature 010 T014a + T014b will mirror both patterns
- Feature 010 spec.md FR-029 / FR-030 / FR-031 documents the deviation contract
- Feature 010 tasks.md T012a executes the D15 + DEV4 registration

**Where to look next**
Read the reference plugin's `acrossai-abilities-manager.php` bootstrap block (lines 82–154) as the canonical shape. Note the two-guard pattern in the `add_action('plugins_loaded', ...)` closure and the priority-1 activation guard's rationale (must run BEFORE the default-priority-10 register_activation_hook callback that would fatal on missing vendor). See INDEX.md `DEV4` row for the deviation's registration + review criteria. Future consumers of the same shared package should copy this bootstrap shape verbatim, adjusting only the plugin-specific slug in error messages.

---

### DEC-BERLINDB-TABLE-REQUEST-BOOT — BerlinDB Table subclasses require request-time instantiation, not just activation-time

**Status**: Active (Feature 011)
**Scope**: Every plugin subclassing BerlinDB Core `\BerlinDB\Database\Kern\Table`
**Tags**: berlindb, boot, request-lifecycle, main-php, generalizable

**Why this is durable**
BerlinDB v3's `Query` subclass looks up its physical table name (`$wpdb->prefix . $name`) from a global DB interface that is populated by the Table subclass's `sunrise()` boot. `sunrise()` runs from the Table constructor. If no Table subclass is instantiated during a given request lifecycle, the Query base class falls back to using `$table_alias` as the FROM clause — producing `Table 'db.<alias>' doesn't exist` fatals at the first Query hit. Calling `Table::instance()` in `Activator::activate()` satisfies DDL lifecycle only; each subsequent request still needs its own `Table::instance()` call to populate the DB interface for that request's Query subclasses.

Feature 011 observed this in the wild on 2026-07-02:
- Admin `?page=acrossai_mcp_manager` → `MCPServerListTable::prepare_items` → `Query::query` → `Table 'local.mcps' doesn't exist`
- REST `rest_api_init` → `MCP\Controller::has_any_enabled_server` → `Query::query` → same fatal

Both code paths bypassed `Activator::activate()` (which only runs at plugin activation) and had no other trigger to instantiate the Table.

**Decision**
Every plugin that hosts BerlinDB Table subclasses MUST instantiate all of them at request time from `Main::load_hooks()` (or equivalent boot method that fires per request during `plugins_loaded`). The call site MUST be reachable BEFORE any admin or public hook that could invoke a Query subclass. Do NOT rely on activation-time instantiation to persist across requests.

For `acrossai-mcp-manager`: a `Main::bootstrap_database_tables()` private helper is invoked from `Main::load_hooks()` inside the `apply_filters( 'acrossai_mcp_manager_load', true )` gate, BEFORE `define_admin_hooks()` and `define_public_hooks()`. It calls `Table::instance()` on each of the four Database\<Module>\Table subclasses.

**Tradeoffs**
- Gained: correct request-time DB interface registration for every Query subclass. Zero runtime SQL fallback to `$table_alias`. Consistent boot semantics per request.
- Made harder: newcomers may add a new BerlinDB Table subclass without adding it to `bootstrap_database_tables()`, causing silent alias-as-FROM fatals on the first request the Query hits. Reviewers must audit that every BerlinDB Table subclass added to `includes/Database/<Module>/Table.php` is also wired into `bootstrap_database_tables()`.
- Reconsider: if BerlinDB Core changes to lazy-register Tables at Query-construction time, this pattern becomes redundant. Until then, the explicit request-time boot is load-bearing.

**Evidence**
- `includes/Main.php` — `Main::bootstrap_database_tables()` (Feature 011 T044)
- `includes/Main.php` — `Main::load_hooks()` invocation
- Sibling plugin `acrossai-abilities-manager/includes/Main.php:349` — `AcrossAI_Abilities_Table::instance()` call inside `define_admin_hooks()` (canonical shape; Feature 011 hoists it to `load_hooks()` for public/REST coverage)
- Live error log 2026-07-02 16:12:56 UTC (`docs/planings-tasks/011-berlindb-migration.md` Emergent Fixes section)
- Feature 011 spec.md FR-028

**Where to look next**
Read `Main::bootstrap_database_tables()` for the canonical shape. Note the call happens INSIDE the `apply_filters` gate (respects the plugin-disable filter) but BEFORE both `define_admin_hooks()` and `define_public_hooks()` (so admin, public, and REST request paths all see the registered Tables). Future BerlinDB-backed features MUST add their Table subclass to this method — audit gate at code review.

---

### DEC-BERLINDB-SUBCLASS-NO-USE-COLLISION — Do not import Kern base class when subclass name matches

**Status**: Active (Feature 011)
**Scope**: Any file that declares a subclass of `\BerlinDB\Database\Kern\{Table,Schema,Query,Row}` using the SAME class name as the parent
**Tags**: berlindb, namespace, class-collision, subclass-naming, workflow-template

**Why this is durable**
This plugin uses a subdirectory-per-module layout (`includes/Database/<Module>/{Table,Schema,Query,Row}.php`) with UNPREFIXED class names — each file declares a class literally named `Table`, `Schema`, `Query`, or `Row` in the module's namespace. If such a file adds `use BerlinDB\Database\Kern\Table;` for readability, PHP imports Kern's `Table` as the local short name `Table` — colliding with the subclass declaration in the same namespace and producing `Cannot redeclare class ... previously declared as local import` fatals. The error surfaces at `php -l`, at `class_exists()` calls, and at autoload time.

Feature 011 hit this across 14 of 16 subclass files during workflow execution (two agents happened to write the correct pattern; twelve did not). The bug was caught by post-workflow `php -l` and fixed by removing the `use` line — the class declarations already used leading-`\` FQN (`extends \BerlinDB\Database\Kern\Table`), so no import was needed.

The sibling plugin `acrossai-abilities-manager` avoids this pattern by prefixing subclass names (`AcrossAI_Abilities_Table` extends `Table`) — so the `use BerlinDB\Database\Kern\Table;` is safe there. That pattern does NOT transfer to a subdir-per-module layout where the subclass shares the parent's short name.

**Decision**
When a plugin file declares a class subclassing a BerlinDB Kern class using the SAME class name (`class Table extends \BerlinDB\Database\Kern\Table`), do NOT add a `use BerlinDB\Database\Kern\<ClassName>;` import. Two safe alternatives:

1. Drop the `use` entirely; extend via leading-`\` FQN (`extends \BerlinDB\Database\Kern\Table`). This is Feature 011's pattern in the `includes/Database/<Module>/` layout.
2. Alias the import (`use BerlinDB\Database\Kern\Table as KernTable; class Table extends KernTable`). Marginally cleaner if the parent is referenced multiple times in the file body.

Either alternative is acceptable; the collision is not. The Kern parent MUST be referenced somewhere in the file (leading-`\` FQN in `extends`, or aliased-form in `extends`) — bare `Table` in `extends` will silently resolve to the CURRENT namespace's `Table` (i.e., recursion into itself) and fail at instantiation.

**Tradeoffs**
- Gained: subclass files with parent-matching names parse cleanly under PHP 8.1+ and load without collision.
- Made harder: newcomers copying a similar-shape file from the sibling plugin (which uses prefixed subclass names) may not realize the `use` line has to go when the subclass name matches. Reviewers must audit every new BerlinDB Kern subclass file for this pattern.
- Reconsider: if this plugin migrates to a flat `includes/Database/AcrossAI_MCP_<Module>_<Class>.php` layout with prefixed class names, the sibling plugin's `use` pattern becomes safe and this decision becomes moot. That migration is out of scope for Feature 011.

**Evidence**
- Feature 011 workflow template bug: 14 of 16 subclass files initially had the collision; caught by post-workflow `php -l` (see `docs/planings-tasks/011-berlindb-migration.md` Emergent Fixes section)
- Post-fix: `find includes admin public *.php -name '*.php' | xargs php -l` returns zero errors
- Sibling plugin `acrossai-abilities-manager` uses prefixed subclass names — the `use` is safe there; the collision is specific to the subdir-per-module unprefixed-class-name layout
- Feature 011 spec.md FR-020 (caller-sweep enumeration, indirectly related as caller files still `use` these subclasses)

**Where to look next**
Read one of Feature 011's Table subclass files (e.g., `includes/Database/MCPServer/Table.php`) — note the ABSENCE of `use BerlinDB\Database\Kern\Table;` at the top and the presence of `class Table extends \BerlinDB\Database\Kern\Table` (leading-`\` FQN). Any future BerlinDB Kern subclass in this plugin's subdir-per-module layout MUST follow the same pattern.

---

### DEC-VENDOR-SETTINGS-TAB-INTEGRATION — Canonical shape for consuming acrossai-co/main-menu's shared Settings page

**Status**: Active (Feature 012)
**Scope**: Any AcrossAI plugin adding a tab to the shared `?page=acrossai-settings` page owned by the `acrossai-co/main-menu` vendor package
**Tags**: vendor-integration, settings-api, main-menu, dataform-carveout, class-exists-omission

**Why this is durable**
The vendor package `acrossai-co/main-menu` exposes ONE shared Settings page that every AcrossAI plugin adds tabs to via the `acrossai_settings_tabs` filter. The vendor's `PageRenderer::render()` emits ONE `settings_fields('acrossai-settings')` nonce + `options.php` handoff for the entire form — so every plugin's `register_setting()` call MUST target the shared `'acrossai-settings'` option group, NOT the per-tab page slug. Getting this wrong makes Save appear to work but silently discard the tab's values with no operator-visible error. Feature 012 is the first AcrossAI plugin outside `acrossai-abilities-manager` to consume this contract — codifying the shape here prevents every future consumer plugin from rediscovering the trap.

**Decision**
When adding a tab to the shared AcrossAI Settings page, follow these four rules verbatim:

1. **Filter hook**: hook `register_tab( $tabs ): array` onto the `acrossai_settings_tabs` filter. Normalize non-array input with `if ( ! is_array( $tabs ) ) { $tabs = array(); }`. Append `array( 'slug' => TAB_SLUG, 'label' => __( ..., 'text-domain' ), 'priority' => <int> )`.
2. **Per-tab page slug**: inside `register_settings()`, derive the page slug via `$page_slug = \AcrossAI_Main_Menu\SettingsPage::tab_page_slug( self::TAB_SLUG );` — this returns `'acrossai-settings-<tab>'`. Pass `$page_slug` as the 4th arg to `add_settings_section()` and the 4th arg to `add_settings_field()`.
3. **Shared option group**: pass literal `'acrossai-settings'` (NOT `$page_slug`) as the 1st arg to `register_setting()`. This is the load-bearing invariant — the vendor's `settings_fields('acrossai-settings')` call inside `PageRenderer::render()` produces a nonce that covers ONLY option keys registered under this group; keys registered under any other group would be silently discarded by `options.php`.
4. **Class member ordering**: match the sibling `acrossai-abilities-manager/admin/Partials/SettingsMenu.php` verbatim — `protected static $instance = null;` → `public static function instance(): self` → `private function __construct() {}` → `public const TAB_SLUG = '...';` (declared AFTER the singleton scaffolding, not before) → `register_tab()` → `register_settings()` → render methods. Do NOT declare the class `final`. Do NOT add a `class_exists( '\AcrossAI_Main_Menu\SettingsPage' )` guard around the `tab_page_slug()` call — the vendor package is a hard-require in composer.json (D15 / DEV4), so the class is guaranteed present at admin_init.

Also — this pattern is a first-party §IV DataForm carve-out: the shared Settings page's `PageRenderer` is a WordPress Settings API surface owned by the vendor package, not a DataForm module. Per Constitution §IV, admin surfaces prefer DataForm — this vendor page is the accepted exception because the vendor contract mandates the Settings API and the shared-page architecture makes DataForm coordination across plugins intractable.

**Tradeoffs**
- Gained: cross-plugin Save round-trip works correctly on the first attempt; every operator-facing toggle persists via ONE nonce + ONE options.php submission. Consumer plugins can add tabs without touching vendor code.
- Made harder: `register_setting( 'acrossai-settings', ..., ... )` looks WRONG to a WordPress dev unfamiliar with the vendor contract — they may "fix" it by changing the option group to the per-tab page slug, silently breaking Save. The docblock above `register_settings()` MUST explain the invariant so reviewers catch the pattern.
- Reconsider: if `acrossai-co/main-menu` is ever demoted from hard-require to optional integration, the omitted `class_exists()` guard becomes a fatal-error trap on any site that has the plugin installed without the vendor package. Re-evaluate this decision (specifically the "no guard" rule in item 4) if the composer.json dependency shape ever changes.

**Evidence**
- `admin/Partials/SettingsMenu.php` (Feature 012 T003) — canonical shape for this plugin
- `acrossai-abilities-manager/admin/Partials/SettingsMenu.php` (Feature 038 sibling) — reference shape
- `vendor/acrossai-co/main-menu/README.md` sections 133-207 — filter contract + `tab_page_slug()` helper documentation
- `vendor/acrossai-co/main-menu/src/SettingsPage.php:30-32` — `tab_page_slug()` signature returning `SETTINGS_SLUG . '-' . sanitize_key($tab_slug)`
- Feature 012 spec.md FR-002..FR-013 + CONSTRAINTS block

**Where to look next**
Any future AcrossAI plugin that adds a tab to the shared Settings page: read `admin/Partials/SettingsMenu.php` in this plugin and the sibling `acrossai-abilities-manager` first. Verify the 4 rules above are satisfied verbatim. Do NOT invent alternative shapes — the vendor contract is a fixed target, not a suggestion.

---

### DEC-UNINSTALL-OPT-IN-GATE — uninstall.php MUST preserve all data by default; destructive teardown gated on explicit opt-in

**Status**: Active (Feature 012)
**Scope**: `uninstall.php` and any future destructive teardown code in this plugin
**Tags**: uninstall, safety-invariant, wp-org-guideline-5, opt-in, behavior-change

**Why this is durable**
Pre-Feature-012 `uninstall.php` unconditionally dropped `acrossai_mcp_oauth_tokens` and `acrossai_mcp_oauth_audit` on plugin uninstall — meaning any operator who briefly uninstalled the plugin (say, to troubleshoot an activation error, or to try a different version) irreversibly lost their OAuth token store, silently invalidating every issued Claude auth token with no visible warning. WordPress.org plugin guideline #5 explicitly requires preserve-by-default on uninstall: "Uninstall procedures must not affect any user setting or plugin data by default." Feature 012 fixed the pre-Feature-012 violation by inverting the default and wiring an opt-in checkbox on the new MCP settings tab.

**Decision**
`uninstall.php` MUST short-circuit at the TOP (immediately after the `WP_UNINSTALL_PLUGIN` check) with:

```php
if ( 1 !== (int) get_option( 'acrossai_mcp_uninstall_delete_data', 0 ) ) {
    return;
}
```

Only when the operator has explicitly ticked the "Delete all data on uninstall" checkbox on the MCP settings tab (which sets the option to `1`) does destructive teardown run. Every line of destructive SQL MUST live AFTER this gate. The default value passed to `get_option()` MUST be `0` — a missing option means preserve-by-default. Do NOT invert the default to `1`; the value the operator saved is the SOLE source of truth.

Future features that need destructive teardown MUST reuse this gate (not add a second one). Adding a second gate that bypasses this one — even for a "safer" cleanup — is prohibited by this decision; consolidate any new teardown into the existing branch below the gate.

**Tradeoffs**
- Gained: satisfies WordPress.org guideline #5; matches sibling `acrossai-abilities-manager` verbatim; operators who uninstall briefly do not lose data.
- Made harder: operators who INTENDED the pre-Feature-012 behavior (uninstall = wipe OAuth tables) must now tick the checkbox first. Documented in README.txt Unreleased changelog as BEHAVIOR CHANGE.
- Reconsider: never. Preserving user data on uninstall is a WordPress.org contract, not a policy choice — even if a future feature justifies a "helpful cleanup," it belongs BELOW the gate, not around it.

**Evidence**
- `uninstall.php` (Feature 012 T009) — canonical shape with gate at top
- Feature 012 spec.md FR-019..FR-023 + spec CONSTRAINTS block
- Feature 012 security review (`docs/security-reviews/2026-07-03-012-mcp-settings-tab-plan.md`) SEC-012-001
- Sibling `acrossai-abilities-manager/uninstall.php` — identical pattern (predates this decision but validates it)

**Where to look next**
Read `uninstall.php` and count how many `if`, `return`, or `wp_die` statements precede the first `$wpdb->query()` call. If there is exactly ONE gate (the `acrossai_mcp_uninstall_delete_data` check) and it lives BEFORE `global $wpdb;`, the invariant is intact. If someone later adds another gate above the `WP_UNINSTALL_PLUGIN` check that bypasses the delete-data gate, that is a regression.

---

### DEC-ADMIN-SURFACE-PRUNE-CLI-AUTH-LOG — Standalone admin submenus for read-only DB-inspection views SHOULD be pruned when a lighter inspection path exists

**Status**: Active (Feature 012)
**Scope**: Plugin admin surface (submenu inventory under the shared `acrossai` parent menu)
**Tags**: admin-surface, pruning, a9-subtractive-precedent, dev1-scope-narrowing, list-table

**Why this is durable**
Feature 011 codified A9 (canonical whitelist additive-only) after multiple regressions where subtractive edits to `AdminPageSlugs::plugin_screen_ids()` broke asset enqueuing on legacy screens. Feature 012 removes the CLI Auth Log admin submenu (a pure WP_List_Table view over `wp_acrossai_mcp_cli_auth_logs`) — the FIRST justified subtractive edit under A9. Without this precedent, every future subtractive edit would either be blocked or would land without the accompanying submenu removal, corrupting the "screen IDs correspond to registered submenus" invariant A9 protects.

**Decision**
Two related rules, applied together:

1. **Prune rule (surface-side)**: standalone admin submenus that render a read-only WP_List_Table view SHOULD be removed when the same inspection is available via a lighter path (WP-CLI, per-server tab on an existing page, dashboard widget). The underlying DB layer + Query/Row classes MUST be preserved when runtime consumers (e.g., OAuth flow) still depend on them.
2. **A9 subtractive precedent (whitelist-side)**: subtractive edits to `AdminPageSlugs::plugin_screen_ids()` are allowed ONLY when the corresponding submenu page is removed in the SAME feature. The removed screen-ID entries and the removed `add_submenu_page()` call MUST land in the same commit (or same feature branch) so the "screen IDs correspond to registered submenus" invariant is never violated at HEAD.

This also narrows the scope of DEV1 (WP_List_Table exception to §IV DataForm mandate): the exception PERMITS WP_List_Table for legitimate read-only inspection UI on a shared page or dashboard widget; it does NOT permit adding a dedicated top-level submenu for pure read-only inspection when a lighter path is available.

**Tradeoffs**
- Gained: shrinks the plugin admin surface footprint; matches the "one submenu per interactive/mutating capability" heuristic; codifies the first A9 subtractive precedent so future prunes can proceed cleanly.
- Made harder: operators who liked the standalone CLI Auth Log page must now use WP-CLI (`wp db query "SELECT ... FROM wp_acrossai_mcp_cli_auth_logs"`) or wait for a future per-server tab. Documented in README.txt Unreleased changelog. No REST or CLI command surface is deleted — only the admin submenu.
- Reconsider: if a future feature adds interactive/mutating capability to the CLI Auth Log (bulk-delete stale rows, mark-as-completed manual override, resend approval email), THAT is a legitimate reason to re-add a submenu. The prune is not permanent — it applies only while the view is purely read-only.

**Evidence**
- `admin/Partials/Menu.php` (Feature 012 T014) — position-3 `add_submenu_page` removed
- `admin/Partials/CliAuthLogListTable.php` — deleted (Feature 012 T013)
- `includes/Utilities/AdminPageSlugs.php` (Feature 012 T015) — CLI_AUTH_LOG const + 2 whitelist entries removed
- `admin/Partials/Settings.php` (Feature 012 T016) — `render_cli_auth_log_page()` removed
- Preserved: every file under `includes/Database/CliAuthLog/**` (Table, Schema, Query, Row, Recorder) — OAuth flow consumes these at runtime
- Feature 012 spec.md FR-024..FR-028 + spec User Story 4
- Feature 012 security review SEC-012-006

**Where to look next**
Before removing any admin submenu in a future feature: (1) verify the target view is purely read-only (no forms, no actions, no toggles); (2) verify a lighter inspection path exists (WP-CLI query, existing tab, dashboard widget); (3) verify the DB layer + Query/Row classes have runtime consumers OUTSIDE the admin submenu — if yes, preserve them; if no, the DB layer can be removed too. Land the submenu removal + `plugin_screen_ids()` entry removal + render-method removal in the SAME commit to preserve the A9 invariant at HEAD.

---

### DEC-SERVER-TAB-CLASS-HIERARCHY — Template-method + Registry pattern for multi-tab admin surfaces

**Status**: Active (Feature 013)
**Scope**: Any admin surface with 3+ tabs on a per-record edit page.
**Tags**: template-method, registry, singleton, admin-partials, dry

**Why this is durable**
F013 refactored the per-server-edit page from a monolithic `render_*_tab` switch (~1,200 LOC target) into a per-tab class hierarchy under `admin/Partials/ServerTabs/`. The pattern proved at 11 concrete tab classes + Registry singleton + AbstractServerTab base with shared helpers. Any future multi-tab admin surface should default to this pattern rather than switch statements.

**Decision**
Multi-tab admin surfaces MUST follow this shape:
1. **Base class** with `slug()`, `label()`, `render_body()` abstract methods + `visible_for()` default + `final render()` template method + shared HTML helpers (`open_form`, `nonce_field`, etc.).
2. **Registry singleton** — F012 SettingsMenu member ordering. Public methods: `all_tabs()`, `visible_tabs()`, `render()`.
3. **Concrete tabs**: `final class`, single-responsibility, `visible_for()` opt-in override.
4. **Dispatch**: enclosing screen calls `Registry::instance()->render( $slug, $context )` after nav emit.

**Tradeoffs**
- Gained: DRY enforcement, isolated unit tests, trivial to add tabs.
- Made harder: legacy slug back-compat is on the caller (see F013 Settings.php `$legacy_slug_map`).
- Reconsider: never for admin tabs.

**Evidence**
`admin/Partials/ServerTabs/AbstractServerTab.php` + `Registry.php` + 11 concrete tab classes. F012 `SettingsMenu.php` is the singleton precedent.

**Where to look next**
Any future feature adding 3+ tabs to a per-record admin page: read `AbstractServerTab.php` + `Registry.php` first; do NOT reinvent a switch statement.

---

### DEC-CLIENT-RENDERER-PUBLIC-API — Public Renderer layer for cross-context (admin + third-party) reuse

**Status**: Active (Feature 013; annotated F016 2026-07-07) — API is `@experimental` until 1.0.0.
**Scope**: MCP client-config UI rendered from admin AND external contexts (BuddyBoss, WooCommerce, other AcrossAI plugins).
**Tags**: public-api, renderer, cross-context, experimental, shortcode, security-critical

**Post-F016 (2026-07-07)**: Renderer count shrinks from 3 to 2. Retired: `ClaudeConnectorBlock`. Surviving: `NpmClientBlock`, `MCPClientsBlock`. Dispatch map (in `ClientRendererController::dispatch_render_action`) shrinks from 3 entries to 2 (`npm`, `clients`). Shortcodes shrink from 3 to 2 (`acrossai_mcp_npm_block`, `acrossai_mcp_clients_block`). Base class (`AbstractClientRenderer`), REST endpoint (`POST /generate-app-password`), and all 4 sanctioned entry points (static call, action hook, context filter, shortcodes) remain intact — the surface merely reduces. The `@experimental` allowance until 1.0.0 covers this reduction; third-party consumers that hardcoded `'claude-connector'` see silent no-op per the dispatcher's unknown-slug guard.

**Why this is durable**
F013 introduces `public/Renderers/` — a new plugin subsystem exposing client-configuration Blocks to third-party plugins with **zero code duplication** vs. admin rendering. The Renderer layer is the ONLY sanctioned integration surface — third parties never reach into `admin/Partials/`. Canonical pattern for future MCP-adjacent third-party integrations.

**Decision**
Any admin surface displaying MCP client config (JSON blocks, "Generate App Password" button, config file path) MUST render via `public/Renderers/` using ONE of four entry points:
1. **Static method**: `<Block>::instance()->render( $server_id, $context )`.
2. **Action hook**: `do_action( 'acrossai_mcp_render_client_block', $slug, $server_id, $context )` — unknown slugs silently no-op.
3. **Context filter**: `apply_filters( 'acrossai_mcp_client_block_context', $context, $slug, $server_id )` — ONLY sanctioned defaults customization point; `(array)`-cast at boundary (SEC-013-003).
4. **Shortcodes**: `[acrossai_mcp_npm_block server="X"]` + 2 others.

Plus `apply_filters( 'acrossai_mcp_client_classes', $default_fqns )` for MCPClientsBlock sub-nav extension; invalid FQNs silently skipped via `class_exists() + is_subclass_of()` (SEC-013-008).

**Security invariants** (bound permanently):
- **Cap check via context.cap** — never hardcoded `manage_options`. BuddyBoss passes `cap='read'`.
- **App Password locked to `get_current_user_id()`** — enforced at UI (button disabled) AND REST (403). Defense against admin-impersonation.
- **Cross-context nonce binding** — action format: `'acrossai_mcp_render_' . $client_slug . '_' . $server_id . '_' . $context_slug`. Cross-context replay returns 403.
- **F012 toggle enforcement inside Renderer** — NpmClientBlock + ClaudeConnectorBlock check their gate options inside `render_body()`, not caller. MCPClientsBlock NOT gated.

**§IV DataForm carve-out** — Renderer displays JSON + emits WordPress-core App Password generation. NOT a data-entry form. Same precedent as F012 DEC-VENDOR-SETTINGS-TAB-INTEGRATION.

**Stability**: `@since 0.0.6 @experimental May change without notice before 1.0.0`. Promotion to semver at future 1.0 tag.

**Tradeoffs**
- Gained: third parties embed MCP UI in ONE line; consistent security across contexts.
- Made harder: 0.x signature changes are breaking for integrators (mitigated by @experimental disclosure).
- Reconsider: at 1.0 tag, promote to semver-stable with deprecation cycle.

**Evidence**
`public/Renderers/{AbstractClientRenderer,NpmClientBlock,MCPClientsBlock,ClaudeConnectorBlock}.php`; `includes/REST/ClientRendererController.php`; `docs/integrations/{buddyboss,woocommerce}-example.md`; `tests/phpunit/Public/Renderers/{AbstractClientRendererTest,PublicApiTest}.php`.

**Where to look next**
Third-party integrators: `docs/integrations/*-example.md`. Future MCP-adjacent features: use this Renderer pattern — do NOT create parallel `admin/Partials/`-only surfaces.

---

### 2026-07-03 — D16 — Template-method helpers pre-plan an optional override for the value most likely to vary

**Status**
Active

**Why this is durable**
Directly prevents recurrence of a real F013 hole: `AbstractServerTab::nonce_field()`
hard-coded the `'acrossai_mcp_manager_server_' . $id` action name. UpdateServerTab
and DangerZoneTab both need distinct actions (`acrossai_mcp_update_<id>`,
`acrossai_mcp_delete_<id>`) — so they bypassed the helper and emitted raw
`wp_nonce_field()` calls, tripping the FR-026 grep gate. The architecture review
caught it post-implementation; the fix was to add an optional
`$custom_action_override` param. Had this been designed in from T004, the two
tabs would have used the helper from day one and the grep gate would have stayed
green throughout the port.

**Decision**
When designing a shared/template-method helper (open_form, nonce_field, config
block, etc.), ask upfront: "what value in this helper is most likely to vary
across ~10% of consumers?" — expose that as an optional-null / empty-string
parameter with a sensible default. If two values are candidates (e.g. nonce
action AND form target URL), expose both. Do not wait for the first override
request; the helper's DRY guarantee depends on the escape hatch existing before
consumers reach for the raw call.

**Tradeoffs**
- Gained: helper stays authoritative even for edge-case consumers; grep gates
  banning raw call sites remain green; consumers don't need to bypass DRY.
- Made harder: helper signatures grow marginally; adds one extra param to
  document. Trivial vs. the audit cost of a bypassed helper.
- Reconsider: when the number of override params exceeds ~3, the helper is
  probably wrong-shaped; split into two helpers or convert to a config-object
  parameter.

**How to apply**
- New helper: enumerate hard-coded values → expose each as optional with sensible
  default → test the default path AND the override path in the same PHPUnit case.
- Existing helper flagged for raw-call bypass: add the override param, refactor
  the bypassing consumer, re-run the grep gate — do NOT add doc carve-outs to
  the grep gate to grandfather the bypass.

**Evidence**
`admin/Partials/ServerTabs/AbstractServerTab.php::open_form()` +
`::nonce_field()` (F013 architecture review R1); `UpdateServerTab.php` +
`DangerZoneTab.php` (refactored consumers); spec.md FR-026 (the grep gate that
surfaced the hole).

**Where to look next**
Any future feature adding a shared HTML helper: apply the "vary-first" checklist
in the Decision block above. See DEC-SERVER-TAB-CLASS-HIERARCHY for the
containing template-method pattern.

---

### 2026-07-03 — D17 — A1 hook-registration by transitivity: init-time bootstrap methods satisfy A1 at the outer Loader boundary

**Status**
Active

**Why this is durable**
F013's `ClientRendererController::register_shortcodes_and_actions()` calls
`add_shortcode()` × 3 + `add_action('acrossai_mcp_render_client_block', ...)`
directly inside the method — not via the Loader. A strict reading of A1 ("all
hook registration lives exclusively in `includes/Main.php`") would flag this as
a violation. But the METHOD is Loader-wired at Main.php via
`$this->loader->add_action('init', $client_renderer_rest, 'register_shortcodes_and_actions')`.
The outer entry point IS Main.php-owned; the inner calls are a bootstrap detail.
Forcing every shortcode + inner action registration through the Loader would
require closures (which Loader can't wire without wrapper classes) or one class
per shortcode — explosion of surface area for zero security benefit. Codifying
the transitivity rule prevents recurring false-positive A1 violations in future
architecture reviews.

**Decision**
A1 is satisfied when the OUTER entry point that eventually causes hook
registration is Main.php-Loader-wired. Inner `add_shortcode()` / `add_action()`
/ `add_filter()` calls made from within a Loader-wired method are permitted —
they inherit A1 conformance by transitivity. This applies to init-time bootstrap
methods (typically wired on `init`, `admin_init`, or `rest_api_init`) that
register a bounded set of related hooks as a unit.

**Tradeoffs**
- Gained: Practical middle ground — code organization stays clean (related
  shortcodes grouped in one method) without exploding the Main.php surface. No
  fake wrapper classes just to satisfy Loader signature requirements.
- Made harder: Grep gates for `add_shortcode(` / `add_action(` / `add_filter(`
  outside Main.php now need to whitelist Loader-wired bootstrap methods.
  Enforcement shifts to code review + naming convention (`register_*_hooks()`
  suffix signals intent).
- Reconsider: If a bootstrap method grows beyond ~10 hook registrations, it's
  probably wrong-shaped — split into per-concern bootstrap methods or promote to
  its own Main.php-wired sub-controller.

**How to apply**
- Reviewer flags `add_shortcode()` / `add_action()` inside a non-Main.php method:
  trace up. If the containing method is Loader-wired on an appropriate WP
  action, mark A1 satisfied.
- New bootstrap method: name it `register_*_hooks()` or
  `register_*_and_actions()` so intent is legible.
- Do NOT rewrite existing Loader-wired bootstrap methods just for style.

**Evidence**
`includes/REST/ClientRendererController.php::register_shortcodes_and_actions()`
(F013 shipping example); `includes/Main.php:499`
(`$this->loader->add_action('init', $client_renderer_rest, 'register_shortcodes_and_actions')`
outer wiring); A1 in `docs/memory/ARCHITECTURE.md` + memory-synthesis (the base
rule this clarifies).

**Where to look next**
DEC-CLIENT-RENDERER-PUBLIC-API — the public API surface these hooks implement.
A1 in memory-synthesis — the base constraint this decision refines.

---

### 2026-07-04 — DEC-ACCESS-CONTROL-V2-ADOPTION — canonical v2 wrapper pattern for AcrossAI-family plugin consumption

**Status**
Active (F015 — supersedes D8's `^1.0` version pin in-place; amends A8 version reference)

**Why this durable**
Feature 015 discovered that the plugin shipped `wpb-access-control ^2.0.0` in
composer.json but every consumer (AccessControlTab.php:65, CliController.php:333,
Main.php:432 comment) targeted v1's `::instance()` singleton API — which does
not exist in v2. Three fatal call sites. The sibling plugin
`acrossai-abilities-manager` had already solved this with the
`AcrossAI_Abilities_Access_Control` wrapper class shape. F015 copy-adapted that
pattern verbatim. Codifying the pattern here prevents future features (or a
third sibling plugin adopting v2) from re-inventing the wrapper.

**Decision**
Any AcrossAI-family plugin consuming `wpboilerplate/wpb-access-control ^2.0.0`
MUST wrap the v2 `AccessControlManager` in a plugin-scoped singleton wrapper
class with:
- `PROVIDERS_FILTER` class constant (plugin-specific hook tag)
- `TABLE_SLUG` class constant (drives DB table name, cache group, REST route
  prefix — MUST match `^[a-z0-9_]{1,32}$` per v2 `Slug::PATTERN`)
- `is_available()` guard (fail-open when package class absent — matches sibling
  DEC-PERM-CB)
- `boot_manager()` lazy-init with `new AccessControlManager( PROVIDERS_FILTER,
  TABLE_SLUG )` — NEVER v1's `::instance()`
- `get_manager(): ?AccessControlManager` accessor
- `register_rest_api()` REST route registration delegate
- `maybe_show_library_notice()` admin notice on package absence
- Activator MUST call `(new RuleTable(TABLE_SLUG))->maybe_upgrade()` at plugin
  activation, gated by `class_exists()` defense-in-depth (SEC-015-001)
- `uninstall.php` MUST purge the namespace + drop the table + delete the version
  option — but ONLY when the plugin-specific opt-in gate fires (preserve-by-default
  per DEC-UNINSTALL-OPT-IN-GATE)
- The 3 built-in providers (`WpRoleProvider`, `WpUserProvider`, `WpCapabilityProvider`)
  MUST be registered via `add_filter( PROVIDERS_FILTER, ..., 'register_default_providers' )`
- The AccessControlBlock UI uses a single-provider `<select>` picker
  (`everyone` / `wp_role` / `wp_user` / `wp_capability`) with a conditional row
  showing the values for the chosen provider — matches sibling plugin's User
  Access UX (Clarifications Q4). Rule storage: one `set_rule($ns, $key,
  $provider_type, $values_array)` call per save, or `clear_rule($ns, $key)` when
  picker = everyone. Capability picker exposes the FULL WP capability set via
  `get_available_capabilities()` (SAFE_CAPABILITIES allow-list from initial
  F015 draft is SUPERSEDED per Q4 — admins bypass every rule per v2
  access-hierarchy step 2, so exposing high-privilege capabilities is not a
  privilege-escalation vector).

**Tradeoffs**
- Gained: consistent v2 consumption pattern across the AcrossAI plugin family;
  the wrapper contains the fail-open decision + observability contract at ONE
  boundary; the version pin can advance without touching consumers.
- Made harder: 158+ LOC of "just wrapper" code per plugin. Acceptable because
  the alternative is scattered `class_exists` guards + inline v2 API calls
  across 3+ files per plugin.
- Reconsider: if the vendor package releases a 3.x with a breaking constructor
  change, amend this DEC (not the consumers).

**Evidence**
`includes/AccessControl/AcrossAI_MCP_Access_Control.php` (F015 shipping class);
sibling plugin `acrossai-abilities-manager` at
`includes/Modules/Abilities/AcrossAI_Abilities_Access_Control.php` (same shape).

**Where to look next**
D18 (mcp_adapter_pre_tool_call filter) — the MCP-boundary enforcement hook the
wrapper's `gate_mcp_tool_call()` implements. D19 (fail-open observability
pattern) — the general pattern this wrapper's action hooks realize.
DEC-UNINSTALL-OPT-IN-GATE — the opt-in gate the uninstall block MUST honor.

**Amendment 2026-07-04 (post-implementation drift audit)**
Two decisions were made after the initial DEC entry was captured:

1. **AccessControlBlock defers to vendor's React component** (not a hand-rolled
   PHP form). The initial F015 draft had `render_body()` emit `<form
   method="post">` + three provider pickers + `submit_button()`. During
   implementation, the user directed adoption of the vendor's React
   `<AccessControl>` component (shipped at `vendor/wpboilerplate/wpb-access-control/js/AccessControl.js`)
   via a `webpack.config.js` alias `'@wpb/access-control' → …/js/AccessControl.js`
   and a new entry `src/js/access-control.js` that mounts it. `render_body()`
   now emits only `<div id="acrossai-mcp-ac-root" data-server-slug="…">Loading…</div>`.
   Persistence: vendor REST (`PUT/DELETE /wpb-ac/v1/mcp/rules/{ns}/{key}`) —
   the previously-scoped `save_access_control` action + `handle_access_control_update()`
   handler in `admin/Partials/Settings.php` are dead code, marked for removal
   in T030 (see tasks.md Phase 10).

2. **TABLE_SLUG value: `'mcp'`, not `'mcp_manager'`**. During implementation
   the user requested the DB table be named `wp_mcp_access_control` (not
   `wp_mcp_manager_access_control`) — TABLE_SLUG constant value is `'mcp'`.
   Consequences: table `{prefix}mcp_access_control`, cache group `wpb_ac_mcp`,
   version option `wpb_ac_mcp_db_version`, vendor REST namespace
   `/wpb-ac/v1/mcp/…`. The rule per this DEC — that TABLE_SLUG matches
   `^[a-z0-9_]{1,32}$` — is preserved; the specific value changed.

Both amendments are compatible with the underlying DEC (v2 wrapper pattern,
fail-open, opt-in uninstall). The vendor React adoption is a §IV DataForm
carve-out variant of the same DEC-CLIENT-RENDERER-PUBLIC-API precedent that
authorized the initial hand-rolled form — the block is still a Renderer,
just deferring rendering to a vendor-shipped component instead of emitting
PHP form HTML directly.

---

### 2026-07-04 — D18 — `mcp_adapter_pre_tool_call` is the canonical MCP-boundary enforcement hook for the AcrossAI plugin family

**Status**
Active (F015)

**Why this durable**
When Feature 015 needed to gate MCP tool invocations by `(user_id, server_id)`,
the alternatives were (a) fork the mcp-adapter package to add a new hook,
(b) hook ability-level `permission_callback` on every ability, or (c) hook
`rest_pre_dispatch` broadly. Options (a) and (b) don't compose cleanly; option
(c) is too broad. Exploration surfaced that mcp-adapter ships this exact filter
at `vendor/wordpress/mcp-adapter/includes/Handlers/Tools/ToolsHandler.php:182`
— returning `WP_Error` short-circuits with a denied MCP response. Codifying
this as the canonical hook prevents future features from re-inventing the
enforcement path.

**Decision**
Any AcrossAI-family plugin wanting to gate MCP tool invocations based on
`(user_id, server_id)` MUST hook the `mcp_adapter_pre_tool_call` filter fired
by `vendor/wordpress/mcp-adapter/includes/Handlers/Tools/ToolsHandler.php:182`.
Signature: `apply_filters( 'mcp_adapter_pre_tool_call', $args, $tool_name,
$mcp_tool, $server )`. Return `WP_Error` with `array('status' => 403)` to
short-circuit execution with a denied MCP response. Do NOT fork mcp-adapter
to add a new hook. Do NOT hook ability-level `permission_callback` (ability-scoped,
doesn't compose across cross-cutting concerns). Loader-wire via
`Main::define_public_hooks()` per A1.

**Tradeoffs**
- Gained: single-line filter registration; no vendor fork; §V Extensibility
  Without Core Modification preserved.
- Made harder: consumer must handle the `$server` argument's late-binding —
  `$server->get_server_id()` returns the mcp-adapter's server-id string, which
  IS the F011 `server_slug` in our plugin (by convention).
- Reconsider: if mcp-adapter deprecates the filter in a future release.

**Evidence**
`includes/AccessControl/AcrossAI_MCP_Access_Control.php::gate_mcp_tool_call()`
(F015 shipping callback); `includes/Main.php::define_public_hooks()` (Loader
wiring); `vendor/wordpress/mcp-adapter/includes/Handlers/Tools/ToolsHandler.php:182`
(vendor filter site).

**Where to look next**
DEC-ACCESS-CONTROL-V2-ADOPTION — the wrapper class this filter callback lives on.
D19 — the observability pattern the deny branch realizes.

---

### 2026-07-04 — D19 — Fail-open observability pattern for security-adjacent enforcement

**Status**
Active (F015)

**Why this durable**
Feature 015's Clarifications Q2 (missing-server race) + Q3 (denial observability)
both surfaced the same shape: on defensive fail-open paths, fire a scoped
`do_action()` so operators can log the anomaly via any observability tool
(Query Monitor, custom logger, remote SIEM) without a hard dependency. The
vendor package's low-level `wpb_access_control_denied` hook fires at a
namespace-agnostic scope; F015's plugin-scoped hooks add the MCP-specific
payload (`server_slug` + `tool_name` + call-site context) the vendor hook lacks.
Codifying the pattern generalizes to any future security-adjacent enforcement
in the AcrossAI plugin family.

**Decision**
On defensive fail-open paths (missing server, unavailable vendor, invalid
provider, unknown auth context), fire a scoped `do_action()` so operators can
log the anomaly without a hard dependency. Fire-and-forget; return value
ignored. Payload MUST include enough context for operators to correlate the
event with other logs (user_id, resource_id, action_name, call-site slug).
Layered pattern: plugin-scoped hooks sit ALONGSIDE (not replacing) any
vendor-provided lower-level hooks — the plugin scope adds domain-specific
payload the vendor scope lacks.

**Tradeoffs**
- Gained: operator visibility into security-adjacent anomalies without adding
  a persistent audit table; no hard dependency on any specific logging plugin;
  zero cost when no listener is registered.
- Made harder: silent-by-default — operators upgrading without adding a
  listener won't know denials are happening. Mitigated by documenting the
  hooks in README.txt with a minimal listener snippet.
- Reconsider: if a specific compliance requirement (SOC 2, HIPAA, PCI) demands
  persistent DB audit — capture a new DEC for the persistent-audit pattern.

**Evidence**
F015's `acrossai_mcp_access_control_missing_server` (fires on race with
concurrent DELETE) + `acrossai_mcp_access_control_denied` (fires BEFORE
WP_Error/empty-list return at both enforcement sites). Documented in
`includes/AccessControl/AcrossAI_MCP_Access_Control.php::gate_mcp_tool_call()`
+ `includes/REST/CliController.php:333` (F015 shipping).

**Where to look next**
DEC-ACCESS-CONTROL-V2-ADOPTION — the wrapper that carries the missing-server
hook. D18 — the MCP-boundary filter the denial hook fires alongside.

---

### 2026-07-04 — D20 — Verify vendor's default set before wiring a consumer-side default-registration filter

**Status**
Active (F015)

**Why this is durable**
Consumer plugins that hook a vendor's "register default providers/handlers/tools/etc." filter can silently double-register when the vendor's own default set grows across releases. This is a recurring risk for any AcrossAI plugin that consumes a shared vendor package (wpb-access-control, mcp-adapter, main-menu, mcp-servers-list). The trap is that the consumer-side wiring is a one-line `add_filter` that reads as harmless boilerplate — until a vendor bump doubles the registered set.

**Decision**
BEFORE wiring a consumer-side default-provider (or default-handler, default-tool, etc.) registration filter for ANY vendor package, `grep` the vendor's source for its own default set. If the vendor already covers everything you'd register, SKIP the wire and document inline. Reserve the consumer filter for genuine ADDITIONS (third-party extension points), not duplicates of the vendor's defaults. When the vendor's default set grows in a future release, the omission stays correct — no maintenance drift.

**Tradeoffs**
- Gained: no double-registration; no maintenance drift when vendor defaults grow; consumer surface stays minimal; predictable provider set even after vendor upgrades.
- Made harder: readers unfamiliar with the vendor's defaults must read the inline comment to understand the missing wire. Mitigate by keeping the comment concise + linking to the vendor source file where defaults are registered.
- Reconsider: if a vendor releases with a broken default set (bug in its own `load_providers()`), the consumer may need to temporarily wire the fallback. Guard with `class_exists()` or version check if that ever happens.

**Evidence**
F015: spec (FR-014) called for wiring 5 hooks including `add_filter( PROVIDERS_FILTER, [ ClassName, 'register_default_providers' ] )` to register 3 built-in providers (WpRoleProvider, WpUserProvider, WpCapabilityProvider). But `wpb-access-control ^2.0.0` internally registers 5 default providers (WpRole + WpUser + WpCapability + BuddyBossProfileType + MemberPressMembership) via `AccessControlManager::load_providers()`. Shipping code intentionally omits the consumer wire, documented inline at `includes/Main.php:372-376`:

```php
// NB: `register_default_providers` filter is intentionally NOT wired —
// the vendor's AccessControlManager::load_providers() already registers
// WpRoleProvider + WpUserProvider + WpCapabilityProvider + BuddyBoss +
// MemberPress as defaults. Third-party plugins can still hook the
// `acrossai_mcp_access_control_providers` filter to append their own.
```

**Where to look next**
DEC-ACCESS-CONTROL-V2-ADOPTION — the vendor consumption pattern this DEC refines. Anytime a future feature adds a `add_filter( '<vendor>_default_<thing>', … )` wire, cross-reference D20 in the plan's Constitution Check. Applies to any Composer-installed vendor package that manages its own "defaults" collection via a filter — verify the vendor's own registration site before wiring.

---

### 2026-07-07 - D21 — Fresh-install-only retirement pattern

**Status**
Active (F016)

**Decision**
Retirement/teardown features MUST NOT default to shipping in-plugin schema-migration code. The default posture is:

1. **Delete** the retired classes, tests, tables, columns, hooks, options, cron events from the codebase.
2. **DO NOT** add idempotent `DROP TABLE IF EXISTS` or `delete_option()` cleanup to `Activator::activate()`.
3. **DO NOT** bump the retiring module's BerlinDB `$version` on schema-shape changes when the operator handles the physical drop manually.
4. **Publish** the manual retirement recipe in `README.txt` §Unreleased with concrete SQL + `wp cron event unschedule` steps + operator advisory (revoke-first for tokens, behavior-change notes for retired auth surfaces).
5. **KEEP** defunct table names in `uninstall.php` DROP list AS AN IDEMPOTENT SAFETY NET, AFTER the `DEC-UNINSTALL-OPT-IN-GATE` short-circuit. `DROP TABLE IF EXISTS` costs nothing when the operator has already dropped the tables manually; catches installs that skip the reactivation path.

**Escape hatch — when to REJECT this pattern**
If the plugin ships to sites with live retired-feature data AND you cannot get operator attestation (public wp.org release with unknown install base, plugin family with enterprise consumers, etc.), self-healing migration IS required. Precedent for the "self-healing" alternative: F011 (2026-07-02) shipped the phantom-version guard because the migration WAS a runtime operation, not an operator recipe.

**Rationale**
- **Smaller diff** — F016 removed ~80 LOC of migration helpers relative to the initial plan (no `ConnectorColumnMigration.php`, no idempotent DROP in Activator, no `column_exists`-gated ALTER fallback).
- **Zero test surface for migration edge cases** — no need for parametrized "does BerlinDB drop columns on version bump?" tests.
- **Eliminates Activator destructive-SQL risk** — a maintainer misreading the plan cannot add "belt and suspenders" cleanup code that runs on every reactivation.
- **Operator retains explicit control over destructive timing** — the SQL runs when the operator is ready, on their timeline, with their backup posture, not on plugin update.

**Reconsider**
- If operator attestation cannot be obtained (public plugin ship, wp.org install base).
- If the retired data has legal-retention constraints that make operator-driven deletion insufficient (GDPR right-to-be-forgotten, HIPAA disposal windows).
- If the retirement is a phased evolution where downstream consumers need a compatibility window (opposite scope from F016 — see any future "deprecation window" DEC).

**Applied**
F016 (2026-07-07) — Claude Connectors retirement.
- Operator recipe: `README.txt` §Unreleased.
- Attestation: `specs/016-remove-claude-connectors/spec.md` §Assumptions "Attestation of no live connector data" (raftaar1191@gmail.com, 2026-07-06).
- Reference implementation of the retention side (uninstall.php safety net): `uninstall.php:57-58` — DROP entries live AFTER `line 33`'s opt-in gate. Verified via F016 T038 awk assertion.

**Where to look next**
Any future retirement feature: cite this DEC in the plan's Constitution Check with the operator-attestation status. If attestation exists → apply this pattern. If not → apply F011's self-healing pattern (phantom-version guard + Activator `maybe_upgrade` calls). WORKLOG.md 2026-07-07 has the milestone narrative.

---

### DEC-ABILITY-OVERRIDE-RESOLUTION — Effective ability exposure has a single resolver

**Status**: Active (Feature 017)
**Scope**: Ability exposure decisions at the MCP boundary + admin UI + REST
**Tags**: resolver, single-source-of-truth, fallback, per-request-cache, generalizable

**Why this is durable**
F017 introduces per-`(server, ability)` overrides that must NOT contradict the ability's own `meta[mcp][public]` default. Every consumer — REST GET, REST POST audit action, `mcp_adapter_pre_tool_call` gate, future WP-CLI subcommands — MUST use the same resolution rule or the plugin surfaces contradictory verdicts across contexts. Codifying the rule as a stateless service prevents drift.

**Decision**
Effective exposure for a `(server_id, ability_slug)` pair is:

1. If a row exists in `{prefix}acrossai_mcp_server_abilities` for the pair → `(bool) $row->is_exposed`.
2. Otherwise → `! empty( $meta['mcp']['public'] )` (the ability's own default).

Single implementation: `Includes\Database\MCPServerAbility\ExposureResolver::resolve( int $server_id, string $ability_slug, array $meta ): bool`. Includes per-request static cache keyed by `"{$server_id}:{$ability_slug}"`. A11 pure-service exception — no singleton, no ctor.

**Consumers (grep gate)**
Every consumer of the F013 partition-by-`meta[mcp][public]` logic MUST route through `ExposureResolver::resolve()`. Grep gate at review:
```
grep -rEn "meta\['mcp'\]\['public'\]\|meta\[\"mcp\"\]\[\"public\"\]" includes/ admin/ public/
```
Exactly two hits acceptable: (1) inside `ExposureResolver::resolve()` itself, (2) inside the REST controller's inline `$meta['mcp']['type'] ?? 'tool'` for the `type` field (unrelated key). Any other hit is a duplicate resolution path that must be routed through the resolver.

**Applied**
- REST GET: `Includes\REST\AbilitiesController::build_row()` — uses resolver for `is_exposed`.
- REST POST: same controller — uses resolver to compute `$was`/`$now` for FR-024 audit action.
- Enforcement gate: `Includes\MCP\AbilityExposureGate::gate_tool_call_by_exposure()` — uses resolver for the 403 decision.
- (Retired) F013's `AbilitiesTab::partition_abilities()` — the last direct-`meta[mcp][public]` consumer is removed by F017 TASK-5.

**Where to look next**
`includes/Database/MCPServerAbility/ExposureResolver.php`. When a future feature adds a fifth consumer (WP-CLI subcommand, background cron, etc.), route through the resolver — do NOT re-derive the fallback rule.

---

### DEC-WP-DATAVIEWS-OVER-REACT — New admin JS surfaces use `@wordpress/dataviews`, not custom React grids

**Status**: Active (Feature 017)
**Scope**: All new admin JS UIs added after 2026-07-07
**Tags**: dataviews, wp-packages-first, principle-vi, react-libs-forbidden

**Why this is durable**
Constitution Principle IV mandates `@wordpress/dataviews` for admin listings; Principle VI mandates Tier 1 `@wordpress/*` packages. A slow drift toward `react-query` / `react-table` / MUI / styled-components would inflate the admin bundle, duplicate React state machinery already shipped by WordPress, and diverge from core admin UI. F017's Abilities tab is the first F017-era proof-of-concept — every future admin tab MUST inherit the same discipline.

**Decision**
New admin JS surfaces (new admin pages, per-server tabs added after F013, dashboard widgets outside DEV1's WP_List_Table exception) MUST use `@wordpress/dataviews` + `@wordpress/components` + `@wordpress/element` + `@wordpress/api-fetch` + `@wordpress/i18n` + `@wordpress/hooks` — no generic React libraries.

Forbidden (grep gate at review):
```
grep -rEn 'react-query|@tanstack|redux|mobx|react-table|@mui/|styled-components' src/js/
```
MUST return zero matches. Failure blocks merge.

**Tradeoffs**
- Gained: coherent admin UI across features; small bundles; `@wordpress/scripts` externalization keeps runtime footprint stable.
- Made harder: features that need advanced grid interactions not covered by `@wordpress/dataviews` need a spec-kit clarification to widen this decision.
- Reconsider: only when `@wordpress/dataviews` provably cannot express a feature's UX AND the alternative is a well-scoped Tier 2 add. Requires a `/speckit-clarify` round + a new DEC-* entry.

**Applied**
F017 (2026-07-07) — `src/js/abilities.js` mounted on the Abilities tab via `admin/Main.php::maybe_enqueue_abilities_app()`.

**Where to look next**
`docs/planings-tasks/017-per-server-ability-selection.md` §CONSTRAINTS. F015 access-control precedent (`src/js/access-control.js`) is the sibling shape (vendor-provided React component in that case) — F017 shows the WP-provided package shape.

---

### DEC-TOOL-SELECTION-PRESENCE-MODEL — Presence-based storage when UX has no third state

**Status**: Active (Feature 020 — 2026-07-09)
**Scope**: Feature data-model decisions for per-resource configuration surfaces.
**Tags**: `presence-based-storage, boolean-antipattern, ui-driven-data-model, set-membership, third-state-avoidance, generalizable`

**Why this is durable**

F020's Tools tab presents a two-column shuttle picker where each ability is either "in the curated list" or "not in the curated list" — no third state (no inherited/default/tri-state affordance). If the storage layer models this with a boolean column (`is_added`) matching each row, a phantom third state emerges: "row exists but `is_added = false`" — a state that has no UI representation and creates ambiguity for future consumers (does an unread row mean unset, false, or was-set-then-unset?). F020 avoided this by using **row presence** as the flag: a row for `(server_id, ability_slug)` exists ⇔ ability is added; no row means not added. The `UNIQUE(server_id, ability_slug)` constraint enforces the invariant at the DB level.

**Decision**

When a UI models set-membership with exactly two states, the storage MUST be presence-based:

- Row exists ⇔ true state.
- Row missing ⇔ false state.
- Enforce with a UNIQUE constraint on the identifying tuple.
- The "delete row" operation replaces the "set false" operation.
- No boolean column that would introduce a third state.

Contrast with F017's `wp_acrossai_mcp_server_abilities` (uses `is_exposed` boolean): F017 needs the boolean because ability exposure has a fallback path — absence of a row means "use `meta.mcp.public`", which is a legitimate third state (not-set / true-overridden / false-overridden). F017's DataViews toggle grid can represent all three states via the toggle's mixed/on/off tri-state semantics. F020 has no such fallback layer — presence is authoritative.

**Decision rule (generalizable)**: model third-state presence in the UI first; if a boolean's `false` value would be indistinguishable from "no row" in the UI, drop the boolean.

**Tradeoffs**
- Gained: simpler schema; UNIQUE constraint enforces correctness at the DB level; no ExposureResolver-style fallback layer needed; delete = false operation is trivially auditable via BerlinDB's `_deleted` action hooks.
- Made harder: features that later need a fallback layer must migrate the schema (add a boolean, backfill, update writers). Trivially reversible but a non-zero migration cost.
- Reconsider: never for pure two-state set-membership. Reconsider when the UX evolves to include a third state (inherited/default) — at that point migrate to a boolean-with-resolver pattern (F017 shape).

**Evidence**
- `specs/020-per-server-tool-selection/data-model.md §Presence-based storage`
- `includes/Database/MCPServerTool/Schema.php:33-89` — 5 columns, no boolean.
- `specs/020-per-server-tool-selection/spec.md §Assumptions §Presence-based storage`.

**Where to look next**

Grep for new per-resource config tables: `grep -rn "'is_[a-z_]*'.*=> *'tinyint'" includes/Database/`. For each hit, verify the UI has a third state (fallback/default). If not, the boolean is a candidate for removal via a data-model refactor.

---

### DEC-F020-TOOL-ENFORCEMENT-PRIORITY — `mcp_adapter_pre_tool_call` slot map annotation

**Status**: Active (Feature 020 — 2026-07-09; annotates D18)
**Scope**: WordPress action/filter priorities on `mcp_adapter_pre_tool_call`.
**Tags**: `mcp-adapter-pre-tool-call, priority-slot-map, deny-precedence, gate-stacking, f015-f017-f020, generalizable, d18-annotation`

**Why this is durable**

The `mcp_adapter_pre_tool_call` filter (D18) now has three registered gates in this plugin — F015 access control (priority 10), F017 ability exposure (priority 20), and F020 tool curation (priority 30). Deny-precedence layering depends on the priority ordering: each higher-priority-number callback runs LATER and must honor any prior `WP_Error` return. Future features adding gates need a stable slot assignment convention to avoid collision + surprise reordering.

**Decision**

The canonical priority slots for `mcp_adapter_pre_tool_call` in this plugin:

| Priority | Feature | Purpose | Reference |
|---------:|---------|---------|-----------|
|       10 | F015    | Access-control rule evaluation | INDEX row D18 |
|       20 | F017    | Per-server ability exposure toggle | F017 FR-030 |
|   **30** | **F020**| **Per-server tool curation** | `contracts/enforcement.md` |

Rules for future gate additions:

1. New gates MUST occupy slot 40+ or explicitly re-plan the slot map with a memory-md capture that supersedes this DEC.
2. All gates MUST honor deny-precedence: if `$args` is already `WP_Error`, propagate unchanged — never re-allow.
3. Fail-open on unresolvable server (matches F015 D19 observability pattern).
4. When a fourth gate is added, **consider extracting** `McpAdapterGateRegistry` (a shared service that gates register into via a filter or add-method call) so slot assignments become declarative rather than scattered across `Main.php`. F020 predates this extraction; the 3-gate cost of scattered wiring is acceptable, the 4-gate cost may not be.

**Tradeoffs**
- Gained: explicit deny-precedence semantics that operators + auditors can reason about; each feature's gate lives in its own callback with its own error code; upstream vendor changes to the filter shape are absorbed independently per gate.
- Made harder: slot-map knowledge must be checked on every new gate feature; scattered wiring in `Main.php` grows linearly.
- Reconsider: at 4 gates, extract a registry. Update this DEC to point at the new registry pattern.

**Evidence**
- `includes/Main.php:437-441` — F020's priority-30 wire.
- `includes/Main.php:432-433` — F017's priority-20 wire (D18 reference implementation).
- `includes/AccessControl/AcrossAI_MCP_Access_Control.php:249-253` — F015's priority-10 wire.
- `specs/020-per-server-tool-selection/contracts/enforcement.md §Priority Slot Map` — canonical documentation.

**Where to look next**

When adding a 4th gate: verify the slot table above matches shipped code (grep `mcp_adapter_pre_tool_call` in `includes/Main.php`); pick slot 40 or higher; add a companion row to this DEC's slot table; consider whether the registry extraction is warranted (2-line PR if slot 40 is the target; multi-file PR if extracting).

---

### D22 — Inline-shipped follow-up features MUST fold into parent spec as concrete task IDs, not "See F###" pointers

**Status**: Active (Feature 021 Phase 10 / F024 fold-in — 2026-07-12)
**Scope**: Spec-Kit tasks.md hygiene when a follow-up feature ships inline with a parent feature's phase iteration.
**Tags**: `tasks-ledger, fold-in, traceability, inline-shipping, spec-kit, generalizable`

**Why this is durable**

When a follow-up feature (F024) ships inline with a parent feature's phase iteration (F021 Phase 9 → Phase 10), a `## Phase N — See F###` stub in the parent tasks.md breaks reviewer traceability: the PR diff contains F024 code but the parent's task ledger has no matching IDs, so completion state is untracked and boundary-gate checks (which iterate task-referenced files) silently miss the newly-added files. F021 shipped this exact anti-pattern on 2026-07-11; architecture review on 2026-07-12 surfaced it as V4 and it was corrected by folding F024's 32 tasks into F021's Phase 10.

**Decision**

When feature X's code lands in feature Y's branch, feature Y's tasks.md MUST enumerate the X-added tasks with concrete IDs using the pattern `T<X-number>-<seq>` (e.g. T024-001, T024-002, ...) grouped under a `## Phase N: <Title> (folded from F###)` heading. Rules:

1. The child feature's spec directory (`specs/###-<slug>/`) MAY be preserved for historical trail but MUST link back to the parent's Phase N heading via a "folded into F###" note.
2. Every child-feature file (new + modified) MUST map to at least one folded task ID.
3. Boundary-gate scripts that whitelist directories MUST be re-audited whenever a fold-in adds a new layer (see [[B26]] governance gate scope drift).
4. A "See F###" pointer without concrete IDs is INSUFFICIENT — treat it as a code smell that reviewer traceability will fail.

**Tradeoffs**
- Gained: PR reviewers see every file mapped to a concrete task; boundary gates catch new additions; completion state stays inside one spec directory; architecture-review + security-review passes can iterate the parent's task list and be assured they've covered every shipped file.
- Made harder: tasks.md grows longer per feature; duplicate task narrative between parent and child spec directories if both are kept.
- Reconsider: If the child feature is genuinely orthogonal and ships in a separate PR/branch/release, keep it standalone — this decision applies only to inline-with-parent-phase shipping.
- Related: [[D13]] (when to escalate a deviation to the constitution — same governance thread); [[B26]] (governance-gate scope drift — the failure mode this prevents).

**Evidence**
- `specs/021-oauth-2-1-implementation/tasks.md §Phase 10` (before): 12-line "See F024" stub with no task IDs.
- `specs/021-oauth-2-1-implementation/tasks.md §Phase 10` (after 2026-07-12): 32 concrete T024-001..T024-065 IDs with file mappings.
- Architecture review report 2026-07-12 V4 finding: "Task Sync — Drifted".
- `specs/024-connectors-nested-tabs/tasks.md` — preserved standalone for history, links back to parent Phase 10.

**Where to look next**

When a feature description references "landed inline with F###", verify tasks.md has concrete IDs, not a pointer. When adding a new top-level directory or namespace in a fold-in, audit every `bin/verify-*-gates.sh` script for whether it needs to scan the new location.

---

### DEC-ADDONS-PAGE-VENDOR-CTOR-BOOT — External-package classes whose constructor self-registers all hooks are exempt from the Loader-only rule (A1), and MUST instead be instantiated inside `Main::define_admin_hooks()` under a `class_exists` guard + `try/catch`

**Status**: Superseded (Feature 028 — 2026-07-17). `\AcrossAI_Addon\AddonsPage` was removed from `acrossai-co/main-menu` 0.0.22 alongside the `freemius/wordpress-sdk` dependency. This plugin no longer instantiates a self-registering external class; the Add-ons page in 0.0.22+ is filter-driven (`acrossai_addons`) and needs no consumer-side boot. Retained here as the reference pattern for any *future* self-registering vendor class. See `docs/planings-tasks/028-remove-freemius-and-filter-self.md`.
**Original status**: Active (Feature 022 — 2026-07-12)
**Scope**: Every consumer plugin integrating `\AcrossAI_Addon\AddonsPage` (bundled in the vendored `acrossai-co/main-menu` package) or any future external-package class that follows the same self-registering-in-constructor pattern.
**Tags**: `vendor-integration, main-menu, addons-page, external-package, a1-exception, boot-flow, class-exists-guard, try-catch, generalizable`

**Why this is durable**

`\AcrossAI_Addon\AddonsPage::__construct()` self-registers six WordPress hooks in its private `boot()` method (`vendor/acrossai-co/main-menu/src/Addons/AddonsPage.php:104-115`): `admin_menu @ 20`, `admin_menu @ 21`, `admin_init`, `admin_enqueue_scripts`, `admin_notices`, plus four `wp_ajax_acrossai_addons_*` handlers and one `admin_post_acrossai_addons_connect_again`. The vendor's public API deliberately does NOT expose per-hook registration methods — the constructor is the ONLY documented entrypoint. Therefore consumers cannot route these hooks through `$this->loader->add_action()` in `Main.php` per Architecture Constraint A1. Any future external package that follows the same self-registering-in-constructor pattern falls under the same exception.

**Decision**

Consumers MUST instantiate `\AcrossAI_Addon\AddonsPage` (or any equivalent self-registering external class) exactly once per request inside `AcrossAI_MCP_Manager\Includes\Main::define_admin_hooks()`, wrapped in:

1. A `class_exists( \AcrossAI_Addon\AddonsPage::class )` guard — Constitution §V Integration Resilience so a stripped vendor package degrades silently rather than fataling.
2. A `try { ... } catch ( \Throwable $e ) { ... }` — the vendor's constructor throws `InvalidArgumentException` on empty Freemius credentials and `RuntimeException` on WP < 6.0 or an unresolvable consumer file. The catch registers an `admin_notices` closure that (a) short-circuits on `! current_user_can( 'manage_options' )` and (b) prints the exception message through `esc_html()` inside a `notice-error` div.

Freemius credentials MUST be stored inline in the `new` call as string literals — do NOT introduce a filter, option, or environment-variable indirection. Rationale: matches the sibling plugin's precedent (audit-trivial + no runtime lookup), and Freemius public keys (`pk_...`) are safe to embed in shipped source.

**Tradeoffs**
- Gained: consumer plugins integrate any AcrossAI shared page in a single guarded block; absent-vendor + bad-credentials + WP-version-too-low all degrade to admin notices instead of fatals; single-registration guard (`MenuRegistrar::$registered`) lets multiple AcrossAI plugins coexist without stacking duplicate nav rows.
- Made harder: A1 has a documented exception now; new hires must know that "hooks live in Loader only" is a *default*, not an absolute. The DEC entry above is the mitigation.
- Reconsider: If the vendor package evolves to expose per-hook registration methods, this DEC's rationale weakens — revisit whether the consumer should still call `new AddonsPage(...)` or route through the Loader instead.
- Related: `DEC-VENDOR-SETTINGS-TAB-INTEGRATION` (Feature 012 — sibling pattern for consuming the same vendor package's Settings tab surface); `DEC-EXTERNAL-PACKAGE-HOOK-CTOR` (sibling plugin `acrossai-abilities-manager` Feature 038 — the canonical reference block).

**Evidence**
- `vendor/acrossai-co/main-menu/src/Addons/AddonsPage.php:69-115` — constructor signature (throws contract) + `boot()` hook list.
- `vendor/acrossai-co/main-menu/src/Addons/MenuRegistrar.php:5-50` — `install_plugins` capability + `self::$registered` cross-plugin dedup guard.
- `acrossai-abilities-manager/includes/Main.php:316-349` — sibling reference block (Feature 038).
- `acrossai-mcp-manager/includes/Main.php` — this feature's insertion in `define_admin_hooks()` immediately after the `$settings_menu` wiring.

**Where to look next**

When adding a second self-registering external package: apply this same DEC. When bumping the vendored `acrossai-co/main-menu` package: verify `\AcrossAI_Addon\AddonsPage` FQN + constructor arity are preserved (the `class_exists` guard would silently fall false on a rename, hiding the regression — SC-001 smoke catches it end-to-end).

**Corollary — the `fs_menu` explicit-at-call-site pattern (main-menu 0.0.16+)**

`\AcrossAI_Addon\AddonsPage`'s constructor accepts an optional `fs_menu` key on its `$args` array (main-menu 0.0.16 commit `0fb50ea`). It is merged over `FreemiusInitializer::DEFAULT_MENU` and controls which Freemius auto-submenus (`account`, `contact`, `support`, `upgrade`, `pricing`, `addons`) surface under the consumer's parent menu. `slug` cannot be overridden this way (stripped before merge — derives from the constructor's `$parent_slug` argument).

Consumer plugins SHOULD pass an explicit `fs_menu` array with all six keys even when the values mirror the vendor defaults, so future maintainers see the intent at the call site instead of inheriting an implicit default that a future package bump could shift under them. Unknown keys pass through verbatim to Freemius's `menu` config so this is future-proof against new Freemius menu extensions.

Reference: `vendor/acrossai-co/main-menu/src/Addons/FreemiusInitializer.php` `DEFAULT_MENU` + `init($menu_overrides)`; `vendor/acrossai-co/main-menu/src/Addons/AddonsPage.php` `$args['fs_menu']` extraction; this plugin's `includes/Main.php` `AddonsPage` instantiation block.

---

### DEC-FREEMIUS-DOUBLE-OPTIN-GATES-ACCOUNT — Freemius' Account submenu requires a completed email round-trip; `is_pending_activation: true` in `fs_accounts` proves click happened but confirmation link wasn't followed

**Status**: Superseded (Feature 028 — 2026-07-17). This plugin dropped Freemius entirely when `acrossai-co/main-menu` 0.0.22 removed the `freemius/wordpress-sdk` dependency. The double-opt-in state machine no longer applies to any live surface here. The DB-sanity SQL and LocalWP dev-mode recipe below stay useful for any *other* AcrossAI plugin still using an unretired Freemius integration; audit-prune once no consumer remains. See `docs/planings-tasks/028-remove-freemius-and-filter-self.md`.
**Original status**: Active (Feature 022 — 2026-07-13)
**Scope**: Every consumer plugin that instantiates `\AcrossAI_Addon\AddonsPage` against a Freemius product with `is_wp_org_compliant: true` (which is the default for AcrossAI umbrella products). Applies to `acrossai-mcp-manager`, `acrossai-abilities-manager`, and every future AcrossAI plugin.
**Tags**: `freemius, double-opt-in, wp-org-compliant, is_registered, is_pending_activation, fs_accounts, localwp, generalizable`

**Why this is durable**

The Freemius SDK gates its **Account** submenu on `is_registered()` (`vendor/freemius/wordpress-sdk/includes/class-freemius.php:10732-10741`), which returns `is_object( $this->_user )`. `$this->_user` is populated ONLY after Freemius stores a `users` (and companion `sites`/`installs`) entry at the top level of `wp_options.fs_accounts`. For a `is_wp_org_compliant: true` product (which is what AcrossAI umbrella products are), Freemius uses a **double opt-in flow**: the "Allow & Continue" click on the opt-in card only records intent (`is_pending_activation: true`); the actual `sites`/`users` records land only after the user clicks a confirmation link Freemius emailed to `wp_users.user_email` (NOT `wp_options.admin_email`) of the currently-logged-in user.

This session (F022) burned ~10 rounds diagnosing "Account submenu missing" when the actual root cause was the email confirmation click never happened — Freemius was in the intermediate "pending activation" state. Without this decision captured, the next feature that touches Freemius will re-debug the same state machine.

**Decision**

When diagnosing "why is Freemius Account/Contact/Support/Add-ons submenu not appearing":

1. **First**, check the DB — is `is_registered()` returning true? Proxy query:
   ```sql
   SELECT
     option_value LIKE '%"sites"%' AS has_sites,
     option_value LIKE '%"users"%' AS has_users,
     option_value LIKE '%is_pending_activation%' AS pending_activation
   FROM wp_options WHERE option_name = 'fs_accounts';
   ```
   - `has_sites = 1` AND `has_users = 1` → opt-in COMPLETED; Account should render. If it doesn't, the bug is elsewhere (permissions, hook priority, or `WP_FS__DEMO_MODE`).
   - `has_sites = 0` AND `has_users = 0` AND `pending_activation = 1` → opt-in **click** happened but **email confirmation link** was NOT clicked. Account will not render. Fix: get the user to click the confirmation link.
   - `has_sites = 0` AND `has_users = 0` AND `pending_activation = 0` → user hasn't started opt-in yet, OR they clicked "Skip" (which flags `is_anonymous_ms: 1` — check for that separately). Account will not render.
2. **Second**, verify which email Freemius is sending the confirmation to. Freemius reads `wp_users.user_email` of the currently-logged-in user — NOT `wp_options.admin_email`. If the admin user's `user_email` points at an unreachable address, the opt-in state machine stalls forever.
3. **Third**, on LocalWP `.local` sites, define these in `wp-config.php` BEFORE first opt-in attempt (they're the difference between the flow working locally vs stalling silently):
   ```php
   define( 'WP_FS__DEV_MODE', true );          // bypass localhost connectivity gate
   define( 'WP_FS__API_HTTP_INSECURE', true ); // allow HTTP fallback to api.freemius.com
   ```
4. **Fourth**, if state is stuck: full reset is safer than surgical edits. Delete the whole `fs_accounts` option (and `fs_active_plugins`, `fs_api_cache`, `fs_cache_*`, `fs_debug_mode`), then deactivate + reactivate the plugin. The opt-in card re-fires on the first admin page load after reactivation.

**Tradeoffs**
- Gained: predictable "why is Account missing" diagnostic recipe; future features skip the debugging arc F022 endured.
- Made harder: `is_wp_org_compliant: true` cannot be turned off per-consumer (it's a Freemius product-side setting). If a consumer plugin wants single-opt-in (skip email confirmation), they need a separate Freemius product configured non-wp.org-compliant — that's a Freemius dashboard change, not a code change.
- Reconsider: if Freemius changes its SDK behavior in a future release (e.g. surfaces Account for pending-activation state), revisit this decision. The DB-query recipe above is the canonical way to verify: as long as `has_sites`/`has_users` are the gate, this decision holds.
- Related: `DEC-ADDONS-PAGE-VENDOR-CTOR-BOOT` (documents the vendor-package instantiation surface); `PATTERN-LOCALWP-FREEMIUS-DEV-MODE` — could be extracted as its own memory entry if more localhost-specific findings emerge.

**Evidence**
- `vendor/freemius/wordpress-sdk/includes/class-freemius.php:10732-10741` — `is_registered()` definition.
- `vendor/freemius/wordpress-sdk/includes/class-freemius.php:18913` — Account submenu registration gated on `is_registered()`.
- `wp_options.fs_accounts` DB dump during F022 diagnosis session — `is_pending_activation: b:1`, `is_plugin_new_install: b:0`, `connectivity_test.is_connected: N` all captured before opt-in reset.
- Post-reset opt-in flow (2026-07-13): admin sees "Thank you for updating to AcrossAI MCP Manager v0.1.0" card → clicks Allow & Continue → Freemius queues email to `wp_users.user_email` → email link click completes the round-trip → `has_sites`/`has_users` populated → Account submenu appears on next admin page load.

**Where to look next**

When Freemius submenus disappear unexpectedly on any AcrossAI plugin: run the sanity SQL first. When adding a new AcrossAI plugin that consumes `acrossai-co/main-menu`: guide the operator to complete opt-in exactly once against the umbrella product (34418 today); after that any additional AcrossAI plugin auto-shares the opt-in state via the umbrella product ID (they call `fs_dynamic_init` for the same product; Freemius returns the existing memoized instance).

---

### 2026-07-14 - DEC-F025-HYBRID-TOOL-STORAGE-PROTOCOL-VS-CURATED

**Status**
Active — supplements (does NOT supersede) `DEC-TOOL-SELECTION-PRESENCE-MODEL` (F020).

**Why this is durable**
Second concrete use of the "boolean-with-fallback pattern when a fallback layer exists" carve-out from DEC-TOOL-SELECTION-PRESENCE-MODEL (F017's ExposureResolver was the first). Codifies WHEN a feature should hybrid-store vs. pushing everything into presence rows.

**Decision**
When a feature stores per-resource state where (a) the state is a UNION of a FIXED cardinality set AND an OPEN-ended set, (b) the fixed set has a well-defined default that must survive existing rows on schema upgrade, AND (c) atomic reset (flip all fixed flags AND clear open-ended) is a first-class operation, THEN store the fixed set as boolean columns on the primary row (`DEFAULT 1` on ALTER = the migration) AND the open-ended set as presence rows in a separate table. Compose the effective set via a single canonical helper (`ToolPolicy::compose_for_row()` in F025's case — mirrors F017's `ExposureResolver::resolve()` per DEC-ABILITY-OVERRIDE-RESOLUTION).

**Tradeoffs**
- Gained: `ALTER TABLE ADD COLUMN ... DEFAULT 1` IS the migration — no backfill helper. Atomic Reset via one UPDATE + one transactional replace_set([]). Type safety on fixed-set slugs.
- Made harder: Two writes on POST (column update + curated replace_set) are not wrapped in an outer transaction — small accepted race window (SEC-025-INFO-2).
- Reconsider: If the fixed set grows or its cardinality becomes uncertain, migrate to unified presence storage with an `is_default` flag.

**Where to look next**
- `specs/025-server-tools-registration-hooks/data-model.md` §Storage layers 1+2
- `includes/Database/MCPServer/ToolPolicy.php`
- `specs/025-server-tools-registration-hooks/plan.md` §Complexity Tracking

---

### 2026-07-14 - DEC-F025-V2-VENDOR-SOURCE-CROSS-CHECK-CADENCE + DEC-F025-RUNTIME-EVIDENCE-OVER-STATIC-REVIEW

**Status**
Active — extends F020 WORKLOG "run v2 for close-in-substance verification" lesson.

**Why this is durable**
F020's v2 lesson stopped at "verify against vendor source". F025 v2 DID verify against vendor source and STILL missed a runtime-timing bug — vendor `add_action('wp_abilities_api_init', ...)` in its own `__construct()` runs too late to catch the very action it targets. Static hook analysis is insufficient when the target hook may already have fired. Runtime evidence 2026-07-14 disproved v2's "safe" verdict.

**Decision**
For any feature consuming a vendor hook contract or vendor return contract:

1. **Vendor cross-check cadence**: EITHER plan.md cites vendor-source line numbers for every contract claim, OR a v2 security review is mandatory before implementation.
2. **Runtime evidence over static review**: When a v2 review cannot easily observe the actual runtime hook order, the reviewer MUST NOT declare timing "safe" — flag as "requires runtime verification during implementation" and add a runtime smoke check to the implementer's task list.
3. **Corollary — canonical constants over runtime resolution**: When a vendor slug/name needs to be referenced at runtime, prefer a plugin-owned constant (like `ToolPolicy::PROTOCOL_TOOLS`) over runtime resolution via the vendor's registration mechanism.

**Tradeoffs**
- Gained: Prevents "v2 declared safe, runtime disproved" bugs (F025 hit this class twice — POST validation + GET catalog).
- Made harder: Reviewers must be humble about the limits of static analysis over hook-timing code.
- Reconsider: If the WordPress Abilities API adds a stable "hooks registered by X action" invariant, static analysis may become sufficient again.

**Where to look next**
- `docs/security-reviews/2026-07-13-025-server-tools-registration-hooks-plan-v2.md` §SEC-025-v2-2
- `includes/REST/ToolsController.php::post_tools()` FR-018 comment
- `includes/Database/MCPServer/ToolPolicy.php::PROTOCOL_TOOL_METADATA`

---

### 2026-07-14 - DEC-F025-TASKS-REVIEW-PRESERVATION-INVARIANT-AND-COVERAGE-MATRIX

**Status**
Active — extends F011 D6 (use imports preserved during subtractive edits).

**Why this is durable**
Task-review-time preservation checklists prevent "I thought I was just deleting X but I actually removed Y" defects when tasks touch security-sensitive methods (auth boundaries, capability checks, nonce middleware). Coverage matrices are the fastest way to detect a dropped finding when a task-review consumes multiple plan-review artifacts (F025 tasks-review consumed v1 + v2 = 6 plan findings — matrix caught inconsistencies immediately).

**Decision**
1. **Preservation invariant on subtractive-edit tasks**: any task that DELETES code from a security-sensitive method MUST include an explicit "MUST NOT modify [permission_callback], [nonce middleware], [capability check]" invariant list in the task description. Reviewer confirms the delta against the list before diff sign-off.
2. **Coverage matrix on task-review artifacts**: every task-review MUST produce a coverage matrix mapping each PRIOR plan-review finding ID to its remediation task ID. If a finding has no task, the matrix surfaces the gap immediately.

**Tradeoffs**
- Gained: Prevents accidental permission_callback removal during subtractive edits. Prevents dropped security findings between plan-review and task-review.
- Made harder: Task descriptions get slightly longer; task-reviews get a mandatory matrix section.
- Reconsider: If the review pipeline gains machine-enforced coverage matrices (linter checks every SEC-* finding has a task ref), the coverage-matrix rule becomes a lint gate.

**Where to look next**
- `specs/025-server-tools-registration-hooks/tasks.md` T012 (PRESERVATION invariant example)
- `docs/security-reviews/2026-07-14-025-server-tools-registration-hooks-tasks.md` §"Coverage matrix"

---

### 2026-07-14 - DEC-F026-SIBLING-COMPOSER-EXTENSION-PATTERN

**Status**
Active — validated by F026 v1 (method-level) and F026 v2 (class-level).

**Why this is durable**
Extends a canonical composer while keeping consumers scoped to the semantic they
want. Naïve alternatives (bool flag param, class rename, in-place widening) all
create call-site ambiguity or force silent-behavior changes on unrelated consumers.

**Decision**
When extending a canonical composer, add a sibling — at the method level if the
new semantic still lives in the same class; at the class level if the new scope
outgrows the class's naming. Never overload with a flag argument. Never rename
the original class to broaden its scope.

Examples in this repo:
1. **Method-level (F026 v1)**: `ToolPolicy::compose_effective_tools_for_row()`
   added as a sibling to `compose_for_row()`. REST GET stays on the original;
   server-registration paths switch to the widened form.
   See `includes/Database/MCPServer/ToolPolicy.php:162`.
2. **Class-level (F026 v2)**: `AbilityDiscovery` added as a sibling to `ToolPolicy`
   when the composer needed to cover resources + prompts (types `ToolPolicy`'s
   protocol-column layer doesn't apply to). Would have polluted `ToolPolicy`'s
   naming and constants. See `includes/Database/MCPServer/AbilityDiscovery.php:37`.

**Tradeoffs**
- Gained: Zero behavior drift on consumers of the original composer. Call sites
  self-document which semantic they want.
- Made harder: Two names to learn per composer family.
- Reconsider: If a repo has many parallel composers (5+), a naming convention
  (e.g. `_effective_for_X`) plus a lint gate becomes more valuable than manual
  sibling additions.

**Where to look next**
- `includes/Database/MCPServer/ToolPolicy.php` (sibling method)
- `includes/Database/MCPServer/AbilityDiscovery.php` (sibling class)
- `specs/026-abilities-into-tool-registration/spec.md` §"Scope expansion: F026 v2"

---

### 2026-07-14 - DEC-F026-ADVERTISEMENT-VS-CALL-TIME-DEFENSE-IN-DEPTH

**Status**
Active — foundational to the F017 + F025/F026 threat model. **Refined 2026-07-15** after F026 v3 refactor arc: the "advertisement-time" layer's shape changed but the two-layer defense principle still holds.

**Why this is durable**
The advertisement-time enforcement and the call-time gate are INDEPENDENT
enforcement layers. A companion filter can widen the advertisement; the
call-time gate still blocks execution. Operator's Abilities-tab decision
remains authoritative regardless of filter mutation.

**Current layer shape (as of 2026-07-15 refactor)**:
- **Advertisement-time layer (post-070ffe2 + 0e122e2)** — enforcement now lives
  inside the three plugin-owned meta-tool callbacks (`Discover::execute`,
  `GetAbilityInfo::check_permission`, `Execute::check_permission`) via
  `AbilityHelpers::apply_exposure_filter` → `ExposureResolver::resolve()`.
  The registration-time composer (`ToolPolicy::compose_effective_tools_for_row`)
  no longer widens `tools/list` with F017-effective abilities — that widening
  was reverted because it conflated "what's a tool" with "what's discoverable".
- **Call-time layer (unchanged)** — F017 `AbilityExposureGate` at
  `mcp_adapter_pre_tool_call` priority 20. Direct calls to an ability's own
  slug still gated per-server.

**Historical layer shape (F026 v1, superseded 2026-07-15)**:
- Advertisement-time layer lived in `ToolPolicy::compose_effective_tools_for_row`
  merging `AbilityDiscovery::for_server(TYPE_TOOL)`. This is now reverted to a
  passthrough of `compose_for_row()`. Resources/prompts widening in the same
  file still uses the merge for the two sibling `Controller` call sites.

**Decision**
Never remove either layer on the assumption the other is sufficient. Concretely:
- Never drop the F017 call-time gate at `mcp_adapter_pre_tool_call` priority 20.
- Never drop `apply_exposure_filter`'s use of `ExposureResolver::resolve()`
  inside the three plugin-owned meta-tool callbacks (F026 v3 shape).
- Never route ability-exposure decisions through a bypass path that skips
  `ExposureResolver`.
- **New corollary (F026 v3)**: **exposure ≠ authorization.** A companion filter
  widening exposure via `acrossai_mcp_is_ability_exposed` MUST NOT bypass the
  target ability's own `permission_callback`. `Execute::check_permission`
  enforces this by ordering (auth → exposure filter → target permission_callback).

**Tradeoffs**
- Gained: Filter-side confused-deputy attacks cannot exfiltrate ability
  execution — they can only add a misleading advertisement (and only inside the
  meta-tool responses post-v3, not directly in `tools/list`).
- Made harder: Two enforcement layers to reason about; documentation must
  explain both. The v3 shape has an additional layer (target ability's
  `permission_callback`) that must be preserved.
- Reconsider: If upstream mcp-adapter#244 lands, the plugin-owned meta-tool
  callbacks can be replaced with a single filter callback on the upstream
  `mcp_adapter_is_ability_exposed` filter. The advertisement-time layer
  location changes, but the two-layer principle stays.

**Where to look next**
- `includes/MCP/AbilityExposureGate.php` (call-time layer, unchanged)
- `includes/Abilities/AbilityHelpers.php` (advertisement-time layer, v3)
- `includes/Abilities/Discover.php`, `GetAbilityInfo.php`, `Execute.php`
- `docs/extending-server-tools.md` §7 "Interaction with the Abilities tab"
- `SEC-025-INFO-1` (the confused-deputy caveat this decision mitigates)
- Test regression guard: `ExecuteTest::test_filter_widening_does_not_bypass_target_permission_callback`

---

### 2026-07-15 - DEC-F026-WP-REGISTER-ABILITY-ARGS-CALLBACK-SWAP

**Status**
Active — F026 v3 shipping pattern for overriding vendor-registered abilities.

**Why this is durable**
When a plugin needs to swap the `execute_callback` / `permission_callback`
on an ability registered by a vendor package while keeping the vendor's
schema/label/description/category/annotations intact, hook WP core's
`wp_register_ability_args` filter (fires inside `WP_Abilities_Registry::
register()` at `wp-includes/abilities-api/class-wp-abilities-registry.php:129`).
Match on the ability slug and return `$args` with rebound callbacks.

**Decision**
Use `wp_register_ability_args` for callback-swap over these rejected
alternatives:

1. **Unregister + re-register with plugin-owned copies** — duplicates ~600
   LOC of vendor callback code; schema-drift risk when vendor updates the
   ability before your override is refreshed; breaks any third-party
   plugin that hooked the vendor's original ability instances.
2. **Priority-race registration** — register at `wp_abilities_api_init`
   priority 5 to preempt vendor's priority 10. Emits `_doing_it_wrong` on
   every request in WP_DEBUG mode. Noisy.
3. **Disable vendor's server entirely** (`add_filter( 'mcp_adapter_
   create_default_server', '__return_false' )`) — clean but removes the
   default `/wp-json/wp/v2/mcp` route entirely.
4. **Post-hoc intercept via `mcp_adapter_pre_tool_call` +
   `mcp_adapter_tool_call_result`** — vendor ability still executes fully,
   then result is filtered. Wasted work + metadata-enumeration hazard if
   the post-filter misfires. F026 shipped this as an interim step
   (commit `4ca9db4`) and immediately superseded it (`070ffe2`).

Reference implementation: `includes/Abilities/CallbackReplacer.php`
(hook `wp_register_ability_args` priority 10, slug→[class, perm, exec] map,
pass-through for non-vendor slugs).

**Tradeoffs**
- Gained: Zero vendor code duplication; vendor schema evolves independently
  without breaking the plugin; sibling code that hooked vendor abilities
  continues to work; enforcement happens BEFORE ability execution (via
  bound `permission_callback`), avoiding the post-hoc rewrite hazard.
- Made harder: Requires a per-request context holder (see `A17`) because
  vendor callbacks don't receive the invoking McpServer instance.
- Reconsider: When vendor mcp-adapter#244 lands (adds
  `mcp_adapter_is_ability_exposed` filter with McpServer context), the
  whole `includes/Abilities/` folder can be replaced with a ~40-line
  companion filter callback.

**Where to look next**
- `includes/Abilities/CallbackReplacer.php` (canonical implementation)
- `includes/Abilities/AbilityHelpers.php` (shared trait for the three
  swapped-callback classes)
- Commit `070ffe2` (F026 v3 refactor)
- `docs/planings-tasks/026-abilities-into-tool-registration.md`
  §"Post-shipping arc" (trade-off table)
- Companion architecture pattern: `A17` (request-scoped WP REST context
  capture) — required because the swapped callbacks don't receive
  McpServer context

---

### 2026-07-17 — D26 — Consumer plugin MUST drop its own slug from a shared vendor filter that renders an ecosystem installable-list

**Status**: Active (Feature 028 — 2026-07-17)
**Scope**: Every AcrossAI plugin whose slug appears in `\AcrossAI_Main_Menu\AddonsPageRenderer::ADDONS` (currently: `acrossai-abilities-manager`, `acrossai-mcp-manager`, `acrossai-model-manager`, `turn-off-ai-features`) and any future vendor-published `apply_filters()`-driven ecosystem list where an already-installed consumer would otherwise render as a self-referential "installable" card.
**Tags**: `vendor-integration, main-menu, consumer-self-exclusion, addons-page, filter-driven-list, admin-partials, generalizable`

**Why this is durable**

Vendor-owned shared surfaces that enumerate ecosystem plugins (Add-ons pages, "related plugins" widgets, dashboard grids) typically hardcode a baseline list and expose it through a WordPress filter. Every consumer plugin present in the baseline is, by definition, already active when the filter runs on that install — so rendering an "Install" card for it is UX-nonsensical (button on an already-active plugin) and becomes an outright bug once the vendor's render inspects `is_plugin_active()` and starts showing an "Activate" or "Deactivated" state on an active plugin. F028 hit exactly this shape with `acrossai-co/main-menu` 0.0.22's `acrossai_addons` filter: the vendor's `AddonsPageRenderer::ADDONS` baseline contains this plugin's slug. Without a consumer-side filter, the plugin would advertise itself.

**Decision**

Every AcrossAI plugin whose slug appears in a vendor's ecosystem-list baseline MUST ship a small singleton in `admin/Partials/` (e.g., `AddonsFilter`) that hooks the vendor's filter at default priority 10 and drops the entry where `slug === <own_plugin_slug>`. Requirements:

1. **Singleton** under `AcrossAI_MCP_Manager\Admin\Partials\` (or the plugin's equivalent namespace) with private `__construct` — matches A2 + S6.
2. **Loader-wired** — filter registration lives in `Main::define_admin_hooks()` via `$this->loader->add_filter( '<vendor_filter>', <instance>, '<method>' )` — matches A1.
3. **Reuse the plugin's canonical slug constant** (this plugin: `\ACROSSAI_MCP_MANAGER_PLUGIN_NAME_SLUG` per D1) — never introduce a third literal (Principle VI).
4. **Defensive normalization** — non-array top-level input returns `array()`; non-array entries in the array are dropped by the inner filter. Callers that pass malformed shapes get harmless empty results, not PHP warnings.
5. **`array_values()` reindex** — return a numerically-indexed array (0..N-1) so `wp_json_encode` doesn't emit an object.

Complements D20: where D20 says "verify the vendor doesn't already register your ADDITION before wiring a consumer filter" (avoid double-registration), D26 says "drop your own SUBTRACTION from vendor-hardcoded ecosystem lists" (avoid self-advertisement). Together they form the consumer-side filter-hygiene pair for shared vendor packages.

**Tradeoffs / Prevention**

- **Gained**: consistent UX across every wp-admin where an AcrossAI plugin is installed — no self-referential "Install" cards; Add-ons page always shows *other* installable options. Cheap: one 60-line file per consumer, zero performance impact (vendor memoizes filter output per request).
- **Made harder**: every AcrossAI plugin now must ship this file; forgetting it silently re-introduces the self-advertisement UX regression. Mitigation: this DEC + review-time grep gate — `grep -rn 'acrossai_addons' admin/Partials/` MUST return at least one match on any plugin listed in `AddonsPageRenderer::ADDONS`.
- **Reconsider**: if a vendor future-releases a filter with per-caller context (e.g., `apply_filters( 'acrossai_addons', $addons, $context )` where `$context` names the requesting plugin), consumers could push the self-exclusion into the vendor. Until then, every consumer owns its own drop.
- **Related**: `D1` (canonical `ACROSSAI_MCP_MANAGER_PLUGIN_NAME_SLUG` constant — the slug source-of-truth this DEC mandates reusing); `D20` (consumer-side default-registration hygiene — the additive-side complement); `S6` (singleton private `__construct` invariant this DEC's singleton relies on).

**Evidence**

- `admin/Partials/AddonsFilter.php` — this plugin's canonical implementation (~60 LOC): singleton, Loader-wired, reuses `\ACROSSAI_MCP_MANAGER_PLUGIN_NAME_SLUG` via `defined()` guard, defensively normalizes input, reindexes via `array_values()`.
- `includes/Main.php::define_admin_hooks()` — the Loader wiring, `$this->loader->add_filter( 'acrossai_addons', AddonsFilter::instance(), 'remove_self' )`.
- `tests/phpunit/Admin/AddonsFilterTest.php` — 4 phpunit cases: strip+reindex, no-op when own slug absent, non-array input, non-array entries.
- `vendor/acrossai-co/main-menu/src/AddonsPageRenderer.php` — the vendor contract: `ADDONS` baseline + `get_addons()` runs `apply_filters( 'acrossai_addons', self::ADDONS )` with per-request memoization.
- F028 planning doc: `docs/planings-tasks/028-remove-freemius-and-filter-self.md`.

**Where to look next**

Before adding a new AcrossAI plugin to `AddonsPageRenderer::ADDONS`: ship the equivalent `AddonsFilter` singleton in that plugin's `admin/Partials/` in the SAME PR that adds the baseline entry. When bumping `acrossai-co/main-menu` past 0.0.23: verify the `ADDONS` baseline still contains this plugin's slug (the filter is inert but harmless if the slug isn't present). If the vendor evolves to a per-caller-context filter (`$context` argument), revisit whether the vendor should absorb the self-exclusion.

---

### 2026-07-18 — D27 — Confidential-client token exchange MAY fall through to PKCE-only verification when no secret is submitted

**Status**: Active (Feature 029 — 2026-07-18)
**Scope**: The OAuth 2.1 `/token` endpoint (`includes/OAuth/TokenController.php`), both `authorization_code` and `refresh_token` grants. Applies whenever a client is registered as `token_endpoint_auth_method = 'client_secret_post'` but does not submit a `client_secret` at exchange (checked in BOTH the Authorization Basic header AND the body).
**Tags**: `oauth-2-1, client-secret-post, pkce-only, defense-in-depth, mcp-host-interop, security-tradeoff, generalizable`

**Why this is durable**

Modern MCP hosts (Claude.ai, ChatGPT, Cursor, Cline) frequently register as `client_secret_post` — either via pre-F027 DCR that defaulted to that value, or via an admin generator that hardcodes it (`handle_admin_generate` line 187) — but then behave as public+PKCE clients at exchange, never carrying the secret. F029 confirmed this in the wild against `acrossai.co` after F027 fixed the DCR default; existing rows with `client_secret_post` + a stored `client_secret_hash` were still failing token exchange because the runtime hard-rejected the missing secret. F027 fixes the source-of-truth default for NEW DCR clients; D27 codifies the runtime softening that makes pre-existing / admin-generated confidential rows also complete.

**Decision**

When `$client->token_endpoint_auth_method === 'client_secret_post'`:

1. If the client submits a non-empty `client_secret` (via Authorization Basic header OR body), verify it via `ClientRepository::verify_secret( $client, $client_secret )` — reject with `invalid_client` HTTP 401 on mismatch. **This branch is unchanged from the pre-F029 behavior.**
2. If the client submits NO `client_secret` (header AND body both empty for that field), **fall through** to the grant's other authentication surface — PKCE S256 verification (`PKCE::verify_s256`) for `authorization_code`; refresh-token-bound-to-client verification for `refresh_token`. Do NOT emit `invalid_client`.

The softening applies to BOTH grant handlers (`handle_authorization_code` and `handle_refresh_token`) symmetrically. It does NOT apply to `token_endpoint_auth_method === 'none'` clients (which are already public+PKCE by design — the F027 default) — for those, the `client_secret_post` conditional never fires.

**Tradeoffs**

- **Security posture**: The residual attack surface for a `client_secret_post` client that doesn't submit a secret is bounded by the intersection of:
  - Mandatory PKCE S256 (rejected regardless of client claim, enforced at `AuthorizationController` per F021).
  - Mandatory RFC 8707 `resource` binding (token issued for one MCP server rejects when presented against another).
  - Single-use auth codes via `AuthCodeRepository::consume_atomic` (B10).
  - Short-lived access tokens + refresh-token family revocation on reuse (RFC 9700 §2.2.2, F021).
  - Server-side `redirect_uri` byte-match enforcement at `/authorize` (S9 pattern).
  A hypothetical attacker who could otherwise defeat PKCE (which requires knowledge of the 43-char high-entropy verifier) additionally doesn't need the client secret — but PKCE has never been "optional" on this endpoint, so the marginal defense loss is bounded.
- **Interop win**: Every modern MCP host completes the flow on first attempt, without operators needing to know whether a client is registered as `none` or `client_secret_post`.
- **Reconsider**: If a future security review deems the residual risk unacceptable (e.g., because PKCE has a known weakness in a specific MCP host implementation), tighten by requiring `token_endpoint_auth_method === 'none'` for public+PKCE flows AND providing a migration path for pre-F027 `client_secret_post` rows.
- **Related**: `F027` (v0.1.2 DCR default → 'none' — the source-of-truth fix); `D20` (consumer-side filter registration hygiene — sibling defense-in-depth pattern); `S7` (OAuth token endpoint `__return_true` permission_callback exception — still valid; the "auth happens in the request" surface is now widened from body-only to header-or-body-or-PKCE); `B10` (atomic single-use auth code consumption — the invariant this softening depends on).

**Evidence**

- `includes/OAuth/TokenController.php:106-111` (post-F029) — the softened `client_secret_post` conditional in `handle_authorization_code`.
- `includes/OAuth/TokenController.php:203-208` (post-F029) — same shape in `handle_refresh_token`.
- `includes/OAuth/TokenController.php:283-311` (post-F029) — `read_client_credentials_from_header()` — the header-first credential resolution that makes the "no secret in body" branch discoverable at the header level too.
- F029 planning doc: `docs/planings-tasks/029-oauth-token-basic-auth-and-dcr-attribution.md`.
- F029 spec §Story 3 Security Posture: `specs/029-oauth-token-basic-auth-and-dcr-attribution/spec.md`.

**Where to look next**

Any future OAuth grant addition (device_code, JWT bearer, etc.) MUST decide how to handle a `client_secret_post` client that doesn't submit a secret. Default: mirror D27's softening ONLY IF the grant has an equivalent alternate-authentication surface (PKCE, JWT signature, etc.). For grants without an alternate surface, hard-reject on missing secret. When bumping the plugin's PKCE minimum (e.g., PKCE plain support removed — already the case per F021), verify this DEC's rationale still holds (PKCE remains the sole alternative auth path).

---

### 2026-07-18 — D28 — BerlinDB schema-drift reconciliation MUST use $upgrades callbacks + admin_init trigger, never a bare version bump

**Status**: Active (Feature 029 — 2026-07-18)
**Scope**: Every plugin-owned BerlinDB Kern\Table subclass in `includes/Database/`. Applies to every future column addition, width widening, index change, or default value shift declared in `Schema.php` on a table that any live install already has.
**Tags**: `berlindb, schema-drift, upgrades-callback, maybe-upgrade, admin-init, generalizable`

**Why this is durable**

BerlinDB Core (`vendor/berlindb/core/src/Database/Kern/Table.php`) has counter-intuitive upgrade semantics — one this codebase already violated once when the F025 protocol-tool flag columns were declared in `Schema.php` without a paired `$version` bump AND without a paired `$upgrades` callback. The result was silent — `MCPServer\Table` shipped with the columns in `Schema.php` but the physical `wp_acrossai_mcp_servers` never grew them, causing the write-loss captured as `B34`. Understanding BerlinDB's actual upgrade contract prevents every future feature from re-tripping this.

**BerlinDB upgrade semantics (verified in vendor source):**

- `Table::maybe_upgrade()` checks `needs_upgrade()` (does stored `db_version` differ from declared `$version`?).
- If YES + table exists → `upgrade()` runs. Otherwise → `install()` runs (create fresh from `Schema`) OR no-op.
- **`upgrade()` does NOT auto-diff `Schema.php` against the live table. It does NOT auto-run `dbDelta`.**
- `upgrade()` walks `$this->upgrades` (per-version callback map). Callbacks with version > stored `db_version` run in order.
- If `$upgrades` is EMPTY → `set_db_version()` stamps the new version and returns success without touching schema.
- Callback signature: `protected function upgrade_to_<version>(): bool`. Returns `false` → BerlinDB aborts and leaves version unstamped so the upgrade retries next admin request.

**Decision**

Any change to a `Schema.php` on a table that live installs already have MUST ship as a coordinated three-part change:

1. **Bump `$version`** in the paired `Table.php` (e.g., `1.1.0` → `1.1.1`).
2. **Register a `$upgrades` entry** for the new version pointing to a `protected function upgrade_to_<version>(): bool` method:
   ```php
   protected $upgrades = array(
       '1.1.1' => 'upgrade_to_1_1_1',
   );

   protected function upgrade_to_1_1_1(): bool {
       // Idempotent per-column via INFORMATION_SCHEMA existence / width check.
       // For ADD COLUMN: check COLUMN_NAME not in COLUMNS, then ALTER ADD.
       // For MODIFY COLUMN: check CHARACTER_MAXIMUM_LENGTH differs from target, then ALTER MODIFY.
       return true;
   }
   ```
3. **Ensure `maybe_upgrade()` fires on `admin_init`** (not just from `Activator::activate()`). Wire via `Main::reconcile_database_schemas()` at priority 3 — before any handler that reads from these tables. `Activator` only fires on plugin activation; in-place upgrades (composer / wp-cli plugin update / manual file replace) never re-activate the plugin, so a bare Activator-only trigger leaves version bumps inert on updates.

Callbacks MUST be idempotent per-item via `INFORMATION_SCHEMA.COLUMNS` inspection. dbDelta is NOT called anywhere in this pattern — plugin-owned ALTER TABLE statements with `phpcs:ignore` comments for the DDL-identifier-interpolation warning.

**Tradeoffs**

- **Gained**: Every live install auto-reconciles on the next admin request after upgrade. No operator action (deactivate + reactivate) required. Truly idempotent — callbacks can re-run without side effects. dbDelta's known-fragile column-diff logic is bypassed entirely; we use explicit ALTERs with `INFORMATION_SCHEMA` gates instead.
- **Made harder**: Every future Schema change needs three edits in two files, not one. Reviewers must catch missing `$upgrades` entries — grep gate: any diff that changes `Schema.php` MUST also change the paired `Table.php` `$version` AND add a matching `$upgrades` entry.
- **Reconsider**: If BerlinDB Core adds an auto-diff mode (e.g., an `$auto_diff = true` protected flag that would trigger a Schema-to-live-table comparison), this DEC's manual-callback requirement can relax. Until then, callbacks are mandatory.
- **Related**: `DEC-BERLINDB-TABLE-REQUEST-BOOT` (F011 — sibling: Tables must be instantiated at request time OR the DB interface never registers the table name); `F011 WORKLOG` phantom-version-guard entry (covers the "table missing" case; D28 covers the "table exists but drifted" case they explicitly don't overlap); `B34` (the application-level silent-write-loss symptom D28 prevents).

**Evidence**

- `vendor/berlindb/core/src/Database/Kern/Table.php:313-346` — `maybe_upgrade()`.
- `vendor/berlindb/core/src/Database/Kern/Table.php:982-1012` — `upgrade()` walks `$this->upgrades`.
- `vendor/berlindb/core/src/Database/Kern/Table.php:1021-1040` — `get_pending_upgrades()` filters by `version_compare`.
- `vendor/berlindb/core/src/Database/Kern/Table.php:1052-1086` — `upgrade_to()` invokes callback + stamps version on success.
- `includes/Database/CliAuthLog/Table.php` (post-F029) — reference impl: `$upgrades = ['1.0.1' => 'upgrade_to_1_0_1']` + `protected function upgrade_to_1_0_1(): bool { ... }`.
- `includes/Database/MCPServer/Table.php` (post-F029) — reference impl for ADD COLUMN case.
- `includes/Main.php::reconcile_database_schemas()` + `admin_init@3` Loader wiring (post-F029) — the trigger surface for in-place upgrades.
- Commit `479518e` (WRONG — bumped `$version` on MCPServer without `$upgrades` callback; would have silently stamped `1.1.1` with the columns still missing). Commit `90cbdeb` (CORRECT — rewrote to `$upgrades` pattern).

**Where to look next**

Before any commit that touches a `Schema.php`: run `git diff --stat includes/Database/` and verify every changed `Schema.php` has a paired `Table.php` change bumping `$version` AND adding a `$upgrades` entry. When adding a new Table module, register the module in `Main::reconcile_database_schemas()` alongside the existing 7 tables. If a new column addition can be safely deferred (feature-flagged behind operator opt-in that keys on new-column-present), the `$upgrades` callback can be omitted, but the version bump then must also be omitted — otherwise BerlinDB stamps the new version and the callback can never run later.

### 2026-07-20 — D29 — Operator-visible `permission_callback` bypass permitted ONLY with six-layer defensive gating (F030)

**Status**
Active — Feature 030 (`DEC-F030-PERMISSION-CALLBACK-OPERATOR-OPT-IN-BYPASS`)

**Why this is durable**

Explicit, operator-visible bypass of an ability's `permission_callback` is a legitimate pattern under strict conditions but must never generalize to unguarded exposure widening. This entry codifies the six defensive layers required for any future bypass feature — future authors reaching for "just set `permission_callback = true`" must audit against this list first.

**Decision**

An explicit, operator-visible bypass of an ability's `permission_callback` is permitted ONLY when ALL six defensive layers are present:

1. `manage_options` capability enforced on the toggle's save path (defensively re-verified inside the handler, not only at the router).
2. Per-server nonce (canonical pattern: `acrossai_mcp_manager_<feature>_<server_id>`) bound to the specific server_id — nonce for server A must fail against server B.
3. Persistent warning banner (`<div class="notice notice-warning inline">`) rendered whenever the toggle is currently ON — the operator SEES the security posture on every tab load.
4. Native browser `confirm()` prompt fired on submit-to-ON — client-side friction, not a security control, but a documented UX safety net.
5. Request scope narrowed to a specific MCP server via `CurrentServerHolder` — non-MCP callers (WP admin, non-MCP REST namespaces, WP-CLI) MUST fall through to the original callback unchanged.
6. Ability scope narrowed to abilities exposed via the per-server junction table via `ExposureResolver::resolve()` — abilities not explicitly toggled on the Abilities tab are NOT bypassed even when the override flag is ON.

Under these six layers, hooking `wp_register_ability_args` at a priority higher than every other known callback-injecting filter (F030 uses P999999 to beat sibling `acrossai-abilities-manager`'s P100000 — see `B35` for the slot map) and returning `true` unconditionally in the closure is a legitimate feature pattern.

**Scoped carve-out from `D24` (`DEC-F026-ADVERTISEMENT-VS-CALL-TIME-DEFENSE-IN-DEPTH`) corollary v3.** D24's corollary states: "exposure ≠ authorization — companion filters widening exposure MUST NOT bypass the target ability's own `permission_callback`." D29 does NOT dilute D24 — D24 remains the default rule for every exposure-widening filter. D29 permits ONLY the narrow "operator-visible security-relevant toggle with all six layers" case. Any future proposal that lacks even one of the six layers falls back under D24 and MUST NOT ship.

Reference impl: `includes/Abilities/PermissionOverrideProcessor.php`.

**Tradeoffs**

- **Gained**: Operator escape-hatch for scenarios where per-ability rule authoring is impractical (dev sites, prototype servers, agent-controlled contexts). Operator can grant broad access without editing PHP.
- **Reconsider**: If any future feature proposes a `permission_callback` bypass without ALL six layers — this decision explicitly does NOT generalize. D24's corollary remains the default rule. Reject the proposal and route the requestor to per-ability rule authoring (F017 + sibling `acrossai-abilities-manager`).
- **Related**: `D24` (`DEC-F026-ADVERTISEMENT-VS-CALL-TIME-DEFENSE-IN-DEPTH` corollary v3 — the default rule this carves out); `D30` (`DEC-F030-EXPLICIT-EXPOSURE-ONLY` — companion; narrows layer #6 to explicit-junction-row-only); `D25` (`DEC-F026-WP-REGISTER-ABILITY-ARGS-CALLBACK-SWAP` — same filter hook, different pattern); `B35` (filter-priority slot map for callback-swap consumers); `A17` (request-scoped REST context capture — layer #5's implementation).

**Evidence**

- `includes/Abilities/PermissionOverrideProcessor.php` — reference implementation, all six layers wired.
- `admin/Partials/ServerTabs/AccessControlTab.php` — layers 3 (warning banner) + 4 (confirm prompt) + 6 (junction table via `ExposureResolver`).
- `admin/Partials/Settings.php::handle_save_permission_override()` — layers 1 (cap) + 2 (nonce).
- `docs/security-reviews/2026-07-20-030-per-server-permission-override-plan.md` + `-staged.md` — plan-phase + staged security review both confirm the six-layer posture.
- `specs/030-per-server-permission-override/memory-synthesis.md` §Conflict Warnings — original surfacing of the D24 tension resolved by this entry.

**Where to look next**

Before shipping any new feature that returns `true` from a `permission_callback` (or from any closure wrapping one), audit against the six layers above. If ANY layer is absent, do NOT ship — either add the missing layer or refactor to a non-bypass alternative (per-user-rule authoring, per-role capability check, etc.).

### 2026-07-20 — D30 — F030 intentionally passes empty `$meta` to `ExposureResolver::resolve()` — scoped carve-out from DEC-ABILITY-OVERRIDE-RESOLUTION

**Status**
Active — Feature 030 (`DEC-F030-EXPLICIT-EXPOSURE-ONLY`)

**Why this is durable**

Security-critical bypass features should require explicit operator opt-in per-ability, not inherit implicit visibility from a plugin author's meta declaration. Documents an intentional narrower semantic that would otherwise look like a bug — future maintainers must NOT "fix" the empty-`$meta` call as if it were an oversight.

**Decision**

F030's runtime override closure at `PermissionOverrideProcessor::inject_override()` calls `ExposureResolver::resolve( $server_id, $slug, array() )` with intentionally empty `$meta`. This is a scoped, documented deviation from `DEC-ABILITY-OVERRIDE-RESOLUTION`'s canonical "row exists → row wins; no row → `meta.mcp.public` fallback" contract.

By passing empty meta, F030 collapses the fallback path to `false` — meaning the override applies ONLY to abilities the operator has EXPLICITLY toggled ON in the Abilities tab (junction row exists in `wp_acrossai_mcp_server_abilities`), NOT to abilities that are globally-public via `meta.mcp.public = true` without a per-server junction row.

**Why narrower**: an unconditional `permission_callback → true` should require operator opt-in per-ability, not inherit implicit visibility from a third-party plugin author's meta declaration. The narrower scope keeps the six-layer defensive gating (`D29`) meaningful — layer #6 becomes "operator explicitly toggled this ability ON for this server," not "operator toggled the server ON and this ability happens to be `meta.mcp.public`."

Reference impl: 20-line inline comment at `includes/Abilities/PermissionOverrideProcessor.php:123-142`.

**Tradeoffs**

- **Gained**: Security-critical `permission_callback → true` bypass requires explicit operator opt-in per-ability — cannot inherit implicit visibility. Operator's Abilities-tab toggle is the sole authorization signal.
- **Made harder**: F030's exposure semantic diverges from F017's canonical resolution. Documented via inline comment + this entry so future maintainers don't "fix" it.
- **Reconsider**: Only if a future feature needs bypass semantics that DO respect `meta.mcp.public` — must be a new documented decision, not an in-place change to `PermissionOverrideProcessor`. The correct extension point is a new processor with its own scoped decision entry.
- **Related**: `D29` (six-layer bypass framework — this entry narrows layer #6); `DEC-ABILITY-OVERRIDE-RESOLUTION` (the F017 canonical semantic this carves out); `B32` (filter defaults MUST express plugin's canonical semantic — this entry EXPLICITLY invokes the "except when narrower is safer" clause).

**Evidence**

- `includes/Abilities/PermissionOverrideProcessor.php:107-153` — the closure body + 20-line inline comment explaining the empty-`$meta` decision.
- `includes/Database/MCPServerAbility/ExposureResolver.php:53-76` — the canonical resolver F030 selectively narrows.
- `docs/security-reviews/2026-07-20-030-per-server-permission-override-plan.md` — plan-phase review that first surfaced the tension (as V1 in `/speckit-architecture-guard-architecture-review`).

**Where to look next**

If a future feature needs to consult `ExposureResolver::resolve()` for a similar authorization-adjacent decision, evaluate whether it should pass real meta (canonical) or empty (F030-style narrower). Default: pass real meta unless you have an explicit reason to be narrower + a documented decision entry justifying it.

---

## D31 / DEC-F032-OAUTH-SERVER-ID-FIRST-CLASS

**Status**: Active — Feature 032

**Why this is durable**

Multi-tenant admin surfaces MUST have a first-class tenant column enforced at the SQL layer, NOT reconstructed from parsing tenant IDs out of composite identifier strings. F032 closes a critical cross-server privilege-escalation gap where an admin on Server A's Connectors tab could revoke or delete Server B's OAuth clients and tokens by modifying `client_id` in the outbound REST body — the pre-F032 code trusted a `server-{id}-` prefix on the `client_id` string as the ownership signal, which was not enforced by the schema, not validated by REST handlers, and did not exist at all for DCR-registered clients. Any future admin surface exposing per-tenant data must apply the same first-class-column pattern from day one.

**Decision**

`server_id BIGINT UNSIGNED NOT NULL` is a first-class column on `wp_acrossai_mcp_oauth_clients`, `wp_acrossai_mcp_oauth_tokens`, and `wp_acrossai_mcp_oauth_auth_codes` (added via D28 3-part contract per Table `$version` 1.0.0 → 1.0.1 + `upgrade_to_1_0_1()` callbacks, ordered Tokens → AuthCodes → Clients in `Main::reconcile_database_schemas()` so JOIN backfill can resolve source rows before client-side purge). `UNIQUE(client_id)` is replaced with composite `UNIQUE(client_id, server_id)` on `oauth_clients` so the same DCR connector can be registered on N servers as N independent rows. Every mutating REST endpoint MUST require `server_id` in the request body AND verify the referenced row's `server_id` matches — mismatch returns `WP_Error 'acrossai_mcp_oauth_cross_server'` status 403 (403 not 404 to prevent cross-server existence leak in response body) AND fires `do_action( 'acrossai_mcp_oauth_cross_server_attempted', $client_id, $server_id_requested, $user_id, $timestamp )` immediately BEFORE the `WP_Error` return (4-arg signature per SEC-032-001 remediation — MUST NOT include the actual owning server_id, which would recreate a cross-server oracle for any hooked listener). OAuth flow propagates `server_id` end-to-end: `AuthorizationController` resolves from RFC 8707 `resource` at authorize → `AuthCodeRepository` persists → `TokenController` copies onto emitted token at code-exchange → refresh flow inherits from prior token. `UserLifecycle::on_user_deleted()` STAYS server-neutral — user deletion is site-wide per FR-042 (regression-tested in `PerServerIsolationTest::test_user_deletion_still_cascades_across_all_servers`). DCR endpoint has a two-step URL check: (1) origin verify against `home_url()` per FR-027 / SEC-032-002 remediation (rejects attacker-origin URLs with `WP_Error 'invalid_target' 400` + fires `acrossai_mcp_oauth_dcr_resource_url_origin_mismatch` action); (2) path resolution via `MCPServerQuery`. DCR endpoint also gates against a rare deploy→migration race window with `WP_Error 'service_unavailable' 503` when the `server_id` column is absent per FR-028 / SEC-032-005 remediation. Legacy pre-F032 DCR rows (no `server-{id}-` prefix, `server_id IS NULL` after backfill) are AUTO-PURGED during the upgrade callback (per Q3 clarification, A-aggressive form) — live AI-host sessions disconnect on next request; users re-authorize via standard OAuth flow (README.txt + release-note carry FR-025 mandatory warning). Backfill of admin clients from `server-{id}-` prefix includes an orphan-server guard (parsed server_id MUST exist in `wp_acrossai_mcp_servers`; otherwise row is left NULL and purged alongside legacy DCR rows) per FR-005 amendment / SEC-032-003 remediation. Purge counts fire `do_action( 'acrossai_mcp_oauth_legacy_dcr_purged', $clients_deleted, $tokens_deleted, $auth_codes_deleted )` exactly once per upgrade run. Feature ships unconditionally — no `acrossai_mcp_manager_oauth_per_server_scoping_enabled` feature flag per Q2 clarification; rollback is via composer package downgrade if operationally required.

**Tradeoffs**

- **Gained**: cross-server privilege escalation impossible on all mutating admin endpoints. Read-side "authorized users" cross-server display leak closed. NOT NULL schema invariant enforced at SQL layer. Same DCR connector supported on multiple servers.
- **Made harder**: OAuth data-plane changes require operator awareness — legacy DCR sessions disconnect on upgrade. Cross-Table coordination via registration order in `Main::reconcile_database_schemas()` (documented; a future refactor reordering would break aggregate observability signal counts — flagged in T024 comment block).
- **Reconsider**: If future multi-tenant surface repeats this pattern, promote the aggregate observability coordination into a reusable helper on `Main` (currently OAuth-specific).
- **Related**: `D28` (BerlinDB schema-drift reconciliation — the canonical contract F032 applies three times); `D19` (fail-open observability pattern — F032's three new signals follow it); `B34` (silent write-loss on schema drift — F032 is the fix for OAuth tables); `B37` (companion — see below).

**Evidence**

- `includes/Database/OAuth{Clients,Tokens,AuthCodes}/{Schema,Row,Table,Query}.php` — 12 files with F032 markers.
- `includes/OAuth/{AuthorizationController,TokenController,ClientRegistrationController,ConnectorAdminController,Repositories/*}.php` — 8 files with F032 markers.
- `includes/Main.php::reconcile_database_schemas()` — OAuth Tables ordered Tokens → AuthCodes → Clients per R2.
- `admin/Partials/ServerTabs/AIConnectorsTab.php` — `data-acrossai-server-id` attribute + `get_active_user_ids_by_client_id_and_server_id` call.
- `src/js/ai-connectors.js` — `server_id` in mutating REST body + 403 error message.
- `tests/phpunit/OAuth/PerServerIsolationTest.php` — 10 tests covering all 4 user stories.
- `tests/phpunit/Database/OAuth{Clients,Tokens,AuthCodes}/PerServerColumnUpgradeTest.php` — 3 schema-upgrade regression tests.
- `docs/security-reviews/2026-07-21-032-oauth-per-server-scoping-plan-v2.md` — v2 plan review confirming 4 SEC remediations closed.

**Where to look next**

Any future admin surface that exposes per-tenant data (per-server abilities, per-server tools, per-server settings, per-server audit) MUST apply the same first-class-column pattern from day one. If tempted to encode tenant ownership in a composite identifier string (e.g., `tenant-{id}-{slug}-{rand}`), STOP — that's the exact anti-pattern F032 exists to close. See `B37` below for the generalizable grep-gate pattern.


---

## D32 / DEC-CONNECTOR-APPROVAL-REVOKE-CASCADE

**Scope**: connector admin approval lifecycle → OAuth token lifecycle wiring; §V extensibility contract for opt-out.

**Why this is durable**

Revoking a user's connector approval and leaving their session live is a security foot-gun — the operator 'reject' UI action does not match the semantic effect (user keeps working until token expiry). But some deployments legitimately want the decoupled behaviour (approval = future eligibility, tokens = current session). The right shape is default-secure + filter-based opt-out, not a hardcoded coupling.

**Decision**

`ConnectorAdminController::handle_revoke_user_approval()` fires `do_action( 'acrossai_mcp_connector_user_approval_revoked', int $server_id, string $connector_slug, int $user_id, int $revoked_by )` immediately after the DELETE (idempotent — fires per admin intent, not per row deletion). The default listener `cascade_revoke_tokens_on_approval_revoked` (wired via `$this->loader->add_action(...)` in `Main::define_admin_hooks()` per §A1) enumerates every admin-generated + DCR client matching the connector profile (same shape as `mass_revoke_connector_tokens`), then calls `TokensQuery::revoke_by_user_and_server_and_client_ids()` to mark every matching non-revoked token as `revoked = 1`. Per revoked token fires `acrossai_mcp_manager_oauth_token_revoked` with `reason = 'approval_revoked'` (stable enum for downstream loggers to differentiate this cascade from admin/delete/nuclear paths). Two opt-out mechanisms: (a) `apply_filters( 'acrossai_mcp_connector_revoke_tokens_on_approval_revoked', true, ... )` — return false to skip only the token cascade (approval row still deleted); (b) `remove_action( 'acrossai_mcp_connector_user_approval_revoked', [ ConnectorAdminController::class, 'cascade_revoke_tokens_on_approval_revoked' ], 10 )` — remove the default listener entirely. Third-party plugins can also `add_action()` on the same hook to layer additional side effects (audit log, notification email, etc.) without disabling the default cascade.

**Tradeoffs**

- **Gained**: revoke-approval UI action now matches its intuitive meaning (user is disconnected immediately). Filter-based opt-out preserves §V Extensibility Without Core Modification for projects that want the pre-F032 decoupled behaviour.
- **Made harder**: `handle_revoke_user_approval` docblock must accurately describe the default cascade AND the opt-out mechanisms — misleading docs would surprise both operators (unexpected token revoke) AND third-party plugin authors (surprised the cascade fires at all).
- **Reconsider**: If future features add more approval-lifecycle → session-lifecycle cascades (e.g., 'suspend user' → 'revoke all tokens site-wide'), extract a shared `LifecycleCascadeRegistry` under `includes/Utilities/` — currently the cascade is a single hardcoded listener.
- **Related**: `D19` (fail-open observability — this cascade fires observability actions on both success and opt-out paths); `D31 / DEC-F032-OAUTH-SERVER-ID-FIRST-CLASS` (same feature branch, complementary — approval-scoping is per (server, connector, user), matching the F032 per-server invariant).

**Evidence**

- `includes/OAuth/ConnectorAdminController.php::handle_revoke_user_approval` + `cascade_revoke_tokens_on_approval_revoked` methods.
- `includes/Main.php::define_admin_hooks()` — listener wiring per §A1.
- `includes/Database/OAuthTokens/Query.php::revoke_by_user_and_server_and_client_ids` + `revoke_by_client_id_and_user_id` — per-client loop implementation.

**Where to look next**

Any future admin action whose UI label implies immediate lifecycle change (revoke, suspend, ban, block) MUST evaluate whether existing session/credential state should cascade. If yes: use this pattern (default-secure fire + opt-out filter). If no: docblock the intentional decoupling explicitly to preempt reviewer confusion.

---

## D33 / DEC-OAUTH-AUTHORIZE-AC-GATE

**Scope**: F015 Access Control enforcement extended to OAuth `/authorize` GET handler + CLI device-grant consent + Application Password generation — the three connection-time surfaces.

**Why this is durable**

Pre-F032 AC enforcement fired ONLY at `mcp_adapter_pre_tool_call` (tool-invocation time). A denied user could complete OAuth handshake, obtain tokens, see their AI host report 'connected' — then silently 403 every subsequent tool call. Invisible to operators, confusing to users, wasteful of storage (auth codes + tokens issued to users who can never invoke a tool). AC must gate at CREDENTIAL ISSUANCE time, not just tool-invocation time.

**Decision**

`AcrossAI_MCP_Access_Control::user_has_server_access( int $user_id, int $server_id ): bool` is a new shared helper — called by `AuthorizationController::handle_get()` (OAuth authorize) BEFORE consent renders, by CLI device-grant consent gate BEFORE the device code is issued, and by Application Password generation BEFORE the password is emitted. On `false` return, the caller MUST redirect with `error=access_denied` (OAuth) or emit `WP_Error` (CLI/AppPassword) + fire `do_action( 'acrossai_mcp_access_control_denied', int $user_id, string $server_slug, null $tool, string $context )` where `$context` is `'oauth_authorize'`, `'cli_device_grant'`, or `'application_password'` — operators can differentiate the surfaces via the context arg. Fail-open per D19: `user_has_server_access` returns `true` when the AC package is absent (Composer package not installed), the server row is missing (Q2 race — same race F015 tool-call gate handles), or the AC manager is null (boot failure). v2 vendor hierarchy handles admin bypass internally (admins pass every rule by default).

**Tradeoffs**

- **Gained**: unauthorized users see immediate, clear denial instead of confusing 'connected then silently 403' behaviour. No credentials issued to users who cannot use them. Consistent UX across all three connection-time surfaces (OAuth, CLI, AppPassword) via the shared helper.
- **Made harder**: the AC helper must fail-open on defensive paths (D19) — a hard-fail on AC-missing would break every OAuth flow on installs without the optional AC package. Fail-open MUST NOT be interpreted as 'security bypass' — the OAuth flow still requires WP auth + PKCE + client validation.
- **Reconsider**: If future connection-time surfaces multiply (e.g., MCP-over-WebSocket handshake, gRPC bearer bootstrap), extract the AC gate + observability fire into a `ConnectionTimeGate` helper — currently duplicated at the 3 call sites.
- **Related**: `D18` (mcp_adapter_pre_tool_call — the tool-invocation-time enforcement site; this decision is its consent-time complement); `D19` (fail-open observability); `DEC-ACCESS-CONTROL-V2-ADOPTION` (F015 canonical wrapper — this decision reuses its manager surface).

**Evidence**

- `includes/AccessControl/AcrossAI_MCP_Access_Control.php::user_has_server_access` — shared helper.
- `includes/OAuth/AuthorizationController.php::handle_get` — OAuth authorize call site with `context = 'oauth_authorize'`.
- `includes/Main.php` — no new hook wiring (helper is called directly from consent code paths, not filter-driven).

**Where to look next**

Any future connection-time credential-issuance surface (WebSocket handshake, new token grant type, service-account impersonation) MUST call `user_has_server_access` BEFORE issuing credentials, MUST fire `acrossai_mcp_access_control_denied` with a distinct `$context` string on denial, and MUST fail-open when the AC package is absent per D19.

---

## D34 / DEC-CROSS-SERVER-NUCLEAR-REVOKE-CARVE-OUT

**Scope**: `POST /wp-json/acrossai-mcp-manager/v1/oauth/revoke-client-tokens-all-servers` (FR-043) — deliberate carve-out from the F032 per-server invariant (D31).

**Why this is durable**

D31 (F032 core) requires every mutating OAuth admin endpoint to validate `server_id` and 403 on mismatch. That's the right default. But operators occasionally need site-wide response to a compromised `client_id` (e.g., a leaked Claude.ai token spreading via phishing). Requiring them to visit each server tab individually is unacceptable emergency-response latency. This endpoint is the sanctioned exception.

**Decision**

`ConnectorAdminController::handle_revoke_client_tokens_all_servers()` accepts ONLY `client_id` in the request body — NO `server_id` — and calls `TokensQuery::revoke_by_client_id_across_all_servers( string $client_id )` to revoke every non-revoked token for that client_id site-wide. It fires `do_action( 'acrossai_mcp_oauth_client_revoked_across_all_servers', string $client_id, int $revoked_token_count, int $user_id, int $timestamp )` exactly once per admin action. CRITICAL invariant: this endpoint MUST NOT fire `acrossai_mcp_oauth_cross_server_attempted` — that action (FR-023) is reserved for actual bypass ATTEMPTS by a caller who submitted a mismatched server_id. This endpoint is operator-intentional cross-server operation, not a bypass attempt; conflating the two observability streams would poison forensic logs with false-positive 'attempted bypass' records that are actually legitimate site-wide operator actions. The docblock on the handler cites this exception explicitly; the two `do_action` calls are mutually exclusive by static-code analysis.

**Tradeoffs**

- **Gained**: one-click site-wide emergency response for compromised client_ids. Clean observability streams — `acrossai_mcp_oauth_cross_server_attempted` remains a high-signal forensic marker (real bypass attempts only), `acrossai_mcp_oauth_client_revoked_across_all_servers` is the operator-intent marker.
- **Made harder**: any future refactor that unifies the two admin revoke code paths MUST preserve the observability-action separation. Docblock + inline comment + this DECISIONS entry all cite the invariant explicitly.
- **Reconsider**: If ops feedback surfaces need for a scoped variant (e.g., 'revoke across all servers in this network' for multisite), add it as a separate endpoint with a distinct observability action — do NOT overload this one.
- **Related**: `D31` (F032 per-server invariant this deliberately carves out from); `D19` (fail-open observability — this endpoint's single observability action follows the pattern).

**Evidence**

- `includes/OAuth/ConnectorAdminController.php::handle_revoke_client_tokens_all_servers` — endpoint with docblocked D31 carve-out.
- `includes/Database/OAuthTokens/Query.php::revoke_by_client_id_across_all_servers` — server-neutral bulk revoke.
- `admin/Partials/ServerTabs/AIConnectorsTab.php::render_connections_panel` — "Revoke from all servers" button UI.

**Where to look next**

Any future operator-invoked cross-server admin action (bulk delete, bulk suspend, aggregated report) MUST be its own endpoint with its own observability action distinct from the bypass-attempt actions. If tempted to reuse a bypass-attempt observability action for a legitimate site-wide op, STOP — that pollutes forensic streams.

---

### 2026-07-25 — D35 — Self-contained subsystem contract: abstract base owns metadata + enumeration; Renderers are consumers only (F034)

**Status**
Active

**Why this is durable**

Any subsystem with (a) an abstract base + concrete subclasses contributed via a filter AND (b) per-subclass display metadata will drift into "metadata orphaned in the Renderer" the moment a third-party subclass gets contributed — because Renderers cannot know metadata for classes they don't own. F034 fixed this specific drift for the MCP client subsystem; captured as a pattern for every future subsystem with the same shape.

**Decision**

Every subsystem matching the two criteria above MUST:

1. Declare all display metadata as **method-with-default overrides on the abstract base** — not as a private const on any Renderer, admin partial, or list-table. Reference: F034 added `get_icon`, `get_description`, `get_config_file`, `get_top_level_key`, `get_instructions`, `get_priority` to `AbstractMCPClient` with empty-string / 100 defaults per FR-002.
2. Expose enumeration via a **single canonical static method on the abstract** that fires the extension filter with a class-level default seed, validates FQNs (`is_string` + `class_exists` + `is_subclass_of`), validates slugs (`/^[a-z0-9-]{1,64}$/`), dedups by slug with `_doing_it_wrong` under `WP_DEBUG`, and sorts deterministically. Reference: `AbstractMCPClient::get_all_registered_clients()` (F034) mirrors `ConnectorProfileRegistry::get_profiles()` (F021).
3. Renderers, admin partials, and any other consumer of the subsystem MUST call the canonical enumeration method and read metadata via the abstract's method calls — NEVER re-implement the filter loop, NEVER hardcode a subclass FQN list, NEVER carry a metadata const keyed by slug.

**Tradeoffs**

- **Gained**: third-party contributions become first-class citizens (no orphaned metadata); one canonical enumeration path per subsystem eliminates drift bugs of the B32 class; adding new subclass fields = one method on the abstract, one override per concrete = no PR churn to display layers.
- **Made harder**: every new subsystem field (icon / description / priority / whatever) requires ALL concrete subclasses to add the override to preserve current UI — but this is enforced at the sensible layer (the class that owns the identity), not scattered across display consumers.
- **Reconsider**: If a future subsystem has metadata that legitimately depends on runtime context (server_id, user role, request path), the abstract's parameter-less getter shape is insufficient. In that case, pass the context to the getter (`get_icon( int $server_id ): string`) rather than reverting to a Renderer-side lookup.

**Related**

- Reference implementation (sibling pattern): `includes/Connectors/AbstractConnectorProfile.php` + `includes/Connectors/ConnectorProfileRegistry.php` (F021).
- Retroactive application: F034 for `AbstractMCPClient` + `MCPClientsBlock`.
- B32 (canonical filter-default): F034 IS the direct application of B32 to the MCP client subsystem.
- Future F036 (planned public discovery API `ConnectionMethodRegistry` under `public/Discovery/`) depends on this decision — it delegates to both subsystem registries instead of re-implementing.

**Evidence**

- `includes/MCPClients/AbstractMCPClient.php` — post-F034 abstract with 6 metadata methods + `DEFAULT_CLIENT_CLASSES` const + `get_all_registered_clients()` static method.
- `includes/MCPClients/{ClaudeDesktop,ClaudeCode,VSCode,GitHubCopilot,Codex,Cursor,Gemini,Custom}Client.php` — 8 concrete classes with 6 method overrides each.
- `public/Renderers/MCPClientsBlock.php` — `render_body()` is 6 lines (was 32 pre-F034); no `CLIENT_META` const; no inline filter loop.
- `tests/phpunit/MCPClients/GetAllRegisteredClientsTest.php` — canonical enumeration tests.
- `tests/phpunit/MCPClients/ConcreteClientMetadataTest.php` — data-provider parameterized metadata assertions across 8 built-ins.
- `specs/034-mcp-client-metadata-refactor/` — the full feature dossier including memory-synthesis reasoning.

**Where to look next**

Any new subsystem that adds an abstract base + `apply_filters('acrossai_mcp_*_classes', ...)` extension seam. Before shipping, audit: are all display fields on the abstract? Is enumeration in ONE canonical method? Do consumers delegate rather than re-implement? If any answer is no, apply this pattern before merge.

---

### 2026-07-27 — D36 — Public `@experimental` API classes MUST be `final class` — extension via filter, never subclass (F035)

**Status**

Active

**Why this is durable**

Public `public/` classes marked `@experimental until plugin 1.0.0` per DEC-CLIENT-RENDERER-PUBLIC-API face two competing extension pressures: (a) developers wanting to subclass to override behaviour, (b) plugin author wanting to freeze the contract shape at 1.0.0 with minimum churn. F035 codifies the resolution and generalizes it as a policy for every future `public/` `@experimental` class: **`final class` + filter-only extension seams**. The class ships with N filter surfaces (F035 has 4: two of its own — `acrossai_mcp_npm_methods`, `acrossai_mcp_connection_methods` — plus two inherited via delegation — `acrossai_mcp_client_classes`, `acrossai_mcp_manager_connector_profiles`); every legitimate extension use case flows through these.

**Decision / Finding**

Any new `public/` class marked `@experimental until plugin 1.0.0` MUST be declared `final class`. Extension happens via WordPress filters (per constitution §V), never via subclass. Rationale — three failure modes prevented:

1. **Singleton state fragmentation**: A subclass `SubX::instance()` would return a different instance than base `X::instance()`, because `protected static ?self $_instance = null` on the base only holds the base instance. Two consumers reading "the registry" would see two different memoization caches. Third-party filter callbacks would fire twice per request (once per instance's per-request enumeration).
2. **Delegation invariant defeat**: A subclass could override a delegation method (e.g., F035's `get_clients()` that delegates to `AbstractMCPClient::get_all_registered_clients()`) and re-fire the underlying filter (`acrossai_mcp_client_classes`) directly. Source-time grep gates (SC-005 style in F035) would still pass (they scan the class file itself), but the runtime contract — "underlying filter fires exactly once" — would be silently broken. Double-invoke of third-party callbacks; side effects fired twice.
3. **`@experimental` shape drift**: A subclass could add public methods or change return shapes in ways downstream JSON-round-trip consumers never anticipated. Freezing a `final class` at 1.0.0 requires only auditing the base class's own surface. Freezing an open class requires auditing every possible subclass in every companion plugin.

Third-party plugins needing a different discovery-API shape use **composition** (own class in own namespace calling the singleton) not inheritance. Concrete pattern: `\BuddyBoss\MCP\CustomDiscoveryRegistry` internally calls `ConnectionMethodRegistry::instance()->get_all()` and reshapes the result. Same shape as F034's `MCPClientsBlock` — consumes canonical enumeration, doesn't extend the abstract.

**Enforcement**: source-level `final class X` keyword. PHP fatals at class-load time with "Class Y cannot extend final class X" if anyone attempts to subclass. Zero runtime cost.

**Reversibility**: `final` is dropped later? Non-breaking (adds subclass support). `final` added later to a formerly-open class? BREAKING (existing subclasses fatal at class-load). Err on the side of shipping `final` — subtractive relaxation is safe; additive tightening isn't.

**Reference implementation**

- `public/Discovery/ConnectionMethodRegistry.php:44` — `final class ConnectionMethodRegistry`
- Documented extension paths: 4 filter seams cited in class-level docblock + `docs/planings-tasks/036-connection-method-discovery-api.md` §Extension paths

**Tradeoffs / Prevention**

- Gained: memoization state integrity, source-time grep-gate runtime enforcement, cleaner 1.0.0 freeze, defensive against subclass-induced double-filter-fire bugs
- Reconsider: only if a real subclass use case emerges post-1.0.0 that filters + composition genuinely cannot satisfy. Requires explicit `/speckit-clarify` gate + spec amendment + probably a constitution §V annotation for the `@experimental` public/ layer specifically
- Related: [[dec-client-renderer-public-api]] (public/ + @experimental policy — F013 base), [[d35]] / [[dec-f034-self-contained-subsystem-contract]] (canonical enumeration delegation — the invariant `final` protects at runtime), constitution §V (extension via filter — the "instead of subclass" alternative this decision points consumers toward), [[b32]] (canonical resolver defence — subclass override of a delegation method would defeat B32's principle at runtime)

**Where to look next**

Any new `public/` class marked `@experimental until plugin 1.0.0`. Before shipping, audit: is the class declared `final`? Does the class docblock cite the extension paths (filter names) that replace subclassing? Does the plugin's public documentation (e.g., quickstart.md) tell third-party developers "extend via filter, use composition for shape divergence"? If any answer is no, apply this pattern before merge.

---

### 2026-07-27 — D37 — Admin UIs are React-first; hand-rolled forms + vanilla JS forbidden as a React substitute (F037)

**Status**

Active

**Why this is durable**

The plugin has three sanctioned patterns for admin UI state management, each with a specific fit:

1. **React + REST** — for interactive multi-field surfaces (canonical: F017 Abilities, F020 Tools, F037 Embeds — all under `/acrossai-mcp-manager/v1/servers/{server_id}/{resource}` URL shape)
2. **Vanilla WP admin PHP + core JS + wp-ajax** — RARE fallback for surfaces where React is genuinely inappropriate (activation notices, WP-CLI dashboards, embedded jQuery UI widgets from third-party plugins)
3. **DEV5 hand-rolled admin forms** — read-only or single-submit surfaces only (F013 Update Server, F013 Danger Zone, F030 Access Control override toggle)

The **failure mode this decision prevents**: attempting to use pattern 3 (DEV5 hand-rolled form) for a pattern-1 use case (interactive multi-field), then bolting on vanilla JS to fix the interactivity gap. F037 shipped this exact anti-pattern in its initial implementation and had to pivot mid-flight — the hand-rolled form couldn't sync sub-toggle disabled state in real-time without JS, and the vanilla JS bolt-on was inconsistent with the F017/F020 React pattern that already existed for sibling per-server admin surfaces. Once the pivot happened, the correct pattern was ~250 lines of React (mirroring F017) instead of ~400 lines of PHP form + inline JS + custom nonce plumbing + custom save handler in `Settings::handle_actions()`. **The vanilla-JS-in-hand-rolled-form pattern loses on every axis vs React + REST** for interactive multi-field surfaces: more code, more security surface (custom nonce vs WP core `wp_rest`), less testable, no shared UX primitives, harder for future maintainers reading a sibling F017 tab.

**Decision / Finding**

Any new admin UI with **interactive multi-field state** MUST use pattern 1 (React + REST). Concretely:

- **REST controller** under `includes/REST/` following F017/F020 shape: singleton, `private __construct()` (S6), `register_routes()` on `rest_api_init` (wired via Loader per A1), explicit `permission_callback` (S2), route path `/acrossai-mcp-manager/v1/servers/{server_id}/{resource}`.
- **React app** under `src/js/{feature}.js` following F017 abilities.js shape: uses ONLY sanctioned `@wordpress/*` packages per DEC-WP-DATAVIEWS-OVER-REACT (element, api-fetch, i18n, components, dataviews when data-grid, hooks); `apiFetch.createNonceMiddleware` only per B25 (NEVER `createRootURLMiddleware`); reads initial state from `window.acrossaiMcp{Feature}` bootstrap set via `wp_localize_script` for zero-round-trip first render.
- **Enqueue** via a `admin/Main.php::maybe_enqueue_{feature}_app()` method mirroring `maybe_enqueue_abilities_app()` verbatim: `?action=edit` + `?tab={slug}` guard, asset manifest read (silent bail on missing), script + optional CSS enqueue, `wp_localize_script` bootstrap.
- **SCSS** at `src/scss/{feature}.scss` imported by the React entry; mini-css-extract emits `build/js/{feature}.css`.
- **Tab body** renders `<div id="acrossai-mcp-{feature}-root"></div>` + `<noscript>` fallback showing read-only current state.

If React is **genuinely inappropriate** — e.g., surface is activation-time only (no admin JS enqueued), a jQuery UI widget from a vendor plugin, or a WP-CLI dashboard widget that ships with WP core patterns — fall back to pattern 2 (vanilla WP admin PHP + core JS + wp-ajax). Never invent hand-rolled forms + inline vanilla JS as a substitute for React interactivity — this pattern doesn't scale, breaks visual consistency with F017/F020/F037 sibling tabs, and forces divergent nonce + save patterns.

**DEV5 (hand-rolled admin form exception)** is NARROWED going forward: applies to read-only rendering + single-submit surfaces with no client-side interactivity requirement. Interactive multi-field surfaces fall OUT of DEV5 into D37 scope. F037 was DEV5's 4th consumer pre-pivot; retracted post-pivot; DEV5 consumer count returns to 3 (Update Server, Danger Zone, Access Control override — all single-submit forms with no cross-field JS interactivity). D13 escalation candidate for DEV5 is withdrawn.

**Enforcement**: `plan.md` Constitution Check §IV MUST cite this decision when adding any new admin UI. If a plan claims DEV5 exemption for an interactive multi-field surface, `/speckit-analyze` MUST flag as a D37 violation. If a plan proposes wp-ajax (pattern 2), it MUST justify in its Constitution Check §IV why React (pattern 1) is inappropriate.

**Reference impls**:

- `includes/REST/AbilitiesController.php` + `src/js/abilities.js` (F017 — the canonical dataviews-grid case)
- `includes/REST/ToolsController.php` + `src/js/tools.js` (F020 — the canonical shuttle-picker case)
- `includes/REST/EmbedsController.php` + `src/js/embeds.js` (F037 — the canonical simple-toggles case)

**Tradeoffs / Prevention**

- Gained: consistent WP-idiomatic admin UX across every interactive tab; WP core `wp_rest` nonce (obsoletes custom server-scoped nonce plumbing); testable REST layer; shared React component vocabulary (`ToggleControl`, `Button`, `Notice`, `Spinner`); path to Phase-2 block-editor blocks (Gutenberg uses the same package family)
- Reconsider: only if `@wordpress/components` shape changes materially post-1.0.0 (unlikely for stable primitives like `ToggleControl`) OR if a future WP-core convention obsoletes the pattern (e.g., a block-editor-first admin UI paradigm)
- Related: [[dec-wp-dataviews-over-react]] (parent decision — package allow-list; D37 strengthens it by adding the anti-pattern prohibition), [[dev5]] (narrowed scope), [[b25]] (apiFetch middleware discipline that D37 rules require), [[a1]] (Loader-wired REST + enqueue registration), [[s2]] (REST permission_callback mandate — D37 pattern satisfies by construction), [[dec-client-renderer-public-api]] (public/ layer @experimental policy — orthogonal but same F013 vintage)

**Where to look next**

Any new feature adding an admin UI. Before shipping, verify against the `plan.md` Constitution Check §IV clause: does this feature have interactive multi-field state? If yes → pattern 1 (React + REST). If no → pattern 2 or pattern 3 with explicit justification. If the plan proposes pattern 3 (DEV5 hand-rolled form), verify the form is truly single-submit + no client-side interactivity requirement; else `/speckit-analyze` should flag as D37 violation.

---

### 2026-07-28 - D38 — Reusable-primitive `register()` exception to A1

**Status**
Active

**Why this is durable**
A1 says "only `includes/Main.php` calls `add_action`/`add_filter`." F037's AbstractReactMountServerTab is a reusable base for third-party companion plugins; enforcing A1 on it would require every consumer to reimplement asset enqueue + REST wiring in their own Main.php. Codifies when the A1 rule may be relaxed.

**Decision / Finding**
An abstract base class positioned as an extension surface for third-party plugins MAY call `add_action` / `add_filter` inside an idempotent public `register()` entry point. The consuming plugin's own boot code invokes `register()`. Each plugin's own boot code still owns its hook registration — A1 holds at every plugin level. The base class does NOT self-register at file-inclusion or class-load time.

Requirements for a class to invoke this exception:
1. `abstract class` positioned as third-party extension surface (documented in class-level docblock)
2. Public static `register()` entry point — idempotent per subclass (guard against double-invocation)
3. No self-registration on file load or class instantiation
4. Consumer contract: guard with `class_exists()` in the consumer's own `plugins_loaded` handler

**Tradeoffs / Prevention**
- Gained: Third-party plugins get a one-liner `MyTab::register()` extension surface instead of hand-rolling enqueue + REST wiring
- Reconsider: If a third-party author calls `register()` from a wrong hook (e.g., after `admin_enqueue_scripts` has already fired), the wiring is a no-op silently; document the correct call site (`plugins_loaded` or earlier). This is the tradeoff for the ergonomic single-entry-point pattern.

Canonical example: `admin/Partials/ServerTabs/AbstractReactMountServerTab::register()` shipped in F037 Pivot C (commit `8d55d21`).

Related: `DEV5` tab-hand-rolled-form exception (§IV DataForm); `DEC-CLIENT-RENDERER-PUBLIC-API` `public/` layer stability contract.

---

### 2026-07-28 - D39 — Per-listener isolation for observability actions

**Status**
Active

**Why this is durable**
Native WordPress `do_action()` re-raises the first thrown exception from a listener, silently skipping subsequent listeners on the same hook. Wrapping `do_action()` in a caller-side try/catch protects the DB write but NOT other listeners. When multiple audit-log consumers subscribe to the same observability action (common), a bug in one silently masks the others. The correct pattern is per-listener isolation.

**Decision / Finding**
Plugin observability actions with a fail-forward requirement MUST use a per-listener isolation helper instead of native `do_action()`. Canonical implementation: `AbstractEmbedTransport::fire_action_isolated( string $hook, ...$args )` — iterates `$wp_filter[$hook]->callbacks` in priority order and wraps each `call_user_func_array` in try/catch.

Consumers pass the same args they'd pass to `do_action()`. Helper is a no-op when no listeners registered.

**Tradeoffs / Prevention**
- Gained: One broken listener MUST NOT abort subsequent listeners on the same hook OR roll back the DB write (full R3 compliance per F015/F017/F020/F030/F032 pattern)
- Reconsider: Exception messages land in the PHP error log (SEC-007 disclosure trade-off — listener authors MUST NOT put sensitive data in exception messages)
- Reconsider: Helper does NOT respect `accepted_args` (extra args passed to listeners expecting fewer — PHP silently discards, but subtle drift from `do_action` semantics). Future refinement: slice `$args` per callback's `accepted_args`.
- Tech debt: F015/F017/F020/F030/F032 audit fires still use native `do_action()` inside try/catch — SC-010 "N-listener isolation" only fully satisfied post-retrofit. Track as follow-up per-feature.

Canonical example: `includes/Embeds/AbstractEmbedTransport::fire_action_isolated()` shipped in F037 RT2 (commit `0c8c5f8`).

Related: research.md R3 (observability-fail-forward); future `B-ERROR-LOG-DISCLOSURE` bug-pattern entry candidate (SEC-007).


---

### 2026-07-29 - D40 — User-scoped enumeration primitives compose existing gates — never re-implement them

**Status**
Active

**Why this is durable**
F038 introduces the first "list what the current user can reach" primitive on the frontend surface. Two competing implementation shapes appeared during planning: (a) compose the shipped F015 + F037 gate cascade end-to-end (`user_has_server_access` + `is_enabled_for_server`), or (b) inline the underlying meta reads for "performance". The inline approach creates two enumeration paths per subsystem that WILL drift — the exact class of bug F035 fixed for MCP clients (D35, B32). F038 codified the composition-only rule and enforces it via three grep-gates (FR-023, FR-024, FR-025) at review time.

**Decision / Finding**
When a subsystem exposes a "list what the current user can reach" primitive, it MUST compose the shipping gate stack — never re-read the underlying meta rows or re-check the wpb-access-control provider list itself. This preserves:

- The **fail-open contract** (Q2 / D19) — F015 wrapper returns `true` when the vendor package is absent; F038 inherits transitively.
- The **R2 per-request memoization** — F037's `is_enabled_for_server` caches per `(server_id, transport_key, dto_slug)` triple; direct meta reads bypass it.
- The **admin-bypass hierarchy** — F015 v2 vendor manager applies admin bypass internally; direct table reads miss it.

F038 also codified a second sub-rule: the abstract data-only base MAY live under `public/Renderers/` (paralleling `AbstractClientRenderer`) despite D36's `final class` guidance for `@experimental public` classes. Rationale: the base IS the extension surface for companion plugins; `final abstract` is a language contradiction. D36's target (delegation-invariant defeat) does NOT apply because F038 IS the gate-application layer, not a gate-enforcer other features must hit.

**Tradeoffs / Prevention**
- Gained: Single canonical enumeration path per subsystem — grep-gate-enforceable at review time, immune to the B32 drift class.
- Gained: Companion plugins (BuddyBoss add-on, WooCommerce My Account, WPUM, MemberPress) inherit fail-open + memoization + admin-bypass without knowing they exist.
- Reconsider: A companion-plugin author who subclasses the base can override `get_accessible_servers()` and skip the gate cascade for their context. That is the base's design (extension surface); documented as consumer responsibility.
- Reconsider (SEC-001): Consumers passing an arbitrary `$target_user_id` (BuddyPress-style profile widgets) are responsible for their own caller-authority gate. F038 evaluates the F015 gate FOR the target user, not against the calling viewer's authority. Documented in class docblock + contract file.

Canonical example: `public/Renderers/UserServers/AbstractUserServersRenderer::get_accessible_servers()` shipped in F038.

Related: D35 (self-contained subsystem contract), D36 (final class for public @experimental — precedent-based deviation documented here), DEC-ACCESS-CONTROL-V2-ADOPTION (F015 wrapper contract), DEC-CLIENT-RENDERER-PUBLIC-API (public/ layer stability), B32 (canonical resolver rule).


---


### 2026-08-02 — D41 / DEC-SERVER-TAB-REGISTRY-DEDUP-LAST-WINS — Registry dedup semantic inverted first-wins → last-wins

**Status**
Active

**Why this is durable**
Establishes the "built-in placeholder → filter-contributor override" pattern for any subsystem-becomes-add-on migration in this plugin. Any future extraction (Abilities → separate plugin, Tools → separate plugin, etc.) can now ship a placeholder built-in tab in `Registry::all_tabs()` and let the extracted plugin's filter callback replace it via `acrossai_mcp_manager_server_tabs`. Coexistence is a framework property, not per-consumer hack.

**Decision**
`admin/Partials/ServerTabs/Registry.php::normalize_entries()` now indexes the accumulator by slug so later assignments overwrite earlier ones (`$normalized[$slug] = ...` + `return array_values($normalized)`). The pre-F040 `_doing_it_wrong( 'duplicate slug ... first registration wins' )` warning is deleted. This matches WordPress-native filter override semantics (higher priority = runs later = wins) and aligns with F034 `ClientsRegistry::get_all_registered_clients()` which already uses last-wins.

**Tradeoffs / Prevention**
- Gained: symmetric with WP-native filter model; symmetric with F034; enables the placeholder → override pattern without special-cased consumer logic. First application: `AIConnectorsPromoTab` (built-in) → companion's `AIConnectorsTab` (filter) — same slug `ai-connectors`, same priority 35; companion's filter runs later and replaces the built-in when active.
- Made harder: third-party plugins can now legitimately override built-in tabs via filter priority. This is intended, not accidental. New RegistryTest case `test_filter_last_registration_replaces_earlier_same_slug` pins the semantic against regression.
- Reconsider: if the built-in list ever needs an "un-overrideable core tab" (e.g., a Danger Zone that MUST always render), promote that specific tab's entry with an `_immutable => true` marker and re-add a skip-on-seen branch scoped to that flag.

**Related**
- F034 `ClientsRegistry` (same pattern, older — established the precedent this decision generalizes).
- F040 `AIConnectorsPromoTab` (first consumer of the placeholder → override pattern, 2026-08-01).
- `DEC-CONNECTOR-PROFILE-*` (superseded by F040 — the OAuth registry patterns this replaces).


---


### 2026-08-21 — D42 / DEC-SUBMENU-URL-LITERAL-NO-CALLBACK — Submenu items may deep-link via URL-literal `menu_slug`

**Status**
Active

**Why this is durable**
Every future wizard, dashboard, tool, or action-launcher that needs its own AcrossAI-menu entry pointing at an EXISTING page + query args will otherwise duplicate a full render callback or fabricate a fake page slug and route through a redirect. Non-obvious WP `admin_menu` behaviour (undocumented in the wp.org docs, discovered by reading core's `admin.php` dispatcher) — reviewers will ask "where's the render callback?" without this pin. First application: F072 Quick Setup submenu.

**Decision**
`add_submenu_page( $parent, $title, $label, $cap, $menu_slug, $callback = '', $position = null )` accepts a URL-like string as `$menu_slug` — WP core's `admin.php` inspects the slug and, if it looks like an admin URL, renders the menu item as a direct link rather than routing through a callback page. This lets a submenu entry deep-link into an existing page with `?…&quick-setup=1&step=1&…` without inventing a new page.

Requirements when using this pattern:
- Menu slug MUST be a full `admin.php?page=…` string (or an absolute admin URL); relative paths won't route.
- Render callback MUST be an empty string `''` (NOT a closure returning nothing — WP still tries to dispatch on non-empty callables).
- Capability check on the linked page still enforces access — the URL-literal item bypasses no security.
- Position may still be passed as the 7th arg for ordering under the parent.
- Add a one-line comment at the call site so a future maintainer doesn't mistake the empty `''` callback for a broken registration.

Reference impl: `admin/Partials/Menu.php::register_submenu()` shipped in F072 — adds a "Quick Setup" submenu at position 3 linking to `admin.php?page=acrossai_mcp_manager&quick-setup=1&step=1`.

**Tradeoffs**
- Gained: zero-callback dashboard/wizard entries; no throwaway "landing" pages; no cost of a full sub-page render just to link to another URL.
- Made harder: linter/IDE won't flag a typo'd URL in the slug; grep for the submenu render callback returns empty (a future maintainer might mistake this for a broken registration). Mitigation: one-line comment above the call — "URL-literal slug → linked page renders it".
- Reconsider: if the target page needs its own dedicated URL (bookmarkable, permalink-stable), promote it to a real page with a callback. If the destination will become plugin-agnostic (moves to a companion plugin), route through a filter instead of a URL string.

**Related**
- F072 planning doc `docs/planings-tasks/072-quick-setup-entry-points.md` — application in wizard entry points.
- A1 hook-registration rule — Menu::register_submenu() is still wired via Includes\Main::define_admin_hooks(); the URL-literal is inside the singleton, not a new hook.


---


### 2026-08-21 — D43 / DEC-CROSS-SURFACE-PARITY-UNIFY-AT-DATA-LAYER — Two renderers, one DTO producer

**Status**
Active

**Why this is durable**
When a PHP-rendered admin surface and a React admin surface must show the same domain object (client config, connector card, ability row, etc.), the tempting fix is to hand-port the PHP renderer into React — silently drifts the moment either side adds a field. Codifying "share the DATA, not the component" up front prevents the re-implementation trap and the resulting drift bugs. Companion to D35 (F034 self-contained subsystem contract — one canonical enumeration for subclass discovery) — D43 covers the complementary "two renderers, one DTO" case D35 doesn't.

**Decision**
Two renderers (PHP admin, React admin, WP-CLI table, REST response, etc.) that must show the same domain object MUST share a single DTO producer (registry method, resolver, model accessor) — NEVER share a component. When one renderer already exists and a new one is added, extend the DTO with the fields the new renderer needs (optional params / additive fields); do NOT re-derive them in the new renderer.

Requirements:
- Encoding decisions (JSON flags, date formats, escaping) MUST live in the DTO producer, not per-renderer — otherwise byte-identity is impossible.
- New DTO fields must be additive (present-or-absent), never rename or remove existing keys — protects consumers of the shared producer.
- Server-scoped or context-scoped enrichment must be opt-in via param (e.g. `get_clients( ?array $server = null )`) — memoized generic consumers stay byte-stable.
- Translation keys for shared user-facing strings MUST be identical across renderers — translators shouldn't key the same sentence twice.

Reference impl: F073 `public/Discovery/ConnectionMethodRegistry::get_clients( ?array $server = null )` shipped as one DTO producer consumed by both:
- Per-server-edit Clients tab (PHP, `public/Renderers/MCPClientsBlock.php` — reads `meta.config_file`, `meta.top_level_key`, computes snippet inline).
- Quick Setup wizard Step 11 (React, `src/js/quick-setup/steps/Step11_ClientDetail.jsx` — reads `meta.config_file`, `meta.top_level_key`, `config`, `instructions` from the DTO).

Same `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES` encoding on both sides; shared Access Control paragraph uses one `__()` translation key.

**Tradeoffs**
- Gained: byte-identity impossible to break by editing one renderer only; new fields land in both surfaces on the same commit; new subclasses (F034 extensibility) auto-appear everywhere; no drift bug class between PHP and React admin surfaces.
- Made harder: DTO producer grows optional params over time. Mitigation: split into a dedicated method per broadly-different shape (e.g. `get_clients_admin()` vs `get_clients_public()`) once the optional-param list exceeds ~3.
- Reconsider: when the two renderers legitimately need to diverge (e.g. one strips secrets, one adds them), split the producer into two callers and cite this DEC in both call sites so the drift is intentional and reviewable.

**Related**
- D35 / DEC-F034-SELF-CONTAINED-SUBSYSTEM-CONTRACT — the sibling rule for subclass ENUMERATION; D43 is the same "one canonical source" instinct applied to DTO SHAPE + ENCODING.
- D36 / DEC-F035-PUBLIC-API-FINAL-CLASS-FILTER-ONLY-EXTENSION — reinforces that the DTO producer is a public / stable surface; contracts (final class + additive-only DTO) apply.
- B32 — "filter defaults must be the canonical resolver's output" is the runtime-behaviour equivalent of D43's structural rule.
- F073 planning doc `docs/planings-tasks/073-wizard-client-snippet-parity.md`.


---


### 2026-08-21 - D44 / DEC-WP-OPTION-DEFAULT-VIA-ACTIVATION-SEED

**Status**
Active

**Why this is durable**
Every future wp_option default flip in this plugin (fresh installs get X instead of Y) will face the same silent-failure trap. `register_setting( … default => X )` metadata alone does NOT change runtime behaviour when the caller passes an explicit second arg to `get_option( key, <fallback> )` — WP core's `filter_default_option` callback checks `$passed_default` and returns the CALLER'S fallback (bypassing the registered default). Every read site in this plugin passes an explicit fallback, so flipping just the register_setting default produces green PHPCS, green tests, and zero runtime change. Silent bug.

**Decision**
To change the runtime default of an existing wp_option-backed plugin setting for fresh installs, add `add_option( 'key', <new default> )` in `Activator::activate()` instead of flipping `register_setting()`'s metadata and every `get_option()` fallback. `add_option()` is idempotent (WP core: "fails silently if the option already exists"), so operators with an explicitly-saved value keep their choice — only sites with no stored row pick up the new default.

Requirements:
- Value type must serialize losslessly through wp_options — use `1`/`0` for booleans (matches how WP admin stores checkbox settings), not `true`/`false` (round-trips as `'1'`/`''`).
- Leave `register_setting()`'s `default` metadata as-is — the row is always present after activation, so metadata is only read by REST schema consumers (cosmetic).
- Leave all `get_option( key, <fallback> )` fallbacks as-is — they never fire post-activation.
- Any PHPUnit assertion on the register_setting metadata default continues to hold — no test edits needed.

Trade-off: if a site loses the option row post-activation (`wp option delete`, cache-nuke plugin, DB sweep), `get_option()` returns the fallback (old default) until the plugin is reactivated. Acceptable for a plugin-wide singleton toggle; not acceptable for a security-critical default.

Reference impl: `includes/Activator.php` — `add_option( 'acrossai_mcp_npm_login_enabled', 1 )` for the npm/CLI login flow default flip. Branch `chore/npm-cli-default-enabled-on-activation`.

**Tradeoffs**
- Gained: 1-file change instead of touching SettingsMenu + Notices + FrontendAuth + SettingsMenuTest. Zero test churn. Operator choices preserved via add_option idempotency. No migration code.
- Made harder: register_setting metadata and runtime behaviour diverge — a reader has to look at Activator to see the actual default. Add a `// default seeded in Activator::activate()` comment near the register_setting call if the setting becomes non-trivial to reason about.
- Reconsider: switch to full "flip register_setting default + update every get_option fallback" when (a) the setting is security-critical and must survive row deletion, (b) downstream REST schema consumers rely on truthful metadata default, OR (c) an install base is being updated in-place where activation may not fire on WP `plugin update`.

**Related**
- D21 (F016 fresh-install-only retirement) — shares the "no migration code" spirit but for teardown.
- Distinct from `DEC-BERLINDB-*` — this is wp_options (WP core), not BerlinDB tables. Same Activator, different storage layer.

---

### DEC-MCPCLIENT-BUILD-ENV-SHARED — Every MCP client must route env through AbstractMCPClient::build_env()

**Status**
Active (Feature 075)

**Context**
Pre-F075, all 16 concrete `MCPClient` subclasses inlined the same `env` array literal in their `get_config_snippet()` return:

```php
'env' => array(
    'WP_API_URL'      => $server_url,
    'WP_API_USERNAME' => $this->current_username(),
    'WP_API_PASSWORD' => $this->safe_token( $auth_token ),
),
```

16-way duplication. Adding a new env key (Feature 075's `NODE_TLS_REJECT_UNAUTHORIZED`) required either 16 file edits or a post-processing JSON walker — both bad. Constitution §VI DRY violation grandfathered in from the client-family's original hand-authoring.

**Decision**
Every new or existing `MCPClient` subclass MUST route its `env` block through the shared helper:

```php
'env' => $this->build_env( $server_url, $auth_token ),
// or, when the subclass needs an extra key (e.g. Claude Code's OAUTH_ENABLED):
'env' => $this->build_env( $server_url, $auth_token, array( 'OAUTH_ENABLED' => 'false' ) ),
```

`build_env()` lives on `AbstractMCPClient` and merges `$extra` FIRST + base env SECOND (base wins on collision — defensive against a subclass accidentally overriding `WP_API_URL`). New cross-cutting env keys (F075's TLS bypass now; future additions later) go in `build_env()`, not in each subclass.

**How to apply**
- Reviewers: reject any PR that adds an inline `'WP_API_URL' => $server_url` array literal in `includes/MCPClients/*Client.php` — must use `build_env()`.
- Canary grep: `grep -RnE "'WP_API_URL'\s*=>" includes/MCPClients/*Client.php` MUST return zero matches (AbstractMCPClient is the sole exception — that's the definition site).
- For clients that use a non-standard key name for the env block (e.g. OpenCode's `'environment' =>`), still delegate: `'environment' => $this->build_env(...)`.

**Trade-offs**
- Gained: single injection point for cross-cutting env additions. F075 shipped `NODE_TLS_REJECT_UNAUTHORIZED` with one method edit instead of 16. Any future env-block extension is one-liner.
- Made harder: a client class that legitimately needs to REMOVE a base env key must override `build_env()` — no clean per-client subtraction. No current client needs this, so acceptable.
- Reconsider: if a future MCP transport uses a semantically-different `env` block (e.g. HTTP transport with connection headers instead of subprocess env vars), `build_env()` may need to be renamed or split — but that's a larger refactor of the client contract, not a return to inlining.

**Related**
- Constitution §VI (DRY, Utilities extraction) — F075 is the reference implementation of "16-way duplication caught and retired via a shared base-class helper."
- F031 (Gemini client) shipped the last inline literal that F075 then retired.

---

### DEC-LOCAL-DEV-AFFORDANCE-SCHEME-AGNOSTIC — Dev-only affordances fire on any local-looking site, HTTP or HTTPS

**Status**
Active (Feature 075)

**Context**
F075's first design gated `NODE_TLS_REJECT_UNAUTHORIZED` injection + warning notice on `home_url()` returning `https://` — the reasoning being that the flag is technically a no-op on HTTP (Node's HTTP client never runs TLS validation), so injecting it would be a misleading "we fixed something" affordance where nothing was broken. On the plugin's own local install (plain HTTP `.local`) the operator hit the "MCP tools stay empty" symptom and didn't see the warning — the HTTPS gate hid the affordance from the exact user it was designed to help.

**Decision**
Local-dev-only affordances (warnings about insecure convenience flags, self-signed-cert bypasses, dev-mode toggles) fire whenever the site LOOKS local, regardless of whether it's served over HTTP or HTTPS. Even when the injected value is technically a no-op for one scheme (e.g. `NODE_TLS_REJECT_UNAUTHORIZED` on HTTP), the accompanying warning + troubleshooting-doc link is still useful — the doc typically covers non-TLS local-dev issues too.

**How to apply**
- Detection: use / extend `Utilities\LocalEnvironment::needs_tls_bypass()` (or a sibling helper following the same shape) — single-condition local-look test, no scheme sub-branch.
- Copy: warning text must be scheme-agnostic — acknowledge both cases explicitly ("on HTTPS with self-signed cert the flag is the real fix; on plain HTTP the flag does nothing but is harmless").
- Never introduce an admin toggle for the underlying insecure flag — the detection helper is the sole gate (spec F075 FR-007).

**Trade-offs**
- Gained: single mental model, single code path, single JSON shape. Operator sees the warning on any dev-tool config (Local by Flywheel HTTP + HTTPS, MAMP, DDEV, wp-env). Matches user's plain-English framing of "show it when the site is local without proper SSL."
- Made harder: the injected env var is decorative on HTTP — a developer opening `~/.claude.json` will see `NODE_TLS_REJECT_UNAUTHORIZED` on their HTTP `.local` site and may wonder why it's there. Warning copy is the mitigation.
- Reconsider: if a future dev-only convenience flag has different semantic risk on HTTP vs HTTPS (e.g. an actively-harmful key that shouldn't be shipped on HTTP), split the detection helper into two predicates — but that's per-affordance, not a return to gating THIS one.

**Related**
- DEC-MCPCLIENT-BUILD-ENV-SHARED — the shared helper is the injection point this decision relies on.
- Spec F075 § Clarifications Q4 (2026-08-24) — the on-the-record decision trace that overrode the earlier HTTPS-only gate.
