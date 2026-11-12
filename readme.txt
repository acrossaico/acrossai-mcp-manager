=== AcrossAI MCP Manager – WordPress MCP Server & Admin for MCP Adapter, Claude & ChatGPT ===
Contributors: raftaar1191
Tags: mcp, ai, mcp-adapter, claude, chatgpt
Requires at least: 6.9
Requires PHP: 8.1
Tested up to: 7.1
Stable tag: 0.4.8
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
* **Activity log**: every MCP request and the abilities it ran, kept as a readable history with retention, a size cap and privacy controls.
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

= See What the AI Actually Did =

Access control decides what an assistant **may** do. The activity log records what it **did**.

* **Every request, and every ability behind it.** A single "create a page" from an assistant may run several abilities; the log keeps the request and its abilities together, so you can open one entry and read what actually happened rather than guessing from the result.
* **On from the moment you update, and bounded without being configured.** Requests are kept 30 days, ability runs 7, and the whole log is capped at 50,000 entries. A daily job applies all three. Both halves can be switched off.
* **Enough detail to answer the real questions**: which assistant, how it authenticated, whether it worked, how long it took, what it was asked and roughly how much of your content it read back.
* **It tells you when it is failing.** An empty log and a broken log look identical, so the screen and Site Health both say so when writes are failing, when the table cannot be reached, or when the daily cleanup has not run.
* **Private by design.** Sensitive values are redacted by key name before anything is stored, response bodies are never kept, and WordPress's own personal-data export and erase both reach it — erasing clears the person from the record while keeping the event, so the audit history stays intact.

Find it under **AcrossAI → Logs**, or on any individual server's own tab.

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
2. The Connect wizard opens automatically. Follow it, or close it and configure by hand.
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

AcrossAI is two free plugins on WordPress.org: MCP Manager (the server, connectors and access control) and Abilities Manager (the 357 abilities and their controls). Connect sets up both in one click.

= Can I see what the AI did to my site? =

Yes. **AcrossAI → Logs** records every request an assistant made and every ability that ran to fulfil it, with the two linked so you can open one entry and read the whole call. It records from the moment you update — requests kept 30 days, ability runs 7, capped at 50,000 entries, all adjustable, and either half can be switched off on the MCP settings tab.

If recording ever fails, the screen and Site Health say so rather than showing an empty list, because a broken log and a quiet one otherwise look the same.

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

= 0.4.8 =
Adds a Clients screen listing every assistant that has connected to your site, kept after the activity log has been pruned. This release does change the database: a new table is created to hold those records and is seeded from your existing log and connector list, applied automatically on update. Everything else is safe — servers, connections and abilities are untouched and no reconnecting is needed. Also adds fleet pairing by pasting a prompt, rebuilds the Fleet tab, lets an assistant read your activity log, and fixes the Claude 1-click button being disabled on sites where it works.

= 0.4.7 =
Adds Fleet, which lets an agency's WordPress site manage yours. It is switched off by default and does nothing until you pair it yourself, and your existing AI access is untouched — no reconnecting is needed. This release does change the database: the activity log gains a column recording which managing site made a request, applied automatically on update.

= 0.4.6 =
Removes the `[acrossai_mcp_servers]` shortcode and the embeds block — both moved to AcrossAI Pro. If you use that shortcode on a page, install AcrossAI Pro before updating or the page will show nothing in its place. Everything else is safe to update: servers, connections and abilities are untouched, no reconnecting is needed and there is no database change. Adds a Troubleshoot screen, and fixes connection details being cached for an hour and OAuth addresses 404ing until Permalinks were re-saved.

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

