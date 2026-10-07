<!--
  SYNC IMPACT REPORT
  ==================
  Version change:    1.1.0 → 1.1.1
  Bump type:         PATCH — factual correction to Principle I's Rationale.
                     No normative rule added, removed, or altered: the MUST
                     ("each map to exactly one module") is unchanged, and
                     OAuth / AI Connectors already satisfies it. Only the
                     enumeration of which areas are active was stale.

  Principles added:    none
  Principles removed:  none
  Principles modified:
    I. Modular Architecture — Rationale only. "OAuth / Claude Connectors"
       was listed as retired (F016, D21). F095 (2026-10-07) reinstated it:
       48 files across includes/OAuth/, includes/Connectors/,
       includes/ConnectorProfiles/, includes/Discovery/ and four BerlinDB
       modules, delivering the plugin's headline free capability. The area
       is now listed as active and its modules enumerated, with the full
       retire → move → reinstate history preserved so D21 is not re-cited
       as current.

  Sections added:      none
  Sections removed:    none

  Templates reviewed:
    - .specify/templates/plan-template.md  ✅ no update needed
      (no reference to the active-feature-area list)
    - .specify/templates/spec-template.md  ✅ no update needed
      (its only OAuth mention is the token-hashing DoD item, untouched here)
    - .specify/templates/tasks-template.md ✅ no update needed

  Deferred TODOs:
    §III consent-surface exception, condition 3 ("operator-gated via a
    default-OFF option"). F095's OAuth consent screen is a browser-mediated
    surface where the user consents on their own behalf, but it is ON by
    default — a deliberate product decision recorded as C5 in
    specs/095-oauth-migration/plan.md — so it does not satisfy condition 3
    as written. Scoping that condition to credentials exceeding the
    consenting user's own authority would resolve it, but that LOOSENS a
    NON-NEGOTIABLE security principle and is therefore MINOR, not PATCH.
    Deliberately NOT folded into this amendment; it needs its own proposal
    and its own approval.

  Suggested commit:
    docs: amend constitution to v1.1.1 (reinstate OAuth / AI Connectors as an active feature area)
-->

# AcrossAI MCP Manager Constitution

## Core Principles

### I. Modular Architecture

Each feature MUST be implemented as a self-contained module with a clear, singular purpose.
Modules MUST be independently testable, extensible, and replaceable without affecting sibling modules.
Shared logic MUST be extracted to `includes/Utilities/`.
No code duplication between modules is permitted under any circumstance.

**Rationale**: Enables parallel development, isolated testing, and safe iteration on any single feature
without risking regressions in others. The active feature areas (MCP Server Management, CLI Auth,
Frontend Auth, Access Control, OAuth / AI Connectors) MUST each map to exactly one module.

The OAuth / AI Connectors area comprises `includes/OAuth/` (authorization server, discovery,
token validation), `includes/Connectors/` (profile abstraction, settings, registry),
`includes/ConnectorProfiles/` (the five vendor profiles), `includes/Discovery/`, and the four
BerlinDB modules `includes/Database/{OAuthClients,OAuthTokens,OAuthAuthCodes,ConnectorApprovedUsers}/`.

(*History: a 5th active area until F016 2026-07-07, then retired per DECISIONS.md D21
fresh-install-only retirement pattern; moved to the acrossai-pro companion by F040 2026-08-02;
**reinstated here by F095 2026-10-07**, which brought the OAuth server and connectors back into
this plugin as a free capability. The D21 retirement is spent — do not cite it as current.*)

### II. WordPress Standards Compliance

All PHP code MUST conform to WordPress Coding Standards (WPCS strict profile).
Static analysis MUST pass PHPStan at level 8 with zero errors.
JavaScript MUST pass ESLint with zero errors or warnings.
All output MUST be escaped using the most specific available WordPress escaping function.
All input MUST be sanitized at system entry points.
No deprecated WordPress functions are permitted.
The plugin MUST be compatible with WordPress 6.9+ and PHP 8.1+.
The plugin MUST be multisite-compatible unless a feature is explicitly scoped to single-site with
documented justification.

**Rationale**: Compliance ensures plugin quality, security, and long-term maintainability within the
WordPress ecosystem. Non-compliant code will not be merged.

### III. Security First (NON-NEGOTIABLE)

- All input MUST be sanitized at system boundaries using the most specific WordPress sanitization
  function (e.g., `sanitize_text_field()`, `absint()`, `wp_kses_post()`)
- All output MUST be escaped at the point of rendering (e.g., `esc_html()`, `esc_attr()`, `esc_url()`)
- All forms and AJAX endpoints MUST verify a nonce before processing any data
- All admin actions MUST enforce a capability check (`manage_options` minimum, or more granular)
- All database queries MUST use `$wpdb->prepare()` — raw interpolated queries are forbidden
- Every `register_rest_route()` MUST have an explicit `permission_callback` — `__return_true` is
  only permitted on public read endpoints, never on mutating routes
- OAuth tokens and Application Passwords MUST be stored hashed (SHA-256 minimum) — never plaintext
- File upload operations MUST validate MIME type, extension, and file size before processing
- No deprecated WordPress security functions are permitted

**Consent-surface exception to the `manage_options` rule** *(added 2026-06-30, Feature-007)*: Browser-mediated consent surfaces where the logged-in user is consenting on their own behalf to issue a credential scoped to their own capabilities (e.g. CLI device-grant consent, OAuth authorization consent, future device-grant flows) are EXEMPT from the `manage_options` minimum. Such surfaces MUST satisfy ALL of the following:

1. Verify `is_user_logged_in()` (the surface is never reachable anonymously);
2. Bind the resulting credential to the consenting user's own `user_id` (the user can only authorize action on their own behalf — never on another user's);
3. Be operator-gated via a default-OFF option (e.g. `acrossai_mcp_npm_login_enabled`), so the surface does not exist on a fresh install without explicit operator opt-in;
4. Cite this exception in the rendering class docblock with the FR identifier driving the broadened authorization;
5. Source any attacker-controllable consent-context (server slug, scope name, requested capability) from the server-side authoritative store (transient, option, DB row) keyed by an unforgeable code — NOT from URL parameters (S9 / CWE-451 / CWE-441).

