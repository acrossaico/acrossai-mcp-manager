# Implementation Plan: Migrate OAuth + AI Connectors from acrossai-pro

**Branch**: `095-oauth-migration` | **Date**: 2026-10-06 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `/specs/095-oauth-migration/spec.md`

## Summary

Move the OAuth 2.1 authorization server and the AI Connectors stack (~15,700 LOC) out of the paid companion `acrossai-pro` and into this plugin, making one-click AI client connection free. Delete the promotional placeholder occupying the Connectors slot and the wizard's paid-gating; put the real interface in their place.

Technically this is a **lift-and-shift with one genuinely new mechanism**: a batched, cursor-resumable data migration that copies live OAuth grants from the companion's tables into correctly-namespaced ones. Everything else is namespace rewriting, hook rehoming, and deletion. The companion needs no release coordination — its existing `class_exists()` probe makes its OAuth inert the moment our `AuthorizationController` loads.

The risk is concentrated almost entirely in that migration. Token digests are the only record of a live grant; the raw token exists solely on the AI client. Alter them and every connected client is permanently disconnected with no recovery path.

## Technical Context

**Language/Version**: PHP 8.1+ (declared `Requires PHP`); JavaScript via `@wordpress/scripts` (webpack), Node 20
**Primary Dependencies**: `berlindb/core ^3.0.0`, `wordpress/mcp-adapter ^0.6.1`, `automattic/jetpack-autoloader ^5.0`, `wpboilerplate/wpb-access-control ^3.1.0`, `acrossai-co/main-menu 0.0.33` — **no new Composer dependency is introduced**; the OAuth implementation is self-contained and deliberately library-free
**Storage**: Four new BerlinDB custom tables (`acrossai_mcp_oauth_{clients,tokens,auth_codes}`, `acrossai_mcp_connector_approved_users`), plus `wp_options` for connector settings and the migration cursor, plus `MCPServerMeta` for per-server settings
**Testing**: PHPUnit ^9.6 against the WP test harness (`@dataProvider`, not `#[DataProvider]`); Jest for JS; PHPCS (WPCS + PHPCompatibility); PHPStan
**Target Platform**: WordPress — single-site only this increment (see Constitution Check C2)
**Project Type**: WordPress plugin (wordpress.org-distributed, free tier)
**Performance Goals**: Token validation is on the hot path of every authenticated MCP request and MUST remain an indexed single-row lookup by digest. Migration is bounded per request by batch size, not by total rows.
**Constraints**: Every externally observable identifier byte-identical (live client contract); token digests transferred unaltered; no code deleted from the companion; declared PHP/WP minimums unchanged
**Scale/Scope**: ~15,700 LOC moved across 60+ files; measured data baseline 532 rows (11 clients / 520 tokens / 0 auth codes / 1 approval) on the most-used install

### Resolved during `/speckit.clarify`

| Decision | Value |
|---|---|
| Migration trigger | Admin-side only (`reconcile_database_schemas` + `Activator`) — house pattern, no deviation |
| Migration shape | Batched with a persisted per-table cursor |
| Failure visibility | Silent on success/skip; Site Health critical + error log on repeated failure |
| Authorisation policy | Preserved exactly; no tightening |

**Batch size**: 200 rows per table per pass, exposed via a filter. Chosen against the 532-row baseline — three passes clears the largest observed table while bounding worst-case request time on an outlier site. Not a clarification; a planning decision recorded here.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

Evaluated against `.specify/memory/constitution.md` v1.1.0.

| Principle | Status | Notes |
|---|---|---|
| I. Modular Architecture | ⚠️ **C1** | §I's rationale lists four active feature areas and records OAuth as *retired per D21*. This feature revives it as a fifth. Constitution text needs a PATCH amendment. |
| II. WordPress Standards | ⚠️ **C2, C3, C4** | See below. |
| III. Security First (NON-NEGOTIABLE) | ✅ PASS **(C5 resolved)** | Consent-surface exception: 4 of 5 conditions met. The unmet one (default-OFF gate) is unmet by the constitution's own named exemplar, so this is a pre-existing divergence rather than a new one. Two follow-ups raised. See C5. |
| IV. User-Centric Design | ✅ PASS | The incoming tab falls squarely inside the v1.1.0 "Connector picker card layout" exception, which names `AIConnectorsTab` explicitly. No DataViews rewrite required. |
| V. Extensibility | ✅ PASS | All integration remains hook-based; the companion degrades via its own probe. No core files modified in either plugin. |
| VI. Reusability & DRY | ⚠️ **C6** | Duplicate `CacheHeaders` must reconcile to one implementation (FR-022). Cross-plugin CSS duplication from F081 becomes intra-plugin duplication — see C6. |
| VII. Definition of Done | ⚠️ depends on C3 | PHPStan gate ambiguity. |