= 0.4.8 =
* **New: a Clients screen that shows who has been connecting.** Until now the plugin could tell you in detail what happened on any single request, and nothing at all about who had been using your site. **AcrossAI → Clients** lists every assistant, bridge and paired site that has connected, with when it first connected, when it was last used and how many requests it has made. Each server has the same list on its own **Clients** tab.
* **The record outlives the log.** Activity is kept for 30 days, so an assistant that stopped calling last month had already vanished from every screen. Client records are never pruned — a connection you set up a year ago is still listed, with its history intact.
* **You can withdraw a client's access from that screen.** Revoke does the right thing for how the client got in: it withdraws the access tokens for an AI connector, deletes the application password for a client configured from a file, or removes a paired site from your host list. Where there is no standing credential to take away — a browser session, say — no Revoke is offered, rather than one that would appear to work and change nothing. **Forget** removes a record you no longer want to see without touching the credential.
* **Clients appear the first time they call.** Creating an application password or registering a connector does not add a row on its own; the client is listed once it actually connects. Assistants registered but never used are shown as **Never used**, which is the difference between one that is set up wrong and one that is not set up yet.
* **New: add this site to a fleet by pasting a prompt.** Joining an agency's dashboard meant reading a twelve-character security ID off this screen, switching to their admin, and typing it in along with your address. The Fleet tab now gives you a block of text instead: paste it into whatever AI the managing site already uses and the pairing happens in one step. Doing it by hand still works and is unchanged — it is the only route if the hub has no AI connected to it. A prompt works with any assistant, not only Claude, and there is a matching one for removing a site again.
* **Changed: the Fleet tab is rebuilt around what you actually do there.** It was two settings tables and a list. It is now three cards: access on or off, how to add a site, and the hosts currently connected. The screen is not really a form — one control turns access on and everything else is a value to read or a procedure to follow — and it now reads that way.
* **New: an assistant can read your activity log.** A new **mcp-manager** toolset lets a connected AI answer questions about your own traffic — period totals, which clients called, what failed — over exactly the same filters the Logs screen uses, so what you see on screen and what it reports cannot disagree.
* **Fixed: "Connect to Claude (1-click)" was disabled on sites where it works.** The button was greyed out with "Unavailable on this site" on public HTTPS sites, because the check ran before a server had been chosen and judged a placeholder address instead of your own. The warning beside it also read as a sentence with its subject missing.
* **For developers: a plugin can now add toolsets to a server type it does not own.** The new `acrossai_mcp_server_type_tools` filter receives the resolved tool list and hands it back, so contributions compose. Previously the only route was re-declaring the whole type entry, which clobbered its label and description and meant whichever plugin registered last silently erased the other's contribution.

= 0.4.7 =
* **New: another WordPress site can now look after this one — Fleet, the free half.** An agency running AcrossAI Pro can manage your site from their own dashboard, and you stay on the free plugin. A new server called **Fleet** is seeded for you and is **switched off by default** — nothing can reach your site until you turn it on and pair it yourself.
* **You start the pairing, not them.** You paste a security ID from their dashboard and press Connect; your site introduces itself to theirs. Nobody types your admin password into somebody else's site, and you can remove the host at any time from your own screen — their access stops immediately, without asking them.
* **Their access is a signature, not a password.** Every request from the hub is signed, and your site stores only the public half. There is no shared credential sitting in your database to leak, and no secret ever travels inside a web address where it would be written into server logs and browser history.
* **Fleet traffic is kept separate from your own.** It arrives on its own address, so the AI access you already use is untouched and entirely independent. The Logs, Abilities and Access Control tabs on the Fleet server therefore describe hub activity and nothing else.
* **The activity log now records which hub did what.** A managing site's actions used to be filed as if someone had clicked them in a browser, which hid the one fact you would actually want after the event. Fleet requests are now attributed to the hub that made them, and can be filtered that way.
* **Changed: the Consultations screen shows both ways to book.** It offered a single "Book a free consultation" button that led to a page requiring an AcrossAI Pro licence — so a free user followed a link promising something free and was then told they needed to buy something. It now shows both routes: request a free call and get a reply by email, or, with a Pro licence, pick your own slot in a live calendar.
* **Fixed: Quick Connect offered servers that cannot be connected to.** Some server types exist to be reached by other software rather than by an assistant. They were listed anyway, so choosing one led to a connection that could never complete.

