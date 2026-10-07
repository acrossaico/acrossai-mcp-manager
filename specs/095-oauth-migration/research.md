# Phase 0 Research: OAuth + AI Connectors Migration

All `NEEDS CLARIFICATION` items were resolved during `/speckit.clarify`. This document records the technical decisions that shape the design, each with rationale and rejected alternatives.

---

## R1 — Migration trigger

**Decision**: Admin-side only. Run from `Main::reconcile_database_schemas()` (`admin_init` priority 3) and `Activator::activate()`. No front-end or OAuth-path trigger.

**Rationale**: Both the framework and this codebase settled this question already. BerlinDB hooks `maybe_upgrade()` to `admin_init` alone (`Kern/Table.php:1337`); its only other call site is gated on `is_testing()`. This plugin's five existing one-shot migrations all run on `admin_init` at priorities 3–6, with the ordering documented as load-bearing. Deviating for one migration would fragment a consistent pattern.

**Accepted cost**: WordPress does not fire activation hooks on *update*, so `admin_init` is effectively the sole trigger for an upgrading site. Sites updated by auto-update or WP-CLI will not migrate until someone loads wp-admin, and the companion stands down immediately. Mitigation is documentation — release notes must tell operators to load wp-admin once after updating.

**Alternatives considered**: OAuth-path trigger (closes the window; deviates from five precedents); `plugins_loaded` for all requests (broadest deviation); read-through fallback to the companion's tables until migration completes (zero window, but splits the hot token path and the fallback must later be removed).

---

## R2 — Migration shape and batch size

**Decision**: Batched copy with a persisted per-table cursor. **Batch size 200 rows per table per pass**, exposed via a filter.

**Rationale**: Measured baseline on the most-used install is 532 rows total (11 clients / 520 tokens / 0 auth codes / 1 approval), and these tables are bounded by design — auth codes are single-use and short-lived, and the expiry-sweep cron trims tokens to live grants. 200 clears the largest observed table in three passes while bounding worst-case request time on an outlier site we cannot observe in advance.

**Rejected**: single-pass `INSERT…SELECT`. It would handle the measured volume trivially, and with a `WHERE NOT EXISTS` guard it is genuinely idempotent and self-resuming (InnoDB rolls the statement back atomically, so there is never a half-copied table). It was rejected deliberately: the failure mode on an unobservable outlier site is a timeout loop that leaves connections broken, and robustness was judged worth the extra machinery.

---

## R3 — Table registration order and request-time boot

**Decision**: Register in the order **Tokens → AuthCodes → Clients**, instantiated at request time in `Main::bootstrap_database_tables()` *and* upgraded in `reconcile_database_schemas()` / `Activator`.

**Rationale**: `D31` pins this exact order from F032. `DEC-BERLINDB-TABLE-REQUEST-BOOT` records that activation-time `Table::instance()` alone leaves BerlinDB's DB interface empty on subsequent requests, after which Query silently falls back to `$table_alias` as the FROM clause — a failure that produces wrong results rather than an error. Both are required; neither substitutes for the other.

**Schema evolution**: any future change to these schemas follows `D28`'s three-part contract — bump `$version`, register an `$upgrades` callback, ensure `maybe_upgrade()` fires on `admin_init`. A bare `$version` bump with empty `$upgrades` silently stamps the new version and alters nothing.

---

## R4 — Connector profile placement

**Decision**: `includes/ConnectorProfiles/` with namespace `AcrossAI_MCP_Manager\Includes\ConnectorProfiles`.

**Rationale**: The companion keeps its five profiles loose at `includes/` top level. The constitution's namespace rule derives the namespace from the directory path, so importing them as-is would put five feature classes directly in `AcrossAI_MCP_Manager\Includes` alongside `Main` and `Activator`. A dedicated directory keeps the namespace meaningful and leaves room for third-party profiles.

**Alternative rejected**: mirror the companion's layout exactly, for diff-ability. Rejected because namespace rewriting already makes a line-by-line diff impossible, so there is no fidelity left to preserve.

---

## R5 — Rewrite rule registration timing

**Decision**: Register the five rewrite rules from a hook wired in `Main::define_public_hooks()`, never at class-construction or file-load time; flush on activation only.

**Rationale**: `B42` records that `add_rewrite_rule()` — and anything that transitively reaches `$wp_rewrite->add_rule()` — fatals with *"Call to a member function add_rule() on null"* when invoked before `init`. Five rules are being imported, so this is a live hazard rather than a theoretical one.

