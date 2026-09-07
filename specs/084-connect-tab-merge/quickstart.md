# Quickstart: Merge the five connection tabs into one "Connect" tab (Feature 084)

Verification recipe for Feature 084. Run the manual paths on a live site, then the canary greps and
automated gates before opening either PR.

`BASE` below is
`https://<site>/wp-admin/admin.php?page=acrossai_mcp_manager&action=edit&server=<ID>`.

## Prerequisites

- A local WordPress site with `acrossai-mcp-manager` on branch `084-connect-tab-merge` and at least
  one MCP server row.
- `acrossai-pro` checked out on its matching companion branch, for the paid-method paths.
- `WP_DEBUG` on, so `_doing_it_wrong()` notices from malformed registrations are visible.
- A non-local site (or a temporarily filtered hostname) for the SC-003 negative case — the local
  default is decided by `LocalEnvironment::needs_tls_bypass()`.

---

## Positive path — one Connect tab with five methods (SC-001, US1)

1. Open `BASE`. The top-level strip shows **8** tabs: Overview · **Connect** · Tools · Abilities ·
   Access Control · Logs · Update Server · Danger Zone.
2. None of npm, MCP Clients, Connectors/Integrations, n8n, WP-CLI appears at the top level.
3. Click **Connect**. The level-2 row reads left-to-right: **Connectors · MCP Clients · npm · n8n ·
   WP-CLI**.
4. Select each method in turn. Each renders exactly what its old top-level tab rendered — the npm
   form submits, the client config blocks copy, the WP-CLI snippet is present, the connector cards
   render with their buttons live.
5. The active method carries `aria-current="page"`; tabbing moves through the methods in visual
   order (FR-019).

---

## Legacy addresses — no bounce to Overview (SC-002, US2)

Each address below must land on **Connect** with the right method active, and must **not** issue a
redirect (confirm in the browser network panel: one `200`, no `30x`).

| Open | Expect |
|------|--------|
| `BASE&tab=npm` | Connect · npm |
| `BASE&tab=clients` | Connect · MCP Clients |
| `BASE&tab=wp-cli` | Connect · WP-CLI |
| `BASE&tab=ai-connectors` | Connect · Connectors |
| `BASE&tab=n8n` | Connect · n8n |
| `BASE&tab=clients&client=cursor` | Connect · MCP Clients, **Cursor pill active** |
| `BASE&tab=ai-connectors&panel=settings` | Connect · Connectors, **Settings panel active** |
| `BASE&tab=n8n&panel=header-auth` | Connect · n8n, **Header Auth panel active** |
| `BASE&tab=clients&client=does-not-exist` | Connect · MCP Clients on its own default client — no error |

Then, from any legacy address, click a link in the new navigation: the address heals to the
`&tab=connect&method=…` form on that click, not on arrival.

**Servers list shortcuts (FR-010/011)**: on the MCP Servers list, the **Connectors** and **MCP
Clients** row pills now point at `&tab=connect&method=…`. All five pills are still present, same
labels, same icons, same order; Access Control, Abilities and Quick Connect are unchanged.

---

## Local default (SC-003, US3)

| Site | Open | Expect |
|------|------|--------|
| local (`.local` / `localhost` / env type `local`) | `BASE&tab=connect` | **MCP Clients** active, its TLS-bypass warning visible |
| non-local | `BASE&tab=connect` | **Connectors** active |
| either | `BASE&tab=connect&method=npm` | **npm** active — the explicit request always wins |

---

## Paid methods, matched pair (SC-004/005, US4)

With **both** plugins on their matched branches:

1. Connectors and n8n appear inside Connect at positions 1 and 4.
2. Their CSS is applied and their buttons respond — this is the check that catches an enqueue gate
   that stopped matching.
3. Open at least one level-3 panel under each (e.g. Connectors → Settings, n8n → Header Auth) and
   confirm it loads and stays selected.
4. **Zero** leftover top-level tabs.

Degradation cases:

| Setup | Expect |
|-------|--------|
| Companion deactivated | Connectors shows the promotional card, no level 3; n8n absent; no errors, no empty containers |
| Companion active, n8n disabled/unlicensed | n8n absent; the other four methods unaffected |
| Companion **updated**, host **not** (reverse pairing, supported) | Screen behaves exactly as it does today — companion detects the older host and registers as top-level tabs |
| Host updated, companion **not** (out of support) | Two orphan top-level tabs; Connectors inside Connect shows the promo card. Nothing fatals, no data affected. Fix is to update the companion. |

---

## Third-party extension point (SC-006, US5)

Register a throwaway method on `acrossai_mcp_manager_connect_methods` and confirm:

1. It appears at its requested priority position.
2. With a `capability` the current user lacks → hidden, and its `render_callback` is never invoked
   (assert with a flag the callback would set).
3. With a `visible_callback` returning `false` → hidden.
4. With a `render_callback` that throws → an inline error in that method's area only; the navigation
   and the rest of the screen stay usable.
5. Registered with slug `npm` → **replaces** the built-in (last-wins, D41).
6. Missing `label` or non-callable `render_callback` → dropped, with a `_doing_it_wrong()` notice
   under `WP_DEBUG`.

Also confirm a third-party tab registered on the **level-1** filter with a non-connection slug still
appears as a top-level tab, unchanged (FR-016).

---

## Edge cases

- Restrict a user so only one method is visible → the level-2 row is **suppressed entirely**, not
  rendered with one lonely control (FR-018).
- Restrict so **no** method is visible → a plain explanatory message, not an empty panel.
- `BASE&tab=connect&method=nonsense` → falls back to the default method, no error, no blank screen.
- `BASE&tab=connect&method=<one the user may not see>` → treated as unknown; falls back; restricted
  content never produced. Indistinguishable from the unknown-method case (C2/C3).