### C1 — §I module inventory is stale (advisory)

§I records OAuth/Connectors as retired. F095 revives it. Requires a constitution PATCH amendment and a matching `DECISIONS.md` annotation on D21. **Non-blocking**; fold into the memory-capture step.

### C2 — Multisite (advisory, justification required)

§II: *"MUST be multisite-compatible unless a feature is explicitly scoped to single-site with documented justification."*

**Justification**: the migrated code carries no network-wide table registration, no `is_global()` BerlinDB usage, and no `switch_to_blog()` handling; the companion shipped it single-site. Extending to multisite would require per-site migration orchestration and network-admin surfaces that are out of scope for a lift-and-shift. Recorded in Complexity Tracking.

### C3 — PHPStan level (pre-existing, not introduced)

§II and §VII both mandate **level 8**. `phpstan.neon.dist:2` is `level: 5`. This is a standing repo-wide divergence, not introduced by F095. **Do not silently lower the spec's gate to match the config, and do not raise the repo to level 8 inside this feature** — raising it would surface unrelated findings across 34k lines and swamp the review. Flagged for its own change; the DoD gate for F095 reads "zero errors at the configured level".

### C4 — Declared WP floor contradicts the constitution (pre-existing)

§II: *"MUST be compatible with WordPress 6.9+ and PHP 8.1+."* The plugin header declares `Requires at least: 7.0`, and `acrossai-mcp-manager.php:34` spells it `Requires WP:` — a header WordPress does not read, so the minimum is unenforced. FR-028 forbids changing floors in this feature. **Pre-existing; flagged for its own change.**

### C5 — RESOLVED 2026-10-06: ON by default is a deliberate product decision

**Resolution: downgraded from GATE FAILURE to advisory. FR-013 stands unchanged; no default-OFF toggle is added. Two follow-ups are raised instead.**

**Primary justification — an owned product decision, confirmed 2026-10-06.** The connector surface is **intended** to be available by default. This plugin's purpose is that a site owner can install it and connect an AI client; a surface that ships switched off, requiring the operator to find and flip a setting before the headline feature works, defeats the reason for making it free. The decision is deliberate and owned, not inherited by accident.

The supporting evidence below shows F095 is also *consistent* with existing practice — but consistency is the secondary argument. The primary one is that default-ON is the correct product behaviour for a credential that is strictly self-scoped, and §III condition 3 is miscalibrated for that case rather than F095 being non-compliant with a sound rule. **This makes follow-up 1 (constitution PATCH) required rather than optional** — the rule, not the feature, is what needs correcting.

The finding below was correct about the letter of §III condition 3, but wrong that F095 introduces the deviation. The constitution names `public/Partials/FrontendAuth.php` as *"the first canonical instance of this exception"* — and that surface does not satisfy condition 3 either:

- `FrontendAuth.php:195` — code fallback is `false`, i.e. OFF
- `FrontendAuth.php:14` — docblock asserts *"operator-gated via `acrossai_mcp_npm_login_enabled` default-OFF"*
- `Activator.php:147` — **but activation seeds `add_option( 'acrossai_mcp_npm_login_enabled', 1 )`**

Per `D44 / DEC-WP-OPTION-DEFAULT-VIA-ACTIVATION-SEED`, the activation seed *is* the runtime default — `get_option`'s second argument is returned only when no row exists, and `Activator` guarantees one does. So the plugin's exemplar consent surface has been enabled on every fresh install since that seed landed, and its docblock now misstates its own gate.

Scoring F095's consent surface against all five conditions:

