=== AcrossAI MCP Manager – MCP Server for Claude, ChatGPT, Cursor & Any AI Agent ===
Contributors: raftaar1191
Tags: mcp, mcp-server, mcp-adapter, claude, chatgpt
Requires at least: 7.0
Requires PHP: 8.1
Tested up to: 7.1
Stable tag: 0.4.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect ChatGPT, Claude, Grok, Gemini & Cursor to WordPress in one click — free. Self-hosted MCP server: no relay, no middleman, no lock-in.

== Description ==

**AcrossAI MCP Manager turns your WordPress site into a Model Context Protocol (MCP) server.** Claude, Cursor, VS Code, GitHub Copilot, Gemini CLI, Codex, Windsurf, Zed and more connect straight to your site and can read, write and act on it — using WordPress's own Application Passwords, with per-server access control you configure.

The Model Context Protocol is the standard Anthropic introduced and the AI industry adopted: it lets an AI assistant discover and call tools on a service through one common interface. This plugin implements that server inside WordPress, so any MCP-capable AI becomes a WordPress co-pilot.

**Setup takes about a minute** via the [Quick Setup wizard](https://acrossai.co/mcp-manager-quick-setup/) — install, click through the guided flow, paste the ready-made JSON into your AI client, done.

**Documentation:** [acrossai.co/docs](https://acrossai.co/docs/) · **Use cases:** [acrossai.co/use-cases](https://acrossai.co/use-cases/) · **Integrations:** [acrossai.co/integrations](https://acrossai.co/integrations/) · **Full changelog:** [acrossai.co/changelog](https://acrossai.co/changelog/)

Every section below links to the relevant page. Source and issues live at [github.com/acrossai-co/acrossai-mcp-manager](https://github.com/acrossai-co/acrossai-mcp-manager).

= Your Site Is the MCP Server — No Relay, No Third Party =

This is the part worth reading twice, because it is the main thing that separates this plugin from the alternatives.

**There is no middleman.** The free plugin makes zero outbound HTTP requests of its own — no telemetry, no phone-home, no proxy, no hosted relay. Your MCP endpoint is a route on your own site, and your AI client talks to it directly. The `npx` bridge that some clients use runs on *your own computer*, not on anyone's server.

That means **your content never passes through a third party's infrastructure**, there is no account to create with us to make it work, no service that has to stay online for your site to keep working, and nothing to migrate if you stop using the plugin. Your credentials are WordPress Application Passwords, issued by your own site and revocable from your own profile page.

A plugin that relays your site through its vendor's servers has to disclose that. This one has nothing to disclose.

= Connect Claude to WordPress =

Works with **Claude Desktop**, **Claude Code** in the terminal, and Claude on the web. Open the server's connect tab, pick Claude, generate an Application Password with one click, copy the ready-made JSON into the config file path the screen shows you, and restart Claude. From then on, ask Claude to draft a post, fix a page, audit site health or reorganise a taxonomy, and it acts on your live site rather than describing what you should click.

→ [Connect an AI client](https://acrossai.co/docs/mcp-connect-a-client/)

= Connect ChatGPT, Claude and Grok in One Click — Free =

**AI Connectors** connect **ChatGPT**, **Claude**, **Grok**, **Gemini** and **Cursor** in one click. Paste one URL into the AI client, approve the consent screen on your own site, and you are connected. No config file to edit, no Application Password to copy.

This used to require the paid add-on. Since 0.4.0 it is part of this plugin and free.

The connection is still yours end to end: the **OAuth 2.1 server runs on your own site**, not on ours. The clients, tokens and authorization codes are rows in your database, and there is no third-party cloud between your AI and your WordPress.

→ [AI Connectors](https://acrossai.co/docs/mcp-ai-connectors/)

= 16 Built-In AI Clients, Configured For You =

Pick your client and the plugin renders the exact config file path, the exact top-level key that client expects, and copy-paste-ready JSON:

**Claude Desktop** · **Claude Code** · **VS Code** · **GitHub Copilot** · **Codex** · **Cursor** · **Gemini CLI** · **Cline** · **Roo Code** · **Kilo Code** · **Amazon Q Developer** · **OpenCode** · **Antigravity** · **Windsurf** · **Zed** · and a **Custom Client** template for anything else that speaks MCP.

Every one uses the same transport underneath, so nothing is second-class. A new client can be registered from your own code through a filter — no fork required.

= What Your AI Can Actually Do =

On its own, this plugin is the server, the security and the plumbing. Install the **free** companion [AcrossAI Abilities Manager](https://wordpress.org/plugins/acrossai-abilities-manager/) and your AI gains **357 abilities across 14 toolsets on any WordPress site**, rising to **over 800 across 32 toolsets** as it detects the plugins you already run.

* **Content** — create and update posts, pages and any custom post type with their meta and revisions; moderate comments; manage the media library, categories and tags; run semantic search to find related content and propose, review and apply internal links.
* **Blocks** — read and surgically edit a page's block tree without rewriting the page, build from patterns, generate sections and landing pages, audit copy and design.
* **Appearance** — theme.json and global styles, site-editor templates and template parts, navigation menus, widget areas, fonts, and site title, logo and icon.
* **Users** — create and edit users, reset passwords, create roles, grant or revoke individual capabilities.
* **Configuration** — read and write any option including values nested inside serialised arrays, change permalinks, and walk the admin menu to find which screen a setting lives on.
* **Database** — inspect schema and table sizes, audit index health and bloated autoloaded options, EXPLAIN a slow query, optimise tables, or run a serialisation-safe search-and-replace.
* **Files** — browse, read, write and delete files inside an administrator-defined allowlist; take and extract zip backups; read and edit wp-config constants; read the debug log.
* **Cron** — see every scheduled task, spot the overdue ones, run one on demand, and prove whether WP-Cron is firing at all.
* **Updates** — search the WordPress.org directory, install and update plugins, themes and core, roll back, and verify files against official checksums.
* **Diagnostics** — Site Health, maintenance mode, recent fatal errors, un-pause what WordPress auto-disabled, and bisect a plugin conflict without ever writing `active_plugins`.
* **Cache** — transients, object cache and rewrite rules.

**Plugins you already run get dedicated toolsets**, active only when that plugin is: WooCommerce, Elementor (and Pro), Rank Math, Yoast SEO, LiteSpeed Cache, Contact Form 7, WPCode, CookieYes, WP Mail SMTP, The Events Calendar, Event Tickets, Loco Translate, Classic Editor, Advanced Custom Fields, Akismet, WPForms, UpdraftPlus and All-in-One WP Migration.

→ [Browse every integration](https://acrossai.co/integrations/)

= Decide Exactly What Each AI Can Touch =

Nobody should hand an AI assistant their whole site by default, so this plugin does not.

* **Tool curation** — choose precisely which abilities a server advertises. A server can offer three tools or three hundred.
* **Per-ability exposure** — switch individual abilities on or off per server, with search, filters and bulk actions, plus a server-level default for everything you have not decided individually.
* **Read-only servers are easy** — roughly half the ability catalogue is annotated read-only and only about 13% is flagged destructive, so a "look but don't touch" server is a matter of filtering.
* **Permission override** — an explicit, per-server opt-in that is off by default.

→ [Tools and abilities](https://acrossai.co/docs/mcp-tools-and-abilities/)

= Administrator-Only by Default =

A brand-new MCP server requires `manage_options` until you say otherwise. Then gate it by **user, role, capability, or your own policy provider**. Every MCP request passes the gate — tool calls, resource reads and prompt requests alike — and denials are observable through hooks.

The gate is deliberately **fail-closed**: if the access-control package is unavailable, a server falls back to administrator-only rather than opening up.

→ [Access control](https://acrossai.co/docs/mcp-access-control/)

= Run More Than One MCP Server =

Create as many servers as you need, each with its own route, namespace, version, enable switch, tool set, ability exposure, access rules and connect message. One locked-down read-only server for a client's AI and one full-access server for yourself, on the same site, without interfering with each other.

→ [MCP servers](https://acrossai.co/docs/mcp-servers/)

= Three Ways to Connect =

* **MCP Client (npx bridge)** — the default. Paste JSON into Claude Desktop, Cursor, VS Code and the rest. Uses `@automattic/mcp-wordpress-remote` with an Application Password. → [Docs](https://acrossai.co/docs/mcp-connect-a-client/)
* **CLI connections with browser approval** — one command in the terminal, one click in the browser, zero password copying. Every approved, successful and failed attempt is recorded in a per-server audit log. Off by default. → [Docs](https://acrossai.co/docs/mcp-cli-connections/)
* **WP-CLI (STDIO)** — the client launches WP-CLI as a subprocess, so **no credential crosses the network at all**. Ideal for local development and CI. → [Docs](https://acrossai.co/docs/mcp-wp-cli-stdio/)

= AcrossAI Pro — the Optional Paid Add-On =

Everything above this point is free. [AcrossAI Pro](https://acrossai.co/pricing/) is a separate plugin that adds the following, and nothing here is required to run an MCP server:

* **Membership-aware access control *(Pro)*** — gate an MCP server by membership or course enrolment instead of only by WordPress role, across **10 platforms**: BuddyBoss, MemberPress, LearnDash, LifterLMS, Paid Memberships Pro, Restrict Content Pro, WooCommerce Memberships, s2Member, Wishlist Member and Memberium.
* **276 more abilities *(Pro)*** — deep coverage for **LearnDash** (74), **BuddyBoss** (60), **MailerPress** (89 plus 28 for MailerPress Pro) and **GeoDirectory** (25), each active only when that plugin is.
* **n8n connection — Beta *(Pro)*** — connect your site to n8n workflows using a generated bearer token or an Application Password, with a chosen lifetime and one-click revocation. Off by default, and labelled Beta in the plugin itself: n8n's own MCP OAuth credential is not yet OAuth 2.1 compliant, so this path is deliberately token-based rather than OAuth.

Pro keeps the same model as the free plugin: **it runs on your own server, with no third-party cloud**, and actions are never metered or credited. Plans start at a **30-day free trial with no card required**, and every plan carries a **14-day money-back guarantee**. Local and staging sites do not count against your site limit.

→ [Plans and pricing](https://acrossai.co/pricing/)

= For Site Owners, Developers and Agencies =

**Site owners** write, edit and publish through conversation with the AI they already pay for, without learning a new admin screen and without their content touching a third party.

**Developers** get a real WordPress MCP server with a documented extension surface: register a client, a server tab, a connect method or a server type through filters, no fork required. WP-CLI STDIO keeps credentials off the network entirely on local boxes.

**Agencies** run a separate server per client site with its own access rules, hand each client an AI connection scoped to exactly what they should reach, and keep an audit log of terminal approvals.

→ [Real-world use cases](https://acrossai.co/use-cases/)

= Built on the WordPress Abilities API =

WordPress 6.9 introduced the Abilities API so plugins can declare self-describing operations an AI can discover and run. This plugin exposes those abilities as MCP tools, which means **any plugin that registers abilities becomes reachable by your AI with no custom integration** — including your own.

= Privacy and Data =

The free plugin sends nothing anywhere. No analytics, no usage reporting, no external service.

The only outbound connection is WordPress core's own plugin installer reaching WordPress.org, and only when *you* click to install a companion plugin from the setup wizard.

Uninstalling is **non-destructive by default**: your servers, rules and logs survive unless you explicitly tick the delete-all-data option first.

= Extend It =

Clients, server tabs, connect methods and server types are all registered through filters — `acrossai_mcp_client_classes`, `acrossai_mcp_manager_server_tabs`, `acrossai_mcp_manager_connect_methods`, `acrossai_mcp_server_types` — plus action hooks on access-control denials and CLI approvals. Add your own from a plugin of your own.

= Full Feature List =

* Self-hosted MCP server — your site is the endpoint, and the plugin makes zero outbound requests
* One-click AI Connectors for ChatGPT, Claude, Grok, Gemini and Cursor — paste one URL, approve the consent screen, done
* An OAuth 2.1 authorization server on your own site — PKCE (S256), refresh-token rotation with reuse detection, dynamic client registration and metadata discovery, with the clients, tokens and codes stored in your own database
* Connections dashboard — see every AI client connected to each server, and revoke any one of them
* Multiple MCP servers per site, each independently routed, versioned and enabled
* 16 built-in AI-client guides with copy-paste JSON and the exact config path per client
* Three transports: npx bridge, CLI browser-approval, and WP-CLI STDIO
* WordPress Application Passwords — generated in one click, revocable from your profile
* Per-server tool curation and per-ability exposure, with search, filters and bulk actions
* Per-server access control by user, role, capability or custom provider — administrator-only until you change it
* Per-server custom connect message for the AI client
* CLI connection audit log covering approved, successful and failed attempts
* Guided Quick Connect wizard, plus a full manual path
* Optional request logging through the free MCP Tracker plugin
* Tunable ability-discovery page size with a live token-cost estimate
* Non-destructive uninstall by default
* Filter-based extension surface for clients, tabs, connect methods and server types
* Works with the free AcrossAI Abilities Manager add-on for 357+ abilities across 14 toolsets

Optionally, with [AcrossAI Pro](https://acrossai.co/pricing/): membership-aware access control across 10 platforms, 276 more abilities, and an n8n connection in Beta. See the section above for detail.

= Requirements =

* WordPress 7.0 or higher
* PHP 8.1 or higher
* WordPress Application Passwords (built into WordPress since 5.6; requires HTTPS)

== Installation ==

1. Go to **Plugins → Add New**, search for "AcrossAI MCP Manager", then click **Install Now** and **Activate**.
2. The Quick Connect wizard opens automatically. Follow it, or close it and configure by hand.
3. Manual path: open **AcrossAI → MCP**, open a server, choose how you want to connect, generate an Application Password, and copy the JSON into your AI client.
4. Restart your AI client. It now sees your site.

Global options — CLI connections, ability-discovery page size and uninstall behaviour — live under **AcrossAI → Settings → MCP**.

To install manually, upload the plugin folder to `/wp-content/plugins/` and activate it from the Plugins screen.

== Frequently Asked Questions ==

Full FAQ + troubleshooting lives at [acrossai.co/docs/mcp-faq-troubleshooting](https://acrossai.co/docs/mcp-faq-troubleshooting/). Quick answers below.

= Is this plugin free? =

Yes, entirely — and so is the [AcrossAI Abilities Manager](https://wordpress.org/plugins/acrossai-abilities-manager/) add-on that supplies the abilities. Both are on WordPress.org under GPL.

The only paid piece is [AcrossAI Pro](https://acrossai.co/pricing/), which adds an n8n connection, membership-aware access control across 10 platforms, and extra plugin toolsets. It starts with a 30-day free trial and no card. Everything else described on this page works without paying anyone.

= Does my content go to a third party? =

No. The plugin makes no outbound HTTP requests of its own — no telemetry, no relay, no proxy. Your MCP endpoint is a route on your own site and your AI client talks to it directly; the `npx` bridge runs on your own machine. The only external call is WordPress core's plugin installer, and only when you click to install a companion plugin.

= Do I need an AI subscription? =

You need an AI client that speaks MCP, and you bring your own. This plugin never charges for AI usage and never runs inference — it exposes your WordPress site as a set of tools your AI can call.

= Which AI clients are supported? =

Sixteen built-in clients ship with the free plugin — every one gets a ready-to-paste JSON snippet and its own tab:

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

**ChatGPT**, **Claude**, **Grok**, **Gemini** and **Cursor** also connect in one click through the built-in **AI Connectors**, which run their OAuth on your own site rather than through anyone's cloud. That is free as of 0.4.0 — it previously required the paid add-on. Adding a brand-new client is a filter callback. See [Connecting an AI client](https://acrossai.co/docs/mcp-connect-a-client/).

= Can the AI break my site? =

It can only do what you allow. New servers are administrator-only until you add an access rule; you choose which abilities each server exposes at all; and every ability still runs WordPress's own capability check for the connecting user, so reaching it through MCP grants nothing extra.

With the Abilities Manager add-on, roughly half the catalogue is annotated read-only and only about 13% is flagged destructive. Higher-risk operations require an explicit confirmation flag, search-and-replace is a dry run unless you say otherwise, file access is confined to an administrator-defined path allowlist, and secrets such as database credentials and auth salts are stripped out of file and log reads.

= What can the AI actually do once connected? =

With the free Abilities Manager add-on: 357 abilities across 14 toolsets on any site — content, blocks, appearance, users, configuration, database, files, cron, cache, updates and diagnostics — rising to over 800 across 32 toolsets as it detects plugins such as WooCommerce, Elementor, Rank Math, Yoast SEO, ACF and LiteSpeed Cache. Without the add-on, the plugin still serves whatever abilities WordPress and your other plugins have registered.

= Do I have to install the Abilities Manager add-on? =

No. This plugin is a complete MCP server on its own and will expose any abilities registered by WordPress or other plugins. The add-on is what gives your AI a large, curated catalogue to work with, and the setup wizard offers to install it for you.

= Can I give one AI access to everything and another almost nothing? =

Yes — that is what multiple servers are for. Create a server per audience, curate its tools, set its ability exposure, and gate it by user, role or capability. They do not interfere with each other.

= Are my credentials secure? =

They are WordPress's native Application Passwords — generated by WordPress, tied to your user, shown once, never stored in this plugin's own tables, and revocable from your profile page. The CLI flow never puts a password in the terminal: you approve in a logged-in browser tab and the credential is issued to that approved session. Application Passwords require HTTPS. Full detail: [Application passwords & security](https://acrossai.co/docs/mcp-application-passwords/).

= Can I connect multiple AI clients to the same site? =

Yes — generate a separate password (or CLI approval) per client. You can also run multiple MCP servers on the same site with different tool and ability sets and per-server access rules. See [MCP servers](https://acrossai.co/docs/mcp-servers/).

= What is MCP? =

The Model Context Protocol — an open standard introduced by Anthropic and adopted across the AI industry — lets an AI assistant discover and call tools on a service through one common interface. An MCP server exposes those tools; this plugin makes WordPress one.

= Why not just use the MCP Adapter plugin? =

You can — this plugin is built on it, and ships it. [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) is the canonical library that turns registered WordPress abilities into MCP tools. It is the engine, and it is deliberately not a product: on its own it gives you one default server with three tools, no admin screens, and no way to decide who may reach it.

This plugin is the part around it — multiple servers, per-server tool curation, per-ability exposure, access control by user, role or capability, 16 client guides with ready-made config, one-click connectors with OAuth on your own site, and an audit log. If you want to wire MCP up in your own code, use the adapter directly. If you want to administer it, use this.

One caveat worth knowing: because this plugin bundles its own copy of the adapter, running the standalone MCP Adapter plugin at the same time will show a duplicate-copy warning. You do not need both — deactivate the standalone one.

= Does it work on multisite? =

It works per site: each site in a network keeps its own servers, rules and credentials. There is no network-admin screen, so activate it per site rather than network-wide.

= Can I add my own AI client or connection method? =

Yes. Clients, server tabs, connect methods and server types are all registered through filters, so you can add your own from a plugin without forking this one.

= How do I revoke an AI client's access? =

Delete its Application Password from your WordPress profile page, or disable the MCP server from the servers list. Either takes effect immediately.

== Support ==

* **Documentation** — [acrossai.co/docs](https://acrossai.co/docs/)
* **MCP Manager docs** — [acrossai.co/doc-category/mcp-manager](https://acrossai.co/doc-category/mcp-manager/)
* **Use cases** — [acrossai.co/use-cases](https://acrossai.co/use-cases/)
* **Integrations** — [acrossai.co/integrations](https://acrossai.co/integrations/)
* **Full changelog** — [acrossai.co/changelog](https://acrossai.co/changelog/)
* **Troubleshooting & FAQ** — [acrossai.co/docs/mcp-faq-troubleshooting](https://acrossai.co/docs/mcp-faq-troubleshooting/)
* **Source code + issue tracker** — [github.com/acrossai-co/acrossai-mcp-manager](https://github.com/acrossai-co/acrossai-mcp-manager)

== Screenshots ==

1. The Overview tab — your server at a glance, including the live MCP endpoint URL to hand your AI client, with every supported client listed underneath.
2. Terminal users connect with one command and approve it in the browser. Every approved, successful and failed attempt is recorded in the per-server CLI connection log.
3. Pick your AI client, generate a WordPress Application Password in one click, and copy configuration JSON that already has the right file path and top-level key for that client.
4. AI Connectors — paste one URL into Claude, ChatGPT, Grok, Gemini or Cursor and approve the consent screen on your own site. Built in and free.
5. WP-CLI STDIO transport — the client launches WP-CLI as a subprocess, so no credential ever crosses the network. Ideal for local development.
6. The Tools tab — choose exactly which abilities this server advertises. Add three, or add hundreds.
7. The Abilities tab — switch individual abilities on or off per server, with search, filters and bulk actions across the whole catalogue.
8. Access Control — decide who may reach each server by user, role or capability. New servers are administrator-only until you change this.
9. Run as many MCP servers as you need on one site, each with its own route, tools and rules, enabled or disabled independently.
10. Global settings, including CLI connections and a deliberately non-destructive uninstall that keeps your data unless you opt out.

== Upgrade Notice ==

= 0.4.1 =
Readme correction only — no code changes. The listing had still been describing one-click connectors as a paid extra; they have been free since 0.4.0.

= 0.4.0 =
One-click connectors are now free and built in. Load any wp-admin page once after updating so existing connections migrate — until you do, connected AI clients cannot authenticate. Nothing needs reconnecting.

= 0.3.8 =
The **AcrossAI** server is recommended again — pinned to the top of the servers list and preselected in Quick Connect. Existing servers are untouched and there is no database change.

= 0.3.7 =
Repairs sites whose database tables were missing columns — which could make a server advertise tools you never selected and silently refuse to remove them. It happens automatically on the next wp-admin page load. Reconnect your AI client afterwards to see the corrected tool list.

= 0.3.6 =
Installing the AcrossAI Abilities Manager add-on now works immediately — no server-type change and no Reset needed. Adds a second, disabled-by-default AcrossAI server, and repairs servers that were created with no tools. Your own tool selections are left alone.

== Changelog ==

The complete, formatted release history — including releases older than the ones shown here — lives at [acrossai.co/changelog](https://acrossai.co/changelog/). WordPress.org truncates this section, so the site is the fuller record.

= 0.4.1 =
* **Corrected — this plugin's own description still said one-click connectors were a paid extra.** They have been part of the free plugin since 0.4.0. The listing, the feature list and the FAQ now say so, and the connector, the OAuth server that powers it and the connections dashboard have moved from the paid section to the free one. No code changed; if you are already on 0.4.0 you already have all of it.
* **Also — tidied this page itself.** Removed two boilerplate sections, one of which claimed PHP 7.4 compatibility and contradicted the plugin's actual PHP 8.1 requirement; shortened the upgrade notices; trimmed the changelog here to the last three releases, with the full history in changelog.txt as always; and added a FAQ answering how this plugin relates to the MCP Adapter plugin it is built on.

= 0.4.0 =
* **New — connecting Claude, ChatGPT, Gemini, Grok or Cursor is now part of this plugin, free.** Paste one URL, approve the consent screen, done. The **Connectors** tab that used to advertise the paid add-on now does the job itself.
* **Please note — load wp-admin once after updating.** Your existing connections are moved across on the first admin page load. Until that happens, connected AI clients cannot authenticate — so if your site auto-updates, sign in once.
* **Unchanged — existing connections keep working.** No reconnecting, no re-approving. Tokens already issued stay valid.
* **Changed — Quick Connect no longer asks you to buy anything.** The add-on pitch and setup screens are gone; choosing one-click connection goes straight to the connector screen.
* **AcrossAI Pro is still useful** for its abilities library and access control — just not needed for connectors. It stands aside on its own.
* **Database — four tables added.** Existing data from the paid plugin is copied across; its tables are left untouched.
* **Security — people outside a server's access rule could still read its list of tools.** They saw every tool's name and description, though they could not run any of them and nothing could be changed or taken. Those people now see an empty server. Administrators and anyone the rule allows are unaffected.
* **Fixed — switching off the Default MCP Server now actually switches it off.** It used to be marked Inactive while its address carried on answering. New installations now start with it switched off; sites that already have it on are not changed.
* **Updated — MCP Adapter 0.7.0.** Adds the newest version of the MCP standard (2026-07-28) and keeps working with the older ones, so existing connections are unaffected. Also clears a stream of errors the previous version wrote to the debug log.
* **Fixed — setup instructions showed Windows and Linux users a macOS file path.** The Clients tab printed the macOS config location for everyone, so Windows users were sent to a folder that does not exist. Every system a client supports is now listed and labelled.

= 0.3.8 =
* **Changed — the **AcrossAI** server is recommended again.** It carries a **RECOMMENDED** badge and its row is pinned to the top of the servers list.
* **Changed — **Quick Connect** step 1 arrives with AcrossAI already selected**, instead of whichever server happened to come first.
* **Changed — new servers start on the **AcrossAI** type** where the AcrossAI Abilities Manager add-on is installed, so they arrive with its toolsets. Without the add-on they still start on **MCP Adapter**. Pick either from the **Server type** dropdown.
* **Existing servers are untouched**, both seeded servers are still undeletable, and there is no database change.

== License ==

This plugin is licensed under the GPL-2.0-or-later license. See LICENSE file for details.

== Credits ==

MCP Manager is built with:
- WordPress native APIs
- Automattic's MCP WordPress Remote package
- WordPress Application Passwords system

Developed with ❤️ for the WordPress community.
