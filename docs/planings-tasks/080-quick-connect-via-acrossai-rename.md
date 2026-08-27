# 080 — Rename "Quick Setup" → "Quick Connect via AcrossAI" everywhere

## Plain-English summary

The F069/F072 wizard is currently labelled **Quick Setup** across every operator-visible surface (admin submenu, plugins.php row action, MCP Servers list page-title button + per-row pill, Settings-page sub-nav tab, admin-bar chip, wizard header) and every machine identifier (URL query param `?quick-setup=1`, REST route `/quick-setup/state|step|complete`, PHP namespace `QuickSetup`, class `QuickSetupController`, source directories `src/js/quick-setup/` + `src/scss/quick-setup.scss`, JS bootstrap global `window.acrossaiMcpQuickSetup`, CSS classes `.acrossai-mcp-quick-setup-*`, activation-redirect transient key).

F080 renames all of it in a single pass. UI labels become **Quick Connect via AcrossAI**; machine identifiers become **`quick-connect`** (kebab) / **`QuickConnect`** (Pascal). No backwards-compat shim — no dual REST route, no 301 redirect, no legacy transient bridging. Existing bookmarks against `?quick-setup=1` will 404 after this ships; wizard scratchpad transients in flight at deploy time are orphaned (harmless — 30-min TTL cleans them up).

Historical documentation (README changelog for 0.3.0 / 0.3.1, `docs/planings-tasks/069-*.md`, `072-*.md`, `docs/quick-setup-design-brief.md`, `specs/069-*/`, `specs/072-*/`, memory files under `docs/memory/`) is **not rewritten** — those describe what shipped as "Quick Setup" at the time; retroactive edits would lie about git-blame history. Only forward-looking copy under a new `= Unreleased =` bullet reflects the rename.

## Context

- Feature scope inherited from F069 (wizard shell) + F072 (entry points), with follow-ups F073 / F074.
- User waived backwards-compat concerns explicitly: "do at all the places in php, js and css, rest route do not worry about the abckward compatiability" — so the shipped-name rename is a hard cut, not a phased migration.
- No DB schema change. Only transient keys change value, and they're TTL-scoped ephemeral state.

## What ships

### Machine-identifier renames

| Surface | Old | New |
|---------|-----|-----|
| URL query param | `?quick-setup=1` | `?quick-connect=1` |
| REST route prefix | `/acrossai-mcp-manager/v1/quick-setup/*` | `/acrossai-mcp-manager/v1/quick-connect/*` |
| PHP namespace | `AcrossAI_MCP_Manager\Admin\Partials\QuickSetup` | `\QuickConnect` |
| PHP class (page) | `QuickSetupPage` | `QuickConnectPage` |
| PHP class (REST) | `QuickSetupController` | `QuickConnectController` |
| PHP constant value (SCRATCHPAD_KEY_PREFIX) | `acrossai_mcp_manager_quick_setup_state_` | `..._quick_connect_state_` |
| Activation-redirect transient | `acrossai_mcp_manager_quick_setup_do_redirect` | `..._quick_connect_do_redirect` |
| Method (`admin/Main.php`) | `maybe_enqueue_quick_setup_app` | `maybe_enqueue_quick_connect_app` |
| Method (`admin/Main.php`) | `suppress_admin_notices_on_quick_setup` | `suppress_admin_notices_on_quick_connect` |
| JS bootstrap global | `window.acrossaiMcpQuickSetup` | `window.acrossaiMcpQuickConnect` |
| JS source dir | `src/js/quick-setup/` | `src/js/quick-connect/` |
| JS entry file | `src/js/quick-setup.js` | `src/js/quick-connect.js` |
| SCSS source file | `src/scss/quick-setup.scss` | `src/scss/quick-connect.scss` |
| CSS classes | `.acrossai-mcp-quick-setup-fullpage`, `-wrap`, `-root` | `-quick-connect-` variants |
| Admin bar node id | `acrossai-mcp-quick-setup` | `acrossai-mcp-quick-connect` |
| Body class | `acrossai-mcp-quick-setup-fullpage` | `acrossai-mcp-quick-connect-fullpage` |
| Webpack entry key | `quick-setup` | `quick-connect` |

