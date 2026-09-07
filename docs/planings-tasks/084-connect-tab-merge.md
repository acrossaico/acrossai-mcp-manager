# Planning: Merge the five connection tabs into one "Connect" tab (Feature 084)

Collapse the five top-level per-server tabs that all answer the same operator question — *"how do I
connect an AI client to this MCP server?"* — into a single **Connect** tab with a second-level nav.
The five are **npm** (`?tab=npm`), **MCP Clients** (`?tab=clients`), **Connectors/Integrations**
(`?tab=ai-connectors`), **n8n** (`?tab=n8n`), and **WP-CLI** (`?tab=wp-cli`). Five sibling tabs for
one concept bloats the tab strip (11 built-ins today) and gives the operator no sense of which path
to take.

Post-merge structure — three levels, ordered exactly as specified. Labels,
slugs and priorities below are read from source, not approximated:

```
WP Admin ▸ AcrossAI ▸ MCP  (?page=acrossai_mcp_manager)
│
└── MCP Servers list ▸ [Edit] a server        (?action=edit&server=N)
    │
    ├── LEVEL 1 — tabs                          (?tab=…)        8 tabs, was 11
    │
    ├── Overview                                 tab=overview        prio 10
    │
    ├── ★ Connect                                tab=connect         prio 20   ← NEW
    │   │
    │   ├── LEVEL 2 — methods                    (&method=…)
    │   │
    │   ├── Connectors/Integrations              method=ai-connectors   10
    │   │   └── LEVEL 3 — panels (&panel=…) · acrossai-pro
    │   │       ├── Claude              panel=claude
    │   │       ├── Grok                panel=grok
    │   │       ├── ChatGPT             panel=chatgpt
    │   │       ├── Gemini              panel=gemini
    │   │       ├── Cursor              panel=cursor
    │   │       ├── Connections         panel=connections      ← Pro's default
    │   │       ├── Approved Users      panel=approved-users
    │   │       └── Settings            panel=settings
    │   │       (free tier: no L3 — single promo/upsell card)
    │   │
    │   ├── MCP Clients                          method=clients         20
    │   │   └── LEVEL 3 — client pills (&client=…) · 16 clients
    │   │       Claude Desktop · Claude Code · VS Code · GitHub Copilot ·
    │   │       Codex · Cursor · Gemini · Windsurf · Zed · Cline ·
    │   │       Roo Code · Kilo Code · Amazon Q · OpenCode ·
    │   │       Antigravity · Custom
    │   │
    │   ├── npm                                  method=npm             30
    │   │   └── (no L3)
    │   │
    │   ├── n8n                                  method=n8n             40   · acrossai-pro
    │   │   └── LEVEL 3 — panels (&panel=…)
    │   │       ├── Bearer Auth        panel=bearer-auth      ← Pro's default
    │   │       ├── Header Auth        panel=header-auth
    │   │       └── Connections        panel=connections
    │   │
    │   └── WP-CLI                               method=wp-cli          50
    │       └── (no L3)
    │
    ├── Tools                                    tab=tools           prio 50
    ├── Abilities                                tab=abilities            60
    ├── Access Control                           tab=access-control       70
    ├── Logs                                     tab=mcp-log              80
    ├── Update Server                            tab=update-server        90
    └── Danger Zone                              tab=danger-zone         100
```

Level-2 default when `?tab=connect` carries no `&method=`:

```
local site  ( LocalEnvironment::needs_tls_bypass() === true )  →  MCP Clients
every other site                                               →  Connectors  (first in order)
```

Tab strip, before and after:

```
BEFORE (11)  Overview · npm · MCP Clients · Connectors/Integrations · n8n · WP-CLI ·
             Tools · Abilities · Access Control · Logs · Update Server · Danger Zone

AFTER  (8)   Overview · Connect · Tools · Abilities · Access Control ·
             Logs · Update Server · Danger Zone
                       └─ npm, MCP Clients, Connectors, n8n, WP-CLI now live in here
```

Two conditionals the tree does not show inline: **Connectors** and **n8n**
render only when acrossai-pro is active (n8n additionally requires premium +
the `acrossai_n8n_enabled` option; without Pro the Connectors slot shows the
free-tier promo card and has no level 3). Separately, `EmbedsTab` (90) and
`WidgetsTab` (95) exist as classes but are **not** in `Registry::all_tabs()`
today, so they appear in neither column above and are out of scope here.

The Connectors and n8n branches are what make three stacked navigation rows
reachable (tabs → methods → panels). That is the specific reason TASK-8
mandates ONE graded navigation family: all three rows use the WordPress
`.nav-tab` idiom and are told apart by scale (14px → 13px → 12px) and stacking
order, not by giving each row a different kind of control. Revised 2026-09-07 —
the original design used square segments at level 2 and round filled pills at
level 3, which made the screen read as three unrelated widgets rather than one
hierarchy. See plan.md §Design Revision.

**Local-install default**: when `Includes\Utilities\LocalEnvironment::needs_tls_bypass()` is true,
an unqualified `?tab=connect` opens **MCP Clients**, not the first-in-order Connectors panel. This is
the same detection that already injects `NODE_TLS_REJECT_UNAUTHORIZED: "0"` into generated client
configs (F075 / D46) and that renders the TLS-bypass notice inside the MCP Clients panel — a local
developer's next action is copying a client config, so that is where they should land.

**This is a CROSS-PLUGIN feature.** Only three of the five tabs live in this plugin (`NpmTab`,
`ClientsTab`, `WpCliTab`). `AIConnectorsPromoTab` is a free-tier placeholder whose real
implementation lives in **acrossai-pro** (`admin/ServerTabs/AIConnectorsTab.php`, registered on the
`acrossai_mcp_manager_server_tabs` filter at priority 35), and the entire **n8n** tab exists only in
acrossai-pro (priority 36). acrossai-pro additionally gates its JS/CSS enqueue on exact
`?tab=ai-connectors` / `?tab=n8n` string matches, so a host-only change would render Pro's panels
unstyled with inert buttons. Feature 084 therefore ships as **two coordinated PRs** — a host PR that
is reviewable on its own, and a small companion PR. The two ship as a **matched pair** — see
CLARIFICATIONS.

