=== AcrossAI MCP Manager ===
Contributors: raftaar1191
Tags: ai assistant, chatgpt, claude, mcp, mcp-server
Requires at least: 7.0
Requires PHP: 8.1
Tested up to: 7.1
Stable tag: 0.3.6
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect ChatGPT, Claude, Grok, Cursor, Gemini to WordPress in under a minute — 16 built-in AI clients + per-server access control.

== Description ==

**Connect ChatGPT to WordPress. Connect Claude to WordPress. Connect Grok to WordPress. Connect any MCP-compatible AI assistant to WordPress.** MCP Manager is the open-source Model Context Protocol (MCP) server for WordPress — so ChatGPT, Claude, Grok, Cursor, Gemini CLI, GitHub Copilot, VS Code, Windsurf, Zed, Cline, Codex, and 5+ other AI clients can safely read, edit, and act on your site through WordPress-native Application Passwords. No copy-pasting configs between tabs; no proxy service in the middle; no vendor lock-in.

**Setup takes under a minute end-to-end via the [Quick Setup wizard](https://acrossai.co/mcp-manager-quick-setup/)** — install the plugin, click through the guided flow, paste the ready-made JSON into your AI client, done. Your first prompt to Claude / ChatGPT / Cursor lands on the same WordPress site you just configured.

Every headline section below links to the full documentation at [acrossai.co/doc-category/mcp-manager](https://acrossai.co/doc-category/mcp-manager/) — the docs are the source of truth and get updated first. Source and issues live at [github.com/acrossai-co/acrossai-mcp-manager](https://github.com/acrossai-co/acrossai-mcp-manager).

= Key Features =

* **Multiple MCP servers per site** — create, enable, disable, and configure independently. → [Docs](https://acrossai.co/docs/mcp-servers/)
* **16 built-in AI-client connection guides** — copy-paste-ready configs for Claude Desktop, Claude Code, VS Code, GitHub Copilot, Codex, Cursor, Gemini CLI, Windsurf, Zed, Cline, Roo Code, Kilo Code, Amazon Q Developer, OpenCode, Antigravity, and a Custom Client template. New clients register via a filter callback. → [Docs](https://acrossai.co/docs/mcp-connect-a-client/)
* **CLI browser-approval flow** — let terminal users connect with one command; approval happens in a browser tab. → [Docs](https://acrossai.co/docs/mcp-cli-connections/)
* **WP-CLI (STDIO) transport** — local clients can connect through a WP-CLI subprocess with no network credential transmission. → [Docs](https://acrossai.co/docs/mcp-wp-cli-stdio/)
* **Application Passwords under the hood** — WordPress-native credentials, one-click generation, and revocation from the user profile page. → [Docs](https://acrossai.co/docs/mcp-application-passwords/)
* **Per-server tool and ability curation** — pick exactly which WordPress abilities each MCP server exposes as callable tools. → [Docs](https://acrossai.co/docs/mcp-tools-and-abilities/)
* **Per-server access control** — gate every MCP request by user, role, capability, or your own policy provider. → [Docs](https://acrossai.co/docs/mcp-access-control/)
* **Frontend embeds** — shortcode + block to show your users how to connect their AI clients from your own site. → [Docs](https://acrossai.co/docs/mcp-embeds-shortcode-block/)

= How It Works =

The **[Quick Setup wizard](https://acrossai.co/mcp-manager-quick-setup/)** walks you through activation, password generation, and pasting the config — the whole path takes under a minute end-to-end and is the recommended way for a first-time install. Prefer to do it by hand? The six-step manual flow below is exactly the same underneath:

1. Install and activate the plugin ([step-by-step](https://acrossai.co/docs/mcp-install-and-activate/))
2. Open **AcrossAI → MCP** in your WordPress admin
3. Pick your AI client tab (Claude, VS Code, ChatGPT, Cursor, Gemini, GitHub Copilot, or Custom)
4. Generate a new Application Password with one click
5. Copy the ready-made JSON config and paste it into your client
6. Restart your client — it now sees your site's abilities

Longer walkthrough with screenshots: [Getting started → connect your first AI client](https://acrossai.co/docs/mcp-getting-started/).

= Connection Types =

MCP Manager ships with three connection styles out of the box, plus one optional paid add-on:

* **MCP Client (npx bridge)** — the default. Paste a JSON config into Claude Desktop, VS Code, Cursor, etc. Uses `@automattic/mcp-wordpress-remote@latest` with a WordPress Application Password. → [Docs](https://acrossai.co/docs/mcp-connect-a-client/)
* **CLI Connections (browser approval)** — one command in the terminal, one click in the browser, zero password copying. → [Docs](https://acrossai.co/docs/mcp-cli-connections/)
* **WP-CLI (STDIO)** — local subprocess, no network credential transmission. Best for CI or local dev boxes. → [Docs](https://acrossai.co/docs/mcp-wp-cli-stdio/)
* **AI Connectors (paid add-on)** — one-click Claude, ChatGPT, Grok, Gemini, and Cursor hosted-OAuth connectors. Requires the separate [AcrossAI Pro plugin](https://acrossai.co/pricing/) (14-day money-back). → [Docs](https://acrossai.co/docs/mcp-ai-connectors/)

= Requirements =

* WordPress 7.0 or higher
* PHP 8.1 or higher
* WordPress Application Passwords support (built-in since WP 5.6)

== Installation ==

1. Upload the plugin directory to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to Settings → MCP Manager to configure

Or:

1. Go to Admin → Plugins → Add New
2. Search for "MCP Manager"
3. Click "Install Now" then "Activate"

== Frequently Asked Questions ==

Full FAQ + troubleshooting lives at [acrossai.co/docs/mcp-faq-troubleshooting](https://acrossai.co/docs/mcp-faq-troubleshooting/). Quick answers below.

= Are my credentials secure? =

Yes. MCP Manager uses WordPress's native Application Passwords — each one is generated by WordPress, tied to your user, revocable from the profile page, and never stored in this plugin's own tables. Full detail: [Application passwords & security](https://acrossai.co/docs/mcp-application-passwords/).

= Can I connect multiple AI clients to the same site? =

Yes — generate a separate password (or CLI approval) per client. You can also run multiple MCP servers on the same site with different tool/ability sets and per-server access rules. See [MCP servers](https://acrossai.co/docs/mcp-servers/).

= Which AI clients are supported? =

Sixteen built-in clients ship with the free plugin — every one gets a ready-to-paste JSON snippet and its own tab in the setup wizard:

* Claude Desktop
* Claude Code
* VS Code
* GitHub Copilot
* Codex
* Cursor
* Gemini CLI
* Windsurf
* Zed
* Cline
* Roo Code
* Kilo Code
* Amazon Q Developer
* OpenCode
* Antigravity
* Custom Client (template for any other MCP-compatible tool)

The paid **AcrossAI Pro** add-on layers a one-click hosted-OAuth flow on top for **ChatGPT, Claude, Grok, Gemini, and Cursor** — no config file to touch. Adding a brand-new client is a filter callback. See [Connecting an AI client](https://acrossai.co/docs/mcp-connect-a-client/).

= Does it work on multisite? =

Yes — each site in the network configures independently.

= Do I need the paid AI Connectors add-on? =

Only if you want the one-click hosted-OAuth flow for Claude, ChatGPT, Grok, Gemini, or Cursor. All other connection styles (MCP Client, CLI, WP-CLI STDIO) are free and shipped with this plugin. See [AcrossAI Pro](https://acrossai.co/pricing/).

== Support ==

* **Docs hub** — [acrossai.co/doc-category/mcp-manager](https://acrossai.co/doc-category/mcp-manager/)
* **Troubleshooting & FAQ** — [acrossai.co/docs/mcp-faq-troubleshooting](https://acrossai.co/docs/mcp-faq-troubleshooting/)
* **Source code + issue tracker** — [github.com/acrossai-co/acrossai-mcp-manager](https://github.com/acrossai-co/acrossai-mcp-manager)

== Screenshots ==

1. Settings page with client tabs for easy configuration
2. Copy-paste ready JSON configuration
3. One-click password generation
4. Per-provider configuration file locations and top-level keys

== Changelog ==

= 0.3.6 =
* **Fixed — installing the AcrossAI Abilities Manager add-on now just works.** Before this release, installing the add-on after this plugin changed nothing you could see: your server still offered the same few tools and nothing told you the larger set had arrived. Getting it took four undocumented steps — open **Tools**, change **Server type** to AcrossAI, confirm, then press **Reset to Type Defaults**. The cause was that the AcrossAI type shipped with an empty tool list, because this plugin did not know what belonged on it until the add-on turned up and said so. It knows now, so the list is written down in advance and the tools start working the moment the add-on is activated. No type change, no Reset, nothing to read.
* **New — the plugin creates a second server, **AcrossAI**, alongside the default one.** It arrives **disabled**, listed after **Default MCP Server**, at `/acrossai/mcp`, and carries the AcrossAI toolsets from the start. Nothing is served until you enable it, and enabling it is your decision. If you do not want it, disable or delete it. (0.3.4 briefly added a similar server and 0.3.5 withdrew it — the row was never the problem, the tools it carried were. It returns with the right ones.)
* **Fixed — a fresh install created that server with no tools at all.** Found by installing on a genuinely new site rather than by resetting an existing one. The plugin created its servers *before* creating the table their tool selections live in, so every selection was written into a table that did not exist yet — and the write reported success. Activation looked fine and the server arrived empty. Sites already affected are repaired automatically on the next wp-admin page load; a server you have curated yourself is left alone.
* **Changed — a server can be enabled before its add-on is installed, and says what it is waiting for.** Previously such a server could not be switched on at all, which broke the **Quick Connect** wizard for exactly the person who had not installed the add-on yet. Now enabling records your intent, the admin shows which plugin is still missing, and installing that plugin makes the server live with no further clicks. Quick Connect will still not hand over a client configuration until then, and says why — a configuration pasted into Claude or Cursor too early connects and looks broken, and because AI clients cache their tool list when they connect, it would keep looking broken afterwards. A server whose type is **unrecognised** still cannot be enabled: no install fixes that one.
* **Fixed — the Tools tab now shows the tools your server is configured with.** It was showing what the server is *serving right now*, so a server carrying fifteen tools could read "Added as tools (1)". Related fixes in the same screen: **Reset to Type Defaults**, **Enable All** and even adding a single tool could quietly delete the rest of your selection; the picker offered tools belonging to a different server type; and changing **Server type** appeared to work but reverted on the next page load.
* **New — you can write your own connect message.** Every server sends a short briefing to an AI client when it connects, explaining what kind of server it is and which tool to call first. The **Overview** tab now offers **System default** (recommended, and kept up to date by the plugin) or **Custom**, prefilled with the message your server sends today so you can edit rather than start from nothing. Already-connected clients keep the message they received; new connections get yours.
* **Changed — every existing server gains **Server guide**.** A one-time addition to servers created before it existed, so they get the tool that explains what the site actually contains. Your own tool selections are untouched.
* **Changed — a quieter admin.** Warnings about a missing add-on now appear only once a server is actually enabled, rather than on a server that is switched off and serving nobody. Tools waiting on a plugin read as ordinary rows instead of a column of apologies. The Overview tab no longer repeats the client list and credential notes that belong on the Connect tab.
* **Removed — the **Backups** toolset.** It was the one toolset named after a category rather than a plugin, covering UpdraftPlus and All-in-One WP Migration together, while every other integration is named for the plugin it serves. It is being rebuilt as one toolset per backup plugin. The backup abilities themselves are unchanged and stay in the add-on.
* **Internal — this plugin now owns the Toolset layer** (the dispatchers behind `toolset/content`, `toolset/users` and the rest) instead of the add-on. **Nothing changes for you:** on a site running both plugins the add-on's copies still win and this plugin's stand down, verified by comparing the two side by side. The abilities themselves have not moved and stay in the add-on.
* **No database change.** Schema stays at `1.1.7`; no table is added, altered or removed. The new server is an ordinary row, written on the first wp-admin page load after updating.

= 0.3.5 =
* **Changed — a new server now arrives with its type's tools already selected.** Creating a server, from either the classic form or the Quick Connect wizard, used to hand it the same three `mcp-adapter/*` protocol tools whatever type you picked, because that is what the database columns default to. A type's tool set was only ever written when you pressed **Reset to Type Defaults**. A new MCP Adapter server now includes **Server guide** from the start, and a new AcrossAI server starts with the AcrossAI toolsets rather than the wrong three. Existing servers are untouched — where a type has tools a server does not, the Tools tab still offers them with a one-click **Apply**, and your own selection is never overwritten.
* **Changed — the plugin no longer creates a second MCP server.** 0.3.4 added an AcrossAI-branded server at `acrossai/mcp` on every site. It is not created any more: a second MCP endpoint appearing unasked is a decision that belongs to you, and the **AcrossAI** server *type* already covers it — create a server and choose that type when you want one. If your site already has that server it is left exactly as it is, still working, and it is now deletable like any other server rather than locked as plugin-managed. Servers list in the order they were created.
* **Changed — the Server type control is hidden when there is only one type to choose.** With no add-on installed there is nothing to pick, so the create form and the Tools tab no longer show a dropdown whose only other option is unavailable. It returns the moment a second type is installed.
* **Internal — the full changelog moved to `changelog.txt`, shipped with the plugin.** WordPress.org truncates a readme's changelog at 5,000 words and had begun cutting this one, so the readme now carries the recent releases and that file holds the complete history.
* **No database change.** Schema stays at `1.1.7`; nothing is added, altered or removed on update.

= 0.3.4 =
* **New + changed — the Tools tab now lists tools, and the Abilities tab lists abilities (F087).** Two admin screens were each showing the wrong set. The per-server **Tools** tab offered all ~370 registered abilities in its left pool, even though abilities have not been advertised individually in `tools/list` since 0.2.x — they reach AI clients *through* a handful of tool-level entries: the three `mcp-adapter/*` protocol tools, and the `toolset/*` dispatchers the AcrossAI Abilities Manager plugin registers (each one a router that takes `action=discover|info|execute` and forwards to a whole group of abilities). Meanwhile those same dispatchers cluttered the **Abilities** tab as ordinary rows with their own Exposed toggle, where a toggle on them either does nothing or breaks the protocol. Both screens now read from one declared list of tool-level abilities: the Abilities tab hides them, the Tools tab shows nothing else. The left pool is relabelled **Available tools** to match. Any plugin can declare its own through the new filter `apply_filters( 'acrossai_mcp_manager_tool_abilities', string[] $slugs )`, which is seeded with the three protocol tools; removing one of those puts it back on the Abilities tab. **Behaviour change worth reading:** on a site where no plugin hooks the filter, the Tools pool holds exactly the three protocol tools, so individual abilities can no longer be added as tools from that screen — use the existing `acrossai_mcp_manager_server_tools` filter to put one on a server in PHP. **Nothing you already picked is lost:** abilities already added to a server keep rendering in the "Added as tools" pane with their real labels, stay removable, and survive every save; they simply can't be re-added from the left pool once removed. The list is presentational — it changes neither per-server exposure, nor tool curation, nor call-time permission enforcement; every tool call is gated exactly as before. Contract and worked examples in `docs/extending-abilities-tab.md`.
* **Changed — the plugin now keeps its own servers' details in step, and will undo edits to them.** Before this release the plugin-managed server rows were written once and never revisited, so an edit to one stuck. They are now reconciled on each admin page load: **name, description, route, route namespace and version are plugin-owned and get restored if they differ.** If you renamed **Default MCP Server** or changed its description or route, that change is reverted on update — recreate it as your own server instead, where nothing will overwrite it. What stays yours: whether the server is **enabled**, its **server type**, and every **tool and ability selection** on it.
* **New — Server types, and Reset finally restores the right tools (F090).** Every MCP server now records what type of server it is. This fixes a real defect: the Tools tab's **Reset** button restored the same three `mcp-adapter/*` protocol tools on every server regardless of what that server was for, so on a server meant to serve the AcrossAI toolsets "reset to defaults" produced the *wrong* defaults and silently discarded the operator's selection. Reset now restores the server's own type's tool set. Two types ship: **MCP Adapter** (the three protocol tools) and **AcrossAI** (toolsets supplied by the AcrossAI Abilities Manager add-on). Every server that existed before this release is recorded as **MCP Adapter** and what it advertises is unchanged — byte for byte. New filter `apply_filters( 'acrossai_mcp_server_types', array $types )` lets any plugin contribute a type or replace a shipped one; contract and worked example in `docs/extending-server-types.md`.
* **New — the AcrossAI type requires its add-on, and says so instead of failing quietly.** A server whose type declares a requirement that is unmet cannot be **enabled**, and the refusal names the missing plugin rather than silently doing nothing. Enforcement is server-side on every route that can switch a server on — the single toggle, the bulk action, and the Quick Connect wizard — not in the interface alone. Two ways out are always offered: install the add-on, or change the server's type. **Disabling is never blocked**, so a server stranded by a deactivated add-on can always be switched off, and a running server is **never auto-disabled** — that would break a live AI client session instead of explaining itself.
* **New — a connected AI client is told what is wrong.** If the add-on is deactivated underneath a server that was already enabled, the server keeps answering and advertises a single entry whose description names the plugin that must be installed. Its address does not 404 and the connection is not dropped. Curated tool selections are untouched throughout and return intact when the add-on is reactivated. One caveat worth knowing: a client holding a tool list from *before* the deactivation and calling one of those tools receives the MCP Adapter's own generic "tool not found" — that lookup happens inside the adapter before any plugin filter runs. Clients that re-list after an error, which is what most agents do, see the explanation.
* **New — bulk tool controls on the Tools tab, matching the Abilities tab.** **Enable All**, **Disable All** and **Reset to Type Defaults**. Each is a one-time write to the server's tool selection, not a standing rule: "Enable All" adds every tool available to this server right now, and a tool-level ability registered *later* by a plugin you install in future is offered in the picker rather than added automatically. That is a deliberate choice — what a server serves is decided in exactly one place, the selection you can see on the tab, so what the screen shows and what an AI client receives cannot disagree. The confirmation dialog says a bulk action replaces the current selection, because it does.
* **Tools tab — says when a change has not reached connected AI clients yet.** An MCP client caches the server's tool list at the moment it connects, and the protocol gives a WordPress server no way to reach one that is already connected: the MCP Adapter advertises `tools.listChanged: false` and its server-to-client channel is unimplemented. Measured against a real client, the notification is ignored even with both corrected. So after a change that alters what the server serves, the Tools tab now states plainly that already-connected clients keep the list they loaded and will see the change on their next session — rather than leaving you with a count that is not yet true for anyone connected. New connections get the change immediately. This is the companion to the AcrossAI Abilities Manager’s `toolset/integrations`, which keeps new capability reachable through a tool a connected client already holds: together, a stale list neither blocks anything nor goes unmentioned.
* **Changed — new servers are created as **MCP Adapter**, and they arrive with that type's tools already selected.** Creating a server from either the classic form or the Quick Connect wizard now starts it on the MCP Adapter type; pick AcrossAI from the **Server type** dropdown when you want it. Servers list in the order they were created, with nothing pinned above anything else. Which type new servers start on is one declaration, `ServerTypes::seed()`'s `is_default` key, resolved everywhere through the existing `ServerTypes::default_slug()`.
* **Database — schema 1.1.7.** Adds `server_type varchar(32) NOT NULL DEFAULT 'mcp-adapter'` to `{prefix}acrossai_mcp_servers`, applied automatically on the first wp-admin page load after updating. The column default is what backfills existing rows, so every pre-existing server keeps its current behaviour. Schema `1.1.7` also drops a `tools_default_policy` column, which **you will not have** if you are updating from 0.3.3 — it existed only between two development migrations and was never released. The coarse expose/hide tool rule it backed was never honoured at call time, so a server could advertise a tool it then refused; the drop is there to clean up development installs. `abilities_default_policy` on the Abilities tab is a different setting and is unaffected.

= 0.3.3 =
* **New — Per-server default ability policy (F082, #95).** Each MCP server now carries a tri-state default policy: **Use each ability's own default** (the pre-F082 behaviour every existing server migrates to), **Expose every ability by default**, or **Hide every ability by default**. Clicking **Enable All** or **Disable All** on the per-server Abilities tab now flips this server-level policy — so abilities that a later plugin update or mu-plugin registers inherit the operator's intent automatically, without any admin action. Per-ability overrides still win over the default. Backwards-compatible: existing servers land on **Use each ability's own default** and every ability's effective exposure is byte-for-byte unchanged. New REST route `POST /acrossai-mcp-manager/v1/servers/{id}/abilities/policy` (permission_callback: `manage_options`). New action hook `acrossai_mcp_server_policy_changed( $server_id, $old_policy, $new_policy, $affected_slugs, $user_id )` fires on non-no-op transitions with a per-slug `[was, now]` diff map — policy-transition audit events carry the affected ability slugs and their exposure states, so choose audit-log integrations you trust. Row-only F030 permission-callback bypass semantics preserved verbatim via the SEC-001 rename `ExposureResolver::resolve()` → `resolve_row_only()`; the three-tier resolver used everywhere else is the new `resolve_effective()` sibling.
* **Cleanup — orphaned pre-F040 OAuth tables retired (F083).** Older builds of this plugin created `wp_acrossai_mcp_oauth_clients`, `wp_acrossai_mcp_oauth_tokens`, `wp_acrossai_mcp_oauth_auth_codes`, and `wp_acrossai_mcp_connector_approved_users`. Feature 040 moved the OAuth subsystem to the paid companion, which creates its own fresh tables under the `acrossai_pro_mcp_*` namespace and only cleans up those names (it never reads, migrates, or drops the old ones) — leaving the old tables abandoned in place with no owner. This release cleans them up two ways. (1) Automatic: on the first wp-admin page load after updating, a one-shot routine drops each orphaned table **only if it exists and is empty** and deletes its stale `*_db_version` option; non-empty tables are never auto-dropped (their rows were never migrated anywhere) — instead the `acrossai_mcp_legacy_oauth_cleanup_skipped` action fires with a per-table row-count map so operators can decide. Re-trigger after emptying by deleting the `acrossai_mcp_legacy_oauth_cleanup_done` option. (2) Safety net: the four old-name tables are also restored to `uninstall.php`'s opt-in drop list (`DROP TABLE IF EXISTS`; cannot touch the companion's differently-named live tables). Operators who prefer manual cleanup can run, after confirming the tables are empty:
    `DROP TABLE IF EXISTS wp_acrossai_mcp_oauth_clients, wp_acrossai_mcp_oauth_tokens, wp_acrossai_mcp_oauth_auth_codes, wp_acrossai_mcp_connector_approved_users;`
    `DELETE FROM wp_options WHERE option_name IN ('acrossai_mcp_oauth_clients_db_version', 'acrossai_mcp_oauth_tokens_db_version', 'acrossai_mcp_oauth_auth_codes_db_version', 'acrossai_mcp_connector_approved_users_db_version');`
    (Adjust the `wp_` prefix to your site's table prefix. Do NOT touch `wp_acrossai_pro_mcp_*` tables — those belong to the active AcrossAI Pro plugin.)
* **UI — the five connection tabs merged into one (F084, #109).** **npm**, **MCP Clients**, **Connectors/Integrations**, **n8n** and **WP-CLI** all answered the same question — *how do I connect an AI client to this server?* — while sitting as five separate tabs in an eleven-tab strip. They are now one tab, labelled **How would you like to connect?**, with the five choices as a second-level row inside it: Connectors/Integrations/Plugins, MCP Client via config file, npm, n8n, WP-CLI. The top-level strip drops from 11 tabs to 8. **Every existing link keeps working:** the five old addresses (`?tab=npm`, `?tab=clients`, `?tab=ai-connectors`, `?tab=n8n`, `?tab=wp-cli`) resolve to the new tab with the right choice selected, and any deeper selection they carry (`&client=`, `&panel=`) is preserved — resolved in place, never via a redirect, so anything keyed to the requested address keeps working. On a local install the tab opens on **MCP Client via config file** rather than the first choice, since copying a client config is almost always the next step there and those configs carry a local-only TLS setting worth seeing early. The servers-list **Connectors** and **MCP Clients** row shortcuts point at the new addresses; the other three shortcuts are untouched. All three navigation rows now use one consistent WordPress tab styling, graded by size so the hierarchy reads at a glance. **Requires AcrossAI Pro 0.9.10+** if that add-on is active — the Connectors and n8n choices are supplied by it, and the two plugins must be updated together; an older Pro leaves those two as leftover top-level tabs until it is updated. Third-party plugins can contribute their own connection method through the new `acrossai_mcp_manager_connect_methods` filter (same entry shape as the existing tab filter, documented in `docs/extending-per-server-tabs.md`).
* **Internal: `ACROSSAI_MCP_MANAGER_VERSION` constant + `Stable tag` bumped to `0.3.3` matching the plugin header.** `Tested up to: 7.1` and `Requires at least: 7.0` unchanged from 0.3.2.

= 0.3.2 =
* **UI + backend — F069 Quick Setup wizard renamed to "Quick Connect via AcrossAI" everywhere (F080, #97).** Every admin surface (plugins.php row action, MCP Servers list page-title button + per-row pill, Settings-page sub-nav tab, admin-bar chip) now reads **Quick Connect via AcrossAI**. The AcrossAI parent-menu submenu and the wizard header itself use the shorter **Quick Connect** — those two surfaces already sit next to the AcrossAI logo / brand context, so the tail is redundant there (#100). Machine identifiers renamed to `quick-connect` in the same pass: URL query param (`?quick-connect=1`), REST routes (`/quick-connect/state|step|complete`), PHP namespace `AcrossAI_MCP_Manager\Admin\Partials\QuickConnect`, class `QuickConnectController`, source directories `src/js/quick-connect/` + `src/scss/quick-connect.scss`, asset directory `assets/quick-connect/`, JS bootstrap global `window.acrossaiMcpQuickConnect`, CSS classes `.acrossai-mcp-quick-connect-*`, transient key prefix `acrossai_mcp_manager_quick_connect_state_`. **Breaking change — no backwards-compat shim:** any operator bookmark against `?quick-setup=1` will 404; any external code calling the old REST route will 404; any in-flight wizard scratchpad transient at deploy time is orphaned (harmless — 30 min TTL). Wizard state, entry-point behaviour, licensing gates, and all downstream integrations otherwise unchanged. Historical `README.txt` changelog entries for 0.3.0 / 0.3.1 that mention "Quick Setup Wizard" are intentionally left as-is — they describe what shipped under that name at merge time.
* **UI — Quick Connect wizard Step 10 now embeds the AcrossAI Pro per-connector walkthrough panels when the paid add-on is active (F081 + F082, #98 / #101 / #102).** When acrossai-pro 0.9.4+ is installed, Step 10 renders the same rich per-vendor walkthrough (Claude / ChatGPT / Cursor / Gemini / Grok) that the per-server **AI Connectors** admin tab shows — three sub-boxes per client with numbered instructions, an inline `<pre>` command block for CLI paths, and a "Still stuck? Full walkthroughs..." docs footnote. F082 exposes the walkthrough HTML through its own discovery lane (`acrossai_mcp_manager_discovery_ai_connector_instructions`, mirroring the existing `..._ai_connectors` producer/consumer pattern) rather than piggybacking on the connector DTO; the pro plugin emits the HTML with a `__ACROSSAI_MCP_URL__` sentinel that Step 10 substitutes with the currently-selected server's URL client-side just before rendering (HTML-escaped). Single source of truth: `*ConnectorProfile::get_mcp_url_setup_html()` on the pro side, consumed unchanged by both this wizard AND the pro plugin's own tab — if a vendor changes their onboarding flow, one edit updates both surfaces. The rich walkthrough renders BELOW the wizard's Back / Finish footer so the primary CTA stays visible without scrolling past the guide (#102 — new `useBelowFooter()` hook on `hooks/useAdvanceGuard.js`, opt-in per step, zero effect on the other twelve). When acrossai-pro is missing or on a pre-F082 version, Step 10 falls back to today's rendering (MCP URL + Copy + "Dynamic Client Registration only" notice) — zero visual regression. Rendered via `dangerouslySetInnerHTML` trusting the paid plugin's `wp_kses_post` guarantee at the filter write boundary. Ports ~20 CSS rules from `acrossai-pro/includes/Connectors/AbstractConnectorProfile::print_setup_styles()` into `src/scss/quick-connect.scss` with a source-of-truth comment banner — identical class names on both surfaces per D50 (cross-surface visual parity via shared markup contract, F077).
* **Compatibility — Tested up to WordPress 7.1.** Minimum required version (`Requires at least: 7.0`) unchanged.
* **Internal: `ACROSSAI_MCP_MANAGER_VERSION` constant + `Stable tag` bumped to `0.3.2` matching the plugin header.**

= 0.3.1 =
* **Docs — WordPress.org listing refresh: tags, short description, "under a minute" positioning, and supported-client roster updated.** `Tags:` header replaced (`mcp, ai, claude, chatgpt, cursor` → `ai assistant, chatgpt, claude, mcp, mcp-server`). Short description reframed to lead with the "Connect ChatGPT / Claude / Grok to WordPress in under a minute" hook and cite the 16-client roster. Description opening paragraph gains a second lede sentence linking the [Quick Setup wizard docs](https://acrossai.co/mcp-manager-quick-setup/) and stating the under-a-minute end-to-end setup claim; the same link + claim also lead the "How It Works" section above the six-step manual flow (kept as an alternative for operators who prefer to do it by hand). Key Features connection-guides bullet and "Which AI clients are supported?" FAQ answer now enumerate all 16 built-in clients (Claude Desktop, Claude Code, VS Code, GitHub Copilot, Codex, Cursor, Gemini CLI, Windsurf, Zed, Cline, Roo Code, Kilo Code, Amazon Q Developer, OpenCode, Antigravity, Custom Client). Paid AcrossAI Pro OAuth Connectors (ChatGPT / Claude / Grok / Gemini / Cursor) are called out as a separate optional add-on. No behavioural change; readme-only refresh.
* **Fix — VS Code + GitHub Copilot user-level MCP config path corrected (F078).** Previously shipped `~/.vscode/mcp.json` (Cursor's convention, not VS Code's). Now ships the documented macOS user-level path `~/Library/Application Support/Code/User/mcp.json` for both clients. Instructions also mention the `Cmd/Ctrl + Shift + P → "MCP: Open User Configuration"` menu entry. GitHub Copilot restart phrasing now correctly explains that Copilot Chat needs to be in Agent mode and VS Code auto-starts the server. Fix cites [VS Code MCP docs](https://code.visualstudio.com/docs/agents/reference/mcp-configuration) and [Copilot MCP docs](https://code.visualstudio.com/docs/copilot/customization/mcp-servers) — see `specs/078-client-config-upstream-fixes/research.md`. The audit that surfaced this fix (4 parallel research agents across all 16 clients) also verified 7 other clients had drift; those fixes are deferred to a follow-up PR pending review.
* **Fix — Local dev sites now auto-inject `NODE_TLS_REJECT_UNAUTHORIZED: "0"` into the copied MCP client JSON and surface an Automattic troubleshooting link (F075).** When the environment looks local (`wp_get_environment_type()` returns `local` or `development`, or the host is `localhost` / `127.0.0.1` / `::1`, or ends with `.local` / `.test` / `.localhost`) — regardless of whether the site is served over HTTPS or plain HTTP — every generated client snippet (all 16 clients: Claude Desktop, Claude Code, Cursor, VS Code, GitHub Copilot, Codex, Gemini, Windsurf, Zed, Cline, Roo Code, Kilo Code, Amazon Q, OpenCode, Antigravity, Custom) now carries the flag in its `env` block, and a static warning notice above the JSON on both surfaces (per-server **MCP Clients** tab and Quick Setup wizard **Step 11**) explains what was added and why, with a link to Automattic's mcp-wordpress-remote troubleshooting doc. Fixes the "MCP client connects but the tool list stays empty" symptom on Local by Flywheel / MAMP / DDEV / wp-env style installs — the flag is the real fix when the local site uses HTTPS with a self-signed certificate, and a harmless no-op on plain HTTP (Node's HTTP client never runs TLS validation) — but the warning + doc link is useful in both cases. Live sites are unaffected: on a real production install (`wp_get_environment_type() = production`, non-local hostname), the injection does not occur and no notice renders. Ops teams that self-host on custom suffixes (`.docker`, `.internal`, `.dev`) can extend the host-suffix list via the new `acrossai_mcp_local_hostname_suffixes` filter. Internal refactor: all 16 clients' `env` arrays are now built via a shared `AbstractMCPClient::build_env()` helper — the previous 16-way duplication of the standard env-key list is retired in the same pass.
* **UI — Client picker emojis removed on both admin surfaces (F076).** The per-server MCP Clients tab pill sub-nav and Quick Setup wizard Step 11 client-picker buttons now render each client's name only — no leading emoji glyph. The `get_icon()` methods on all 16 client classes stay defined (so companion plugins reading the ConnectionMethodRegistry DTO's `icon` field still see the value); only the two visible pickers stop rendering it.
* **UI — Per-server MCP Clients tab now uses the same numbered STEP 1..5 walkthrough as the Quick Setup wizard's Step 11 (F077).** The admin tab's client-detail area is reorganized under STEP 1 (Generate the password) → STEP 2 (Open the config file) → STEP 3 (Locate the top-level key) → STEP 4 (Copy this config and paste it under the top-level key — includes the local-dev warning + JSON + Copy button) → STEP 5 (Restart the MCP client — client-specific action). Same content as before; consistent visual scaffolding between the two surfaces.
* **Internal: `ACROSSAI_MCP_MANAGER_VERSION` constant + `Stable tag` bumped to `0.3.1` matching the plugin header.**

= Earlier versions =

* Entries for 0.3.0 and everything before it live in `changelog.txt`, shipped inside the plugin, and in the release history on GitHub: https://github.com/acrossaico/acrossai-mcp-manager/releases

== Support & Contribution ==

For issues, feature requests, or contributions, visit the plugin repository.

Questions? Check the FAQ section or look for documentation in the plugin settings page.

== Development ==

This plugin follows WordPress coding standards and best practices:
- PHP 7.4+ compatible
- Full object-oriented architecture
- Secure nonce verification
- Proper capability checks
- Sanitized input validation
- Escaped output

== License ==

This plugin is licensed under the GPL-2.0-or-later license. See LICENSE file for details.

== Credits ==

MCP Manager is built with:
- WordPress native APIs
- Automattic's MCP WordPress Remote package
- WordPress Application Passwords system

Developed with ❤️ for the WordPress community.