---

## R6 — Hook rehoming

**Decision**: Every registration from the companion's `bootstrap_oauth_hooks()` moves into `Main::define_admin_hooks()` / `define_public_hooks()` via the Loader, resolving each singleton to a named variable first.

**Rationale**: Constitution §Boot Flow Rule makes `Main.php` the single source of hook registration and explicitly prohibits passing `FeatureClass::instance()` inline as the second argument. `A1` and spec FR-020 say the same. The companion uses an identical Loader signature, so the classes port unchanged — only the registration site moves.

**Note**: `D17` records that Loader-wired bootstrap methods inherit A1 conformance for their inner `add_action` calls, which covers the router's internal rule registration.

---

## R7 — Duplicate reconciliation

**Decision**: Keep this plugin's `includes/Utilities/CacheHeaders.php`; drop the companion's `includes/OAuth/CacheHeaders.php` (89 LOC) and repoint its callers.

**Rationale**: Constitution §VI forbids duplication outright, and `includes/Utilities/` is the designated home for shared logic. Diff the two before deleting — if the OAuth copy carries behaviour the Utilities one lacks, merge it in rather than dropping it.

**Related**: `C6` in plan.md — `D51`'s cross-plugin CSS duplication and `D52`'s defensive union DTO read both lose their premise once the plugin boundary disappears, and become §VI duplication. De-duplicate in TASK-5.

---

## R8 — Wizard paid-gating removal scope

**Decision**: Delete `Step8_ProPromo.jsx` (199 LOC) and `Step9_ProSetup.jsx` (185 LOC) outright; remove the method grid's paid marking, the router's `skipProSetup` predicate, and the licence check in `QuickConnectController` (~lines 960–1017). Route Step 7 → Step 10 directly. Nothing replaces them.

**Rationale**: Spec FR-016/FR-017 after the Q1 clarification. The originating planning document scoped this as "rewrite one step"; it is ~400+ lines across five surfaces. Deleting the screens while leaving the gate would strand the wizard.

**Accepted cost**: the product loses its main in-wizard surface for discovering the paid Abilities library. Re-establishing it is deliberately deferred rather than designed inside a data migration.

---

## R9 — Consent-surface governance (C5)

**Decision**: The connector surface is **ON by default**. No default-OFF master toggle. FR-013 stands — the authorisation policy is preserved exactly.

**Rationale (primary — product decision, confirmed 2026-10-06)**: The feature is meant to work on install. A free plugin whose headline capability ships switched off, requiring the operator to locate and enable a setting first, defeats the purpose of making it free. This is a deliberate, owned position — not a posture inherited by oversight.

**Rationale (supporting — consistency)**: Constitution §III condition 3 requires a default-OFF operator gate, and F095's surface does not have one — but neither does `FrontendAuth`, which the constitution names as *"the first canonical instance of this exception"*. `Activator.php:147` seeds `acrossai_mcp_npm_login_enabled` to `1`, and per `D44` the activation seed is the runtime default. Condition 3 is therefore already not honoured by the exemplar. Four of the five conditions hold.

**Consequence**: the rule is miscalibrated, not the feature. §III condition 3 reads as though every consent surface issues a credential that could exceed the consenting user's authority; these do not. Follow-up 1 is therefore **required**, not optional.

Substantively the blast radius is bounded: tokens bind to the consenting user and carry only that user's capabilities, unrecognised DCR clients always require admin approval, and a connector must be enabled per-server first.

**Follow-ups**: constitution PATCH restating condition 3 to match deliberate practice; correct the `FrontendAuth.php:14` docblock, which claims default-OFF while `Activator.php:147` makes it ON.

---

## R10 — Uninstall drop-list

**Decision**: Delete the one-shot orphan sweeper (`LegacyOAuthCleanup`) entirely. **Keep** the four table names in `uninstall.php`'s drop array, rewriting their comment.

**Rationale**: Only the sweeper is a live hazard — it drops empty tables during normal `admin_init`, and a newly created still-empty table on a site that has not yet swept is exactly its target. `uninstall.php` runs only at uninstall, so its entries were never dangerous; once the plugin owns those tables, dropping them there becomes correct. `B44` records that adding a Table subclass does *not* automatically add it to that array, so removing the entries and relying on someone to re-add them is precisely the documented failure mode.

The comment must be rewritten: the entries were justified as orphan cleanup from a previous era and now describe the plugin's own tables. Without that, a future reader removes them as stale.
