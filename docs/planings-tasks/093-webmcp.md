# Planning: WebMCP — the server's tool list in the browser (Feature 093)

Issue: [#127](https://github.com/acrossai-co/acrossai-mcp-manager/issues/127)

## In plain English

Today an AI assistant reaches this site from **outside** — Claude on your desktop, Cursor in your
editor — over our remote MCP server. The assistant is somewhere else, and it calls in.

WebMCP is the other direction. The browser tab itself declares what it can do, and an agent
already *inside* the browser — Gemini in Chrome, ChatGPT's browser, an extension — calls those
tools directly instead of squinting at the screen and clicking buttons.

We already have everything this needs: the abilities, the per-server exposure rules, and a
per-server tool list the admin has already curated. This feature puts that list in the browser.

The reason this is a handful of tools and not 370 is the entire design. A page that declares one
tool per ability hands the agent a 370-item menu in a single prompt. We now have field evidence
for what happens when you try: a competing WordPress bridge registered 296 tools and **ChatGPT
switched WebMCP off for that document entirely.**

**The browser gets exactly what the chosen server says it gets.** WebMCP gets its own submenu
under AcrossAI: a page that explains what this is, and a dropdown listing your servers by name.
Pick one, and that server decides everything else —

- its **Tools** tab decides which tools appear,
- its **Abilities** tab decides what sits behind them,
- its **Access Control** tab decides who may use them at all; if the rule is not satisfied, no
  tools are shown.

Need a server dedicated to browser agents? Create one the normal way and select it here. There is
no second place to curate anything, and nothing new to learn: it is the same server model that
already serves remote AI clients, pointed at the browser instead.

It ships **off by default**, behind a beta gate, on admin screens only.

## This supersedes the issue's "register the triple" design

The issue specifies registering the three meta-tools — `mcp-adapter/discover-abilities`,
`…/get-ability-info`, `…/execute-ability` — mapped to `wp_discover_abilities` and friends. Checked
against real data, that design breaks on the very server it names as the default:

| Server | Type | `tool_*` meta flags | Curated tools | Effective list |
|---|---|---|---|---|
| 3 — Default MCP Server | `mcp-adapter` | all **on** | `mcp-adapter/server-guide` | **4 tools** |
| 5 — **AcrossAI** (`acrossai-mcp-server`) | `acrossai` | all **off** | 14 × `toolset/*` | **14 tools** |

The recommended default server has **all three meta-tool flags set to 0**. It does not use the
triple at all — it exposes fourteen `toolset/*` dispatchers (`appearance`, `blocks`, `cache`,
`configuration`, `content`, `cron`, `database`, `diagnostics`, `files`, `integrations`, `other`,
`server-guide`, `updates`, `users`). So the issue's design would, for its own default, register
three tools the admin explicitly switched **off** and none of the fourteen they actually curated.

The fix is not a different hardcoded list. It is to stop hardcoding:

> **Register `ToolPolicy::compose_for_row( $row )`.**

That method (`includes/Database/MCPServer/ToolPolicy.php:123`) already composes the effective list
— the union of enabled protocol columns mapped through `COLUMN_MAP`, plus curated slugs from
`MCPServerToolQuery::get_added_slugs()`, deduped with stable ordering. It is the single source of
truth behind the *Added as tools* panel. Calling it means the WebMCP page inherits the Tools tab
for free, and keeps inheriting it when F092's stable-tool-menu work changes what is on the list.

The count stays safe either way: **4 or 14**, both far under the ~30 respira-press found workable
and two orders of magnitude under the 296 that broke ChatGPT. Bounded by curation rather than by
a hardcoded constant — which is the property we actually wanted.

The triple is not special-cased out, either. On an `mcp-adapter`-type server its three flags are
on, so `compose_for_row()` returns them and they get registered. The issue's design is the
*subset* this produces for one server type.

## What Novamira is doing about this — nothing

Asked directly, because they are the obvious comparison. The answer is clean:

**Novamira does not implement WebMCP.** It is a remote MCP server in the same category as MCP
Manager — an external agent (Claude Code, Claude Desktop, Cursor, VS Code, Windsurf, Zed) calls
*into* WordPress through an MCP bridge, with PHP execution and filesystem access as its
differentiator. The model runs in the developer's IDE or terminal, never in the page. Their
published roadmap is ACF / JetEngine / Meta Box / Pods / ASE specialisations — deeper abilities
for the same remote transport, not browser tools.

So there is no Novamira design to copy or contradict here, and no competitive pressure from them
on this feature. The useful comparison turned out to be somebody else entirely.

## The prior art that actually matters

The issue's prior-art list missed the most advanced implementation of this idea in WordPress:
**[respira-press/webmcp-for-wordpress](https://github.com/respira-press/webmcp-for-wordpress)**,
which claims the first WordPress site with working site tools in ChatGPT's browser. Five findings
from it change our plan:

**1. The 296-tool failure — our core thesis, measured.**
They registered one tool per ability and *"296 registered tools made ChatGPT disable WebMCP for
the document entirely."* They retreated to *"a curated ~30-tool, page-scoped set."* This is the
strongest possible validation of registering the curated list rather than the catalogue: our
4-or-14 sits comfortably inside the band they found workable, and it is bounded by what an admin
chose rather than by how many abilities happen to be installed.

**2. ChatGPT's `modelContext` is frozen and implements only `registerTool`.**
Their batch `provideContext()` call *"silently no-ops"* against a frozen object. They now
*"register tools one at a time through `registerTool` when it exists."* Our plan already registers
individually; this confirms it is mandatory, not stylistic, and that `provideContext` must not
appear anywhere in our bridge.

**3. Slashes in the URL path will 404 on Apache.**
Apache's `AllowEncodedSlashes Off` broke their routes; they encode `/` as `__` and map it back in
the sanitizer. **This bites us harder than it bit them** — every one of our ability slugs contains
a slash (`mcp-adapter/discover-abilities`, `toolset/content`). Design rule below.

**4. Two-nonce design.** `wp_rest` in `X-WP-Nonce` authenticates the cookie; a separate app-layer
CSRF token rides `X-WMCP-Nonce`. Their discovery endpoint initially 401'd because the first fetch
omitted a nonce entirely — worth knowing before we debug it ourselves.

**5. A real consent model exists and it is staged edits.** `create-page-duplicate` →
`approve-duplicate` / `reject-duplicate`: the agent stages a reviewable duplicate and a human
approves it *on the page you are both looking at*. That is a far better answer to our open consent
question than "restrict to read-only", and it is a candidate for v2 rather than v1.

One more, from an unrelated bug report
([traali/basketball-stats#8](https://github.com/traali/basketball-stats/pull/8)): a polyfill that
`defineProperty`s over the host getter means **native agents never see the tools at all** —
ChatGPT Desktop and Chrome origin-trial consumers read the host `document.modelContext`. The rule
is: feature-detect `document.modelContext.registerTool`, never `defineProperty` over a host
getter, polyfill only when the API is absent. Our issue already said "skip the polyfill when
native exists"; this is the field evidence for why that is load-bearing rather than tidy.

## Decisions this adds to the issue

| # | Decision | Why |
|---|---|---|
| D1 | **Ability slugs travel in the JSON body, never in the URL path.** Routes are `POST /webmcp/execute` with `{"slug": "..."}`, not `/webmcp/execute/{slug}`. | Every slug contains `/`. Apache `AllowEncodedSlashes Off` 404s it. Avoids their `__` escaping hack entirely rather than reimplementing it. |
| D2 | **`provideContext` is forbidden in the bridge.** Register each of the three via `registerTool`, awaiting the Promise. | Frozen object in ChatGPT's browser silently no-ops a batch call. |
| D3 | **Never `defineProperty` over `document.modelContext`.** Feature-detect `.registerTool`; load the polyfill only when absent. | A shadowing polyfill blinds native agents — the exact failure we would otherwise ship to Chrome users. |
| D4 | **Two-nonce auth**: `wp_rest` in `X-WP-Nonce` plus our own CSRF token. Send a nonce on the *discovery* call too. | Matches the only working implementation; their 401 was caused by omitting it on discovery. |
| D5 | **Consent v1 stays read-only**, with staged-duplicate approval recorded as the v2 design rather than invented later. | Gives the read-only restriction an exit path instead of leaving it a dead end. |
| D6 | **Establish server context; do not re-implement the permission chain.** Set `CurrentServerHolder` to the selected server for the duration of the request, then call the existing `Execute` / `Discover` / `GetAbilityInfo` paths unchanged. | See below — the chain is already written, and re-implementing it silently drops four steps. |
| D7 | **Register `ToolPolicy::compose_for_row()`, not a hardcoded triple.** The tool list is whatever the selected server's Tools tab shows. | The recommended default server has all three meta-tool flags **off** and 14 curated toolsets. Hardcoding the triple would expose tools the admin disabled and miss every one they chose. See the section above. |
| D8 | **Tool names derive from slugs, deterministically and stably** — `toolset/content` → `wp_toolset_content`, `mcp-adapter/execute-ability` → `wp_mcp_adapter_execute_ability`. One rule, no per-tool mapping table. | WebMCP names must be identifier-shaped (no `/`) and stable across sessions; a hand-maintained map would drift the moment a toolset is added. |
| D9 | **Access Control must pass before a single tool is registered.** Check `user_has_server_access( get_current_user_id(), $server_id )` at the top of the bridge's bootstrap and again on every REST call. If it fails, register nothing and return nothing. | The server owns tools, abilities *and* who may reach it. Registering first and refusing later leaks the catalogue — see below. |
| D10 | **WebMCP gets its own submenu, not a Settings tab**, under the AcrossAI parent. It explains what WebMCP is and how it works, then offers the server dropdown. | It needs room to teach — this is a beta of an API most admins have never heard of. A settings tab is the wrong shape for a page that is half documentation. |

### D9 in full — the third gate that will not fire

This is the same structural trap as D6, and it is now the third instance of it. **Three independent
gates all hook the vendor MCP transport, and none of them fire for a WebMCP request:**

| Gate | Hook | How it finds the server | WebMCP |
|---|---|---|---|
| Ability exposure | `AbilityHelpers::apply_exposure_filter()` | `CurrentServerHolder` (transport-populated) | **silent, fails open** to `meta.mcp.public` |
| Access control — list | `gate_mcp_tools_list()` on `mcp_adapter_tools_list` | the `$server` object argument | **never called** |
| Access control — execute | `gate_mcp_tool_call()` on `mcp_adapter_pre_tool_call` | the `$server` object argument | **never called** |

Each resolves the server from something only the MCP transport provides — `CurrentServerHolder`
for the first, a `\WP\MCP\Core\McpServer` argument for the other two. A WebMCP request arrives over
plain REST with a cookie. None of that context exists, so all three quietly do nothing.

**Registering tools and refusing them later is not good enough**, and we have already paid for
that lesson in this codebase. The access-control audit that produced
`gate_mcp_tools_list()` / `gate_mcp_resources_list()` / `gate_mcp_prompts_list()` found exactly
this shape: a user outside a server's access rule was correctly refused *execution* while still
being served the full `tools/list` — every tool name and description. The fix was to hide the list,
not just block the call. A WebMCP bridge that registers first and checks later reintroduces that
disclosure in the browser, where the tool list is handed to an AI with no further prompting.

So the order is: **check access, then compose, then register.** `user_has_server_access()` is the
entry point and already does the slug lookup internally — but note it is deliberately **fail-open
in five branches** (manager unavailable, no user, no server, missing row, empty slug). For the
remote transport that is the right default. For WebMCP it is not: the bridge must treat anything
other than an explicit `true` from a *fully resolved* context as a refusal, which means checking
the preconditions itself rather than leaning on the helper's return value alone.

### D6 in full — this is the sharpest finding in the review

The issue proposes rebuilding the permission chain in the new controller:

```
ExposureResolver::resolve_effective( … )  →  $ability->has_permission( $input )  →  wp_execute_ability( … )
```

That chain is wrong in detail and redundant in shape. `Execute::check_permission()`
(`includes/Abilities/Execute.php:36`) **already is** the canonical chain, and it does four things
the proposed version does not:

1. tool-level capability check via the `mcp_adapter_execute_ability_capability` filter (default `read`);
2. an existence check guarded against the WP 6.9 `_doing_it_wrong` notice that firing
   `wp_get_ability()` on an unregistered name now emits;
3. `AbilityArgumentNormalizer::normalize()` **before** the permission call;
4. the actual method is **`$ability->check_permissions( $parameters )`** — `has_permission()` as
   written in the issue does not exist.

And the real reason to reuse rather than rebuild: exposure is not resolved from an argument at
all. `AbilityHelpers::apply_exposure_filter()` reads
`CurrentServerHolder::instance()->get_server_id()`, and that holder is populated from the **vendor
MCP transport**. A WebMCP request has no transport context, so it returns `null` — and the
documented behaviour on `null` is, verbatim:

> *Fail-open pattern: callers must treat null as "no per-server context available" (typically →
> fall back to `meta.mcp.public`).*

**Fail-open.** A WebMCP controller that forgets to establish context does not merely lose the
per-server rules — it silently widens to every ability carrying `meta.mcp.public`, ignoring the
admin's selection entirely. That is the same class of hole the issue correctly identified in the
generic `/wp-abilities/v1/` route, reachable through our own new controller instead.

So the server-side work is not "re-implement the gate". It is **"establish the context the gate
already reads, and fail closed if it cannot be established."** Either resolve the vendor
`McpServer` for the selected slug and `CurrentServerHolder::set()` it, or add a narrow
id-override to the holder for non-transport callers — `set()` currently requires a
`\WP\MCP\Core\McpServer` object, so which of the two is cheaper is a Phase-1 spike, not a
decision to take on paper now.

## Open question — still open

**Can Gemini in Chrome see polyfilled tools, or does it require native + an origin-trial token?**

I could not settle this from public sources, and I want to be explicit about why: searching for it
returns *our own issue #127* among the top results, so the "Gemini almost certainly talks to
Blink's internal agent runtime" line reads as external confirmation when it is in fact our own
text being indexed back at us. It is still a hypothesis, not a finding.

What *is* independently confirmed: without the origin-trial token `document.modelContext` does not
exist at all in Chrome, so the feature-detect is doing real work on every stable-Chrome install.

This stays task one.

## Tasks

### Phase 0 — settle the blocker before writing product code (half a day)

- **T1** Static page, bundled polyfill, no token, one trivial tool. Open in Chrome with Gemini.
  Does Gemini call it? This answers whether native and polyfill are substitutes or two separate
  audiences — and therefore whether the origin-trial token is mandatory plumbing or optional.
- **T2** Same page in ChatGPT's browser. Confirm the frozen-object behaviour first-hand and that
  one-at-a-time `registerTool` succeeds where a batch call does not.
- **T3** Confirm the polyfill package identity and that it does not shadow a native
  implementation. The issue names `@mcp-b/webmcp-polyfill`; `MiguelsPizza/webmcp-polyfill` also
  exists. Pick on the shadowing behaviour in D3, not on name recognition.

**If T1 says Gemini cannot see polyfilled tools**, native and polyfill serve different audiences,
both are required, and the origin-trial token becomes mandatory — with a hard deadline, since the
trial ends **2026-11-16**.

### Phase 1 — server side, fail closed

- **T4** `WebMcpController` under `acrossai-mcp/v1/webmcp/` — `tools`, `execute`, `nonce`. Its own
  namespace; do **not** reuse `/wp-json/wp-abilities/v1/…` (see the issue: the exposure gate hooks
  the vendor MCP transport and no-ops without a `$server`, so the generic route exposes
  `is_exposed = 0` abilities).
- **T5 (spike, do first in this phase)** Establish server context per D6. Resolve the selected
  server by slug, then either `CurrentServerHolder::set()` a vendor `McpServer` for it or add a
  narrow id-override for non-transport callers. Decide which on the spike, not on paper.
- **T6** With context established, delegate to the existing `Execute` / `Discover` /
  `GetAbilityInfo` paths unchanged. Do **not** re-derive the chain in the controller.
- **T7** **Assert context, fail closed.** If `get_server_id()` is null at the top of a WebMCP
  request, return 403 — never proceed. The holder's documented fallback is fail-*open* to
  `meta.mcp.public`, which is precisely the wrong default here. This deserves its own regression
  test: a request with no context must be refused, not silently widened.
- **T8** Fail closed on every other degenerate case too: no row for the slug, `is_enabled = 0`,
  **`compose_for_row()` returns an empty list**, empty exposure list. A dangling selection must
  never fall back to the default server. Note the degenerate case is now "no tools composed", not
  "all three flags off" — the recommended server runs with all three flags off by design.
- **T8a** `GET /webmcp/tools` returns `ToolPolicy::compose_for_row( $row )` (D7) with each tool's
  label, description and input schema, so the bridge registers from one authoritative response
  rather than reconstructing the list client-side.
- **T8b** **Access control first, on every route** (D9). `user_has_server_access(
  get_current_user_id(), $server_id )` before composing, before returning tools, before
  executing — and treat anything short of an explicit allow on a fully resolved context as a
  refusal, rather than inheriting the helper's five fail-open branches. Its own regression test:
  a user outside the server's rule must receive **no tool names at all**, not merely a refused
  execution.
- **T9** Two-nonce auth (D4) and the `/nonce` refresh route. Build the refresh in now — WP nonces
  last 12–24h and an agent in a long-open editor tab *will* outlive one.

### Phase 2 — the WebMCP submenu

- **T10** Register a **submenu under the AcrossAI parent** (D10), alongside MCP and Connect, via
  `add_submenu_page()` in `admin/Partials/Menu.php`. Not a tab on the shared Settings page — the
  page is half explainer and needs the room. Options still registered against their own
  `option_group` so nothing collides.
- **T11** Two options: `acrossai_mcp_webmcp_enabled` (bool, default 0) and
  `acrossai_mcp_webmcp_server` (**slug**, default `''`). Resolve the default lazily to
  `DefaultServerSeeder::ACROSSAI_SLUG` at read time; never write a concrete id at activation.
- **T11a** **The explainer.** Top of the page: what WebMCP is, that the browser agent calls tools
  instead of clicking the screen, which browsers can see it today, and that everything about
  *what* it can do lives on the chosen server. Most admins have never heard of this API; the page
  has to teach before it configures.
- **T12** **Server dropdown, by name.** Lists `is_enabled = 1` servers, showing the names the
  admin knows them by (*AcrossAI*, *Default MCP Server*, *My own server*), defaulting to
  **AcrossAI Recommended** (`acrossai-mcp-server`) — the same row `ProtectedServers` treats as
  recommended on the servers list, so both screens agree which one is blessed. Creating a server
  dedicated to WebMCP needs no new UI: make one the normal way and pick it here.
- **T13** **Show the consequence, own nothing.** For the selected server render its composed tool
  list (the same *Added as tools* names), the effective ability count behind them, and its access
  rule — each deep-linking to the tab that owns it:
  `…&action=edit&server={id}&tab=tools`, `…&tab=abilities`, `…&tab=access-control`.
  The WebMCP page never becomes a second place to curate. Tools, abilities and who may reach them
  are the server's business; this page selects a server and reports what that choice means.
- **T13a** **Access-control state is part of that display, and it gates the page's own promise.**
  If the current admin does not satisfy the selected server's rule, say so plainly and show no
  tool list — the same refusal the bridge will perform (D9), surfaced where it can be fixed rather
  than discovered as silence in the browser.
- **T14** Live browser feature-detect in the tab. Without it, an admin on Safari enables the
  feature, sees nothing happen anywhere, and files a bug.

### Phase 3 — the bridge

- **T15** Feature-detect; never shadow (D3). Bundle the polyfill locally — wp.org forbids remote
  assets and the admin CSP blocks a CDN.
- **T16** Register **each tool returned by `/webmcp/tools`** via `registerTool`, one at a time,
  awaiting the Promise (D2). `document.modelContext` only — `navigator.modelContext` was removed
  in Chrome 152.
- **T17** Names identifier-shaped and **stable**, derived by rule (D8): `toolset/content` →
  `wp_toolset_content`, `mcp-adapter/execute-ability` → `wp_mcp_adapter_execute_ability`. Agents
  that have seen the site before will reuse them, so the rule must be deterministic and must not
  change once shipped.
- **T18** Withdraw on navigation — one `AbortSignal` per tool, aborted on route change. The block
  editor is SPA-shaped, so page load does not bound tool lifetime.
- **T19** Admin screens only for v1.

### Phase 4 — safety rails

- **T20** Read-only abilities only; `execute` behind an explicit second toggle (D5).
- **T21** Log server-selection changes. Switching servers now changes the **tool names
  themselves** — an `acrossai` server registers `wp_toolset_*`, an `mcp-adapter` server registers
  `wp_mcp_adapter_*`. That is more visible to an agent than the issue assumed (it expected only
  the contents behind three fixed names to change), but a live session still holds the old set
  until it re-reads, so withdraw-and-re-register on change rather than relying on the agent to
  notice.
- **T22** Orphaned tool rows: `wp_acrossai_mcp_server_tools` on the dev site holds 14 rows for
  `server_id = 6`, a server that no longer exists in `wp_acrossai_mcp_servers`. Deleting a server
  leaves its curated tools behind. Harmless today because `compose_for_row()` is called with a
  live row, but it is a second reason to **key the WebMCP selection by slug, never by id** — an id
  that gets reused inherits a dead server's tool list.

## What this is not

- **Not a frontend feature.** `execute-ability` is a universal execution layer by design. On the
  public frontend that hands every in-page agent the full exposed surface with no consent model.
- **Not a replacement for the remote MCP server.** For anyone without an in-browser agent the
  remote server is the better path and this reaches nobody.
- **Not a second curation screen.** The WebMCP page selects a server and reports the consequence. Tools, abilities and access are owned by that server's own tabs and nowhere else.
- **Not a consent model.** v1 restricts rather than solves. Staged duplicates (D5) is the design
  to grow into.
- **Not permanent plumbing.** The origin trial ends **2026-11-16**. Whatever Phase 0 concludes
  about tokens has a shelf life, and the polyfill tier is what carries cross-browser until
  Firefox/Safari ship natively — not expected before late 2027.

## Sources

- [respira-press/webmcp-for-wordpress](https://github.com/respira-press/webmcp-for-wordpress)
- [traali/basketball-stats#8](https://github.com/traali/basketball-stats/pull/8)
- [use-novamira/novamira](https://github.com/use-novamira/novamira)
- [Novamira review — WP Mayor](https://wpmayor.com/novamira-review/)
- [Novamira docs](https://novamira.ai/docs/getting-started/)
- [code-atlantic/webmcp-abilities](https://github.com/code-atlantic/webmcp-abilities)
- [WordPress/ai#448](https://github.com/WordPress/ai/issues/448)
- [wordpress-playground#4301](https://github.com/WordPress/wordpress-playground/pull/4301)
