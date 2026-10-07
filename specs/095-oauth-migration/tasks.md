# Tasks: Migrate OAuth + AI Connectors from acrossai-pro

**Feature**: `095-oauth-migration` | **Branch**: `095-oauth-migration`
**Spec**: [spec.md](./spec.md) · **Plan**: [plan.md](./plan.md) · **Security**: [security-constraints.md](./security-constraints.md)

## Format: `[ID] [P?] [Story] Description`

- **[P]** — parallelisable (different files, no dependency on an incomplete task)
- **[US1]…[US4]** — user story from spec.md; Setup / Foundational / Polish carry no story label

## Path Conventions

- **Destination** (this plugin): paths relative to plugin root, e.g. `includes/OAuth/PKCE.php`
- **Source** (companion, read-only — never edited by this feature per FR-025): `../acrossai-pro/…`
- Namespace rewrite on every moved file: `AcrossAI_Pro\…` → `AcrossAI_MCP_Manager\Includes\…` / `\Admin\…`
- Text domain rewrite on every moved string: `'acrossai-pro'` → `'acrossai-mcp-manager'`

> **Tests are required for this feature** — constitution §VII mandates PHPUnit coverage for all new logic, and FR-024 requires porting the companion's existing suites. Test tasks are therefore not optional here.

---

## Phase 1: Setup

**Purpose**: Establish preconditions. No production code changes.

- [x] T001 Complete the Pre-flight Attestation in `docs/planings-tasks/095-oauth-migration.md` — confirm whether any install outside `~/local-sites/` holds real customer OAuth tokens. This determines whether Phase 3 is a data-safety exercise or a convenience one; do not start T020 without it.
- [~] T002 [P] Snapshot a reference database from an install carrying companion OAuth data; record baseline row counts for all four source tables in `specs/095-oauth-migration/quickstart.md` under Test 2.
- [x] T003 [P] Capture the pre-move contract surface from the companion and save it for later diffing: run the `register_rest_route|add_rewrite_rule|do_action|apply_filters|wp_schedule_event` grep from `contracts/oauth-endpoints.md` §5 against `../acrossai-pro/includes/`.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Clear the landmine and land the data layer. **Every user story depends on this phase.** No story may start until T013 passes.

**⚠️ T004–T006 must land before any table of those names is created (FR-004).**

- [x] T004 Delete `includes/Database/LegacyOAuthCleanup.php` entirely, including its `DONE_OPTION` and the `acrossai_mcp_legacy_oauth_cleanup_skipped` action.
- [x] T005 Unwire the `LegacyOAuthCleanup::maybe_cleanup()` call site in `includes/Main.php` (inside `reconcile_database_schemas()`), and delete any `tests/phpunit/**` covering it.
- [x] T006 In `uninstall.php`, **keep** the four OAuth table names in the `$tables` drop array and rewrite the comment above them: they were listed as orphan cleanup from a prior era and now describe this plugin's own tables (FR-005). Removing them would leak four tables — the exact `B44` failure mode.
- [x] T007 [P] Port `includes/Database/Support/DatetimeColumn.php` and `TimezoneHealthCheck.php` from the companion, namespace-rewritten.
- [x] T008 [P] Port the `OAuthTokens` module to `includes/Database/OAuthTokens/{Table,Schema,Query,Row}.php`. Reproduce columns and index names verbatim per `data-model.md`; set `$name = 'acrossai_mcp_oauth_tokens'`, `$db_version_key = 'acrossai_mcp_oauth_tokens_db_version'`, `$version = '1.0.1'`.
- [x] T009 [P] Port the `OAuthAuthCodes` module to `includes/Database/OAuthAuthCodes/{Table,Schema,Query,Row}.php`, same rules, `$version = '1.0.1'`.
- [x] T010 [P] Port the `OAuthClients` module to `includes/Database/OAuthClients/{Table,Schema,Query,Row}.php`, preserving the **composite** `UNIQUE(client_id, server_id)` index per `D31`; `$version = '1.0.1'`.
- [x] T011 [P] Port the `ConnectorApprovedUsers` module to `includes/Database/ConnectorApprovedUsers/{Table,Schema,Query,Row}.php`, `$version = '1.0.0'`.
- [x] T012 Add this plugin's phantom-version guard to all four new `Table.php` subclasses (`if ( ! $this->exists() ) { delete_option( $this->db_version_key ); } parent::maybe_upgrade();`), matching the five existing modules.
- [x] T013 Register all four tables in `includes/Main.php::bootstrap_database_tables()` **and** upgrade them in `reconcile_database_schemas()` and `includes/Activator.php`. Registration order is **Tokens → AuthCodes → Clients** (`D31`). Request-time instantiation is mandatory, not optional — activation-time `Table::instance()` alone leaves BerlinDB's DB interface empty on later requests and Query silently falls back to `$table_alias` as FROM (`DEC-BERLINDB-TABLE-REQUEST-BOOT`).
- [x] T014 [P] Grep-gate the four new `Schema.php` files: any column named `*_secret`, `*_token`, `*_password`, `*_key` must be `char(64)`, never `varchar(255)` plaintext (`B20`, SC-C10). **Also verify two invariants across the four modules and the migration writer** (SEC-T04): Query writers filter against `Schema::columns()` before persisting, blocking mass-assignment via forged keys (`B7`, SC-C8); and no code compares a TINYINT column with `===` against an integer — `$wpdb` returns `revoked` and `used` as strings, so `1 === $row->revoked` is always false and a revoked token would read as live (`B18`).
- [x] T015 [P] ~~Write a new `TableInstallationTest`~~ — **delivered differently, deliberately.** Four existing tests already parameterise over the Table subclasses via `@dataProvider provideTables`, with hardcoded lists of five. Writing a parallel test would have duplicated that coverage (§VI) and left the originals silently stale — the `B48` drift pattern. Instead the providers in `SchemaParityTest`, `SchemaReconcilerTest` and `PhantomVersionGuardTest` were extended so the four new tables inherit the existing schema-parity, reconciler and phantom-version-guard coverage. **Discovered follow-up: T075.**
- [x] T016 [P] Write `tests/phpunit/Database/OAuth/LegacyCleanupRemovedTest.php` — asserts `LegacyOAuthCleanup` no longer exists, and that the four names **are still present** in `uninstall.php`'s drop list (guards both halves of T004/T006 against future "tidying").