- Restrict the user so **Connectors is hidden but MCP Clients is visible**, on a **non-local** site,
  then open `BASE&tab=connect` with no method → lands on **MCP Clients**. This is the C2 regression
  test: an implementation that filters by capability *after* resolving would land on the hidden
  first-in-order Connectors method instead.

---

## Preservation contracts — canary greps

Anchored on code syntax rather than bare slugs, because the new code legitimately names all five
slugs inside `LEGACY_TAB_METHODS` and in docblocks (bug pattern **B51**).

```bash
# No connection slug is registered as a top-level tab in this plugin any more.
grep -rEn "'tab'\s*=>\s*'(npm|clients|wp-cli|ai-connectors|n8n)'" admin/ includes/
# Expected: 0 matches.
#   (The companion keeps its own legacy-form fallback for older hosts — that is expected and lives
#    in the acrossai-pro tree, not here.)

# The tab filter is still applied from exactly ONE source location — no partitioning was
# reintroduced (D-5). This counts CALL SITES IN SOURCE, not runtime firings: Registry does not
# memoize, so the filter is applied twice per edit-page render today (strip + body). Do NOT
# rewrite this as a runtime counting-callback assertion — it would observe 2 and fail on a
# healthy tree (SEC-084-008).
#
# NOTE: these three patterns are anchored on the ASSIGNMENT, not the bare symbol. Both
# registries name `apply_filters( self::FILTER_NAME` and `RegistryEntryNormalizer::normalize`
# in their DOCBLOCKS too, so the unanchored forms over-count (2/2/3 instead of 1/1/2).
# Bug pattern B51, confirmed by running the naive form during implementation.
grep -cE "^\\s*\\$raw = apply_filters\\( self::FILTER_NAME" admin/Partials/ServerTabs/Registry.php
# Expected: 1

# The method filter also fires in exactly one place.
grep -cE "^\\s*\\$raw = apply_filters\\( self::FILTER_NAME" admin/Partials/ServerTabs/Connect/MethodRegistry.php
# Expected: 1

# Validation is shared, not copied (§VI).
grep -rE "^\\s*(return |\\$normalized\\s+= )RegistryEntryNormalizer::normalize\\(" admin/
# Expected: 2 (Registry.php and Connect/MethodRegistry.php)
grep -c "sanitize_key( (string) \$entry\['slug'\] )" admin/Partials/ServerTabs/Registry.php
# Expected: 0 — the body moved to the normalizer.

# Out-of-scope vocabularies untouched (SC-008). Run before and after; counts must match.
grep -rn "VALID_METHODS" includes/REST/QuickConnectController.php | wc -l
grep -rn "ConnectionMethodRegistry" public/ | wc -l

# The URL builder still returns raw (S5 contract, C1).
grep -n "esc_url" admin/Partials/ServerTabs/ConnectTab.php
# Expected: no esc_url inside method_url() — escaping happens at output sites only.

# ...and every output site DOES escape (C1 / B6 / B8). Each hit that emits into HTML must
# carry esc_url or esc_attr on the same line.
grep -rn "method_url(" admin/ includes/ public/ | grep -vE "function method_url|@|//|\*"
# Review each hit against the output-site inventory in
# contracts/connect-method-registration.md §2. Any HTML-emitting hit without esc_url/esc_attr
# is a merge blocker.

# The rejected ?method= value is never reflected back (C3).
grep -rniE "unknown (connection )?method|invalid method" admin/Partials/ServerTabs/
# Expected: 0 matches — the fallback is silent.

# Contained failures catch Throwable, not Exception (C4).
grep -n "catch ( \\\\Throwable" admin/Partials/ServerTabs/ConnectTab.php
# Expected: >= 1
grep -n "getMessage()" admin/Partials/ServerTabs/ConnectTab.php
# Expected: only inside a WP_DEBUG-guarded error_log() call — never in rendered output.

# Both superglobal reads are sanitized (C5).
grep -n "\$_GET\[" admin/Partials/ServerTabs/ConnectTab.php
# Expected: every hit wrapped in sanitize_key( wp_unslash( ... ) ).
```

---

## Automated verification

```bash
composer dump-autoload

# PHPCS (WPCS strict) — zero errors AND zero warnings
composer run phpcs

# PHPStan level 8 — zero errors
composer run phpstan

# ESLint — zero errors, zero warnings
npm run lint:js

# Package hierarchy gate (constitution §VI)
npm run validate-packages

# SCSS rebuilt so backend.css carries the level-2 segment rules
npm run build

# New PHPUnit suites. NOTE: the repo-wide WP-dependent suites are broken by F011-F080 API drift
# (T069, out of scope). Run these via the scratch PHPUnit 9.6 + polyfills runner established in F082.
#   tests/phpunit/Admin/ServerTabs/ConnectTabTest.php
#   tests/phpunit/Admin/ServerTabs/Connect/MethodRegistryTest.php
#   tests/phpunit/Includes/Utilities/RegistryEntryNormalizerTest.php
#   tests/phpunit/Admin/ServerTabs/RegistryTest.php   (counts derived, not re-hardcoded — B48)
```

---

## Success signal

- 8 top-level tabs, one labelled Connect, five methods inside it in the specified order.
- Every legacy address resolves with its deeper selection intact and **zero** redirects.
- Both paid methods render, styled, with working controls and working level-3 panels.
- All four automated gates clean; all canary greps at their expected counts.
- `README.txt` in **both** plugins records the minimum paired version (FR-014).