= 0.4.6 =
* **Removed: the front-end embed shortcode and block.** `[acrossai_mcp_servers]`, the per-server Embeds tab and the transports behind them have moved to AcrossAI Pro, which is where the rest of the publishing surface already lived. If you have that shortcode on a page it will stop rendering when you update, and the page will show nothing in its place — install AcrossAI Pro to keep it. Nothing else is affected: servers, connections, abilities and access control are untouched, and no connected assistant notices the difference.
* **New: a Troubleshoot screen that says why an assistant cannot connect.** Until now the admin showed nothing when a connection failed — the assistant reported its own unhelpful error and every check you could reach said the site was healthy, because it was. The new screen under **AcrossAI → Troubleshoot** probes this site the way an assistant does and reports eight checks with a plain-language reason and, where it helps, text you can paste straight into a hosting ticket. It runs only when you press the button, and it changes nothing.
* **It names the plugin that is blocking you.** If something refuses unauthenticated access to the REST API — which blocks the very first step of every connection, before any credential exists — the screen now names the plugin responsible and quotes the error that plugin returned, instead of telling you "a security plugin" and leaving you to deactivate thirty of them one at a time.
* **It checks the things that actually break connections.** Whether a cache is serving an assistant a stale copy of your site's connection details; whether a security rule is rejecting non-browser visitors (Claude connects as `python-httpx`, and "block Python" rules ship switched on in some firewall plugins); whether the OAuth web addresses resolve at all; and which caching or security layers sit in front of the site. It is honest about its own blind spot: every check runs from the site to itself, so it cannot see a block based on where the visitor is connecting from, and it says so rather than showing a green tick.
* **Fixed: your site told every cache to keep its connection details for an hour.** The documents an assistant reads to learn how to connect were being served with an instruction to cache them for sixty minutes, and nothing told caches to skip them. On one site that meant an operator fixed a real problem, tested again, and still failed — for an hour, with no sign anywhere that a cache was involved. The window is now sixty seconds, page caches are told explicitly not to store these documents, and when you change a server's address or switch it off the affected documents are purged immediately.
* **Fixed: OAuth web addresses could 404 until you re-saved Permalinks.** Four addresses an assistant needs are built from WordPress's permalink rules, and those rules can go missing for reasons that have nothing to do with this plugin — another plugin rebuilding them, a database copied from a site where this plugin was switched off, a restore. The plugin only noticed when its own version changed, so the rest of the time the fix was to visit **Settings → Permalinks** and press Save. It now checks the rules are actually there on each admin page load and repairs them itself. A site using Plain permalinks is reported separately, because those addresses can never work there and no amount of repairing changes that.
* **Fixed: the activity log screen had no styling at all.** It had been running on browser defaults since it shipped: four stat boxes floating loose, a table with no header band, no row lines, and columns that filled barely half the screen. It now has the layout it was designed with, plus a column showing roughly how many tokens each response returned.
* **Changed: "Activity" is now "Logs".** The menu entry, the page title and the web address all say Logs. The per-server tab was already called Logs, so the two now agree. Any bookmark to the old address will need updating.
* **Changed: the AI Connectors panel now matches each assistant's real dialog.** The steps for Claude, ChatGPT, Gemini, Grok and Cursor were written from memory and had drifted from what those products actually show. Each one has been rewritten against the current dialog, with the fields named as they appear on screen, and Claude gains a one-click button that opens its connector dialog with everything filled in.
* **New: let your AI client set itself up.** Alongside the manual instructions for all sixteen supported clients, there is now a block of text you can paste into the client itself, telling it to open its own configuration file and add the server. Same configuration either way — it just does the file editing for you.
* **Fixed: a permanent warning on sites that will never need it.** The notice about WordPress scheduled tasks being switched off appeared on every admin page, forever, on every local and staging install — to report that a clean-up with nothing to clean would not run. It now appears only once there is OAuth data to clean up, and a site that runs scheduled tasks from a real cron entry can say so and silence it for good.

= 0.4.5 =
* **Fixed: the Activity Log screen never loaded.** The screen introduced in 0.4.4 rendered blank on every site. Its JavaScript declared a dependency on a WordPress component that does not exist, and WordPress refuses to load a script whose dependencies are missing — so nothing ran, and nothing anywhere said why. Underneath that, the screen was looking for its own built files in the wrong folder, which is what made it fall back to that bad dependency list in the first place. Both are fixed, and the screen now loads at **MCP → Activity Log** and on each server's **Logs** tab. Nothing was lost: the log has been recording correctly all along, it simply could not be displayed. No settings change and no database change — updating is enough.

= 0.4.4 =
* **New: a log of what AI assistants actually did on your site.** Every request an assistant makes, and every ability that ran to fulfil it, recorded together — one "create a page" from an assistant may run several abilities, and the log keeps them linked, so you open one entry and read what happened instead of inferring it from the result. It records which assistant, how it authenticated, whether it worked, how long it took, what was asked and roughly how much of your content was read back. Find it under **MCP → Activity Log**, or on an individual server's own tab.
* **The log is on from the moment you update, and bounded without being configured.** Requests are kept 30 days, ability runs 7 — they are much noisier — and the whole log is capped at 50,000 entries. A daily job applies all three. Every number is adjustable and either half can be switched off on the MCP settings tab. Recording is on by default because a log nobody enabled is a log nobody has when they need it; it is bounded by default because a plugin that records without being asked should also tidy up without being asked.
* **It tells you when it is failing.** An empty log and a broken log look identical, which is how a logging fault stays invisible. The screen and Site Health now both report when writes are failing, when the table cannot be reached, and when the daily cleanup has not run — the last one usually meaning WordPress scheduled tasks are not running at all on the site, which affects far more than this log.
* **Private by design.** Values that look like credentials are redacted by key name before anything is stored, response bodies are never kept, and WordPress's own personal-data export and erase both reach the log. Erasure clears the person from the record and keeps the event, so a subject request cannot be used to delete audit history.
* **Changed: the connection wizard is now called "Connect".** It was "Quick Connect via AcrossAI" in the admin bar, the menu, the server list and the wizard's own header. Nothing moved and nothing behaves differently — the label just says less.
* **Fixed: a PHP notice on every page load.** A database table was passing an index setting in a form newer PHP versions warn about. It had never had any effect — the table was not using it — so removing it changes nothing except that the notice stops. Only visible to sites with debug logging switched on.

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
