=== AcrossAI MCP Manager – WordPress MCP Server & Admin for MCP Adapter, Claude & ChatGPT ===
Contributors: raftaar1191
Tags: mcp, ai, mcp-adapter, claude, chatgpt
Requires at least: 6.9
Requires PHP: 8.1
Tested up to: 7.1
Stable tag: 0.4.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WordPress MCP server built on the official MCP Adapter. Connect Claude, ChatGPT, Gemini, Grok & Cursor in one click. No relay, no lock-in.

== Description ==

**AcrossAI MCP Manager adds everything the WordPress MCP Adapter leaves out: admin screens, multiple MCP servers, per-server access control and one-click AI connectors.** It turns your site into a WordPress MCP server that Claude, ChatGPT, Gemini, Grok, Cursor and any other AI agent can connect to directly, with no relay and no third party in between.

Connect Claude to WordPress, connect ChatGPT to WordPress, or give Cursor, VS Code, GitHub Copilot, Codex and other AI agents access limited to exactly the tools and users you choose. Ask your AI to draft a post, fix a page, audit site health or clean up the database, and it acts on your live site instead of telling you what to click.

**Setup takes about a minute** with the [Quick Setup wizard](https://acrossai.co/mcp-manager-quick-setup/). Full guides are in the [documentation](https://acrossai.co/docs/).

= Built on the Official WordPress MCP Adapter =

[MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) is the official WordPress library that turns registered abilities into MCP tools. It is the engine, and it is deliberately not a product: on its own it gives you one default server, no admin screens and no way to decide who may reach it.

AcrossAI MCP Manager is the extension that makes the adapter usable on a real site:

* **Multiple MCP servers**, each with its own route, tools, rules and enable switch
* **Per-server tool curation and per-ability exposure**, with search, filters and bulk actions
* **Access control** by user, role, capability or your own policy provider
* **One-click AI connectors** with an OAuth 2.1 server running on your own site
* **16 AI client guides** with ready-made config, plus an audit log of terminal approvals

MCP Adapter is included, so there is nothing extra to install. Any plugin that registers WordPress abilities becomes reachable by your AI with no custom integration, including your own.

= Connect Claude to WordPress =

Works with Claude on the web, Claude Desktop and Claude Code.

* **Claude web and desktop:** open the **Connectors** tab, copy your server URL, add it as a connector in Claude, and approve the consent screen on your own site. No config file, no password to copy.
* **Claude Code:** connect with one terminal command and approve it in your browser (CLI connections, off by default), or paste the generated JSON config.
* **Claude Desktop (config file):** pick Claude Desktop on the Clients tab, generate an Application Password in one click, and paste the ready-made JSON into the file path the screen shows you.

= Connect ChatGPT, Gemini, Grok and Cursor to WordPress =

**AI Connectors** connect **ChatGPT**, **Claude**, **Gemini**, **Grok** and **Cursor** in one click: paste one URL into the AI client, approve the consent screen on your own site, and you are connected.

The connection stays yours end to end. The **OAuth 2.1 server runs on your own site**, and the clients, tokens and authorization codes are rows in your own database. See [AI Connectors](https://acrossai.co/docs/mcp-ai-connectors/).

= 16 AI Agents and Clients, Configured For You =

Pick your client and the plugin shows the exact config file path for your operating system, the top-level key that client expects, and copy-paste-ready JSON:

**Claude Desktop** · **Claude Code** · **VS Code** · **GitHub Copilot** · **Codex** · **Cursor** · **Gemini CLI** · **Cline** · **Roo Code** · **Kilo Code** · **Amazon Q Developer** · **OpenCode** · **Antigravity** · **Windsurf** · **Zed** · and a **Custom Client** template for any other MCP client.

= No Relay: Your Site Is the MCP Server =

This plugin makes zero outbound HTTP requests of its own: no telemetry, no phone-home, no proxy, no hosted relay. Your MCP endpoint is a route on your own site, and your AI client talks to it directly. The `npx` bridge some clients use runs on your own computer, not on anyone's server.

There is no account to create with us, no service that has to stay online for your site to keep working, and nothing to migrate if you stop using the plugin.

= What Your WordPress AI Agent Can Do =

On its own, this plugin is the server, the security and the plumbing. Add the companion [AcrossAI Abilities Manager](https://wordpress.org/plugins/acrossai-abilities-manager/) and your AI agent gets **357 abilities across 14 toolsets on any WordPress site**, rising to **over 800 across 32 toolsets** as it detects the plugins you already run.

* **Content**: posts, pages and custom post types with meta and revisions; comments, media, categories and tags; semantic search and internal-link suggestions.
* **Blocks**: read and surgically edit a page's block tree, build from patterns, generate sections and landing pages.
* **Appearance**: theme.json and global styles, templates and template parts, menus, widgets, fonts, site title, logo and icon.
* **Users**: create and edit users, reset passwords, create roles, grant or revoke capabilities.
* **Configuration**: read and write any option, including nested serialised values; change permalinks.
* **Database**: schema and table sizes, index health, bloated autoloaded options, EXPLAIN a slow query, serialisation-safe search-and-replace.
* **Files**: browse, read and write inside an administrator-defined allowlist; zip backups; wp-config constants; the debug log.
* **Cron, updates, diagnostics and cache**: overdue cron jobs, plugin and core updates with rollback and checksum checks, Site Health, recent fatal errors, plugin-conflict bisecting, transients and object cache.

Try prompts like:

* "Draft a post about our spring sale and save it as a draft."
* "Check Site Health and tell me what needs fixing."
* "Find the slowest database query and explain why it is slow."
* "Which cron jobs are overdue, and is WP-Cron running at all?"

= WooCommerce MCP, Elementor MCP, Rank Math & Yoast MCP =

Plugins you already run get dedicated toolsets, active only when that plugin is: **WooCommerce**, **Elementor** (and Pro), **Rank Math**, **Yoast SEO**, **Advanced Custom Fields**, **LiteSpeed Cache**, **Contact Form 7**, **WPForms**, **WPCode**, **CookieYes**, **WP Mail SMTP**, **The Events Calendar**, **Event Tickets**, **Loco Translate**, **Classic Editor**, **Akismet**, **UpdraftPlus** and **All-in-One WP Migration**.

Browse [every integration](https://acrossai.co/integrations/).

= Access Control: Decide What Each AI Can Touch =

Nobody should hand an AI agent their whole site by default, so this plugin does not.

* **Administrator-only by default**: a new MCP server requires `manage_options` until you add a rule.
* **Gate by user, role, capability or your own policy provider.** Every MCP request passes the gate, including tool calls, resource reads and prompts. The gate is fail-closed: if access control is unavailable, the server falls back to administrator-only.
* **Tool curation**: a server can offer three tools or three hundred.
* **Read-only servers are easy**: roughly half the ability catalogue is annotated read-only and only about 13% is flagged destructive.
* **Every ability still runs WordPress's own capability check** for the connecting user, so MCP access grants nothing extra.

= Multiple MCP Servers on One Site =

Run a locked-down read-only server for a client's AI and a full-access server for yourself on the same site. Each server has its own route, namespace, version, tools, ability exposure, access rules and connect message, and they never interfere with each other.

= Four Ways to Connect =

* **AI Connectors (OAuth)**: paste one URL, approve on your site. For ChatGPT, Claude, Gemini, Grok and Cursor.
* **MCP client config (npx bridge)**: paste JSON into Claude Desktop, Cursor, VS Code and the rest, using an Application Password and `@automattic/mcp-wordpress-remote`.
* **CLI connections with browser approval**: one terminal command, one click in the browser, no password copying, with every attempt recorded in a per-server audit log. Off by default.
* **WP-CLI (STDIO)**: the client launches WP-CLI as a subprocess, so no credential crosses the network. Ideal for local development and CI.

= For Site Owners, Developers and Agencies =

**Site owners** write, edit and publish by talking to the AI they already pay for, without their content touching a third party.

**Developers** get a WordPress MCP server with a documented extension surface. Clients, server tabs, connect methods and server types register through filters (`acrossai_mcp_client_classes`, `acrossai_mcp_manager_server_tabs`, `acrossai_mcp_manager_connect_methods`, `acrossai_mcp_server_types`), with action hooks on access-control denials and CLI approvals.

**Agencies** run a separate MCP server per client, scoped to exactly what that client should reach, with an audit log of terminal approvals.

= Privacy and Data =

This plugin sends nothing anywhere: no analytics, no usage reporting, no external service. The only outbound connection is WordPress core's own plugin installer reaching WordPress.org, and only when you click to install a companion plugin.

Uninstalling is non-destructive by default. Your servers, rules and logs survive unless you tick the delete-all-data option first.

= Requirements =

* WordPress 6.9 or higher
* PHP 8.1 or higher
* HTTPS (required by WordPress Application Passwords)

== Installation ==

1. Go to **Plugins → Add New Plugin**, search for "AcrossAI MCP Manager", then click **Install Now** and **Activate**.
2. The Quick Connect wizard opens automatically. Follow it, or close it and configure by hand.
3. For one-click setup, open **AcrossAI → MCP**, open a server, go to the **Connectors** tab and copy the URL into your AI client.
4. For config-file clients, choose your client on the **Clients** tab, generate an Application Password, copy the JSON into your client and restart it.

Global options, including CLI connections, ability-discovery page size and uninstall behaviour, live under **AcrossAI → Settings → MCP**.

== Frequently Asked Questions ==

= How do I connect Claude to WordPress? =

Install AcrossAI MCP Manager, open your server's **Connectors** tab and copy the URL. In Claude, add it as a connector, then approve the consent screen on your WordPress site. For Claude Desktop config files or Claude Code, use the **Clients** tab or a CLI connection instead.

= How do I connect ChatGPT to WordPress? =

Copy your server URL from the **Connectors** tab, add it as a connector in ChatGPT, and approve access on your own site. The OAuth server runs on your WordPress install, not on ours.

= How do I use Claude Code with WordPress? =

Turn on CLI connections under **AcrossAI → Settings → MCP**, run the command shown on your server's screen, and approve it in your logged-in browser. No password ever appears in the terminal. You can also paste the Claude Code JSON from the **Clients** tab, or use WP-CLI STDIO on a local site.

= Is this an MCP Adapter extension or a standalone WordPress MCP server? =

Both. It is built on the official MCP Adapter and adds the admin layer around it: multiple servers, access control, tool curation, client guides and one-click connectors. Because the adapter is included, it also works as a complete WordPress MCP server on its own. If you want to wire MCP up in your own code, use the adapter directly. If you want to administer it, use this.

= Do I need to install the MCP Adapter plugin separately? =

No. MCP Adapter is included. If you already run the standalone MCP Adapter plugin, you will see a duplicate-copy notice; deactivate the standalone plugin, since you do not need both.

= Does my content go to a third party? =

No. The plugin makes no outbound HTTP requests of its own. Your AI client talks to a route on your own site, and the `npx` bridge runs on your own machine.

= Do I need an AI subscription? =

You bring your own AI client that speaks MCP. This plugin never runs inference and never charges for AI usage.

= Which AI agents and clients are supported? =

ChatGPT, Claude, Gemini, Grok and Cursor connect in one click through AI Connectors. Claude Desktop, Claude Code, VS Code, GitHub Copilot, Codex, Cursor, Gemini CLI, Windsurf, Zed, Cline, Roo Code, Kilo Code, Amazon Q Developer, OpenCode and Antigravity get ready-made config, and a Custom Client template covers any other MCP client.

= Does it work with WooCommerce, Elementor, Rank Math, Yoast and ACF? =

Yes. With the Abilities Manager add-on, each of these gets a dedicated toolset that switches on automatically when the plugin is active. See [every integration](https://acrossai.co/integrations/).

= Can the AI break my site? =

It can only do what you allow. New servers are administrator-only, you choose which abilities each server exposes, and every ability still runs WordPress's capability check for the connecting user. With the Abilities Manager add-on, higher-risk operations need an explicit confirmation flag, search-and-replace is a dry run unless you say otherwise, file access is limited to an allowlist, and secrets such as database credentials and salts are stripped from file and log reads.

= Do I have to install the Abilities Manager add-on? =

No. This plugin is a complete MCP server and exposes any abilities registered by WordPress or other plugins. The add-on adds a large curated catalogue, and the setup wizard offers to install it.

= Can I give one AI full access and another read-only access? =

Yes. Create a server per audience, curate its tools and abilities, and gate it by user, role or capability.

= Are my credentials secure? =

Config-file connections use WordPress's native Application Passwords: generated by WordPress, shown once, never stored in this plugin's tables, and revocable from your profile. One-click connectors use OAuth 2.1 with PKCE and refresh-token rotation with reuse detection, all on your own site.

= Why does my AI client get a 401 error? =

Application Passwords require HTTPS, so check that your site URL uses https. Some hosts strip the Authorization header before it reaches WordPress; ask your host to pass it through. If you just updated to 0.4.0 or later, load any wp-admin page once so existing connections migrate.

= Why does the MCP endpoint return a 404? =

Check that the server is enabled in the servers list; the Default MCP Server starts switched off on new installs. If it is enabled, re-save **Settings → Permalinks** to flush rewrite rules.

= Does it work on multisite? =

Yes, per site. Each site keeps its own servers, rules and credentials. Activate it per site rather than network-wide.

= How do I revoke an AI client's access? =

Revoke it from the connections dashboard, delete its Application Password from your profile, or disable the server. Each takes effect immediately.

= What is MCP? =

The Model Context Protocol is an open standard, introduced by Anthropic and adopted across the AI industry, that lets AI assistants and AI agents discover and call tools on a service. This plugin makes WordPress an MCP server.

== Support ==

Documentation and troubleshooting: [acrossai.co/docs](https://acrossai.co/docs/). Source code and issues: [github.com/acrossai-co/acrossai-mcp-manager](https://github.com/acrossai-co/acrossai-mcp-manager).

== Screenshots ==

1. Overview tab: your WordPress MCP server at a glance, with the live endpoint URL for your AI client and every supported client listed.
2. CLI connections: connect Claude Code or another terminal client with one command, approve in the browser, and review every attempt in the audit log.
3. Clients tab: pick your AI client, generate an Application Password in one click, and copy JSON with the right file path and key.
4. AI Connectors: paste one URL into Claude, ChatGPT, Gemini, Grok or Cursor and approve the consent screen on your own site.
5. WP-CLI STDIO: the client launches WP-CLI as a subprocess, so no credential crosses the network.
6. Tools tab: choose exactly which abilities this MCP server advertises.
7. Abilities tab: switch individual abilities on or off per server, with search, filters and bulk actions.
8. Access control: decide who may reach each server by user, role or capability. New servers are administrator-only.
9. Multiple MCP servers on one site, each with its own route, tools and rules.
10. Global settings, including CLI connections and a non-destructive uninstall.

== Upgrade Notice ==

= 0.4.1 =
Now supports WordPress 6.9 and later. No settings change and no reconnecting needed.

= 0.4.0 =
One-click connectors are now free and built in. Load any wp-admin page once after updating so existing connections migrate; until then, connected AI clients cannot authenticate.

= 0.3.8 =
The AcrossAI server is recommended again and preselected in Quick Connect. Existing servers are untouched and there is no database change.

== Changelog ==

The complete release history lives at [acrossai.co/changelog](https://acrossai.co/changelog/).

= 0.4.1 =
* Changed: minimum WordPress version lowered from 7.0 to 6.9.
* Fixed: the plugin header used an unrecognised name for the minimum WordPress version, so WordPress did not enforce it.
* Corrected: the description still said one-click connectors were a paid extra. They have been part of this plugin since 0.4.0.
* Improved: rewrote the plugin listing and FAQ, and added a FAQ on how this plugin relates to MCP Adapter.
* Tidied: removed boilerplate that claimed PHP 7.4 compatibility, shortened upgrade notices and trimmed the changelog to recent releases.

= 0.4.0 =
* New: connecting Claude, ChatGPT, Gemini, Grok or Cursor is now built in and free. Paste one URL, approve the consent screen, done.
* Note: load wp-admin once after updating so existing connections migrate. Until then, connected AI clients cannot authenticate.
* Unchanged: existing connections and issued tokens keep working with no reconnecting.
* Changed: Quick Connect no longer asks you to buy anything.
* Database: four tables added. Existing data from the paid plugin is copied across and its tables are left untouched.
* Security: people outside a server's access rule could read its tool list (names and descriptions only, nothing could be run). They now see an empty server.
* Fixed: switching off the Default MCP Server now actually stops its endpoint. New installs start with it off.
* Updated: MCP Adapter 0.7.0, adding MCP 2026-07-28 support while staying compatible with older versions.
* Fixed: the Clients tab showed Windows and Linux users the macOS config path. Every supported system is now listed.

= 0.3.8 =
* Changed: the AcrossAI server is recommended again, with a badge and pinned to the top of the servers list.
* Changed: Quick Connect step 1 preselects the AcrossAI server.
* Changed: new servers start on the AcrossAI type when the Abilities Manager add-on is installed; otherwise on MCP Adapter.
* Existing servers are untouched and there is no database change.

== License ==

This plugin is licensed under the GPL-2.0-or-later license. See LICENSE file for details.

== Credits ==

MCP Manager is built with:
- WordPress native APIs
- Automattic's MCP WordPress Remote package
- WordPress Application Passwords system

Developed with ❤️ for the WordPress community.
