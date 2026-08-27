# 081 — Connector walkthrough panels in Quick Connect Step 10

## Plain-English summary

Step 10 of the Quick Connect wizard currently shows a 5-button pill picker (ChatGPT / Claude / Cursor / Gemini / Grok), the plugin's canonical MCP URL, and a single generic notice — "This connector supports Dynamic Client Registration only". Operators using the free wizard don't see the rich per-client walkthrough (numbered "Claude on the Web → Team → Claude Code" boxes with instructions per surface) that the paid `acrossai-pro` plugin renders on its per-server AI Connectors tab.

F081 teaches Step 10 to consume a new **`walkthrough_html`** field on the connector DTO — populated server-side by `acrossai-pro` in a companion PR — and render it via `dangerouslySetInnerHTML` when present. Falls back to today's "DCR-only" notice when the field is absent (either because acrossai-pro isn't installed or hasn't been updated to the walkthrough-emitting version yet). Zero visual regression on either path.

Ports the `AbstractConnectorProfile::print_setup_styles()` CSS block (~20 rules) from acrossai-pro's inline `<style>` tag into `src/scss/quick-connect.scss` so the wizard's rendering matches the paid tab byte-for-byte, using identical class names on both surfaces per D50 (cross-surface visual parity via shared markup contract — established by F077).

## Context

- Free plugin: `acrossai-mcp-manager` (this repo).
- Paid plugin: `acrossai-pro` (external at `/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/plugins/acrossai-pro`).
- Current wiring: `acrossai-pro`'s `DiscoveryConnectorAdapter::provide_ai_connectors()` hooks the free plugin's `acrossai_mcp_manager_discovery_ai_connectors` filter and returns lightweight DTOs (`{category, slug, name, description, icon, meta}`) into `ConnectionMethodRegistry::get_ai_connectors()`. Step 10 consumes those DTOs today.
- Walkthrough source of truth on the paid side: `Claude/ChatGPT/Cursor/Gemini/Grok ConnectorProfile::get_mcp_url_setup_html( $mcp_url )` — each returns per-server-URL HTML with 2-4 numbered `<section class="__setup-section">` blocks.
- Visual styling emitted server-side via `AbstractConnectorProfile::print_setup_styles()` at `acrossai-pro/includes/Connectors/AbstractConnectorProfile.php:510-637` — inline `<style>` tag, ~20 rules covering `.acrossai-mcp-connector-panel__setup*` class family.

## Recommended approach

### 1. Step 10 conditional render

`src/js/quick-connect/steps/Step10_ConnectorsDetail.jsx`:

Read `activeConnector.walkthrough_html` (or `activeConnector.meta.walkthrough_html` — depends on the acrossai-pro companion PR's chosen field placement; both variants supported with a defensive union read). If a non-empty string is present:

- Render it via `<div className="acrossai-mcp-connector-panel__setup-embed" dangerouslySetInnerHTML={ { __html: walkthroughHtml } } />` immediately below the MCP URL block.
- Suppress the today-current "Dynamic Client Registration only" notice (redundant with the embedded walkthrough).

When the field is absent (undefined, null, or empty string):

- Fall back to today's rendering: MCP URL + Copy + "DCR-only" notice. Zero visual regression for operators on installations where acrossai-pro either isn't installed OR hasn't been updated to the walkthrough-emitting version.

Sanitization: acrossai-pro guarantees the HTML has been through `wp_kses_post` at write time (docblock note on `get_mcp_url_setup_html`). Free plugin trusts that guarantee — no additional client-side sanitization. Same trust boundary as the existing `dangerouslySetInnerHTML` pattern used in a couple other spots in this codebase.

### 2. SCSS port

Copy the ~20 rules from `acrossai-pro/includes/Connectors/AbstractConnectorProfile.php:517-635` into `src/scss/quick-connect.scss`, appended after the existing step-scaffold rules. Class names stay identical (`.acrossai-mcp-connector-panel__setup*`) so a future acrossai-pro visual refresh doesn't drift the wizard.

Banner comment on the ported block cites `acrossai-pro/includes/Connectors/AbstractConnectorProfile.php:print_setup_styles()` as the source of truth so any future update on either side surfaces the mirror requirement in code review.

Follows F077's codified rule of thumb (D50): 4-10 rules → duplicate with color literals; 10+ → shared partial. This lands at ~20 rules — over the threshold. But the paid plugin's rules live inside a PHP `<style>` echo, not a shared SCSS partial that could be imported cross-plugin. Extracting a shared partial would require dependency on the paid plugin's source tree — bad idea for a free plugin. Duplication with a "source of truth" comment banner is the correct call for a cross-plugin visual contract.

### 3. What acrossai-pro needs to do (companion PR — out of scope for this repo)

For completeness: the paid plugin's `DiscoveryConnectorAdapter::provide_ai_connectors()` needs to extend the DTO with `walkthrough_html`. The user is landing that PR separately.

Before that PR ships: Step 10 falls back to today's rendering — no regression.

After that PR ships: Step 10 lights up with the rich walkthrough.

### 4. Regression tests

Reuse the existing F080 rename-gate suite as a canary — extend the contract test with an assertion that Step 10's file mentions `walkthrough_html` (proving the consume side is present). Small; catches accidental revert.

## Files touched

- `src/js/quick-connect/steps/Step10_ConnectorsDetail.jsx` — conditional walkthrough render + defensive DTO-field read.
- `src/scss/quick-connect.scss` — ~20 CSS rules ported with source-of-truth comment banner.
- `tests/phpunit/RenameGate/QuickConnectContractTest.php` — one assertion added (or a new small `Step10ContractTest.php`).
- `README.txt` — `= Unreleased =` bullet.
- `docs/planings-tasks/081-connector-walkthrough-panels.md` (this file).

Zero new REST routes. Zero DB migrations. Zero PHP class or namespace renames. Zero filter renames.

## Verification

1. Fresh install with acrossai-pro **not** installed → Step 10 shows "No AI connectors registered on this site yet. Install AcrossAI Pro..." (existing empty-state — verified unchanged).
2. acrossai-pro installed but at the pre-walkthrough version → Step 10 shows the 5 pills, MCP URL, "DCR-only" notice (today's behaviour — verified unchanged).
3. acrossai-pro installed at the walkthrough-emitting version → Step 10 shows the 5 pills, MCP URL, and the rich walkthrough panel below (blue-headered numbered sub-boxes). Byte-for-byte visual match with the paid AI Connectors tab.
4. Grep gate (`composer run test -- --testsuite rename-gate`) — clean.
5. PHPUnit mcpclients — 100/195 unchanged.
6. `npm run build` — regenerates `build/js/quick-connect.{js,css,rtl.css,asset.php}`.

## Ships as F081 via the spec-kit chain

`/speckit.git.feature "connector-walkthrough-panels"` → `/speckit.specify` → `/speckit.clarify` → `/speckit.plan` → `/speckit.tasks` → `/speckit.implement` → `/speckit.analyze` → `/speckit.security-review.staged` (expected zero findings — subtractive markup + conditional render + duplicated CSS; no new input surface; `dangerouslySetInnerHTML` trust boundary is documented on the DTO field docblock) → `/speckit.memory-md.capture-from-diff` (candidate D51: `DEC-CROSS-PLUGIN-VISUAL-CONTRACT-DUPLICATE-OVER-SHARED-PARTIAL` — when two plugins render the same visual, duplicate the CSS in the consumer with a source-of-truth comment cite rather than extract a shared partial that would create a cross-plugin dependency) → `/speckit.git.commit` → PR against `main`.