The first canonical instance of this exception is `public/Partials/FrontendAuth.php` (Feature-007 / 2026-06-30). Captured as durable pattern S9 in `docs/memory/PROJECT_CONTEXT.md`. Future consent surfaces invoking this exception MUST cite both this constitution paragraph AND S9 in their plan §Constitution Check + rendering class docblock.

**Rationale**: Security failures have irreversible real-world consequences. These rules are absolute
and cannot be waived for velocity, deadlines, or any other reason. The consent-surface exception is not a dilution — it acknowledges that the threat-model for "user consenting on own behalf to issue a credential scoped to themselves" is structurally different from the threat-model for "admin action mutating site-wide state". The five conditions above ensure the consent-surface stays within bounded-blast-radius semantics.

### IV. User-Centric Design (NON-NEGOTIABLE)

All admin interfaces MUST prioritize site administrator experience above implementation convenience.
All form handling and data input MUST use `DataForm` (exported from `@wordpress/dataviews`) —
there is no separate `@wordpress/dataforms` package.
All data display and listing MUST use `@wordpress/dataviews` (WordPress DataViews).
DataForm MUST handle: field-level validation, inline error display, and submission state feedback.
DataViews MUST provide: searchable lists, column sorting, pagination, and contextual filtering.
No custom form or table rendering that duplicates DataForm/DataViews functionality is permitted.

**Exception — MCP Manager parent menu**: The top-level MCP Manager admin page
(`?page=acrossai_mcp_manager`) uses `WP_List_Table` for the server list and a tabbed settings
form for the server edit page. This is a pre-approved exception because the page predates the
DataViews mandate and its data model (server rows, toggle/delete row actions) maps naturally to
`WP_List_Table`. All new admin UIs added after this constitution is ratified MUST use
DataViews/DataForm — this specific exception does not extend to any future screen.

**Exception — Connector picker card layout** *(added 2026-07-12, constitution v1.1.0)*: The
AI Connectors tab (`?tab=ai-connectors`) and its nested Level 2 (per-connector sub-tabs) +
Level 3 (Generate | Connections | Settings) panels MAY use hand-rolled card sections, WP core
`.nav-tab-wrapper` markup, and a `widefat striped` table for the Connections panel when the row
model is "one card per registered connector profile with brand/icon/instructions + a Generate
button" — a display of static configuration + a small operational list, not a
searchable/sortable/filterable data grid. This exception explicitly covers `AIConnectorsTab`
(F021 Phase 8+), its Phase 9 shared card shell, and its Phase 10 nested tab structure
(F024 folded in). Any future admin UI whose data model is a filterable/sortable row set MUST
still use DataViews/DataForm — this exception is narrowly scoped to the branded connector-picker
UX and small operational lists (< 20 rows) inside it.

