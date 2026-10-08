# Planning: WebMCP — the tool triple in the browser (Feature 093)

Issue: [#127](https://github.com/acrossai-co/acrossai-mcp-manager/issues/127)

## In plain English

Today an AI assistant reaches this site from **outside** — Claude on your desktop, Cursor in your
editor — over our remote MCP server. The assistant is somewhere else, and it calls in.

WebMCP is the other direction. The browser tab itself declares what it can do, and an agent
already *inside* the browser — Gemini in Chrome, ChatGPT's browser, an extension — calls those
tools directly instead of squinting at the screen and clicking buttons.

We already have everything this needs: the abilities, the per-server exposure rules, and three
meta-tools that wrap the whole catalogue. This feature puts those three on the page.

The reason it is **three** and not 370 is the entire design. A page that declares one tool per
ability hands the agent a 370-item menu in a single prompt. We now have field evidence for what
happens when you try: a competing WordPress bridge registered 296 tools and **ChatGPT switched
WebMCP off for that document entirely.** Three tools stay three tools no matter how big the
catalogue gets.

It ships **off by default**, behind a beta gate, on admin screens only.

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
strongest possible validation of the triple: our three tools are an order of magnitude under even
their reduced set, and the count does not move when the catalogue grows.

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
  all three tool flags off, empty exposure list. A dangling selection must never fall back to the
  default server.
- **T9** Two-nonce auth (D4) and the `/nonce` refresh route. Build the refresh in now — WP nonces
  last 12–24h and an agent in a long-open editor tab *will* outlive one.

### Phase 2 — the Settings tab

- **T10** Register via `acrossai_settings_tabs` (`slug => 'webmcp'`, `priority => 25`), sections
  scoped to the tab page slug so `option_group` does not collide with the MCP tab.
- **T11** Two options: `acrossai_mcp_webmcp_enabled` (bool, default 0) and
  `acrossai_mcp_webmcp_server` (**slug**, default `''`). Resolve the default lazily to
  `DefaultServerSeeder::ACROSSAI_SLUG` at read time; never write a concrete id at activation.
- **T12** Picker lists `is_enabled = 1` servers only.
- **T13** Blast-radius display: which of the three `tool_*` flags are on, and the **effectively
  exposed ability count**. A 370-ability `expose` default and a 6-ability allowlist look identical
  in a dropdown and are not the same decision.
- **T14** Live browser feature-detect in the tab. Without it, an admin on Safari enables the
  feature, sees nothing happen anywhere, and files a bug.

### Phase 3 — the bridge

- **T15** Feature-detect; never shadow (D3). Bundle the polyfill locally — wp.org forbids remote
  assets and the admin CSP blocks a CDN.
- **T16** Register the three via `registerTool`, one at a time, awaiting the Promise (D2).
  `document.modelContext` only — `navigator.modelContext` was removed in Chrome 152.
- **T17** Names identifier-shaped and **stable**: `wp_discover_abilities`, `wp_get_ability_info`,
  `wp_execute_ability`. Agents that have seen the site before will reuse them.
- **T18** Withdraw on navigation — one `AbortSignal` per tool, aborted on route change. The block
  editor is SPA-shaped, so page load does not bound tool lifetime.
- **T19** Admin screens only for v1.

### Phase 4 — safety rails

- **T20** Read-only abilities only; `execute` behind an explicit second toggle (D5).
- **T21** Log server-selection changes. Tool *names* do not change when the selection does — an
  agent with a live session silently starts getting a different ability list.

## What this is not

- **Not a frontend feature.** `execute-ability` is a universal execution layer by design. On the
  public frontend that hands every in-page agent the full exposed surface with no consent model.
- **Not a replacement for the remote MCP server.** For anyone without an in-browser agent the
  remote server is the better path and this reaches nobody.
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
