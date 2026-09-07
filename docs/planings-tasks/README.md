# Migration Planning: Main Branch → WPBoilerplate Structure

## What This Is

Spec-kit prompts for migrating every module from **`acrossai-mcp-manager`** (main branch)
into **`acrossai-mcp-manager-new`** (feature/issue-3) following the WPBoilerplate /
`wp-plugin-development` skill structure.

---

## Source → Target: The Core Difference

| Aspect | Main Branch (old) | New Branch (target) |
|---|---|---|
| Namespace | `ACROSSAI_MCP_MANAGER\` | `AcrossAI_MCP_Manager\` |
| Boot pattern | Direct singleton `Core\Plugin` | Loader singleton `Includes\Main` |
| Hook registration | `add_action()` inside constructors | Only via `$this->loader->add_action()` in `Main.php` |
| Folder layout | `src/Admin/`, `src/Core/`, `src/Database/` … | `admin/Partials/`, `includes/`, `public/Partials/` |
| Assets | Flat `assets/*.css` / `assets/*.js` | `src/js/` + `src/scss/` → `build/` via webpack |

---

## Phase Files

| File | What it covers | Status |
|---|---|---|
| `phase-brownfield.md` | Pre-flight scan + Phase 0 constitution notes | Phase 0 ✅ done |
| `phase-2-core-boot.md` | Core boot flow, constants, DB table classes | — |
| `phase-3-admin.md` | Admin Settings, list tables, Application Passwords | — |
| `phase-mcp.md` | MCP Controller + 8 MCP Client classes | — |
| `phase-6-oauth.md` | OAuth / Claude Connectors (full OAuth 2.0 flow) | — |
| `phase-cli-auth.md` | Frontend Auth page + REST CLI controller (5 endpoints) | — |

Full file-by-file source→target mapping: [`source-map.md`](source-map.md)

---

## Feature Specs

| # | Slug | Date | Status | Doc |
|---|---|---|---|---|
| 011 | berlindb-migration | 2026-07-02 | Complete | [011-berlindb-migration.md](011-berlindb-migration.md) |
| 012 | mcp-settings-tab | 2026-07-03 | Complete | [012-mcp-settings-tab.md](012-mcp-settings-tab.md) |
| 013 | per-server-tabs-refactor | 2026-07-03 | Complete | [013-per-server-tabs-refactor.md](013-per-server-tabs-refactor.md) |
| 015 | access-control-v2-adoption | 2026-07-04 | Implemented | [015-access-control-v2-adoption.md](015-access-control-v2-adoption.md) |
| 016 | remove-claude-connectors | 2026-07-07 | Complete | [016-remove-claude-connectors.md](016-remove-claude-connectors.md) |
| 017 | per-server-ability-selection | 2026-07-07 | Implemented | [017-per-server-ability-selection.md](017-per-server-ability-selection.md) |
| 020 | per-server-tool-selection | 2026-07-09 | Implemented | [020-per-server-tool-selection.md](020-per-server-tool-selection.md) |
| 021 | oauth-2-1-implementation | 2026-07-10 | Implemented | [021-oauth-2-1-implementation.md](021-oauth-2-1-implementation.md) |
| 022 | addons-page-registration | 2026-07-12 | Planned | [022-addons-page-registration.md](022-addons-page-registration.md) |
| 040 | migrate-ai-connectors-to-companion | 2026-07-31 | Implemented | [040-migrate-ai-connectors-to-companion.md](040-migrate-ai-connectors-to-companion.md) |
| 082 | per-server-ability-policy-defaults | 2026-09-05 | Implemented (Phase 2 + US1 MVP; manual smoke + F030 fence execution pending) | [082-per-server-ability-policy-defaults.md](082-per-server-ability-policy-defaults.md) |

---

## Execution Order

```
phase-brownfield   ← run scan + migrate to generate draft specs/
       ↓
phase-2-core-boot  ← boot flow + DB table classes (prerequisite for everything)
       ↓
phase-3-admin      ← needs DB classes from phase-2
       ↓
phase-mcp          ← needs DB classes from phase-2
       ↓
phase-6-oauth      ← needs DB classes from phase-2
       ↓
phase-cli-auth     ← implement REST controller first, then FrontendAuth
       ↓
Assets (see below) ← run last, after all CSS/JS needs are known
```

---

## Key Rules (enforced in every phase)

1. **Never `add_action()` inside a class constructor.** All wiring goes through `$this->loader->add_action()` in `Main.php`.
2. **Constants only in `Main::define_constants()`** via the private `define()` guard.
3. **Admin UI classes live in `admin/Partials/`** — never in `includes/`.
4. **`includes/` is context-neutral** — no admin-specific logic.
5. **Activation logic in `includes/Activator::activate()`** — not in closures.
6. **Assets read version + deps from `build/*.asset.php`** — never hardcoded.
7. **Text domain = `acrossai-mcp-manager`; load on `init`**, not `plugins_loaded`.
8. **Every REST endpoint must have a `permission_callback`** — never `__return_true` on mutating routes.
9. **REST namespace = `acrossai-mcp-manager/v1`** — never shorten.

---

## Repo Paths (agent reference)

```
SOURCE (read from, never modify):
/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/mcp-migration/acrossai-mcp-manager/

TARGET (this repo — write here):
/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/mcp-migration/acrossai-mcp-manager-new/
```

---

## How to Use

1. **`phase-brownfield.md`** — run the four Brownfield commands to auto-generate draft `specs/` for every module.
2. For each phase file: read the **Source Files to Read First** block, refine the Brownfield-generated spec, then run `/speckit.plan` → `/speckit.tasks` → `/speckit.implement` → `/speckit.analyze`.
3. Check the **Success Criteria** before moving to the next phase.
4. After all phases, run **Assets** (below) then the four pre-ship scripts.

---

## Assets (run last — after all phases complete)

Migrates `assets/*.css` / `assets/*.js` → `@wordpress/scripts` webpack pipeline.

**Source → Target:**

| Source (`assets/`) | Target (`src/`) | Build output |
|---|---|---|
| `admin.css` | `src/scss/backend.scss` | `build/css/backend.css` |
| `admin.js` | `src/js/backend.js` | `build/js/backend.js` |
| `frontend-auth.css` | `src/scss/frontend.scss` | `build/css/frontend.css` |
| `frontend-oauth.css` | `src/scss/frontend-oauth.scss` | `build/css/frontend-oauth.css` |

**Steps:**

```
/speckit.specify

Feature: Asset Build Pipeline — CSS + JS via @wordpress/scripts
Feature number: 008

1. webpack.config.js entry map:
   'js/backend':         './src/js/backend.js'
   'js/frontend':        './src/js/frontend.js'
   'css/backend':        './src/scss/backend.scss'
   'css/frontend':       './src/scss/frontend.scss'
   'css/frontend-oauth': './src/scss/frontend-oauth.scss'

2. Copy CSS content from assets/ into the corresponding src/scss/ files.
   Copy JS content from assets/admin.js into src/js/backend.js.

3. Admin\Main::enqueue_styles/scripts() — guard by screen ID, read from build/*.asset.php.
   Public\Main — guard by query var, read from build/*.asset.php.
   No hardcoded version strings or dependency arrays.

4. npm run build exits 0.
5. Delete old assets/ directory after build passes.
```

**Success criteria:**
- [ ] `npm run build` exits 0
- [ ] All `build/*.asset.php` files exist for every entry point
- [ ] Admin assets enqueue only on `acrossai_mcp_manager` screen
- [ ] Frontend assets enqueue only on their respective virtual pages
- [ ] Old `assets/` directory removed

---

## Pre-ship Scripts (run after everything)

```bash
node .agents/skills/wp-plugin-development/scripts/validate-structure.mjs --dir=.
node .agents/skills/wp-plugin-development/scripts/validate-security.mjs --dir=.
node .agents/skills/wp-plugin-development/scripts/detect-deprecations.mjs --dir=.
node .agents/skills/wp-plugin-development/scripts/detect-rest-endpoints.mjs --dir=.
```