**Rationale**: Consistency with WordPress core UI patterns reduces the learning curve for
administrators and ensures a coherent, familiar admin experience across all active feature areas.
The Connector picker exception acknowledges that DataViews cannot express "one branded card per
connector profile" without visible layout gymnastics, and that the ecosystem cost of forcing
every future connector plugin (Claude, ChatGPT, Gemini, Copilot, …) into a filterable grid
outweighs the consistency benefit for what is fundamentally a static configuration display.

### V. Extensibility Without Core Modification

New features and third-party integrations (WPBoilerplate Access Control, MCP adapter, OAuth providers)
MUST be implemented via WordPress action/filter hooks, extension points, or new self-contained
modules — never by modifying existing core plugin files.
All integrations MUST be optional: the plugin MUST function correctly and degrade gracefully
when an integrated plugin or service is absent.
Auto-discovery of external services (e.g., MCP servers) MUST NOT block admin page rendering.

**Rationale**: Prevents merge conflicts, preserves update safety, and enables ecosystem growth
without introducing tight coupling between the core plugin and optional dependencies.

### VI. Reusability & DRY Principle

All common logic MUST be extracted to shared utilities (`includes/Utilities/`) before it is used
in a second location.
If equivalent functionality already exists anywhere in the codebase, it MUST be reused — never
duplicated. Before implementing any utility, the existing codebase MUST be checked.
Use `@wordpress/*` packages first (Tier 1), then npm packages (Tier 2). Never introduce a
dependency that duplicates React, ReactDOM, or other packages already bundled by WordPress.
Run `npm run validate-packages` before every commit to enforce this hierarchy.

**Rationale**: Duplication creates maintenance burden, divergence bugs, and contradictory behaviour.
A single source of truth for every abstraction keeps the codebase consistent and auditable.

### VII. Definition of Done

A feature is ONLY considered complete when ALL of the following gates pass:

- [ ] PHPCS validation: zero errors and zero warnings
- [ ] PHPStan level 8: zero errors
- [ ] ESLint: zero errors
- [ ] Security review complete: sanitization, escaping, nonces, and capabilities verified at every boundary
- [ ] Unit tests written and passing for all new logic
- [ ] All new data input uses `DataForm` from `@wordpress/dataviews` (exceptions: MCP Manager parent menu; AI Connectors tab card layout)
- [ ] All new data display uses DataViews (`@wordpress/dataviews`) (exceptions: MCP Manager parent menu; AI Connectors tab card layout)
- [ ] No code duplication or DRY violations exist in the changeset
- [ ] All functions, hooks, and classes are prefixed with `acrossai_mcp_`
- [ ] All standards in `AGENTS.md` are met
- [ ] `npm run validate-packages` passes

**Rationale**: Partial completion creates technical debt that compounds across features. This gate
enforces consistent, shippable quality at every increment.

## Code Quality & Workflow

- All PHP functions, hooks, filters, and class names MUST be prefixed with `acrossai_mcp_`
- All forms and AJAX handlers MUST verify nonces using `check_ajax_referer()` or `wp_verify_nonce()`
- Capability checks MUST be enforced on all admin page renders and all data-mutation endpoints
- Input MUST be sanitized immediately upon receipt; output MUST be escaped at the point of render
- No deprecated WordPress functions are permitted — use the current replacement
- Use `wp_remote_get()` / `wp_remote_post()` for all outbound HTTP requests; never call `curl` directly
- Use `@wordpress/*` packages (Tier 1), then npm packages (Tier 2)
- Run `npm run validate-packages` before every commit
- Never modify files inside `.agents/tools/` — these are external submodule dependencies

## Architecture & UI Standards

**Directory Layout**:

```
admin/
└── Partials/       # All admin-facing classes: menu, page renderers, asset enqueues
includes/
├── Utilities/      # Shared utility functions, helpers, formatters
├── MCP/            # MCP server boot and registration
├── MCPClients/     # AI client configuration generators (one class per client)
└── REST/           # REST API controllers (CLI auth, access control)
public/
└── Partials/       # Frontend-facing classes (FrontendAuth virtual page)
src/
├── js/             # JavaScript source files
└── scss/           # Stylesheet source files (compiled by @wordpress/scripts)
tests/
├── phpunit/        # PHP unit and integration tests
└── jest/           # JavaScript unit tests
```

