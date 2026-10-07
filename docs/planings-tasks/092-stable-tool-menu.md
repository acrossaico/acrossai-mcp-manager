# Planning: A tool list that stops changing (Feature 092)

## In plain English

When an AI assistant connects to a site it is handed a **list of tools**, like being given a menu
when you sit down. That menu is printed once, at the moment it connects.

Install a plugin afterwards and every already-connected assistant is still holding the old menu.
It cannot use the new capability and does not know it exists. It only gets a fresh menu next time
it connects.

We cannot hand it a new one — see issue #129, where that was measured rather than assumed. So
this feature changes what is **on** the menu instead.

Today we print one item per plugin: *WPCode*, *Akismet*, *WooCommerce*, *Rank Math*. Every plugin
installed changes the menu. After this feature there is **one item for plugin capability**, and
installing a plugin adds nothing to the menu — the new abilities appear inside that item.

The menu stops changing, so it cannot go out of date.

This is the **small** version, chosen deliberately over a fuller redesign. See "What this is not"
at the end.

## The problem, precisely

The `acrossai` server type's tool list is two populations with very different behaviour:

| Stable — one per DOMAIN | Volatile — one per PLUGIN |
|---|---|
| content, appearance, users, configuration, database, files, cron, cache, updates, diagnostics, blocks, other | akismet, wp-mail-smtp, cookieyes, wpcode, learndash, elementor, woocommerce, rank-math |

The left column has never changed and never will. The right column changes on every plugin
activation, and **that is the whole of #129**. The `mcp-adapter` server type does not have the
problem at all, because its three protocol tools are fixed by construction.

The volatility is not inherent to MCP. It follows from one decision: giving each integration its
own top-level tool.

## What changes

Integrations stop being promoted into their own dispatchers. All plugin-specific capability lives
in one stable tool.

```
toolset/plugins    WPCode, WooCommerce, Rank Math, Akismet, WP Mail SMTP,
                   CookieYes, LearnDash, Elementor — and any other plugin
                   with abilities, whether or not we wrote an integration for it
```

Three things make this work:

**Structure moves from the tool LIST into the DISCOVER OUTPUT.** The list is cached at connect
time and cannot be refreshed; discover output is regenerated on every call. `Base_Toolset_Ability`
already supports `sub_group`, `card`, `search`, `limit` and `offset`, so plugin identity becomes
`sub_group: wpcode` — the model gets *more* structure than a flat tool name gave it, and none of
it lives in the part that goes stale.

**The description may name what is currently installed.** A description that goes stale is
harmless: the tool still exists and still works. A tool that goes *missing* is not. The failure
mode degrades instead of breaking.

**Plugin identity survives regardless**, because it is in every ability name — `rank-math/audit-site-seo`,
`woocommerce/action-scheduler`. A model discovering inside the bucket still learns exactly which
plugins this site has. This is what makes the "we lose per-plugin visibility" objection much
smaller than it first appears.

## Naming

`toolset/other` currently means *"plugins we have not written an integration for"* — a leftovers
drawer, residual by definition. After this change it would hold the most capability-dense set on
the site while still being called "other", which tells a model very little.

So the bucket is `toolset/plugins`, and `toolset/other` retires into it. Renaming costs one extra
source slug in a migration we have to write anyway.

**Explicitly rejected: keeping both.** Two buckets named "integrations" and "other" read almost
identically to a model, so it cannot tell which to search and will search both. The distinction
between "we wrote an integration" and "we did not" is internal bookkeeping — real to us, useless
to the model.

## What this costs

The model no longer sees *WooCommerce* as a top-level tool with its own description. It sees the
bucket and has to look inside.

Softened by the three points above, but not eliminated. This is the one genuine trade.

It also runs slightly against ecosystem convention: Jetpack (`jetpack-newsletter`,
`jetpack-related-posts`), WooCommerce (`woocommerce`), ACF (`acf-field-management`) and WPCode
(`wpcode-library`) all name plugin-specific ability CATEGORIES after the plugin. Worth noting that
those are ability categories rather than MCP tools — a different layer, and one that never appears
in `tools/list` — but the convention is real and this moves away from it.

## The migration risk — the part to get right

A server can already have `toolset/wpcode` curated in `wp_acrossai_mcp_server_tools`. When the
sibling stops registering that slug, `ServerTypes::registered_only()` filters it out: the row
survives, but the server silently serves fewer tools than before.

Silent is the problem. Nothing errors, and an operator who deliberately curated per-plugin
toolsets simply loses them.

So a one-time data migration maps every retired slug to `toolset/plugins`, dedupes, and leaves
everything else alone. Option-gated so it runs once and cannot re-run — the same discipline as
F090's corrective UPDATE, gated on having just created its column precisely so a later re-run
cannot undo an operator's choice.

**The mapping must not be hardcoded here.** `acrossai-mcp-manager` owns servers;
`acrossai-abilities-manager` owns abilities, and this plugin contains no toolset vocabulary — a
boundary verified across the whole plugin family. So the sibling publishes an old-slug → new-slug
map through a filter, and this plugin applies it generically without learning a single toolset
name. That keeps the two-filter contract intact and means a future rename needs no change here at
all.

`expose` servers need nothing: the pool resolves live, so it follows automatically.

## Where the work lives

**`acrossai-abilities-manager`** — stop promoting integrations into their own dispatchers, rename
the bucket, give it a description worth reading, expose plugin identity as `sub_group`, and
publish the migration map.

**`acrossai-mcp-manager`** — apply that map to curated rows, once. Nothing else: the `acrossai`
server type's tools arrive from the sibling through `acrossai_mcp_server_types`, so the type
follows automatically.

## Open questions

1. **Does the bucket's default discover list abilities, or sub-groups?** With every plugin's
   abilities inside, listing them all is a lot of output for a first call. Summarising by
   sub_group first, then drilling in, is likely better — but it changes what a first discover
   returns.
2. **Does the description enumerate installed plugins?** Useful, and safely stale. Worth
   confirming it is wanted rather than assumed.
3. **Is `toolset/plugins` the right name?** It is clear and honest. `toolset/addons` and
   `toolset/extensions` are alternatives; the word should be one a site owner would use.

## What this is not

A fuller redesign was considered and deferred: **capability categories** — `toolset/seo`,
`toolset/commerce`, `toolset/backup` — registered even when no matching plugin is installed, so an
empty category can report the gap, name the common plugins that fill it, and offer a manual route
using the generic toolsets.

That is a better product. It is the only shape able to express what a site **cannot** do, which is
information neither a bucket nor a per-plugin list can carry. It is also weeks of work, a larger
migration, and a changed experience for every existing user.

This feature buys the stability now. If the category design is wanted later, this is a step
towards it and not away: the bucket becomes the first category, and the migration machinery built
here is the machinery that would move rows again.

## Relationship to #129

| | Effect |
|---|---|
| **092** (this) | Plugin activation stops changing the menu at all |
| #129 option 1 | A stale menu stops *blocking* — covers Tools-tab edits, which no menu design prevents |
| #129 option 2 (PR #132) | Neither — states the fact honestly |

092 and option 1 are complementary, not alternatives. This removes the commonest cause; option 1
covers what remains.