| Condition | Status |
|---|---|
| 1. `is_user_logged_in()` verified | ✅ `AuthorizationController.php:115`, `:292` |
| 2. Credential bound to consenting user's own `user_id` | ✅ `:188`, `:236`, `:334` |
| 3. Operator-gated via default-OFF option | ❌ — **and neither is the named exemplar** |
| 4. Exception cited in rendering class docblock | ✅ `AuthorizationController.php:10-15` |
| 5. Consent context from server-side store, not URL params | ✅ server-side settings + auth-code lookup (S9) |

Four of five hold; the gap is shared with the surface the constitution itself holds up as the model. F095 therefore **inherits the project's actual posture rather than introducing a new deviation**, and adding a default-OFF master toggle to OAuth alone would make it stricter than the exemplar — inconsistent, and it would force every free user to find and flip a switch before the headline feature works, defeating the purpose of making it free.

The substantive security question stands on its own and the answer is acceptable: the blast radius is bounded by design. Tokens bind to the consenting user and carry **only that user's own capabilities**, so a subscriber's AI client can do nothing the subscriber cannot; unrecognised DCR clients always require admin approval regardless of any toggle; and a connector must be enabled per-server before `/authorize` will complete. This is the standard OAuth model, not privilege escalation.

**Follow-ups raised (neither blocking, neither in F095's scope):**

1. **Constitution PATCH** — restate §III condition 3 to match deliberate practice. The honest formulation is narrower: a default-OFF gate is required where the issued credential could exceed the consenting user's own capabilities; where it is strictly self-scoped, per-surface operator configuration suffices. Follows the §Governance amendment procedure.
2. **Fix `FrontendAuth.php:14`** — the docblock claims default-OFF; `Activator.php:147` makes it ON. One of the two is wrong and has been since the seed landed. Documentation drift on a security-critical claim, so worth its own small change rather than a drive-by edit here.

<details>
<summary>Original finding (superseded — retained for audit trail)</summary>

#### GATE FAILURE: consent surface is not operator-gated default-OFF

§III's consent-surface exception permits the OAuth authorization consent screen to bypass the `manage_options` minimum, but **only** if all five conditions hold. Condition 3:

> *Be operator-gated via a default-OFF option … so the surface does not exist on a fresh install without explicit operator opt-in.*

Today the de facto gate is commercial: the surface only exists where an operator installed **and licensed** the paid companion. F095 removes that gate. And the companion's own default is permissive — `ConnectorSettings::get_server_settings()` seeds *every registered profile as enabled* on first read, with `require_admin_approval` collapsing to false when there is no legacy value.

Net effect on a fresh free install: a recognised connector is enabled by default, approval is not required by default, and any logged-in user can complete `/authorize` and receive a token. The blast radius is bounded — tokens bind to the consenting user and carry only that user's capabilities, and unrecognised DCR clients always require admin approval — but condition 3 is plainly unmet.

**This conflicts with spec FR-013**, which requires the authorisation policy be preserved exactly, per the Q4 clarification. The spec cannot satisfy both. §III is NON-NEGOTIABLE, so it wins by governance, but the resolution changes scope and is the user's call. Options are set out in the Governance Summary. **Resolve before `/speckit.tasks`.**

</details>

### C6 — DRY across a boundary that no longer exists (advisory)

`D51` mandated duplicating connector CSS into this plugin rather than sharing a partial, and `D52` mandated a defensive union DTO read — both justified *by the plugin boundary*. Once OAuth lives here the boundary is gone: `D50`'s intra-plugin rule (10+ rules → shared SCSS partial) applies instead, and the union read in `Step10_ConnectorsDetail.jsx` becomes dead weight that §VI would call duplication. Fold the de-duplication into TASK-5.

## Project Structure

### Documentation (this feature)

```text
specs/095-oauth-migration/
├── plan.md               # This file
├── spec.md               # Feature specification (28 FRs, 11 SCs)
├── memory-synthesis.md   # Index-first memory retrieval
├── research.md           # Phase 0 output
├── data-model.md         # Phase 1 output
├── quickstart.md         # Phase 1 output
├── contracts/            # Phase 1 output
├── checklists/
│   └── requirements.md
└── tasks.md              # Phase 2 (/speckit.tasks — NOT created here)
```

### Source Code (repository root)

```text
includes/
├── OAuth/                          # NEW — 25 files, protocol logic, context-neutral
│   ├── AuthorizationController.php │ TokenController.php │ ClientRegistrationController.php
│   ├── ConnectorAdminController.php │ AdminTokenController.php
│   ├── DiscoveryController.php │ DiscoveryHealthCheck.php │ DiscoveryConflictGuard.php
│   ├── TokenValidator.php │ OAuthRouter.php │ PKCE.php │ MessagePage.php
│   ├── CimdResolver.php │ CimdRegistry.php │ BearerChallengeHeader.php
│   ├── Cleanup.php │ UserLifecycle.php
│   ├── Repositories/               # AccessToken, RefreshToken, AuthCode, Client, Scope
│   └── Security/                   # RateLimiter, SecretsVault
├── Connectors/                     # NEW — AbstractConnectorProfile, ConnectorSettings,
│                                   #       ConnectorProfileRegistry, ConnectorSlugDisplay
├── ConnectorProfiles/              # NEW — Claude, ChatGPT, Cursor, Gemini, Grok
├── Discovery/
│   └── DiscoveryConnectorAdapter.php   # NEW
├── Database/
│   ├── OAuthClients/ │ OAuthTokens/ │ OAuthAuthCodes/ │ ConnectorApprovedUsers/   # NEW
│   ├── Support/                    # NEW — DatetimeColumn, TimezoneHealthCheck
│   ├── OAuthDataMigration.php      # NEW — batched cursor copy
│   └── LegacyOAuthCleanup.php      # DELETED (FR-004)
├── Main.php                        # MODIFIED — table bootstrap, hook wiring, migration call
└── Activator.php                   # MODIFIED — table upgrade + migration

admin/Partials/ServerTabs/
├── AIConnectorsTab.php             # NEW (replaces promo)
├── N8nTab.php                      # NEW
├── AIConnectorsPromoTab.php        # DELETED (FR-016)
└── Connect/MethodRegistry.php      # MODIFIED — seed real tab at slug ai-connectors pri 10

templates/oauth/{consent,message}.php   # NEW
src/js/{ai-connectors,n8n-admin}.js     # NEW
src/scss/{ai-connectors,n8n}.scss       # NEW
src/js/quick-connect/steps/Step8_ProPromo.jsx   # DELETED
src/js/quick-connect/steps/Step9_ProSetup.jsx   # DELETED
uninstall.php                           # MODIFIED — sweeper entries kept, comment rewritten (FR-005)
webpack.config.js                       # MODIFIED — restore entries
```

**Structure Decision**: Mirrors the constitution's namespace rule — directory path under plugin root maps to `AcrossAI_MCP_Manager\…`. One deliberate departure from the companion's layout: its five connector profiles sit at `includes/` top level; here they go into `includes/ConnectorProfiles/` so the namespace stays meaningful and the directory doesn't accumulate loose feature classes. The two admin tabs move from the companion's `admin/ServerTabs/` into `admin/Partials/ServerTabs/` as the Admin Partials Rule requires.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|---|---|---|
| Single-site only (C2, §II) | Migrated code has no network-wide table registration, no `is_global()`, no `switch_to_blog()`; companion shipped single-site | Adding multisite support means per-site migration orchestration + network-admin surfaces — a feature in its own right, not a lift-and-shift |
| Four new custom tables (§Database) | Token validation needs indexed single-row lookup by digest on every authenticated request, plus expiry sweeps and family-grouped revocation | Options/meta cannot serve an indexed, high-cardinality, frequently-expired row set; `get_option` on every MCP request is not viable |
| REST controllers over the ~400-line guidance | `ConnectorAdminController` 994 LOC / 15 routes, `AuthorizationController` 854, `ClientRegistrationController` 731 — imported as-is | Splitting them during the move would make the diff un-reviewable against the source and risk behavioural drift on a security surface. Recorded as follow-up, not done here |
| OAuth hand-rolled rather than a library | Opaque-token + SHA-256-at-rest design has no key material to leak and revokes instantly | `league/oauth2-server` saves ~1,300 LOC while vendoring ~20,800, forcing a PSR-7 bridge and an RSA private key in `wp_options`, and raising the PHP floor |
