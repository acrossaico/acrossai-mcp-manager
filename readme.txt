=== AcrossAI MCP Manager – WordPress MCP Server & Admin for MCP Adapter, Claude & ChatGPT ===
Contributors: raftaar1191
Tags: mcp, ai, mcp-adapter, claude, chatgpt
Requires at least: 6.9
Requires PHP: 8.1
Tested up to: 7.1
Stable tag: 0.4.3
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WordPress MCP server with 357+ abilities. Connect Claude, ChatGPT, Gemini, Grok & Cursor in one click. Built on MCP Adapter. No relay.

== Description ==

**AcrossAI turns your WordPress site into an MCP server with 357 ready-made abilities, and gives you control over every one of them.** Claude, ChatGPT, Gemini, Grok, Cursor and any other AI agent connect straight to your site and can read, write and act on it, with no relay and no third party in between. It is built on the official WordPress MCP Adapter and the WordPress Abilities API.

Connect Claude to WordPress, connect ChatGPT to WordPress, or give Cursor, VS Code, GitHub Copilot, Codex and other AI agents access limited to exactly the tools and users you choose. Ask your AI to draft a post, fix a page, audit site health or clean up the database, and it acts on your live site instead of telling you what to click.

**Setup takes about a minute** with the [Quick Setup wizard](https://acrossai.co/mcp-manager-quick-setup/). Full guides are in the [documentation](https://acrossai.co/docs/).

= Connect Claude, ChatGPT and Any AI Agent to WordPress =

* **One-click AI Connectors** for **ChatGPT**, **Claude**, **Gemini**, **Grok** and **Cursor**: paste one URL into the AI client, approve the consent screen on your own site, done. The OAuth 2.1 server runs on your site, and its clients and tokens are rows in your own database.
* **Claude Code**: one terminal command, approved in your browser, with every attempt recorded in an audit log. No password ever appears in the terminal.
* **16 AI client guides** with the exact config path for your operating system and ready-made JSON: Claude Desktop, Claude Code, VS Code, GitHub Copilot, Codex, Cursor, Gemini CLI, Cline, Roo Code, Kilo Code, Amazon Q Developer, OpenCode, Antigravity, Windsurf, Zed and a Custom Client template.
* **Four ways to connect**: AI Connectors (OAuth), an `npx` bridge with Application Passwords, CLI connections with browser approval, or WP-CLI STDIO so no credential crosses the network.

= What Your WordPress AI Agent Can Do =

**357 abilities on any WordPress site, with no configuration**, rising to **over 800** as AcrossAI detects the plugins you already run.

* **Content**: posts, pages and custom post types with meta and revisions; comments, media, categories and tags; semantic search that proposes, reviews and applies internal links.
* **Blocks**: edit a page's block tree without rewriting the page, build from patterns, generate sections and landing pages, audit copy and design.
* **Appearance**: theme.json and global styles, templates and template parts, menus, widget areas, fonts, site title, logo and icon.
* **Users**: create and edit users, reset passwords, create roles, grant or revoke individual capabilities.
* **Configuration**: any option, including values nested inside serialised arrays; permalinks; which admin screen a setting lives on.
* **Database**: schema and table sizes, index health, bloated autoloaded options, EXPLAIN on a slow query, serialisation-safe search-and-replace.
* **Files**: browse, read, write and delete inside an administrator-defined allowlist; zip backups; wp-config constants; the debug log.
* **Cron, updates, diagnostics and cache**: overdue cron jobs, plugin and core updates with rollback and checksum checks, Site Health, recent fatal errors, transients and object cache.

Try prompts like "Check Site Health and tell me what needs fixing" or "Find the slowest database query and explain why it is slow."

= Real Use Cases, Start to Finish =

* **[Build a WordPress site with Claude in one hour](https://acrossai.co/use-cases/build-wordpress-site-with-claude-in-one-hour/)**: five free plugins, 93 Performance and 100 SEO in Lighthouse.
* **[Build a WordPress site with blocks using Claude](https://acrossai.co/use-cases/build-wordpress-site-with-blocks-using-claude/)**: design system, header, footer, logo and homepage from native blocks.
* **[Build a WordCamp event site with Claude](https://acrossai.co/use-cases/build-wordcamp-event-site-with-claude/)**: pages, mega menu and a Contact Form 7 form.
* **[Tag every WordPress post with AI](https://acrossai.co/use-cases/tag-wordpress-posts-with-ai/)**: reusing your existing tags instead of creating near-duplicates.
* **[Update WordPress core from your phone](https://acrossai.co/use-cases/update-wordpress-core-from-your-phone/)**: no dashboard, no SSH, just chat messages routed through WordPress's own updater.

= A Dozen Tools, Not 357 =

An AI client receives its tool list once, at connect time, and pays for it out of the model's context window in every conversation. Most assistants degrade past a few dozen tools. So abilities are grouped into **toolsets**, and each toolset is a **single tool** with three actions: `discover`, `info` and `execute`. Your AI reaches everything, drilling in only when it needs to.

= WooCommerce MCP, Elementor MCP, Rank Math & Yoast MCP =

Plugins you already run get their own toolset, registered **only when that plugin is active**:

* **[Elementor](https://acrossai.co/integrations/elementor/)** (89 abilities): pages, templates, kits, global widgets and form submissions.
* **[Yoast SEO](https://acrossai.co/integrations/yoast-seo/)** (64) and **[Rank Math](https://acrossai.co/integrations/rank-math/)** (61): titles and meta, indexing, redirections, schema and settings.
* **[LiteSpeed Cache](https://acrossai.co/integrations/litespeed-cache/)** (61): targeted purges, TTLs, exclusions and vary rules.
* **[WooCommerce](https://acrossai.co/integrations/woocommerce/)** (34): catalogue, prices, stock, orders, customers and store health.
* **[Contact Form 7](https://acrossai.co/integrations/contact-form-7/)** (25), **[WPCode](https://acrossai.co/integrations/wpcode/)** (24) and **[CookieYes](https://acrossai.co/integrations/cookieyes/)** (22): forms and mail templates, snippets and their logic, cookies and Google Consent Mode.
* **[The Events Calendar](https://acrossai.co/integrations/the-events-calendar/)** (18) and **[Event Tickets](https://acrossai.co/integrations/event-tickets/)** (16): events, venues, tickets, capacity and check-ins.
* **[Advanced Custom Fields](https://acrossai.co/integrations/advanced-custom-fields/)** (16), **Site Kit by Google** (14) and **[Loco Translate](https://acrossai.co/integrations/loco-translate/)** (14): field groups, Search Console and Analytics 4 reports, and translations.
* **[All-in-One WP Migration](https://acrossai.co/integrations/all-in-one-wp-migration/)** (9) and **[UpdraftPlus](https://acrossai.co/integrations/updraftplus/)** (8): backups, whether they worked, and what each contains.
* **[WP Mail SMTP](https://acrossai.co/integrations/wp-mail-smtp/)**, **[Classic Editor](https://acrossai.co/integrations/classic-editor/)**, **Akismet** and **WPForms**.

The optional [AcrossAI Pro](https://acrossai.co/pricing/) add-on contributes 276 more abilities for **MailerPress**, **LearnDash**, **BuddyBoss** and **GeoDirectory**. Browse [every integration](https://acrossai.co/integrations/).

= Access Control and Safety: Nothing Is Wide Open =

* **Administrator-only by default**, then gate each server by user, role, capability or your own policy provider. The gate is fail-closed.
* **Every ability runs WordPress's own capability check** for the calling user, so MCP access grants nothing extra.
* **Roughly half the catalogue is read-only** and only about **13% is flagged destructive**.
* **Higher-risk operations need a confirmation flag**, and search-and-replace is a dry run unless you say otherwise.
* **File access is confined to an allowlist**, secrets are redacted from file and log reads, and database abilities never accept a raw table name.

= Full Control Over Every Ability =

* **Browse** every registered ability in a searchable, sortable table, and **allow or disallow** any of them in one click. A disallowed ability is unregistered outright.
* **Override metadata** such as `readonly`, `destructive`, `idempotent`, `show_in_mcp` and `mcp_servers` with a Yes / No / Inherit control.
* **Bulk actions** on up to 50 abilities, and an **Ability Library** to switch whole groups on or off.
* **Multiple MCP servers per site**, each with its own route, tools, ability exposure and access rules: a read-only server for a client's AI and a full-access one for yourself.

Overrides live in their own table. The WordPress ability registry is never modified.

= Reproduce a Plugin Conflict Without Breaking the Site =

**Conflict Testing** toggles any plugin's effective active state for one browser session **without writing to `active_plugins`**, so your AI can bisect a conflict for you. A sandbox probe refuses any plugin that would fatal-error the site.

= Built on the Official MCP Adapter. No Relay, No Lock-In. =

[MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) is the official WordPress library that turns abilities into MCP tools, and it is included. AcrossAI adds the admin, access control, connectors and abilities around it. There is no telemetry and no hosted relay: your MCP endpoint is a route on your own site. Every ability is a standard WordPress ability, so it also works over the REST API or with any other Abilities API consumer.

The only outbound connection is WordPress core's own plugin installer reaching WordPress.org, and only when you click to install a companion plugin. Uninstalling is non-destructive by default: your servers, rules and logs survive unless you tick the delete-all-data option first.

= For Site Owners, Developers and Agencies =

**Site owners** run their site by talking to the AI they already pay for. **Developers** add clients, server tabs, connect methods, server types and toolsets through filters, with no fork required. **Agencies** run a separate MCP server per client, scoped to exactly what that client should reach.

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

Open your server's **Connectors** tab and copy the URL. In Claude, add it as a connector, then approve the consent screen on your WordPress site. For Claude Desktop config files or Claude Code, use the **Clients** tab or a CLI connection instead.

= How do I connect ChatGPT to WordPress? =

Copy your server URL from the **Connectors** tab, add it as a connector in ChatGPT, and approve access on your own site. The OAuth server runs on your WordPress install, not on ours.

= How do I use Claude Code with WordPress? =

Turn on CLI connections under **AcrossAI → Settings → MCP**, run the command shown on your server's screen, and approve it in your logged-in browser. You can also paste the Claude Code JSON from the **Clients** tab, or use WP-CLI STDIO on a local site.

= What is included? =

AcrossAI is two free plugins on WordPress.org: MCP Manager (the server, connectors and access control) and Abilities Manager (the 357 abilities and their controls). Quick Connect sets up both in one click.

= Is it free? =

Yes, under GPL. The optional AcrossAI Pro add-on contributes extra integrations.

= Why does my AI see about a dozen tools when there are 357 abilities? =

Abilities are grouped into toolsets, and each toolset is one tool with `discover`, `info` and `execute` actions. Exposing hundreds of separate tools would flood the model's context window.

= Is this an MCP Adapter extension or a standalone WordPress MCP server? =

Both. It is built on the official MCP Adapter and adds the admin layer and abilities around it. Because the adapter is included, it also works as a complete WordPress MCP server.

= Do I need to install the MCP Adapter plugin separately? =

No. MCP Adapter is included. If you already run the standalone MCP Adapter plugin, deactivate it to avoid a duplicate-copy notice.

= Does my content go to a third party? =

No. There is no relay or proxy. Your AI client talks to a route on your own site.

= Do I need an AI subscription? =

You bring your own MCP-capable AI client. AcrossAI never runs inference and never charges for AI usage.

= Can the AI break my site? =

It can only do what you allow. Servers are administrator-only by default, every ability runs WordPress's capability check, risky operations need confirmation, search-and-replace defaults to a dry run, and file access is limited to an allowlist.

= Can I give one AI full access and another read-only access? =

Yes. Create a server per audience, curate its tools and abilities, and gate it by user, role or capability.

= Are my credentials secure? =

Config-file connections use WordPress Application Passwords, shown once and revocable from your profile. One-click connectors use OAuth 2.1 with PKCE and refresh-token rotation with reuse detection, all on your own site.

= Why does my AI client get a 401 error? =

Application Passwords require HTTPS, so check that your site URL uses https. Some hosts strip the Authorization header before it reaches WordPress; ask your host to pass it through.

= Why does the MCP endpoint return a 404? =

Check that the server is enabled; the Default MCP Server starts switched off on new installs. If it is enabled, re-save **Settings → Permalinks**.

= Does it work on multisite? =

MCP servers work per site, each with its own rules and credentials; activate per site rather than network-wide. The abilities catalogue has not yet been tested on multisite.

= Does removing it leave anything behind? =

The WordPress ability registry is never modified, and uninstalling keeps your servers, rules and overrides unless you choose to delete them.

= What happens when I reset an override? =

The override row is deleted, and the ability inherits its values from the registry again.

= How do I revoke an AI client's access? =

Revoke it from the connections dashboard, delete its Application Password, or disable the server. Each takes effect immediately.

= What is MCP? =

The Model Context Protocol is an open standard, introduced by Anthropic and adopted across the AI industry, that lets AI assistants discover and call tools on a service.

== Support ==

Documentation: [acrossai.co/docs](https://acrossai.co/docs/). Every ability, searchable: [acrossai.co/abilities](https://acrossai.co/abilities/). Source code: [github.com/acrossai-co](https://github.com/acrossai-co).

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
11. The abilities table: every registered ability, searchable and sortable.
12. The edit drawer: Yes / No / Inherit override controls for each ability field.
13. Bulk actions to allow, disallow or reset many abilities at once.
14. The Ability Library: enable or disable whole ability groups.
15. The Add-ons page: browse free companion plugins.
16. Abilities settings: abilities per page and allowed upload file types.

== Upgrade Notice ==

= 0.4.3 =
Adds a Firefox-only note explaining why a connection can fail silently there, and stops an uninstall leaving one settings row behind. No settings change and no reconnecting needed.

= 0.4.2 =
A clearer plugin listing and six new screenshots of the ability controls. No code change, no settings change and no reconnecting needed.

= 0.4.1 =
Now supports WordPress 6.9 and later. No settings change and no reconnecting needed.

= 0.4.0 =
One-click connectors are now free and built in. Load any wp-admin page once after updating so existing connections migrate; until then, connected AI clients cannot authenticate.

= 0.3.8 =
The AcrossAI server is recommended again and preselected in Quick Connect. Existing servers are untouched and there is no database change.

== Changelog ==

The complete release history lives at [acrossai.co/changelog](https://acrossai.co/changelog/).

= 0.4.3 =
* **New: a heads-up in Firefox, where tracking protection can block an assistant from connecting.** Firefox's Enhanced Tracking Protection can stop the connection part-way through — the connector is added, sign-in opens, and then nothing completes, with no error shown anywhere to explain it. Opening a connector screen in Firefox now shows a note explaining this and how to get past it: click the shield icon to the left of the address bar and switch Enhanced Tracking Protection off for this site, then connect again. The setting is remembered per site, affects nothing else you browse, and can be switched back on once the connection is working. The note appears only in Firefox and can be dismissed.
* **Fixed: uninstalling left a settings row behind.** The "delete all data on uninstall" sweep deliberately skipped every option starting `acrossai_mcp_connector_`, because an earlier release had handed that namespace to the paid plugin and deleting another plugin's settings would have been wrong. Version 0.4.0 took that area back, which quietly turned the exception into a leak: the only row it still protected was this plugin's own version marker for one of its four tables — a table the same uninstall drops. The sweep now covers the whole namespace. Only affects uninstalling with the delete option switched on.

= 0.4.2 =
* Improved: rewrote the plugin listing around what your AI can actually do on the site, instead of leading with how the plugin relates to MCP Adapter.
* Added: real use cases with links, ability counts for each plugin integration, and an explanation of why your AI sees about a dozen tools when there are 357 abilities.
* Added: sections on controlling every ability, and on reproducing a plugin conflict without writing to `active_plugins`.
* New: six screenshots of the ability controls — the abilities table, the override drawer, bulk actions, the Ability Library, Add-ons and abilities settings.
* Tidied: shortened the FAQ and replaced the questions that only restated the description.
* Note: listing and documentation only. No code, settings or database change.

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

AcrossAI is built with:
- The official WordPress MCP Adapter
- WordPress native APIs
- Automattic's MCP WordPress Remote package
- WordPress Application Passwords system

Developed with ❤️ for the WordPress community.