### UI-label renames (all 8 sites → "Quick Connect via AcrossAI")

Admin submenu (menu title + page title), plugins.php row action, MCP Servers list `page-title-action` button, per-row quicklink pill in the servers-list Actions cell, Settings-page sub-nav tab label, admin-bar chip (also drops the trailing `for MCP` qualifier — redundant with "AcrossAI"), wizard shell heading in `StepLayout.jsx`.

### File renames (git-mv)

- `admin/Partials/QuickSetup/` → `admin/Partials/QuickConnect/`
- `tests/phpunit/Admin/QuickSetup/` → `tests/phpunit/Admin/QuickConnect/`
- `admin/Partials/QuickSetup/QuickSetupPage.php` → `admin/Partials/QuickConnect/QuickConnectPage.php`
- `includes/REST/QuickSetupController.php` → `includes/REST/QuickConnectController.php`
- `tests/phpunit/REST/QuickSetupControllerTest.php` → `QuickConnectControllerTest.php`
- `tests/phpunit/Admin/QuickSetup/QuickSetupPageTest.php` → `tests/phpunit/Admin/QuickConnect/QuickConnectPageTest.php`
- JS + SCSS moves as tabled above.
- `build/js/quick-setup.*` + `build/css/quick-setup.*` stale artefacts deleted from the repo (`npm run build` regenerates as `quick-connect.*`).

## Verification

1. Grep gate returns zero non-historical hits:
   ```bash
   grep -rn -i "quick.setup\|quicksetup" \
     admin/ includes/ src/ tests/phpunit/ webpack.config.js acrossai-mcp-manager.php \
     --include='*.php' --include='*.jsx' --include='*.js' --include='*.scss' --include='*.json'
   # Allowed historical matches: README.txt (0.3.0 / 0.3.1 changelogs), docs/**, specs/069-*, specs/072-*, docs/memory/**
   ```
2. Admin session walk-through — every one of the 8 entry-point surfaces reads "Quick Connect via AcrossAI"; every URL carries `?quick-connect=1`; wizard heading matches.
3. REST — `GET /wp-json/acrossai-mcp-manager/v1/quick-connect/state` returns 200; `GET /…/quick-setup/state` returns 404.
4. `composer run test` — all suites green (renamed test namespaces + class basenames autoload correctly).
5. `composer run phpcs` + `composer run phpstan` — clean.
6. `npm run build` — produces `build/js/quick-connect.*` + `build/css/quick-connect.*`; old `quick-setup.*` artefacts are gone.
7. Manual regression — a fresh activation on this local install fires the activation-redirect transient using the new key and lands the operator on the wizard step 1 (no fatal error). Any in-flight scratchpad from a previous session's `quick_setup_state_*` transient is orphaned; wizard starts fresh — verified to render without error.

## Not in scope

- The external docs URL `https://acrossai.co/mcp-manager-quick-setup/` referenced in `README.txt` (3 sites) stays as-is in this PR. The acrossai.co page rename + 301 redirect is a separate follow-up on the site side, not a plugin edit.
- Historical `README.txt` changelog entries for 0.3.0 / 0.3.1 mentioning "Quick Setup Wizard" — describe what actually shipped under that name at merge time; do not retroactively rewrite.
- `docs/memory/**`, `docs/planings-tasks/069-*.md`, `072-*.md`, `docs/quick-setup-design-brief.md`, `specs/069-*/**`, `specs/072-*/**` — all historical design records. The new `specs/080-quick-connect-via-acrossai-rename/` captures the rename story.

## Ships as F080 via the spec-kit chain

`/speckit.git.feature "quick-connect-via-acrossai-rename"` → `/speckit.specify` → `/speckit.clarify` → `/speckit.plan` → `/speckit.tasks` → `/speckit.implement` → `/speckit.analyze` → `/speckit.security-review.staged` (expected zero findings — rename touches no auth / input / output shape) → `/speckit.memory-md.capture-from-diff` (candidate D51 — `DEC-EXPLICIT-NO-BACKCOMPAT-RENAME`) → `/speckit.git.commit` → PR against `main`.