The change is **backwards-compatible for every existing URL**. All five legacy `?tab=` values keep
working via in-place normalization (never a redirect — see CONSTRAINTS), preserving any `?panel=` /
`?client=` args they carry. No public renderer API, REST route, discovery DTO, embed transport key,
or Quick Connect wizard vocabulary changes — several of those subsystems use identical-looking
strings (`'npm'`, `'clients'`, `'connectors'`) and are explicitly out of scope.

---

## Speckit Workflow

```markdown
# 1. Branch
/speckit.git.feature "connect-tab-merge"

# 2. Specify
/speckit.specify "Merge the five per-server connection tabs (npm, clients,
ai-connectors, n8n, wp-cli) into a single top-level Connect tab (slug
'connect', label 'Connect', priority 20) whose level-2 sub-navigation is
driven by a new ?method= query param, ordered Connectors, MCP Clients, npm,
n8n, WP-CLI. Add a Connect\MethodRegistry with a new
acrossai_mcp_manager_connect_methods filter that mirrors the existing
ServerTabs\\Registry entry contract exactly (slug, label, priority,
capability, render_callback, visible_callback, _builtin) with slug-keyed
last-wins dedup, so the acrossai-pro companion registers its Connectors and
n8n panels one level down instead of as top-level tabs. Extract
Registry::normalize_entries() into a shared
Includes\\Utilities\\RegistryEntryNormalizer so both registries validate entries
with identical semantics. (Superseded by plan.md D-2: hydrate() does NOT
follow it — it instantiates FilteredServerTab and maps AbstractServerTab
instances, both admin-layer types that A3 forbids from includes/. Its shared
loop body becomes FilteredServerTab::hydrate_entries() instead.) Keep NpmTab, ClientsTab, WpCliTab and
AIConnectorsPromoTab as classes — only re-slot their priority() to the panel
scale (ai-connectors 10, clients 20, npm 30, 40 reserved for the companion's
n8n, wp-cli 50) and repoint their submit_target_url to the new
ConnectTab::method_url() builder. Default the active panel to the requested
?method=, else to MCP Clients when
Includes\\Utilities\\LocalEnvironment::needs_tls_bypass() is true, else to
the first visible panel. Preserve every legacy deep link (?tab=npm,
?tab=clients&client=X, ?tab=wp-cli, ?tab=ai-connectors&panel=X,
?tab=n8n&panel=X) through IN-PLACE normalization in
Settings::render_edit_page()'s existing legacy_slug_map — never a redirect,
because admin_enqueue_scripts fires before render and every enqueue gate
keyed on the legacy URL form (including the companion's) must keep matching.
Build NO compatibility layer for an un-migrated companion: the two plugins
ship as a matched pair and the operator coordinates both updates, so
Registry::for_server() stays a straight seed-filter-normalize-sort with no
partitioning and the level-2 nav emits exactly one URL shape.
Do not touch ConnectionMethodRegistry category keys,
QuickConnectController::VALID_METHODS, the ClientRendererController renderer
map, embed transport keys, or the Quick Connect wizard's JS method keys —
they are different vocabularies that merely look similar. Memory hygiene:
annotate DEC-SERVER-TAB-CLASS-HIERARCHY and D41
DEC-SERVER-TAB-REGISTRY-DEDUP-LAST-WINS with forward pointers to the new
two-level registry topology."

# 3. Plan + guard + security
/speckit.memory-md.plan-with-memory
/speckit.architecture-guard.governed-plan
/speckit.security-review.plan

# 4. Tasks + guard
/speckit.tasks
/speckit.architecture-guard.governed-tasks

# 5. Implement + quality checks
/speckit.architecture-guard.governed-implement
composer dump-autoload
composer run phpcs
composer run phpstan
npm run build
npm run lint:js

# 6. Review + memory + commit
/speckit.analyze
/speckit.architecture-guard.architecture-review
/speckit.security-review.staged
/speckit.memory-md.capture-from-diff
/speckit.git.commit
```

### Detailed Description for `/speckit.specify`