**PHP Namespace Rule**: Every PHP class MUST use a namespace that mirrors its directory path
under the plugin root, using `AcrossAI_MCP_Manager` as the root and `\` as the separator. Examples:

- `includes/Main.php` → `AcrossAI_MCP_Manager\Includes`
- `includes/MCP/Controller.php` → `AcrossAI_MCP_Manager\Includes\MCP`
- `includes/MCPClients/ClaudeCodeClient.php` → `AcrossAI_MCP_Manager\Includes\MCPClients`
- `includes/REST/CliController.php` → `AcrossAI_MCP_Manager\Includes\REST`
- `includes/Utilities/TokenHelper.php` → `AcrossAI_MCP_Manager\Includes\Utilities`
- `admin/Partials/Settings.php` → `AcrossAI_MCP_Manager\Admin\Partials`
- `public/Partials/FrontendAuth.php` → `AcrossAI_MCP_Manager\Public\Partials`

Never invent short namespaces — always derive from the full directory path.

**Admin Partials Rule**: Any class that calls `add_menu_page()`, enqueues admin assets via
`wp_enqueue_style()` / `wp_enqueue_script()`, or renders admin HTML MUST live in `admin/Partials/`
with namespace `AcrossAI_MCP_Manager\Admin\Partials`. Classes in `includes/` are context-neutral —
they MUST NOT contain admin-specific logic.

**Boot Flow Rule**: `includes/Main.php` is the single source of all hook registration.
`define_admin_hooks()` and `define_public_hooks()` are the ONLY methods that call
`$this->loader->add_action()` / `$this->loader->add_filter()` — all hooks trace directly to
one of these two methods with no intermediate delegation.
All feature classes use the plugin-wide **singleton `instance()` pattern**:
`protected static $_instance = null;` + `public static function instance(): self`.
`includes/Main.php` resolves each singleton to a **named variable** before passing it to the
Loader — never inline. This is the canonical form:

```php
$mcp_controller = MCP\Controller::instance();
$this->loader->add_action( 'rest_api_init', $mcp_controller, 'register_servers' );
```

Passing `FeatureClass::instance()` directly as the second argument to `add_action` is
**prohibited** — it couples instantiation to hook registration and makes the call harder to read.
Feature classes MUST NOT call `Loader::instance()` themselves.
No `register_hooks()` delegation. No hook-registering code MAY run inside `load_dependencies()`.

**REST Controller Pattern**: A feature module's REST controller MUST be split into per-domain
sub-controllers whenever it would otherwise exceed roughly 400 lines or own more than one user
story's handlers. Sub-controllers MUST use the singleton pattern and MUST NOT register any
WordPress hooks themselves — only the top-level controller is wired in `Main.php` via the Loader.

**Module Contract**: Every feature class MUST:

1. Implement the singleton `instance()` pattern (`protected static $_instance = null;` +
   `public static function instance(): self`)
2. Use a `private` constructor; dependencies are obtained via other classes' `::instance()` calls —
   never via constructor injection from outside
3. Depend only on shared utilities from `includes/Utilities/` — never on sibling modules directly
4. Expose integration points exclusively via WordPress actions and filters

**Database**:

- Direct SQL is permitted only with `$wpdb->prepare()`
- Prefer WordPress options/meta APIs for simple key-value or per-object storage
- Custom database tables are only permitted when the data model genuinely cannot fit existing APIs,
  with documented justification in the feature plan

**Integration Resilience**:

- All calls to optional integrations (WPBoilerplate Access Control, MCP adapter) MUST be wrapped in
  availability checks and MUST NOT throw fatal errors or produce broken UIs when absent
- MCP server listing MUST use the `wpboilerplate/wpb-mcp-servers-list` Composer package — direct
  `McpAdapter::instance()->get_servers()` calls are prohibited. Wire collect via the Loader:

```php
$mcp_servers_list = \WPBoilerplate\McpServersList\McpServersList::instance();
$this->loader->add_action( 'rest_api_init', $mcp_servers_list, 'collect', 20 );
```

## Governance

This constitution supersedes all other development practices for the AcrossAI MCP Manager plugin.
In any conflict between this constitution and another document, this constitution takes precedence.
`AGENTS.md` remains the source of truth for tooling standards; this constitution governs architecture
and quality principles.

**Amendment Procedure**:

1. Propose the amendment in writing with clear rationale
2. Increment version following semantic versioning:
   - MAJOR: backward-incompatible removal or redefinition of a principle
   - MINOR: new principle added or existing principle materially expanded
   - PATCH: clarifications, wording fixes, or non-semantic refinements
3. Update this file and propagate changes to all affected templates
4. Record a sync impact report (in the HTML comment block at the top of this file)
5. Commit with message: `docs: amend constitution to vX.Y.Z (<summary>)`

**Compliance**: All pull requests and code reviews MUST verify compliance with every principle in this
constitution. Any implementation that appears to violate a principle MUST either be refactored or
include documented justification in the feature plan explaining why a compliant approach was not
feasible.

**Version**: 1.1.1 | **Ratified**: 2026-05-28 | **Last Amended**: 2026-10-07