- [ ] T076 [P] Replace the 28 `window.alert()` / `window.confirm()` calls in `src/js/ai-connectors.js` with `@wordpress/components` modals. Ported verbatim and currently behind a file-level `eslint-disable no-alert` with a stated reason. Most are confirmations in front of destructive revoke/delete actions, so the replacement must keep a confirmation step — this is a UI feature, not a lint fix.
- [ ] T077 [P] Port the Connectors test suite. Requires deciding whether to add `brain/monkey` as a dev dependency (needed by `ConnectorSettingsTest`), and rewriting `ConnectorProfileRegistryTest` — four of its tests assert the `acrossai_pro_profiles` BC cascade that no longer exists.
- [x] T078 Collapse the duplicated connector-panel CSS (architecture review V4). `AbstractConnectorProfile::print_setup_styles()` echoed 128 lines of CSS from PHP for the AI Connectors tab while `src/scss/quick-connect.scss` carried the same rules for the wizard — 7 overlapping selectors, a direct breach of constitution §I:50 ("No code duplication between modules is permitted under any circumstance"). D51 justified the copy as spanning a plugin boundary; F095 dissolved that boundary, so D50's intra-plugin rule governs and the justification lapsed. Extracted `src/scss/_connector-setup-panel.scss`, imported by **both** `ai-connectors.scss` and `quick-connect.scss`, and deleted the emitter (145 lines with its docblock) plus its five `parent::print_setup_styles()` call sites. The tab already enqueues `build/js/ai-connectors.css`, so it keeps the rules without the inline `<style>`. Verified: both built bundles carry an identical set of 7 selectors, the tab renders with the inline style gone and its markup intact, and zero duplicated selectors remain. Used `@import` to match the existing `_policy-panel.scss` convention; that adds two more Dart Sass deprecation warnings to a project-wide total — migrating the whole SCSS tree to `@use` is its own task, not a rider on this one.
- [ ] T079 Enable `no-undef` for JS. F095 shipped a `ReferenceError: proState is not defined` that broke the Quick Connect wizard at render: the Pro-gate removal deleted two consts but left them referenced in two useMemo/useEffect dependency arrays. Neither ESLint nor webpack caught it — `wp-scripts lint-js` runs with no local config and the default set does not enable `no-undef`, and webpack bundles undefined identifiers happily. A probe run with `no-undef: error` and browser globals reports ZERO findings across `src/js`, so turning it on is clean — it needs a real `eslint.config.mjs` extending the wp-scripts defaults.
- [x] T080 Register the five built-in connector profiles and wire the two discovery lanes. The profiles were ported in Phase 4 but their registration was not: the companion contributed them from a closure gated on Freemius `can_use_premium_code()` and a `HostDependencies` probe, and dropping both gates dropped the registration. `ConnectorProfileRegistry` builds its list purely from `acrossai_mcp_manager_connector_profiles`, so every connector surface was empty — the AI Connectors tab, its Settings checkboxes, and Quick Connect Step 10 (which showed "No AI connectors registered on this site yet"). Added `ConnectorProfileRegistry::register_builtin_profiles()`, Loader-wired from `Main::define_public_hooks()` with `DiscoveryConnectorAdapter::provide_ai_connectors` and `::provide_ai_connector_instructions`. Verified on the running site: all three filters and both `ConnectionMethodRegistry` accessors return five entries. `Step10InstructionsWiringTest` now pins all three callbacks, canary-verified — it previously asserted only that the registry *declares* the instructions filter, which is why it stayed green while Step 10 was broken.
- [x] T081 Sweep the add-on surface the namespace rewrite missed (FR-018). The patterns `acrossai-pro` / `AcrossAI_Pro` do not match "AcrossAI Pro" with a space, so prose survived in four places: Step 10's notice, `AIConnectorsTab`'s empty state (including a "Browse add-ons" primary CTA), and `StepLayout`'s titles for the deleted steps 8 and 9. `admin/Main.php` also still localized `addonsUrl`, `pluginInstallUrl` and the `freemiusPro` checkout ids with no consumer, and still enqueued `https://checkout.freemius.com/js/v1/` on every wizard page view — a third-party script serving a removed paywall. `Registry.php` and `AdminTokenController` documented the placeholder-override pattern and a fourth Freemius permission gate as live; the gate was already absent from the code.
- [x] T082 Repair the phpcs gate, which had regressed to 4 errors / 11 warnings in files from the late `Integrations` port (missing docblocks on `GlobalIntegration::__construct` and `N8nTab::is_enabled`, array alignment, one reserved-keyword parameter). The `array{...}` shape on `GlobalIntegration` is kept but collapsed to one line — Squiz reads the multi-line form as a missing parameter name. A canary confirms the shape is documentation, not a gate, at PHPStan level 5. `$resource` renamed to `$resource_url` locally rather than excluding `resourceFound` site-wide.
- [x] T083 Remove the two dangling `HostCapabilities` calls and fix one wrong-namespace import. `AIConnectorsTab::panel_url()` and `N8nTab::panel_url()` both called `\AcrossAI_MCP_Manager\Includes\HostCapabilities::method_url()` — the namespace rewrite renamed a companion-only class that plan.md listed for removal, instead of dropping the call. The AI Connectors tab fataled on every render ("The ai-connectors connection method could not render"). The shim asked the host whether it had a Connect tab and delegated to `ConnectTab::method_url()` if so; this plugin IS the host, so both call sites now call `ConnectTab::method_url()` directly. Separately `AdminTokenController` imported `Admin\ServerTabs\N8nTab` instead of `Admin\Partials\ServerTabs\N8nTab`, so the n8n token permission gate would fatal rather than return 403. Verified on the running site: both tabs render (five connectors present) and `permission_callback()` returns `WP_Error(rest_cookie_invalid_nonce)`.
- [x] T084 Add `NoDanglingClassReferenceTest` to the rename-gate suite — resolves every in-plugin class reference (fully-qualified and `use` imports) against the PSR-4 map on disk. Canary-verified against both T083 bugs. This is the generalisation of the F095 failure mode: a namespace rewrite produces references that are syntactically perfect and fatal at runtime, and `php -l`, phpcs and the inert PHPStan gate all pass them. Resolution is by path rather than `class_exists()` because loading these files needs WordPress and this suite runs without it.
- [x] T085 **The PHPStan gate has never run — now it does.** `phpstan.neon.dist` listed `acrossai-mcp-manager.php` under `bootstrapFiles`, and that file opens with `if ( ! defined( 'WPINC' ) ) { die; }`, so PHP exited during bootstrap and PHPStan reported success having analysed nothing (no findings, no `[OK]` line, exit 0). Every green run in this project's history examined zero files. Fixed: added `szepeviktor/phpstan-wordpress` (pulling the WordPress stubs) as a dev dependency, replaced the bootstrap with a dedicated `phpstan-bootstrap.php` that defines only the constants the analysed code reads and never requires the plugin, and raised `level` from 5 to **8** as constitution §III and the §VII DoD require. First real run surfaced **548 errors**, recorded in `phpstan-baseline.neon` so the gate runs today and fails on anything new; the backlog is now explicit debt rather than invisible. Canary-verified against the real T089 bug: removing `MessagePage`'s `CacheHeaders` import is reported as an error, where previously it shipped with the gate green. Also fixed the CI step, which ran with no `--memory-limit` — the 128M default is exhausted part-way through this codebase and PHPStan then reports an INCOMPLETE run rather than failing, which reads as success.
- [ ] T096 Pay down the PHPStan baseline — **548 → 459** after the first pass (2026-10-07). Two causes removed, both false debt rather than real: (a) 20 `constant.notFound` entries were my own `phpstan-bootstrap.php` being incomplete — added `ACROSSAI_MCP_MANAGER_PLUGIN_BASENAME`, `..._PLUGIN_NAME_SLUG` and `DB_NAME`; (b) 69 `class.notFound` entries all traced to ONE phantom type. `Loader.php` and `Main.php` declared the loader as `AcrossAI_MCP_Manager\Includes\AcrossAI_MCP_Manager_Loader`, a class that has never existed — the real one is `Loader`. Because `$this->loader` had an unknown type, **every `add_action()`/`add_filter()` call in `Main.php` was unanalysable**: the A1 hook mechanism for the entire plugin was invisible to static analysis. Corrected across 6 docblock references; all 75 hook registrations now type-check cleanly, leaving only missing-annotation noise on `Loader` itself. Remaining 459 are genuine pre-existing debt — largest groups are `missingType.iterableValue` (114), `property.notFound` (63), `function.alreadyNarrowedType` (40). Burn down in themed passes.
- [x] T086 **Scope reversal — n8n stays in acrossai-pro.** The F095 clarification answer was "everything the promo card promises, plus n8n"; the n8n half is withdrawn. Connectors go free, n8n stays paid. Removed from this plugin: `N8nTab`, `OAuth\AdminTokenController` (n8n-exclusive — `CONNECTOR_SLUG = 'n8n'` hardcoded), the whole `includes/Integrations/` module (`GlobalIntegration` + `GlobalIntegrationRegistry`, whose only consumer was `N8nTab`), `src/js/n8n-admin.js`, `src/scss/n8n.scss`, `assets/n8n-icon.svg`, the webpack entry, the admin enqueue, three Loader registrations, and three `AdminTokenController` test files. **Deliberately kept:** `ConnectTab::LEGACY_TAB_METHODS['n8n']` (host-side routing for `?tab=n8n`, which the companion's method still needs), `AIConnectorsTab`'s filter stripping n8n grants from the connectors panel (those rows are written to our table by whoever owns n8n), and `AccessTokenRepository::MAX_ADMIN_TTL_SECONDS` (now the LAST line of defence, not a second one, since the controller enforcing it lives in another plugin). `MethodRegistry` priority 40 is held open and unseeded, pinned by a rewritten test that also asserts a companion registration at 40 slots between npm and wp-cli.
- [ ] T087 **BLOCKER for the 0.4.0 release — acrossai-pro must un-gate its n8n stack before or with this.** Decision taken 2026-10-07: **option C — n8n stays fully self-contained in acrossai-pro.** Pro un-gates its n8n hooks AND its own `oauth_tokens` + `oauth_clients` tables AND registers a narrowed `TokenValidator` scoped to n8n tokens. Both validators then run on `determine_current_user`; first match wins. Rejected option B (pro calling mcp-manager's `AccessTokenRepository`) because it makes a paid feature depend on a free-plugin repository class.
  Why un-gating hooks alone is not enough: mcp-manager's `TokenValidator` reads `acrossai_mcp_oauth_tokens`, while pro's `AdminTokenController` writes to `acrossai_pro_mcp_oauth_tokens` — so an n8n token would be issued successfully and then fail every authentication. Silent, and worse than the current visible 404.
  The token audit needs no work: `AdminTokenController` fires `acrossai_mcp_manager_oauth_token_issued` itself and pro's `maybe_log_n8n_token_issuance` listens — both inside pro.
  **Open sub-decision:** F095 copied rather than moved, so every pre-split n8n token now exists in BOTH tables with `connector_slug = 'n8n'`. Revoking in mcp-manager's UI would not revoke pro's copy, and both validators would match the same digest. Pick an owner — either mcp-manager excludes `connector_slug = 'n8n'` from its validator and from future migration runs, or pro honours only rows it minted after the split. Leaving it implicit produces a token nobody can fully revoke.
  **Direction of travel:** n8n moves onto mcp-manager's tables in a later release — decided 2026-10-07, explicitly NOT part of this one. Pro's own tables are the interim state so the two plugins can ship independently; the dual-table revoke divergence above is deferred with it, which is an accepted bounded risk (pre-F095 `connector_slug = 'n8n'` tokens only, all expiring within their original TTL of at most 90 days) rather than an oversight.
  Full brief: `specs/095-oauth-migration/acrossai-pro-issue-113.md`, mirrored to [acrossaico/acrossai-pro#113](https://github.com/acrossaico/acrossai-pro/issues/113).
  **Not yet pushed to GitHub** — issue writes were failing API-side on 2026-10-07; the repo copy is authoritative until the issue is updated.
- [x] T088 Correct the README/changelog ownership story. The 0.4.0 changelog announced n8n as included — removed. Separately, the README feature list still labelled one-click connectors, the OAuth 2.1 server and the connections dashboard as *(Pro)*, which F095 never updated; those are free now and the two "what Pro adds" summaries listed them as paid. Pro's list is now access control, extra abilities and n8n.
- [x] T089 Fix the `/authorize` fatal — a third dangling-class shape. `OAuth\MessagePage` called `CacheHeaders::send_no_store()` UNQUALIFIED, so PHP resolved it to `OAuth\CacheHeaders`, which F095 deliberately did not port (the `Utilities` copy is a superset). Every other file in that namespace imports it correctly; only this one was missed. Effect: `/authorize` returned HTTP 500 and every OAuth error page fataled — the single most important endpoint in the feature. Found by exercising the live site through the MCP server, not by any gate. Fixed with the missing `use`; verified `/authorize` now returns 400 with the proper "Connection problem" page.
- [x] T090 Extend `NoDanglingClassReferenceTest` with the unqualified-reference check. The existing two cases (fully-qualified, `use` imports) could not see T089: there is no backslash and no `use` line to inspect. The new case tokenizes rather than pattern-matching — a regex over raw source reports hundreds of false positives from class names in docblocks — and resolves `Foo::`/`new Foo(` against the file's own namespace. Canary-verified against T089. Suite now 21 tests.
- [x] T091 **Rewrite rules were never flushed on upgrade.** `flush_rewrite_rules()` existed only in `Activator.php`, and WordPress does not run activation hooks on plugin UPDATE. Every site upgrading to 0.4.0 would keep its stored `rewrite_rules` and serve 404 for all five OAuth routes — `.well-known/oauth-authorization-server`, `.well-known/oauth-protected-resource` (bare and path-inserted), `/authorize` and `/token` — until someone re-saved Settings → Permalinks. Discovery and the entire authorization flow unreachable after upgrade, with every CI gate green. Added `OAuthRouter::maybe_flush_rewrites()`, keyed on the plugin version (so a future rule change re-flushes without a new option) and called from `Main::reconcile_database_schemas()` on `admin_init` priority 3 — the same one-shot lane as the other upgrade routines, and after `init` has registered the rules. Reproduced the pre-upgrade state on a real site (OAuth rules stripped from the stored option → 404/404), then confirmed one admin page load restores all five and returns 200/400/400.
- [ ] T092 Local dev environment blocks OAuth entirely — document in quickstart. Two nginx defaults in Local make the OAuth path untestable, and neither is a plugin bug: (a) the PHP-FPM block never sets `fastcgi_param HTTP_AUTHORIZATION`, so no `Authorization` header reaches PHP and both application passwords and OAuth Bearer tokens resolve to user 0 — `TokenValidator` can never see a token; (b) `restrictions.conf` carries `location ~ /\. { deny all; return 404; }`, which 404s every `.well-known` path before WordPress is reached. Both were fixed on this machine in `~/Library/Application Support/Local/run/<site>/conf/nginx/site.conf` (backup at `site.conf.bak-before-authheader`), but Local regenerates that file from its templates when site settings change, so the fix is not durable. Quickstart Test 1 and Test 4 must state these preconditions, or the next person reads an environment 404 as a feature failure — exactly what happened here before the cause was isolated.
- [x] T093 Ship the five connector icons. The profile classes were ported but `assets/{claude,chatgpt,gemini,grok,cursor}-icon.svg` were not, so `get_icon_url()` returned a URL to a file that does not exist and every connector rendered a broken-image placeholder — on the AI Connectors tab and on Quick Connect step 10. Copied from the companion (n8n's icon deliberately left behind, per T086). Verified all five return HTTP 200 as `image/svg+xml`, and that every profile the registry hands out resolves to a file on disk.
- [x] T094 Add `BundledAssetsExistTest` to the rename-gate suite. Same failure shape as the dangling-class bugs and equally invisible: `plugins_url()` builds a URL from a path without touching the filesystem, so a missing asset passes `php -l`, phpcs and every suite, and shows up only as a broken image or a 404 in the browser. The test resolves the literal `assets/...` / `build/...` argument of every `plugins_url()` call against the repository, plus a named check per connector icon so a profile silently dropping its icon is also caught. Canary-verified: removing one icon fails both halves with the exact file and line. Deliberately narrow — dynamically-built asset paths cannot be checked statically and are out of scope.
- [x] T095 Drop the connector card header. `AbstractConnectorProfile::render_card_header()` emitted an icon plus the connector name directly beneath a tab strip that already names it — duplication, and the thing that made the missing icons (T093) visible in the first place. Removed the method, its call in `render_default_card()`, and the three now-dead rules in `src/scss/ai-connectors.scss`. Nothing overrode it. The icons still ship and `get_icon_url()` is unchanged: `DiscoveryConnectorAdapter` carries `icon_url` into the Discovery DTOs for the wizard, which has its own presentation. Verified the tab renders with zero `connector__icon`/`__header`/`__title` occurrences while all five tabs and the MCP URL row stay intact.
- [x] T097 Reconcile the spec artifacts with the n8n scope reversal. `/speckit-analyze` found the T086 decision had been recorded only in `tasks.md`, leaving six downstream contradictions — two of them CRITICAL. **FR-003** ("The plugin MUST provide admin-issued bearer tokens") was directly contradicted by shipped code; it now states n8n is out of scope and lists the host-side support this plugin still owes it. **FR-006** claimed stand-down happens "with no release coordination", which is false and was the most load-bearing wrong sentence in the spec — amended to say OAuth/discovery/connectors stand down automatically while n8n requires the companion to ship first. **US4** withdrawn, **SC-008** narrowed to the five vendors, **FR-027**'s rationale corrected (the companion's two tables are permanent under option C, not transitional), the n8n row in both the spec's endpoint table and `contracts/oauth-endpoints.md` marked not-served, `plan.md`'s file tree corrected, and quickstart Test 6 retitled with an explicit note that an n8n 404 is expected rather than a regression.
- [x] T098 Close the SC-006 gap. The criterion demanded a byte-level identifier comparison and nothing referenced it — `contracts/pre-move-surface.txt` was captured pre-move for exactly this purpose and was wired to nothing. It was also unsatisfiable as written, because that capture predates T086 and contains the n8n route. Narrowed to permit that one documented removal, and a concrete `diff` against the capture added to the quickstart gates. Also labelled SC-010 and SC-011 on the quickstart checks that already exercised them, so traceability no longer reports them uncovered.
- [x] T099 Pin FR-028 with `VersionFloorsUnchangedTest`. The PHP 8.1 / WP 7.0 floors were asserted in the spec and verified nowhere. The test pins both, and pins the plugin header against `README.txt` so the two files cannot drift apart — they carry the same numbers in different syntax with nothing keeping them in step. Canary-verified. It deliberately pins the existing `Requires WP:` key rather than correcting it: that key is unread by WordPress, so fixing it changes runtime behaviour and is tracked separately.
- [x] T100 Amend the constitution to v1.1.1 — reinstate OAuth / AI Connectors as an active feature area (architecture review V3). §I's Rationale listed the area as retired per D21 (F016, 2026-07-07) while F095 had reinstated it across 48 files, so the constitution's own module inventory could not be used to judge where a new file belongs. Now listed as active with its modules enumerated, and the full retire → move → reinstate history preserved so D21 is not re-cited as current. PATCH, not MINOR: the normative MUST ("each map to exactly one module") is unchanged and OAuth already satisfied it — only the enumeration was stale. Followed the §Governance amendment procedure: version bumped, `Last Amended` updated, sync impact report rewritten, all three templates reviewed (none needed changes — spec-template's only OAuth mention is the token-hashing DoD item). Every enumerated module path verified to exist on disk.
- [ ] T101 **Decide §III consent-surface condition 3** (deferred from T100, recorded in the constitution's sync impact report). Condition 3 requires a consent surface to be "operator-gated via a default-OFF option". F095's OAuth consent screen is a browser-mediated surface where the user consents on their own behalf, but it ships **ON by default** — a deliberate product decision recorded as C5 in `plan.md`. As written, the constitution and the shipped behaviour disagree. Scoping condition 3 to credentials that exceed the consenting user's own authority would resolve it, but that **loosens a NON-NEGOTIABLE security principle**, making it a MINOR amendment requiring its own proposal and approval — it was deliberately not folded into the T100 PATCH. Until decided, the OAuth consent screen is a standing §III deviation that should be cited in the feature plan under the §Governance compliance clause.
- [x] T102 Clear CVE-2026-67434 (TASK-SEC-003). `squizlabs/php_codesniffer` 3.13.5 → 3.13.6; `composer audit` now reports no advisories. Pre-existing and transitive via `wp-coding-standards/wpcs`, and it never shipped — both release workflows use `composer install --no-dev` — so this was dev/CI exposure only. phpcs still reports 0 errors 0 warnings after the bump, so no ruleset behaviour changed.
- [x] T103 Record the first code-level security review of F095 at `docs/security-reviews/2026-10-07-095-oauth-migration-branch.md`, with its routing row in `docs/memory/INDEX.md`. The three earlier reviews covered planning artifacts only; nothing had ever reviewed the ~15,700 ported lines as code. Result: 0 Critical, 0 High, 2 Medium (both accepted scope consequences, not defects), 2 Low, 1 Informational.
- [ ] T104 **Decide the §III MINOR amendment** (TASK-SEC-002, supersedes the T101 placeholder). Full proposal raised at `docs/constitution-proposals/2026-10-07-section-iii-condition-3.md`: scope condition 3's default-OFF requirement to surfaces that can issue a credential **exceeding the consenting user's own authority**, leaving surfaces that only ever bind to the consenting user's own account free to ship enabled — still operator-disableable, and still required to justify themselves in the class docblock. Explicitly does not weaken conditions 1/2/4/5 and does not exempt `FrontendAuth.php`, whose Application Password outlives the consenting session. The alternative is reversing C5 and making connectors default-OFF, which is a product decision. Needs approval before applying; v1.1.1 → v1.2.0.
- [ ] T075 [P] Extend `tests/phpunit/Database/SchemaManifestTest.php` — its provider derives from a `SCHEMAS` constant holding a full expected column manifest per table, so the four new modules need their manifests added. Not done alongside the other three providers because it needs each table's complete column list transcribed, not just a class reference.

**Checkpoint**: tables exist and install cleanly; nothing drops them.

---

## Phase 3: User Story 1 — An existing connection survives the upgrade (P1) 🎯 MVP

**Goal**: A client holding a valid token keeps working across the upgrade without re-authorising.

**Independent test**: on a site with the companion active and a live connected client, upgrade, then issue an MCP tool call without re-authorising — it must succeed.

**Why this is the MVP**: it is the only story that can cause irreversible harm. Token digests are the sole server-side record; the raw token lives only on the client.

### Tests for User Story 1

> **T017–T019 and T026 were consolidated into one file**, `tests/phpunit/Database/OAuthDataMigrationTest.php`. All four need the same expensive fixture — four real companion-shaped source tables, seeded and torn down — and four separate files would have duplicated it four times.

- [x] T017 [P] [US1] Write `tests/phpunit/Database/OAuth/MigrationFidelityTest.php` — seeds companion-shaped source tables, runs the migration, asserts `token_hash`, `token_family_id` and `server_id` are byte-identical across every row (FR-008, SC-C1). **Also checksum every source table before and after the run and assert equality** — those tables are the only rollback path, and a bug that truncated or updated one would destroy the sole means of recovery (SEC-T03).
- [x] T018 [P] [US1] Write `tests/phpunit/Database/OAuth/MigrationIdempotencyTest.php` — running twice produces no duplicates; clearing the done-flag and re-running is a no-op (FR-011, SC-004).
- [x] T019 [P] [US1] Write `tests/phpunit/Database/OAuth/MigrationResumeTest.php` — sets a mid-table cursor, re-runs, asserts it resumes from the cursor rather than restarting and still reaches correct totals (FR-011).

### Implementation for User Story 1

- [x] T020 [US1] Implement `includes/Database/OAuthDataMigration.php` — batched copy, **200 rows per table per pass** behind a filter, ordered by source `id` ascending, persisting a per-table cursor. Copy **never** move; source tables stay untouched as the rollback path (FR-010).
- [x] T021 [US1] Gate the migration on `acrossai_mcp_oauth_migration_done` (early-bail read, written with autoload disabled) and `acrossai_mcp_oauth_migration_cursor`. Set the done-flag only when every source table is drained.
- [x] T022 [US1] Wire the migration into `includes/Main.php::reconcile_database_schemas()` (after schema reconciliation so destination tables exist) and `includes/Activator.php`. **Admin-side only** — no front-end or OAuth-path trigger (FR-011, R1).
- [x] T023 [US1] Implement the companion-absent path: skip silently, set the done-flag, write no cursor state, raise no notice (FR-012).
- [x] T024 [US1] Migrate server meta keys `_acrossai_pro_server_settings` → `_acrossai_mcp_server_settings` and `_acrossai_pro_pending_users` → `_acrossai_mcp_pending_users` (FR-013).
- [x] T025 [US1] Implement failure visibility: on repeated failure raise a Site Health critical and an error-log entry. **Output limited to table name, cursor position and row count** — never row contents, column values, or raw DB error/query text (FR-012, SEC-002, SC-C2).
- [x] T026 [P] [US1] Write `tests/phpunit/Database/OAuth/MigrationDiagnosticsTest.php` — forces a batch failure and asserts no 64-character hexadecimal string appears in any diagnostic payload (SC-012, TASK-SEC-002).
- [x] T027 [P] [US1] Port `includes/OAuth/Repositories/{AccessToken,RefreshToken,AuthCode,Client,Scope}Repository.php`, namespace-rewritten.
- [x] T028 [P] [US1] Port `includes/OAuth/Security/{SecretsVault,RateLimiter}.php`. Preserve `RateLimiter`'s scalar `(int) get_transient()` read.
- [x] T029 [P] [US1] Write `tests/phpunit/Database/TokenValidatorFailsClosedTest.php` **before** porting the validator — asserts an MCP call against an empty or missing token table returns unauthenticated, not a grant (SC-C3, SEC-T06). Fail-closed behaviour is easy to assert after the fact and hard to notice losing; writing the test first makes the ported behaviour demonstrably correct rather than presumed correct.
- [x] T030 [US1] Port `includes/OAuth/TokenValidator.php` and wire its `determine_current_user` bearer authenticator via the Loader in `includes/Main.php::define_public_hooks()`. **Preserve the fails-closed contract verbatim** — every failure branch returns the incoming `$user_id`, never a grant (SC-C3). T029 must pass against the ported class.

**Checkpoint**: an existing token authenticates against the new tables. Run quickstart Test 1 — **if it fails, stop and restore the snapshot.**

> ### ⛔ Phase 3 MUST NOT reach a live site without Phase 4 (SEC-T01)
>
> Validate it on a test install only. The companion stands down when `class_exists( AuthorizationController )` becomes true, and that class does not land until **T037, in Phase 4**. So at the end of Phase 3 the companion is still fully active and still owns OAuth, while our validator (T030) is also live — reading a *second copy* of the same tokens.
>
> Both would authenticate, because the copy is faithful. **Revocation is what breaks.** An operator revoking a token through the companion's UI sets `revoked` on `acrossai_pro_mcp_oauth_tokens`; our copy still says the token is live. If our validator serves the next request, the revocation silently has no effect — and revocation is a security control, not a convenience.
>
> Phase 2 genuinely is safe to merge alone. Phase 3 is not.



---

## Phase 4: User Story 2 — A free user connects without buying anything (P1)

**Goal**: A clean install can complete the whole connection flow with no purchase, install or licence step.

**Independent test**: on a site with only this plugin, run Quick Connect end to end and call a tool.

### Tests for User Story 2

- [x] T031 [P] [US2] Port the companion's `tests/Unit/OAuth/` suite into `tests/phpunit/OAuthUnit/`, namespace-rewritten. *(Landed as `OAuthUnit/`, not the `OAuth/` this task originally named — `OAuth/` is not a configured testsuite.)*
- [x] T032 [P] [US2] Port the companion's `tests/Unit/OAuthDiscovery/` suite into `tests/phpunit/OAuthDiscovery/`. *(Landed as `OAuthDiscovery/`, not the `OAuth/Discovery/` this task originally named.)*
- [~] T033 [P] [US2] **Blocked — needs rework, not a port.** The Connectors suite does not transfer cleanly for two reasons. (1) `ConnectorSettingsTest` depends on `brain/monkey`, which this repo does not have as a dev dependency; adding it is a real decision, not a side effect of a migration. (2) Four of the eight tests in `ConnectorProfileRegistryTest` assert the dual-filter BC cascade that T047 deliberately removed, plus five further errors need separate investigation. Tracked as T077.
- [ ] T034 [P] [US2] Write `tests/phpunit/OAuthUnit/ContractSurfaceTest.php` — asserts every identifier in `contracts/oauth-endpoints.md` is registered: REST namespace and route paths, five rewrite rules, query vars, cron hook, action and filter names (FR-015). **Add a second assertion over the same route table: walk `rest_get_server()->get_routes()` for the namespace and assert that the set of routes using `__return_true` is exactly the three documented public protocol endpoints — discovery metadata, DCR registration, token** (SEC-T02, `S2`, §III). Registration alone is not enough: a route ported with its `permission_callback` silently dropped still has the right path, so a contract test that only checks paths would pass while the route stands open. **Path corrected (architecture review V2):** was `tests/phpunit/OAuth/`, which is not a configured testsuite — `phpunit.xml.dist` registers `OAuthDiscovery` and `OAuthUnit`, and `.github/workflows/phpunit.yml` asserts no test file sits outside one, so the original path would have failed CI on creation.

### Implementation for User Story 2

- [x] T035 [P] [US2] Port `includes/OAuth/PKCE.php` and `MessagePage.php`.
- [x] T036 [P] [US2] Port `includes/OAuth/{CimdResolver,CimdRegistry}.php`. Preserve `wp_safe_remote_get()` with `redirection => 0` and the capped timeout and response size — this is the SSRF defence on an attacker-supplied URL.
- [x] T037 [US2] Port `includes/OAuth/AuthorizationController.php`. Preserve: `is_user_logged_in()` floor, per-server connector-enabled check, `require_admin_approval` toggle semantics, the always-approve-required rule for unrecognised DCR clients, admin self-bypass **and its `acrossai_mcp_connector_admin_self_bypassed` audit action** (FR-014, SC-C6).
- [x] T038 [US2] Port `includes/OAuth/TokenController.php`. Preserve `D27` — a `client_secret_post` client submitting no secret falls through to PKCE-only verification rather than `invalid_client`, symmetrically in both grant handlers.
- [x] T039 [US2] Port `includes/OAuth/ClientRegistrationController.php` (RFC 7591 DCR + operator client generation).
- [x] T040 [P] [US2] Port `includes/OAuth/{DiscoveryController,DiscoveryConflictGuard}.php`.
- [x] T041 [US2] Port `includes/OAuth/DiscoveryHealthCheck.php` **and extend it**: compare the served documents' `issuer` / `resource` against this plugin's expected values and report a failure when they differ. Do **not** enumerate competing implementations by name (FR-016, SEC-007, SC-C11). **Verified complete (architecture review V5):** `includes/OAuth/DiscoveryHealthCheck.php` exists (10,585 bytes) with the issuer/resource identity comparison the task asked for, is Loader-wired on `site_status_tests` (`Main.php:967`), and was observed reporting live on a running site — it went from `recommended` to `good` once T091 restored the rewrite rules.
- [ ] T042 [P] [US2] Write `tests/phpunit/OAuthDiscovery/DiscoveryIdentityTest.php` — asserts the health check fails when a competing implementation's document is served, and passes when ours is (SC-013). **Path corrected (architecture review V2):** was `tests/phpunit/OAuth/`, which is not a configured testsuite — `phpunit.xml.dist` registers `OAuthDiscovery` and `OAuthUnit`, and `.github/workflows/phpunit.yml` asserts no test file sits outside one, so the original path would have failed CI on creation.
- [x] T043 [US2] Port `includes/OAuth/OAuthRouter.php` with its five rewrite rules and two query vars. Register from a hook wired in `define_public_hooks()` — **never at class construction or file load**; `add_rewrite_rule()` before `init` fatals (`B42`, R5).
- [x] T044 [P] [US2] Port `includes/OAuth/{BearerChallengeHeader,Cleanup,UserLifecycle}.php` and schedule `acrossai_mcp_manager_oauth_cleanup` on activation / clear on deactivation.
- [x] T045 [P] [US2] Port `templates/oauth/{consent,message}.php`, updating loader paths. Preserve the nonce field and the server-side sourcing of all displayed state — **no `$_GET` reads** (S9, SC-C7).
- [x] T046 [P] [US2] **Pulled forward into Phase 3 — task-list dependency error.** `TokenValidator` (T030) consults `ConnectorSettings`/`ConnectorSlugDisplay` to check a token's connector is still enabled, and `AccessTokenRepository` (T027) needs `ConnectorProfileRegistry`. The auth path therefore depends on the Connectors framework, so scheduling it in the UI phase was wrong. Ported `includes/Connectors/{AbstractConnectorProfile,ConnectorSettings,ConnectorProfileRegistry,ConnectorSlugDisplay}.php`.
- [x] T047 [P] [US2] Port the five profiles to `includes/ConnectorProfiles/{Claude,ChatGPT,Cursor,Gemini,Grok}ConnectorProfile.php` (R4). Drop the companion's `acrossai_pro_profiles` filter alias; keep `acrossai_mcp_manager_connector_profiles`.
- [x] T048 [P] [US2] Port `includes/Discovery/DiscoveryConnectorAdapter.php` and hook it to the existing seam `acrossai_mcp_manager_discovery_ai_connectors` at `public/Discovery/ConnectionMethodRegistry.php:306`.
- [x] T049 [US2] Rehome every registration from the companion's `bootstrap_oauth_hooks()` into `includes/Main.php::define_admin_hooks()` / `define_public_hooks()` via the Loader, resolving each singleton to a named variable first. **No `add_action` / `add_filter` inside any ported class** (A1, FR-021, constitution §Boot Flow Rule).
- [x] T050 [US2] Delete `admin/Partials/ServerTabs/AIConnectorsPromoTab.php` (583 LOC).
- [x] T051 [US2] Port `admin/ServerTabs/AIConnectorsTab.php` → `admin/Partials/ServerTabs/AIConnectorsTab.php`, now extending the local `AbstractServerTab`.
- [x] T052 [US2] In `admin/Partials/ServerTabs/Connect/MethodRegistry.php`, drop the `new AIConnectorsPromoTab()` seed (line ~112) and its import (line ~36); seed the real tab. **Slot identity must not change**: slug `ai-connectors`, priority `10`.
- [x] T053 [US2] Delete `src/js/quick-connect/steps/Step8_ProPromo.jsx` and `Step9_ProSetup.jsx`, and remove the gate behind them — the method grid's paid marking in `Step7_MethodGrid.jsx`, the `skipProSetup` predicate in `App.jsx`, the routing in `useWizardRouter.js`, and the licence check in `includes/REST/QuickConnectController.php` (~lines 960–1017). Route Step 7 → Step 10 directly (FR-017).
- [x] T054 [US2] Port `src/js/ai-connectors.js` and `src/scss/ai-connectors.scss`; restore the `js/ai-connectors` entry in `webpack.config.js`, replacing the F040 tombstone comment. **Shares `webpack.config.js` with T064 — not parallel-safe against it** (SEC-T05).
- [x] T055 [US2] Add `maybe_enqueue_ai_connectors_app()` to `admin/Main.php`, matching the shape of the existing `maybe_enqueue_tools_app()`.
- [x] T056 [P] [US2] Port the OAuth-specific notices (HTTPS, WP-Cron, discovery conflict) into `admin/Partials/Notices.php`.

**Checkpoint**: a clean install connects an AI client end to end. Run quickstart Test 5.

---

## Phase 5: User Story 3 — The operator manages and revokes connections (P2)

**Goal**: Connections can be reviewed, approved and revoked from the admin.

**Independent test**: connect a client, revoke it, confirm its next request is rejected.

- [~] T057 [US3] Port `includes/OAuth/ConnectorAdminController.php` with all 15 routes under namespace `acrossai-mcp-manager/v1`. Every route checks `manage_options`; every per-server route validates `server_id` and returns `acrossai_mcp_oauth_cross_server` 403 on mismatch (SC-C4).
- [x] T058 [US3] Preserve the `D34` carve-out: `/oauth/revoke-client-tokens-all-servers` takes only `client_id`, fires `acrossai_mcp_oauth_client_revoked_across_all_servers` once, and **must not** fire `acrossai_mcp_oauth_cross_server_attempted` (SC-C5). **Verified complete (architecture review V5):** `/oauth/revoke-client-tokens-all-servers` is registered at `ConnectorAdminController.php:121` and the carve-out is documented at `:956`.
- [x] T059 [US3] Preserve the `D32` approval-revoke cascade and its `acrossai_mcp_connector_revoke_tokens_on_approval_revoked` filter opt-out. **Verified complete (architecture review V5):** The cascade is Loader-wired at `Main.php:953` and `ConnectorAdminController::cascade_revoke_tokens_on_approval_revoked()` honours the `acrossai_mcp_connector_revoke_tokens_on_approval_revoked` opt-out at `:811`.
- [ ] T060 [P] [US3] Write `tests/phpunit/OAuthUnit/CrossServerBindingTest.php` — asserts a token for server A cannot act on server B, and that the site-wide revoke does not fire the bypass-attempt action (SC-C4, SC-C5, `B37`). **Path corrected (architecture review V2):** was `tests/phpunit/OAuth/`, which is not a configured testsuite — `phpunit.xml.dist` registers `OAuthDiscovery` and `OAuthUnit`, and `.github/workflows/phpunit.yml` asserts no test file sits outside one, so the original path would have failed CI on creation.
- [ ] T061 [P] [US3] Write `tests/phpunit/OAuthUnit/ApprovalCascadeTest.php` — revoking a user approval revokes matching tokens; the filter opt-out skips the cascade but keeps the delete. **Path corrected (architecture review V2):** was `tests/phpunit/OAuth/`, which is not a configured testsuite — `phpunit.xml.dist` registers `OAuthDiscovery` and `OAuthUnit`, and `.github/workflows/phpunit.yml` asserts no test file sits outside one, so the original path would have failed CI on creation.

**Checkpoint**: revocation works end to end. Run quickstart Test 3 §revocation.

---

## Phase 6: User Story 4 — Automation platform connects with an admin-issued token (P3)

**Goal**: An operator issues a bearer token for n8n and drives the MCP server with it.

**Independent test**: issue a token from the n8n screen and make an authenticated MCP call.

- [~] T062 [US4] Port `includes/OAuth/AdminTokenController.php`, preserving **both** guards in `permission_callback`: `wp_verify_nonce( $nonce, 'wp_rest' )` and `current_user_can( 'manage_options' )`.
- [x] T063 [US4] Port `admin/ServerTabs/N8nTab.php` → `admin/Partials/ServerTabs/N8nTab.php`.
- [x] ~~T064~~ **WITHDRAWN (architecture review V1)** — Instructed re-adding `src/js/n8n-admin.js`, `src/scss/n8n.scss` and the webpack entries — exactly what T086 deleted when n8n returned to acrossai-pro. Kept visible rather than deleted so the reversal is auditable; see T086 and `specs/095-oauth-migration/acrossai-pro-issue-113.md`. Original text: ~~[US4] Port `src/js/n8n-admin.js` and `src/scss/n8n.scss`; restore the `js/n8n-admin` and `css/n8n` entries in `webpack.config.js`. **Shares `webpack.config.js` with T054 — not parallel-safe against it** (SEC-T05).~~
- [x] ~~T065~~ **WITHDRAWN (architecture review V1)** — Instructed testing `AdminTokenController`, which T086 removed from this plugin. The n8n token path is verified in the companion’s release, not this one. Kept visible rather than deleted so the reversal is auditable; see T086 and `specs/095-oauth-migration/acrossai-pro-issue-113.md`. Original text: ~~[P] [US4] Write `tests/phpunit/OAuth/AdminTokenTest.php` — asserts the token is returned once, stored only as a hash, and bound to the requested server.~~

**Checkpoint**: all four user stories independently functional.

---

## Phase 7: Polish & Cross-Cutting Concerns

- [x] T066 Reconcile the duplicate `CacheHeaders`: diff the companion's `includes/OAuth/CacheHeaders.php` against this plugin's `includes/Utilities/CacheHeaders.php`, keep one, repoint all callers (FR-022, §VI, R7).
- [x] T067 Resolve C6 — the plugin boundary that justified `D51` and `D52` no longer exists. De-duplicate the connector CSS ported into `src/scss/quick-connect.scss` per `D50`'s intra-plugin rule, and remove the now-dead defensive union read in `Step10_ConnectorsDetail.jsx`.
- [x] T068 Text-domain sweep — zero `'acrossai-pro'` in any `__()` / `_e()` / `esc_html__()` call across `includes/`, `admin/`, `public/`, `templates/` (FR-020).
- [x] T069 Run the full-repo audit grep from `quickstart.md` §Gates. Only two exceptions permitted: the migration's `acrossai_pro_mcp_*` / `_acrossai_pro_*` source references, and the `acrossai_mcp_connector_%` comment in `uninstall.php`. Each must carry an inline comment naming Feature 095 and the follow-up that removes it.
- [x] T070 [P] Add the SEC-004 verification to `quickstart.md` Test 3 execution: run the whole test under a reversed `active_plugins` order so the `class_exists()` timing assumption is actually exercised.
- [x] T071 [P] Bump the version in three places — `acrossai-mcp-manager.php` `Version:`, `ACROSSAI_MCP_MANAGER_VERSION` in `includes/Main.php`, `Stable tag:` in `README.txt`.
- [x] T072 [P] Write the `changelog.txt` and `README.txt` entries. **The release note must tell operators to load wp-admin once after updating** — that sentence is the entire mitigation for the accepted upgrade window (SEC-003, FR-011).
- [~] T073 Run the full quickstart (Tests 1–7) on both the upgrade site and the clean site.
- [x] T074 Run all gates: `composer run phpcs`, `composer run phpstan`, `composer test`, `npm run lint:js`, `npm run build`, `npm run validate-packages`.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: no dependencies. T001 blocks T020.
- **Foundational (Phase 2)**: depends on Setup. **Blocks every user story.** T004–T006 must precede T008–T011 — creating a table whose name is still on the sweeper's kill-list is the `B44`/F083 race.
- **US1 (Phase 3)**: depends on Phase 2. No dependency on other stories.
- **US2 (Phase 4)**: depends on Phase 2. Shares the repositories from T027 but is otherwise independent of US1.
- **US3 (Phase 5)**: depends on Phase 2 and on T046 (`ConnectorSettings`) from US2.
- **US4 (Phase 6)**: depends on Phase 2 and T027. Independent of US2 and US3.
- **Polish (Phase 7)**: depends on all desired stories.

### Critical ordering within Phase 2

```text
T004 → T005 → T006   (landmine cleared)
                ↓
T007 ─┬─ T008 ─┬─ T012 → T013 → T015, T016
      ├─ T009 ─┤
      ├─ T010 ─┤
      └─ T011 ─┘
```

### Parallel Opportunities

- **Phase 1**: T002, T003 together.
- **Phase 2**: T007–T011 are five independent module ports — the widest parallel window in the feature. T014–T016 together once T013 lands.
- **Phase 3**: T017–T019 together (tests); T027, T028 together; T026 and T029 together (both are tests).
- **Phase 4**: T031–T034 together; T035, T036, T040, T044, T045, T046, T047, T048 together; T056 alongside the T035-group (T054 now owns webpack.config.js exclusively).
- **Phase 5**: T060, T061 together.
- **Phase 7**: T070, T071, T072 together.

### Parallel Example: Phase 2 module ports

```text
# Four BerlinDB modules, four files each, zero shared state:
T008  includes/Database/OAuthTokens/{Table,Schema,Query,Row}.php
T009  includes/Database/OAuthAuthCodes/{Table,Schema,Query,Row}.php
T010  includes/Database/OAuthClients/{Table,Schema,Query,Row}.php
T011  includes/Database/ConnectorApprovedUsers/{Table,Schema,Query,Row}.php
```

---

## Implementation Strategy

### MVP (User Story 1 only)

Phases 1 → 2 → 3, then stop and validate. At that point existing tokens authenticate against the new tables and the companion has already stood down — **the irreversible risk is fully retired** before any new capability is built. Everything after US1 is additive.

### Honest note on story independence

The template assumes stories are independently deliverable slices. Here they are independently *testable* but not independently *shippable*: US2 cannot ship without US1's migration, because releasing the authorization flow while existing tokens are stranded would break paying customers. Treat US1 as a hard gate, not a parallel option.

### Incremental delivery

1. Phase 2 alone is a user-visible no-op — safe to merge early and de-risks the landmine.
2. Phase 3 → validate quickstart Test 1 on a **test install** → **stop if it fails**. Do not deploy Phase 3 to a live site; it must ship together with Phase 4 (SEC-T01).
3. Phase 4 → the headline capability; validate Test 5.
4. Phases 5, 6 → management surface and n8n.
5. Phase 7 → polish and release.

---

## Notes

- **FR-025 is absolute**: this feature deletes nothing from `acrossai-pro`. Its stand-down is automatic via `mcp_manager_still_owns_oauth()`, which flips the moment T037 lands `AuthorizationController`. Stripping the companion is tracked at [acrossaico/acrossai-pro#113](https://github.com/acrossaico/acrossai-pro/issues/113).
- **Do not remove** the `acrossai_mcp_connector_%` exclusion from `uninstall.php` in this release (FR-027) — the companion still owns those options until its OAuth is stripped.
- **Out of scope, tracked separately**: constitution PATCH (§I module list; §III condition 3 scoped to credentials exceeding the consenting user's authority); the false default-OFF claim at `FrontendAuth.php:14`; PHPStan level reconciliation (plan.md C3); the WP floor and unreadable `Requires WP:` header (plan.md C4).