> **Before writing a single line of code, read and internalize these governing
> documents in full:**
>
> 1. `AGENTS.md` + `.specify/memory/constitution.md` — singleton pattern, the
>    A1 hook-registration rule (all `add_action`/`add_filter` in
>    `includes/Main.php`), §VI DRY, §IV DataViews mandate and its
>    pre-approved carve-outs, Definition-of-Done gates.
> 2. `docs/extending-per-server-tabs.md` — the **published** third-party
>    contract for `acrossai_mcp_manager_server_tabs`, including the priority
>    slot table. This feature changes that table; the doc is part of the
>    deliverable, not an afterthought.
> 3. `admin/Partials/ServerTabs/{AbstractServerTab,Registry,FilteredServerTab}.php`
>    — the framework being extended one level down. In particular
>    `AbstractServerTab::server_edit_url()` (line ~499) and its docblock at
>    lines ~481-498, which documents the **raw-URL contract** that the new
>    `ConnectTab::method_url()` must replicate verbatim.
> 4. `public/Renderers/MCPClientsBlock.php` lines ~114-162 — the existing
>    in-tab sub-nav precedent (`?client=` pills, `add_query_arg()` chained
>    onto a raw `submit_target_url`, default = first registered client).
> 5. The companion's two tabs, read in full before designing the panel
>    contract:
>    `/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/plugins/acrossai-pro/admin/ServerTabs/AIConnectorsTab.php`
>    and `.../N8nTab.php`, plus their registrations at
>    `.../acrossai-pro/includes/Main.php:1233,1239` and their enqueue gates at
>    `.../acrossai-pro/admin/Main.php:79,239,262`.
> 6. `includes/Utilities/LocalEnvironment.php` — `needs_tls_bypass()` is the
>    local-default trigger. Note it is **not memoized** and fires
>    `apply_filters` on every call; call it at most once per render.
>
> Every decision — panel-registry topology, query-param naming, legacy-URL
> handling, priority re-slotting — must be justified against the above. If a
> choice is not explicitly covered, default to the shape the tab Registry
> already uses one level up. Do not write code that would fail any
> Definition-of-Done gate: PHPStan level 8, PHPCS zero errors AND zero
> warnings, ESLint zero errors, all `__()` calls using text domain
> `'acrossai-mcp-manager'` (host) / `'acrossai-pro'` (companion).
>
> **Public API artifacts to preserve verbatim (grep-gate before + after):**
>
> - Filter `acrossai_mcp_manager_server_tabs` — name, signature
>   `apply_filters( $tabs, array $server ): array`, and entry shape. Third
>   parties depend on it; it keeps working for non-connection tabs.
> - `\AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\AbstractServerTab` —
>   the companion extends it **across the plugin boundary**. No signature
>   change to `slug()`, `label()`, `priority()`, `visible_for()`,
>   `render()`, `render_body()`, or any protected helper.
> - `\AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\Registry::FILTER_NAME`,
>   `::instance()`, `::for_server()`, `::visible_tabs()`, `::render()`,
>   `::all_tabs()`.
> - `AbstractServerTab::server_edit_url()` — still used by third-party tabs
>   and by every non-Connect built-in tab.
> - Everything under `public/Renderers/` and `public/Discovery/` — untouched.
>
> **Explicitly NOT to be renamed (same strings, different subsystems):**
>
> | String | Where | What it actually is |
> | --- | --- | --- |
> | `'npm'`, `'clients'` | `includes/REST/ClientRendererController.php` (~:210-211) | Public renderer-slug map for the `acrossai_mcp_render_client_block` action + REST contract |
> | `'npm'` | `public/Renderers/NpmClientBlock.php` `slug()` / DTO `category` | Renderer identity |
> | `'npm'`, `'clients'`, `'ai_connectors'` | `public/Discovery/ConnectionMethodRegistry.php` | Discovery API category keys |
> | `'npm'` | `includes/Embeds/NpmEmbedTransport.php` | Embed transport key |
> | `'connectors'`, `'client'`, `'npm'`, `'wpcli'` | `includes/REST/QuickConnectController.php::VALID_METHODS` | Wizard REST payload vocabulary — **not** the new `?method=` vocabulary, despite the name |
> | `'npm'`, `'wpcli'` | `src/js/quick-connect/**` | Wizard JS method keys |
>
> **Pre-flight greps** (record the baseline; every hit must still resolve or
> be deliberately migrated):
>
> ```
> # Tab-slug references across both plugins
> grep -rEn "tab=(npm|clients|wp-cli|ai-connectors|n8n)|'(npm|clients|wp-cli|ai-connectors|n8n)'" \
>     --include='*.php' --include='*.jsx' --include='*.js' --include='*.md' \
>     admin/ includes/ public/ src/ docs/ tests/ README.txt \
>     ../acrossai-pro/admin/ ../acrossai-pro/includes/
>
> # New param must not collide with anything already reading ?method=
> grep -rEn "\\\$_GET\['method'\]|'method'\s*=>" --include='*.php' \
>     admin/ includes/ public/ ../acrossai-pro/admin/ ../acrossai-pro/includes/
>
> # Enqueue gates keyed on ?tab= (host + companion)
> grep -rEn "\\\$_GET\['tab'\]" --include='*.php' admin/ includes/ ../acrossai-pro/
> ```
>
> **Current → target slug/priority map (the data-preservation contract):**
>
> | Surface | Today | After 084 |
> | --- | --- | --- |
> | `overview` | tab, priority 10 | unchanged |
> | `npm` | tab, priority 20 | **panel**, priority 30 |
> | `clients` | tab, priority 30 | **panel**, priority 20 |
> | `ai-connectors` | tab, priority 35 (host promo; companion override) | **panel**, priority 10 (same override mechanism) |
> | `n8n` | tab, priority 36 (companion only) | **panel**, priority 40 (slot reserved by host, filled by companion) |
> | `wp-cli` | tab, priority 40 | **panel**, priority 50 |
> | `connect` | — | **tab**, priority 20 (NEW) |
> | `tools` … `danger-zone` | tabs, 50…100 | unchanged |
>
> Built-in top-level tab count drops 11 → 8. Priority band **21-49 becomes
> vacant** at tab level; third-party tabs that documented themselves there
> (the published example uses 45) keep a sane position between Connect and
> Tools, but a tab that deliberately picked 25 to interleave with the old
> connection tabs will visibly reposition. This is unavoidable and must be
> called out in `docs/extending-per-server-tabs.md`.
>
> ---
>
> **TASK-1 — Extract the shared entry normalizer**
>
> Files: `includes/Utilities/RegistryEntryNormalizer.php` (NEW),
> `admin/Partials/ServerTabs/Registry.php` (delta only)
>
> Move the bodies of `Registry::normalize_entries()` and `Registry::hydrate()`
> **verbatim** into two public statics on a new `final class
> RegistryEntryNormalizer` (A11 pure-service — no singleton, no ctor):
>
> - `normalize( array $raw, string $filter_name, string $since ): array` —
>   the `_doing_it_wrong()` call sites take the filter name + version as
>   parameters instead of hardcoding `Registry::FILTER_NAME`.
> - `hydrate( array $entries, array $builtin_map ): array` — the built-in
>   `slug => instance` map is injected rather than read from `all_tabs()`.
>
> `Registry::normalize_entries()` / `::hydrate()` become one-line private
> delegates so no other call site inside `Registry` moves and the existing
> docblocks stay where reviewers expect them.
>
> Rationale (constitution §VI): the method registry in TASK-3 needs identical
> validation, and that logic carries three subtle behaviours a copy would
> drift on — slug-keyed **last-wins** dedup, `_builtin` short-circuiting the
> label/`render_callback` checks, and `_index` preserving the winner's
> insertion position for stable priority ties. This validation is also the
> exact surface the companion's entries pass through, so divergence would be
> cross-plugin-visible.
>
> While here: fix the stale docblock on `normalize_entries()` that still
> claims *"Duplicate slug → first-registration wins"* — the code has done
> last-wins since F040.
>
> This TASK is a pure refactor. All existing `RegistryTest` cases MUST pass
> unchanged before proceeding to TASK-2.
>
> ---
>
> **TASK-2 — `ConnectTab` (the container)**
>
> Files: `admin/Partials/ServerTabs/ConnectTab.php` (NEW)
>
> `final class ConnectTab extends AbstractServerTab`. Members:
>
> - `public const SLUG = 'connect'` and `public const QUERY_VAR = 'method'`.
> - `public const LEGACY_TAB_METHODS` — the five pre-084 tab slugs mapped to
>   their method names (identity mapping). **Load-bearing public constant**:
>   `Settings::render_edit_page()` seeds its legacy slug map from the keys,
>   `Registry` partitions companion registrations on it, and
>   `requested_method()` recovers the method from a legacy `?tab=` deep link.
> - `slug()`, `label()` = `__( 'Connect', 'acrossai-mcp-manager' )`,
>   `priority()` = `20`.
> - `public static function method_url( array $server, string $method, array $extra_args = array() ): string`
>   — the canonical L2 URL builder: `page`, `action=edit`, `server`,
>   `tab=connect`, `method=<sanitize_key>`, plus `$extra_args` merged verbatim
>   for L3 chaining. **MUST return a RAW, unescaped URL** — copy the warning
>   docblock from `AbstractServerTab::server_edit_url()` verbatim. Public and
>   static because the companion calls it (TASK-9); treat the signature as
>   frozen public API from merge forward.
> - `render_body()` — fetch visible panels, resolve the active one, emit the
>   L2 nav and then the active panel's own `render()`. Empty-panel case
>   renders a plain `mcp-tab-panel` notice.
> - `private requested_method()` — `?method=` when present, else recover from
>   `?tab=` through `LEGACY_TAB_METHODS`, else `''`.
> - `private resolve_active_panel( array $panels, string $requested )` —
>   exact slug match → else the `clients` panel **when
>   `LocalEnvironment::needs_tls_bypass()`** → else `$panels[0]`. The
>   local check runs at most once per render (only on the miss path), so no
>   memoization is needed.
> - `private render_method_nav()` — skip entirely when fewer than two methods
>   are visible; one `<a>` per method, built with `method_url()`, with
>   `aria-current="page"` on the active one. Exactly ONE URL shape — the
>   second, legacy-shaped branch is not built (see TASK-4).
>
> ---
>
> **TASK-3 — `Connect\MethodRegistry` (the level-2 registry)**
>
> Files: `admin/Partials/ServerTabs/Connect/MethodRegistry.php` (NEW)
>
> Mirrors `Registry` one level down, reusing `AbstractServerTab` as the method
> type and `FilteredServerTab` as the third-party adapter unchanged — methods
> *are* tabs, nested. Members:
>
> - `public const FILTER_NAME = 'acrossai_mcp_manager_connect_methods'`.
> - `all_methods()` seeding, in order: `AIConnectorsPromoTab` (10),
>   `ClientsTab` (20), `NpmTab` (30), **slot 40 deliberately empty and
>   commented as reserved for the companion's n8n**, `WpCliTab` (50).
> - `for_server()` = seed → `apply_filters` →
>   `RegistryEntryNormalizer::normalize/hydrate` → `usort` by `priority()`.
> - `visible_methods()` — filters by `visible_for()`, same as
>   `Registry::visible_tabs()`.
>
> Class docblock MUST cross-reference the unrelated
> `Public\Discovery\ConnectionMethodRegistry` (different layer, untouched per
> FR-020) so neither is grepped in mistake for the other.
>
> Registers **no hooks** — it is a lazily-instantiated singleton reached from
> the render path, so A1 holds by construction.
>
> ---
>
> **TASK-4 — Rewire `Registry`**
>
> Files: `admin/Partials/ServerTabs/Registry.php` (delta only)
>
> `all_tabs()`: remove `NpmTab`, `ClientsTab`, `AIConnectorsPromoTab`,
> `WpCliTab`; insert `new ConnectTab()` directly after `OverviewTab`. Built-in
> count 11 → 8. Swap the two extracted private methods for
> `RegistryEntryNormalizer` delegates (TASK-1).
>
> **That is the entire task.** Per spec Clarifications (2026-09-07) no
> compatibility layer is built for an un-migrated companion — the two plugins
> ship as a matched pair and the operator coordinates both updates. So
> `for_server()` keeps its current straight-line shape (seed → filter →
> normalize → hydrate → sort): **no** entry partitioning, **no**
> `legacy_connect_entries()`, **no** absorb flag, and the tab filter continues
> to be applied from exactly **one source location**. (Note: that is a
> source-call-site count, not a runtime count — `Registry` does not memoize, so
> the filter is applied twice per edit-page render today, once for the strip via
> `visible_tabs()` and once for the body via `render()`. Unchanged by F084.)
>
> Consequence to accept knowingly: on a site running this release against an
> **un-updated** companion, the companion's `ai-connectors` and `n8n` entries
> no longer collide with any built-in slug, so they render as two orphan
> top-level tabs while the Connect tab's Connectors method shows the free promo
> card. Nothing fatals; the fix is to update the companion. This is recorded in
> the spec's Edge Cases as an unsupported pairing.
>
> ---
>
> **TASK-5 — Re-slot the four migrated tab classes**
>
> Files: `admin/Partials/ServerTabs/{AIConnectorsPromoTab,ClientsTab,NpmTab,WpCliTab}.php`
> (deltas only)
>
> Keep every class where it is, under its current name. Do **not** rename to
> `*Panel` and do **not** move into `ServerTabs/Connect/`: they already extend
> the correct base and already emit `mcp-tab-panel` bodies, so a rename buys
> nothing while churning ~950 LOC of `git blame` on top of an already
> cross-plugin change. Reviewability of the host PR is the binding constraint.
>
> - `AIConnectorsPromoTab::priority()` → `10`. Its `'active' === $state`
>   self-suppression branch stays exactly as-is (it remains the safety net if
>   the companion registers but its render callback throws).
> - `ClientsTab::priority()` → `20`; `submit_target_url` becomes
>   `ConnectTab::method_url( $server, 'clients' )`.
> - `NpmTab::priority()` → `30`; `submit_target_url` becomes
>   `ConnectTab::method_url( $server, 'npm' )`.
> - `WpCliTab::priority()` → `50`. It builds no URLs, so that is the entire
>   diff; its ~145 lines of inline command-block logic do not move.
>
> Add one docblock line to each: registered as a Connect **method**
> (`?tab=connect&method=<slug>`) since this release, not as a top-level tab;
> class name retained deliberately.
>
> The `ClientsTab` change is the one to verify carefully:
> `MCPClientsBlock::render_subnav()` does
> `add_query_arg( 'client', $slug, $context['submit_target_url'] )`, which now
> chains onto `…&tab=connect&method=clients` and yields
> `…&tab=connect&method=clients&client=<slug>`. This works **only** because
> `method_url()` returns a raw URL.
>
> ---
>
> **TASK-6 — Legacy URL normalization in `Settings.php`**
>
> Files: `admin/Partials/Settings.php` (delta only, the `$legacy_slug_map`
> block inside `render_edit_page()`)
>
> Extend the existing map with
> `array_fill_keys( array_keys( ConnectTab::LEGACY_TAB_METHODS ), ConnectTab::SLUG )`
> so all five legacy slugs dispatch to the Connect tab. No second map value is
> needed: the legacy tab slug **is** the method name, and
> `ConnectTab::requested_method()` reads the untouched `$_GET['tab']` to
> recover it.
>
> **In-place normalization only — never `wp_safe_redirect()`.** See CONSTRAINTS
> for the full rationale; the short version is that `admin_enqueue_scripts`
> fires long before `render_edit_page()`, so redirecting would strip the
> legacy URL form that both this plugin's and the companion's enqueue gates
> match on.
>
> ---
>
> **TASK-7 — Update the server list-table quick-link pills (Actions column)**
>
> Files: `admin/Partials/MCPServerListTable.php` (delta only, the
> `$quick_links` block at ~:276-312)
>
> The Actions column of the MCP Servers list table renders `Edit` +
> `Enable/Disable` buttons followed by a row of deep-link pills:
> **Connectors · Access Control · Abilities · MCP Clients · Quick Connect via
> AcrossAI**. Two of those five (**Connectors** and **MCP Clients**) point at
> tabs this feature is merging, so they break to the Overview fallback unless
> updated — they are the most visible casualty of the merge and the single
> highest-traffic entry point into the affected screens.
>
> The current structure keys the array **by tab slug** and uses that key
> directly as the `tab` query arg:
>
> ```php
> $quick_links = array(
>     'ai-connectors'  => array( 'label' => …, 'icon' => 'admin-plugins' ),
>     'access-control' => array( 'label' => …, 'icon' => 'shield' ),
>     'abilities'      => array( 'label' => …, 'icon' => 'superhero-alt' ),
>     'clients'        => array( 'label' => …, 'icon' => 'admin-users' ),
> );
> foreach ( $quick_links as $tab_slug => $meta ) {
>     $tab_url = add_query_arg( array( …, 'tab' => $tab_slug ), admin_url( 'admin.php' ) );
>     …
> }
> ```
>
> That slug-as-key shape cannot express a second query arg, so reshape each
> entry to carry its own `args` map and merge it in the loop:
>
> ```php
> $quick_links = array(
>     'ai-connectors'  => array(
>         'label' => __( 'Connectors', 'acrossai-mcp-manager' ),
>         'icon'  => 'admin-plugins',
>         'args'  => array( 'tab' => ConnectTab::SLUG, ConnectTab::QUERY_VAR => 'ai-connectors' ),
>     ),
>     'access-control' => array( …, 'args' => array( 'tab' => 'access-control' ) ),
>     'abilities'      => array( …, 'args' => array( 'tab' => 'abilities' ) ),
>     'clients'        => array(
>         'label' => __( 'MCP Clients', 'acrossai-mcp-manager' ),
>         'icon'  => 'admin-users',
>         'args'  => array( 'tab' => ConnectTab::SLUG, ConnectTab::QUERY_VAR => 'clients' ),
>     ),
> );
>
> foreach ( $quick_links as $meta ) {
>     $tab_url = add_query_arg(
>         array_merge(
>             array(
>                 'page'   => AdminPageSlugs::PARENT,
>                 'action' => 'edit',
>                 'server' => (int) $item['id'],
>             ),
>             $meta['args']
>         ),
>         admin_url( 'admin.php' )
>     );
>     // …unchanged sprintf, but note $tab_slug is no longer in scope…
> }
> ```
>
> Use the `ConnectTab::SLUG` / `ConnectTab::QUERY_VAR` constants, never string
> literals — this is the third site (after the L2 nav and `Settings.php`) that
> would otherwise hardcode the new URL shape.
>
> The **Quick Connect via AcrossAI** pill emitted after the loop (~:314-330)
> is unaffected: it is not tab-based (`?quick-connect=1&step=1&server=N`, no
> `?tab=`), as its own inline comment states. Do not touch it. Access Control
> and Abilities keep their single `tab` arg and must be verified unchanged.
>
> **DECIDED (2026-09-07, product owner) — keep all five pills, repointed.**
> The row keeps `Connectors · Access Control · Abilities · MCP Clients ·
> Quick Connect via AcrossAI`; only the first and fourth change their URL, per
> the code sketch above. Rationale:
>
> - The two pills are **deep links, not tab mirrors**. Post-merge they open
>   the same tab but different panels, so each still lands the operator
>   exactly where they wanted in **one click** — identical to today. The merge
>   declutters the tab strip; it is not a mandate to thin the row shortcuts.
> - The **Connectors pill is the free-tier upsell surface**. On installs
>   without acrossai-pro it is the primary path to the promo card; removing it
>   from the row would hide the upgrade pitch behind an extra click.
>
> **Rejected alternative** (recorded so this is not relitigated): collapsing
> both into a single **Connect** pill pointing at `?tab=connect` with no
> `?method=`. It would trim the row from five pills to four (it currently
> wraps onto two lines), but costs an extra click to reach a specific method
> AND interacts badly with the local-aware default from TASK-2 — on a local
> site a generic "Connect" click would always land on MCP Clients, even when
> the operator wanted Connectors, which reads as the button ignoring them.
> A generic pill cannot express intent, so the default rule that *helps* an
> explicit "MCP Clients" click *hurts* an ambiguous one.
>
> Implementation consequence: **do not** add, remove, or relabel any pill in
> this TASK. The diff is exactly two `args` values plus the loop reshape.
>
> Note the pill styling (`.acrossai-actions-quicklinks .acrossai-quicklink`,
> `src/scss/backend.scss:275-310`) is slug-agnostic — no SCSS change is needed
> for this TASK regardless of which option is chosen.
>
> ---
>
> **TASK-8 — Level-2 nav styling**
>
> Files: `src/scss/backend.scss` (delta only)
>
> Add `.acrossai-connect-methods-nav` + `.acrossai-connect-method` as a
> **square segmented control**, comma-appended to the existing
> `.acrossai-client-tab` colour/hover/active rules so the compiled output for
> the round L3 client pills stays byte-identical.
>
> Do **not** reuse WP core's `.nav-tab-wrapper` for L2: the companion already
> uses it at L3 inside the Connectors panel, so a third identical strip would
> read as a rendering bug rather than a hierarchy. Square L2 segments versus
> round L3 pills is what keeps three stacked nav rows legible. Optionally zero
> the L3 row's top padding inside `.acrossai-connect__panel` so the two rows
> do not double their vertical gap.
>
> Run `npm run build`; `build/js/backend.css` is committed in this repo.
>
> ---
>
> **TASK-9 — Companion: host-capability probe + panel registration**
>
> Files (all under `../acrossai-pro/`):
> `includes/Compat/HostCapabilities.php` (NEW), `includes/Main.php` (delta),
> `admin/ServerTabs/AIConnectorsTab.php` (delta),
> `admin/ServerTabs/N8nTab.php` (delta), `admin/Main.php` (delta)
>
> This is the **companion PR**. All host-shape knowledge is centralised in one
> new class:
>
> - `HostCapabilities::has_connect_tab(): bool` — `class_exists()` probe on
>   the host's `ConnectTab` FQN.
> - `HostCapabilities::method_url( array $server, string $method, array $extra_args = array() ): string`
>   — delegates to the host's `ConnectTab::method_url()` when present, else
>   falls back to the pre-084 top-level `?tab=<method>` shape. Returns a raw
>   URL; callers `esc_url()` at output.
> - `HostCapabilities::is_connect_method_request( string $method ): bool` —
>   true for **both** `?tab=connect&method=<m>` and the legacy `?tab=<m>`
>   form. On a bare `?tab=connect` with no `?method=` it returns false by
>   design: the host may have defaulted to a different panel (local default),
>   and enqueuing the wrong bundle is worse than the one lost case (a bookmark
>   of bare `?tab=connect` on a non-local site renders the Connectors panel
>   without JS until any pill is clicked — note it in the companion README).
>
> `includes/Main.php`: keep both existing `acrossai_mcp_manager_server_tabs`
> registrations (they early-return via the probe on a new host) and add two
> registrations on `acrossai_mcp_manager_connect_methods`. Extract shared
> private `ai_connectors_entry( int $priority )` / `n8n_entry( int $priority )`
> builders so the tab (35/36) and panel (10/40) registrations differ only in
> that argument. **Preserve the existing gating asymmetry exactly**: n8n keeps
> its `can_use_premium_code()` + `N8nTab::is_enabled()` gates (as the panel's
> `visible_callback`), ai-connectors keeps having none. Do not "fix" that here.
>
> `AIConnectorsTab::panel_url()` and `N8nTab::panel_url()` each collapse to a
> one-line delegation to `HostCapabilities::method_url( $server, '<method>',
> [ 'panel' => sanitize_key( $panel ) ] )`, removing the hardcoded
> `'tab' => 'ai-connectors'` / `'tab' => 'n8n'` literals (and, in the
> AIConnectors case, the hardcoded `'page' => 'acrossai_mcp_manager'`). Drop
> `N8nTab`'s now-unused `AdminPageSlugs` import per A6.
>
> `admin/Main.php`: replace the exact-match `$_GET['tab']` comparisons in both
> enqueue gates with `HostCapabilities::is_connect_method_request()`. The
> `panel=connections` cross-bundle hop is unchanged — `?panel=` did not move.
>
> ---
>
> **TASK-10 — Tests**
>
> Files: `tests/phpunit/Admin/ServerTabs/RegistryTest.php` (update),
> `tests/phpunit/Admin/ServerTabs/ConnectTabTest.php` (NEW),
> `tests/phpunit/Admin/ServerTabs/Connect/MethodRegistryTest.php` (NEW),
> `tests/phpunit/Includes/Utilities/RegistryEntryNormalizerTest.php` (NEW),
> `../acrossai-pro/tests/Unit/Admin/MainN8nConnectionsEnqueueTest.php` (update)
>
> See the Manual Verification Checklist for the per-case list. Note the
> WP-dependent suites in this repo are broken repo-wide independently of this
> feature — write the tests correctly, run what can run via the scratch
> PHPUnit 9.6 + polyfills runner, and gate merge on the manual matrix.
>
> ---
>
> **TASK-11 — Docs + memory hygiene**
>
> Files: `docs/extending-per-server-tabs.md`, `README.txt`,
> `../acrossai-pro/README.md`, `docs/planings-tasks/README.md`,
> `docs/memory/*` (via `/speckit.memory-md.capture-from-diff`)
>
> - `docs/extending-per-server-tabs.md`: update the priority table (overview
>   10, **connect 20**, tools 50 …), mark **21-49 as vacated** with a pointer
>   ("if your tab is a connection method, register a panel instead"), correct
>   the "ten built-ins" prose, add an **"Adding a Connect method"** section
>   (filter name, identical entry shape — link the existing table rather than
>   duplicating it, method slots, `?tab=connect&method=` deep-link form, L3
>   chaining via `ConnectTab::method_url()` **with the raw-URL warning**), and
>   a **"Migrating from top-level tabs"** section: the five slugs that moved
>   down a level, the new filter to register them on, and a plain statement
>   that a companion registering them the old way now renders orphan
>   top-level tabs (no compatibility layer — matched-pair release).
> - `README.txt` `= Unreleased =`: the merge, the label/slug, `?method=`, the
>   local-aware default, the new filter, "old bookmarks keep working — no
>   redirect", the priority-table change, and the minimum companion version.
> - Companion `README.md`: `= Unreleased =`, fix its example URL to
>   `…&tab=connect&method=ai-connectors`, note the minimum host version and
>   the bare-`?tab=connect` caveat.
> - `docs/planings-tasks/README.md`: append the F084 row.
> - Memory: annotate `DEC-SERVER-TAB-CLASS-HIERARCHY` and
>   `D41 / DEC-SERVER-TAB-REGISTRY-DEDUP-LAST-WINS` with forward pointers to
>   the two-level topology; the last-wins mechanism now operates at both
>   levels, which is exactly what makes the promo→real swap keep working one
>   level down.

---

## CONSTRAINTS

> These are the invariants a reviewer blocks merge on.

- **No redirect for legacy URLs — in-place normalization only.**
  `admin_enqueue_scripts` fires long before `Settings::render_edit_page()`. A
  redirect to the canonical `?tab=connect&method=…` form would mean only a
  gate matching the *new* form ever enqueues — an un-updated companion would
  render its panels with no JS and no CSS. Leaving the request URL untouched
  keeps **both** gate forms matching whichever URL the browser actually
  requested, preserves any `?panel=` / `?client=` args for free, avoids a new
  `admin_init` hook (and the headers-already-sent risk that comes with
  redirecting after the admin header is emitted), and keeps the frozen
  `specs/**/quickstart.md` verification scripts passing unmodified. Self-
  healing still happens: every link the plugin renders emits the canonical
  form, so the first click promotes the user.
- **`ConnectTab::method_url()` MUST return a raw, unescaped URL.**
  `MCPClientsBlock::render_subnav()` chains `add_query_arg()` onto it.
  Escaping inside the builder produces `&#038;` → `&amp;#038;` and silently
  breaks every client pill. A test must assert the returned string contains a
  bare `&`.
- **`ConnectTab::method_url()` becomes cross-plugin public API** the moment
  the companion calls it. Never rename it, never change its signature, never
  make it non-static.
- **Do NOT change the `acrossai_mcp_manager_server_tabs` filter name,
  signature, or entry shape.** Third parties depend on it and it keeps
  working for non-connection tabs.
- **Do NOT modify `AbstractServerTab`'s public/protected surface.** The
  companion extends it across the plugin boundary; any signature change is a
  breaking cross-plugin change.
- **Build NO compatibility layer for an un-migrated companion** (spec
  Clarifications 2026-09-07). `Registry::for_server()` keeps its straight-line
  shape, the tab filter fires once per request, and the level-2 navigation
  emits exactly one URL shape. Reviewers reject any reintroduction of entry
  partitioning, absorbed-slug tracking, or a second legacy URL branch — the
  two plugins ship as a matched pair instead.
- **`?method=` must not collide.** Run the pre-flight grep for
  `$_GET['method']` across both plugins before merge.
- **Preserve the companion's gating asymmetry**: n8n is premium+option gated;
  ai-connectors is not. Do not "fix" that in this feature.
- **Do not touch the look-alike strings** enumerated in the specify
  description's table (discovery categories, wizard `VALID_METHODS`, renderer
  map, embed transport keys, wizard JS keys).
- **Do not add memoization to `Registry::for_server()`** in this feature (see
  TASK-4 rationale); log it as a follow-up.

---

## Manual Verification Checklist

### TASK-1 — Shared normalizer
- [ ] `includes/Utilities/RegistryEntryNormalizer.php` exists; `final class`, no
      singleton, no ctor, no hooks registered.
- [ ] `Registry::normalize_entries()` and `::hydrate()` are one-line
      delegates; no behavioural diff.
- [ ] Stale "first-registration wins" docblock corrected to last-wins.
- [ ] Every pre-existing `RegistryTest` case passes **unchanged** at this
      commit (pure-refactor gate).

### TASK-2/3 — ConnectTab + method registry
- [ ] `ConnectTab::method_url( [ 'id' => 42 ], 'npm' )` contains
      `page=`, `action=edit`, `server=42`, `tab=connect`, `method=npm`, and a
      **bare `&`** (not `&#038;`).
- [ ] `method_url( …, 'Bad Method!' )` → `method=badmethod`.
- [ ] `method_url( …, 'ai-connectors', [ 'panel' => 'settings' ] )` also
      contains `panel=settings`.
- [ ] `Connect\MethodRegistry::all_methods()` returns exactly
      `[ ai-connectors, clients, npm, wp-cli ]` with priorities
      `[ 10, 20, 30, 50 ]` — slot 40 verifiably empty.
- [ ] A filter entry at priority 40 lands between npm and wp-cli.
- [ ] A filter entry with `slug => 'ai-connectors'` **replaces** the promo
      panel (last-wins) — promo markup absent from output.
- [ ] Malformed entries (no slug / no label / non-callable render_callback)
      are dropped; `_doing_it_wrong` fires under `WP_DEBUG`.
- [ ] `visible_methods()` honours `capability` (test as a subscriber) and
      `visible_callback`.

### TASK-4 — Registry rewire
- [ ] Top-level nav shows **8** tabs, `Connect` immediately after `Overview`.
- [ ] `?tab=npm` etc. no longer appear as top-level tabs.
- [ ] `Registry::for_server()` still applies the tab filter from **exactly one
      source location** — verify with
      `grep -c "apply_filters( self::FILTER_NAME" admin/Partials/ServerTabs/Registry.php`
      (expected `1`), **not** with a runtime counting callback: `Registry` does
      not memoize, so a callback observes 2 firings per edit-page render (strip
      + body) on a healthy tree. See SEC-084-008.
- [ ] A third-party tab registered on the tab filter with a NON-connection
      slug still appears as a top-level tab, unchanged.
- [ ] `RegistryEntryNormalizer` delegates are in place and every pre-existing
      `RegistryTest` case still passes.

### TASK-5 — Migrated panels
- [ ] Panel order on screen: Connectors, MCP Clients, npm, n8n, WP-CLI.
- [ ] MCP Clients panel's client pills produce
      `…&tab=connect&method=clients&client=<slug>` and switch correctly.
- [ ] npm panel's form still submits to the right URL and its feature gate
      (`acrossai_mcp_npm_login_enabled`) still works.
- [ ] WP-CLI panel renders all three command blocks unchanged.
- [ ] With acrossai-pro deactivated, the Connectors panel shows the promo
      card; with it active, the promo is suppressed.

### TASK-2 — Local-aware default
- [ ] On this `.local` dev site: `?tab=connect` with no `?method=` opens
      **MCP Clients**; the TLS-bypass notice is visible in that panel.
- [ ] Force non-local (`add_filter( 'home_url', … 'https://example.com' )` or
      `wp_get_environment_type() === 'production'`): same URL opens
      **Connectors**.
- [ ] `LocalEnvironment::needs_tls_bypass()` asserted directly first, so a
      failure points at the right layer.
- [ ] With the `clients` panel filtered away on a local site, the default
      falls back to the first visible panel without error.

### TASK-6 — Legacy URLs (browser, all five)
- [ ] `?tab=npm` → Connect ▸ npm.
- [ ] `?tab=clients&client=cursor` → Connect ▸ MCP Clients ▸ Cursor pill.
- [ ] `?tab=wp-cli` → Connect ▸ WP-CLI.
- [ ] `?tab=ai-connectors&panel=settings` → Connect ▸ Connectors ▸ Settings.
- [ ] `?tab=n8n&panel=connections` → Connect ▸ n8n ▸ Connections.
- [ ] None of the above falls through to Overview.
- [ ] The browser URL is **unchanged** (no redirect); the first nav click
      promotes it to the canonical form.

### TASK-7 — Server list-table Actions column
- [ ] **Connectors** pill → `?tab=connect&method=ai-connectors`, opens the
      Connectors panel (promo card when Pro is inactive, real panel when
      active) — NOT Overview.
- [ ] **MCP Clients** pill → `?tab=connect&method=clients`, opens the MCP
      Clients panel — NOT Overview.
- [ ] **Access Control** and **Abilities** pills unchanged and still working
      (regression guard — they share the rewritten loop).
- [ ] **Quick Connect via AcrossAI** pill unchanged
      (`?quick-connect=1&step=1&server=N`, no `?tab=`) and still opens the
      wizard at Step 1 with this row's server preselected.
- [ ] All pills render with their dashicon + label, and the row still wraps
      cleanly at narrow widths (the row currently spans two lines).
- [ ] Pills carry no hardcoded `'connect'` / `'method'` literals — grep for
      `ConnectTab::SLUG` and `ConnectTab::QUERY_VAR` in the diff.
- [ ] **Still exactly FIVE pills**, same labels, same icons, same order
      (decision above) — no pill added, removed, or relabelled. The diff is
      two `args` values plus the loop reshape, nothing more.
- [ ] The Connectors pill still reaches the promo card on an install with
      acrossai-pro **inactive** (free-tier upsell path preserved).

### TASK-8 — Level-2 styling
- [ ] L2 and L3 both render in the WP `.nav-tab` idiom at 13px and 12px against the
      strip's 14px; three
      stacked nav rows are visually distinguishable.
- [ ] L2 nav is suppressed entirely when only one panel is visible.
- [ ] `aria-current="page"` on exactly one L2 item.
- [ ] `npm run build` regenerates `build/js/backend.css`.

### TASK-9 — Companion + degradation matrix
- [ ] **new host + new companion**: five panels; Connectors and n8n panels
      fully styled; Revoke / Approve / Generate / Copy buttons all live.
- [ ] **new host + OLD companion** (check out the pre-084 build): no orphan
      top-level tabs; Connectors + n8n appear as panels **with** their JS/CSS
      loaded (click a live button to prove it); `_doing_it_wrong` notices
      under `WP_DEBUG`.
- [ ] **old host + new companion**: today's tab bar, unchanged behaviour.
- [ ] **new host, companion deactivated**: four panels, Connectors shows the
      promo card.
- [ ] n8n panel appears only when premium is active AND
      `acrossai_n8n_enabled` is on; hidden otherwise in every combination.
- [ ] Companion's `panel=connections` cross-bundle enqueue still fires.

### Quality gates (all must be green before commit)
- [ ] `composer run phpcs` — zero errors, zero warnings (host + companion).
- [ ] `composer run phpstan` — level 8, zero errors.
- [ ] `npm run lint:js` — zero errors, zero warnings.
- [ ] `npm run build` succeeds; committed `build/` artifacts updated.
- [ ] `npm run validate-packages` passes.
- [ ] PHPUnit: new + updated cases run via the scratch runner; results pasted
      into the Evidence Collation Template below.
- [ ] Pre-flight greps re-run; every recorded hit either still resolves or is
      accounted for by a TASK.

### Final full-repo audit (blocker before merge)
- [ ] `grep -rEn "'tab'\s*=>\s*'(npm|clients|wp-cli|ai-connectors|n8n)'" admin/ includes/`
      returns **zero** matches in this plugin (the companion keeps its own
      legacy-form fallback for older hosts — that is expected).
- [ ] `grep -rn "nav-tab-wrapper" admin/ | grep -i connect` returns nothing
      (L2 must not use the core tab bar).
- [ ] `grep -rn "esc_url" admin/Partials/ServerTabs/ConnectTab.php` shows
      escaping only at output, never inside `method_url()`.
- [ ] `docs/extending-per-server-tabs.md` priority table matches the shipped
      `all_tabs()` order exactly.

---

## Pre-flight Attestation

- [ ] **Deployment surface**: enumerate the sites running
      acrossai-mcp-manager with acrossai-pro active. Because no compatibility
      layer ships, the two releases MUST reach every such site together — a
      site that takes this plugin's update without the companion's shows two
      orphan tabs until the companion follows. Record the attester, the date,
      and the coordination plan here.
- [ ] **Companion version floor**: record the acrossai-pro version that first
      contains TASK-9, and cite it in both READMEs.
- [ ] **Third-party tab audit**: confirm no known third-party plugin
      registers a server tab in the 21-49 priority band that assumes
      interleaving with the old connection tabs. If one exists, notify before
      merge — its position will visibly change.

---

## Evidence Collation Template

> Fill in after the manual checklist is complete; paste real output, not
> summaries.

### 1. Tab strip before / after
```
# before (record from main):
# after:
```

### 2. Local-aware default
```
# needs_tls_bypass() on this install:
# ?tab=connect (no method) resolved panel:
# forced non-local resolved panel:
```

### 3. Legacy URL matrix (five rows, one line each)
```
?tab=npm                        ->
?tab=clients&client=cursor      ->
?tab=wp-cli                     ->
?tab=ai-connectors&panel=settings ->
?tab=n8n&panel=connections      ->
```

### 4. Degradation matrix (four combinations)
```
new host + new companion  ->
new host + OLD companion  ->
old host + new companion  ->
new host, companion off   ->
```

### 4b. Server list-table Actions column (TASK-7)
```
# pill count (MUST be 5) + layout (one line or two):
# Connectors     -> URL:                       lands on:
# Access Control -> URL:                       lands on:
# Abilities      -> URL:                       lands on:
# MCP Clients    -> URL:                       lands on:
# Quick Connect  -> URL:                       lands on:
# Connectors pill with acrossai-pro DEACTIVATED -> reaches promo card? (y/n):
```

### 5. PHPUnit
```
# command:
# result:
```

### 6. Quality gates
```
# phpcs:
# phpstan:
# lint:js:
# build:
```

### 7. Summary & merge decision
```
# blockers:
# decision:
```
