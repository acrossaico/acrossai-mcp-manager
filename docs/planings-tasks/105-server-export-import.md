# Planning: export and import a server's configuration (Feature 105)

> **Status:** built and green in CI on `feat/105-server-export` (PR #229, draft). This document was
> rewritten once the feature existed, because five of its original claims turned out to be wrong.
> Where a decision changed, both the original and the reason are kept — the reasons are the useful
> part.

## In plain English

Setting a server up is a dozen small decisions — which tools it serves, which AI connectors may
reach it, whether abilities are all exposed or picked individually. Making those decisions twice,
on a staging site and then again on the live one, is work nobody should do by hand.

So: an **Import / Export** tab on **AcrossAI → Settings** that writes your servers to a JSON file
and reads one back, with each server choosing which of its six setting groups travel.

Three things it deliberately does not do:

- It never carries a credential. An imported server arrives configured and with **no live
  connections** — every client re-authorises.
- It never switches a server on unless you ask. A file must not publish an MCP endpoint on a site
  nobody has looked at yet.
- It never creates a server another plugin owns.

## The sketch, and the two places it was wrong

The original ask was:

> if enable all is selected then do not export the abilities; if that is Customise then export
> the abilities into json

It is wrong twice over. Tools and Abilities are **two separate controls** and the sketch treated
them as one; and even for Abilities, "Enable All" does not mean there is nothing to export.

| | storage | what it controls | what "all" is |
| --- | --- | --- | --- |
| **Tools** (Tools tab) | `server_tools` rows + 3 `tool_*` columns | what the server advertises | **rows** — "Enable All" materialises one per pool slug, leaving no stored mode |
| **Abilities** (Abilities tab) | `abilities_default_policy` | what the discover/execute meta-tools may reach | the flag `expose` |

Verified in `ToolPolicy::compose_effective_tools_for_row()`, which returns exactly the protocol
columns plus the curated rows, and whose docblock rules abilities out:

> **NOT included** (F026 scope revert, 2026-07-15): abilities where `meta.mcp.public = true` (or
> `is_exposed = 1` per-server override) are NOT included.

So for **abilities** the sketch is half right — the three buttons *are* the three stored values
(`expose` / `hide` / `per-ability`). For **tools** there is no stored mode at all, only rows.

**But the other half of the sketch is wrong, and shipping it was a bug.** "Enable All, so there is
no list to export" assumes the override table stops mattering under `expose`. It does not:
`ExposureResolver::resolve_effective()` opens with *"Priority 1: explicit row wins over
everything"*, and only falls through to the server policy when no row exists. A server on Enable
All with three abilities toggled off holds three rows, and those rows decide those three.

The first implementation exported the policy alone in that case. A real round trip caught it — a
server exported as "everything on", and importing the file turned all 389 abilities back on. The
block now always carries both: the policy decides every ability **without** a row, including ones
that do not exist yet; the rows decide the rest.

## What the live site showed

Four servers, measured before anything was written:

| server | policy | curated tools | overrides | protocol columns |
| --- | --- | --- | --- | --- |
| `acrossai-mcp-server` | **expose** | 15 | 0 | none on |
| `acrossai-fleet-server` | **expose** | 14 | 0 | none on |
| `my-own` | per-ability | 1 | 0 | **all three on** |
| `mcp-adapter-default-server` | per-ability | 1 | 0 | **all three on** |

Two findings came out of this, and both changed the design.

**`server_abilities` was empty on every server** at the time of measuring. That made the override
path look unused — and is part of why the Enable All bug above survived the first implementation.
It is not unused; it is just empty on a site nobody has curated yet. Toggling one ability off on
the Abilities tab creates a row, whatever the policy says.

**Every curated set is byte-for-byte what `apply_type_defaults()` writes.** Zero hand-made tool
decisions across four servers. The thing originally identified as "the valuable payload" is
derivable from one string, `server_type`, with one click of Reset to Type Defaults.

That nearly killed the feature, and it is why the tools block records a **named state plus a
delta** rather than fifteen absolute slugs — see below. It is also why the measurement is recorded
here: on a site that *does* curate, the delta is where the value is.

> One measurement error worth recording. The first run compared against `ServerTypes::tools_for()`
> and reported one hand-added tool. `tools_for()` narrows to abilities registered right now;
> `declared_tools()` is what `apply_type_defaults()` actually writes. Comparing against the wrong
> one invents a delta wherever a contributing plugin is merely inactive.

## The format

```json
{
  "format": "acrossai-mcp",
  "version": 1,
  "generated_at": "2026-11-19T09:00:00Z",
  "source": { "site_url": "https://staging.example.com", "plugin_version": "0.4.8" },
  "servers": [
    {
      "slug": "acrossai-mcp-server",
      "name": "AcrossAI",
      "type": "acrossai",
      "origin": "database",
      "route": { "namespace": "acrossai-mcp", "route": "mcp" },
      "blocks": ["status", "message", "tools", "abilities", "access", "connectors"],

      "status":     { "enabled": true },
      "message":    { "mode": "default" },
      "tools":      { "state": "type-defaults", "added": [], "removed": [],
                      "protocol": { "discover": false, "get_info": false, "execute": false } },
      "abilities":  { "policy": "expose",
                      "overrides": [ { "slug": "toolset/database", "exposed": false } ] },
      "access":     { "rule": "everyone", "values": [] },
      "connectors": { "stored": true, "enabled_slugs": ["chatgpt", "claude"],
                      "allow_other": false, "require_admin_approval": false }
    }
  ]
}
```

`source` is diagnostic only and never read on import — it answers "which site did this come from"
when somebody emails you a file.

### `blocks` is per server, and it is the safety net

**Changed from the original plan**, which had one global block list in the envelope and argued
against per-server choice on ambiguity grounds. The objection was real but fixable, and the fix is
the field above: each record declares its own list.

Without it, a server exported *without* its Tools block is indistinguishable from one exported
*with no tools at all*, and the import empties it. The importer reads `blocks` first and touches
nothing outside it, so "not exported" stays distinct from "exported as empty".

A hand-written file with no `blocks` key falls back to "every block key present in this object",
so manual edits still behave.

### `origin` governs creation, not export

**Changed from the original plan**, which skipped plugin-registered servers at export and listed
them in a `skipped` array inside the file.

That conflated two things. A plugin-registered server stores its settings in exactly the same
places as any other — and on this site the adapter's default server is one of only two with all
three protocol tools switched on, and its `is_enabled` is a real off-switch since the
`mcp_adapter_create_default_server` filter landed. Its settings are worth carrying.

What it does not own is its own existence:

| `origin` | On import |
| --- | --- |
| `database` | Update if the slug exists, otherwise **create**. |
| `plugin` | Update if the slug exists. **Never create** — report it and move on. |

Creating it would be worse than skipping: a `database` row under that slug would shadow the real
one the moment its plugin was installed. An unrecognised origin is read as `plugin`, which is the
cautious direction.

### Tools: a named state plus a delta

`state` is one of `type-defaults` / `all` / `none` / `custom`, and `added` / `removed` are measured
against it. Two reasons, beyond size:

- On this site every server reports `type-defaults` with two empty arrays — which is itself the
  useful signal, and exactly the information an absolute list would hide.
- An absolute list **pins** an imported server to one release's defaults. Change a type's declared
  tools in a later release and an old file silently reverts the improvement. `type-defaults` means
  "whatever this release says".

The three protocol columns ride in this block, because they are part of the same screen and the
same `split_payload()` contract even though they live in columns rather than rows.

## What must never leave the site

Built as an **allowlist**: the record is assembled field by field from what may travel, and
everything else is absent because it was never added. ACF's exporter strips three fields and keeps
the rest, which it can afford because a field group holds no secrets. A server row sits beside a
live fleet pairing credential and OAuth client secrets.

| | |
| --- | --- |
| **Travels** | name, slug, description, type, route, origin, `is_enabled`, abilities policy and overrides, tool state + delta + protocol columns, connector enablement, custom instructions, the access rule |
| **Never — secret** | `_fleet_security_id`, `acrossai_mcp_oauth_clients`, `_tokens`, `_auth_codes` |
| **Never — site-bound** | `_fleet_hosts` (other sites' host keys and public keys), `connector_approved_users`, `_acrossai_mcp_pending_users`, access rules naming individual users, `acrossai_mcp_clients`, every `*_db_version` / `*_done` / fingerprint option |

A leaked export cannot be recalled the way a bad database row can, which is why
`test_a_fully_configured_export_leaks_no_secret` is a **merge-blocker**: a server is given all of
the above and nine forbidden strings must be absent. Its failure message says to stop *adding* the
field, never to filter it afterwards — a denylist only removes what somebody remembered to name.

### Access control

`everyone` and `authenticated` travel cleanly. Roles and capabilities travel as slugs; one the
destination lacks simply matches nobody, which is the safe direction to fail in. A rule naming
individual **users** is refused and reported as `users_omitted`, because a user id is a different
person on another site — the same reason `connector_approved_users` never travels.

## Edge cases

**An unknown tool slug needs no handling at all.** Curated rows deliberately outlive the plugin
that registered the ability — deactivate ACF and the `toolset/acf` row stays, filtered at compose
time by `ServerTypes::registered_only()` and returning intact on reactivation. So the importer
writes it and the row waits. The single biggest simplifier in the feature.

**An unknown `server_type` is not safe the same way.** Such a server serves one setup diagnostic
and nothing else. Correct behaviour, no new code, but the import report has to say so.

**A policy change wipes override rows.** `PolicyTransition::apply()` clears every override as a
side effect, and *skips* that clear when the policy is unchanged. Both halves are traps:

- Write overrides first, then the policy → they are deleted.
- Import a server whose policy already matches → this site's stale overrides survive.

So the sequence is fixed: **set the policy, clear unconditionally, write the imported ones.** Not
"clear if changed". Pinned by `test_overrides_are_cleared_even_when_the_policy_is_unchanged`.

**The slug is both the match key and the route.** This is where the design is weaker than ACF's,
which matches on an immutable `key` precisely so renaming cannot orphan a file. Rename a server
here and an older export of it arrives as a *new* server. Acceptable for v1; the fix, if it ever
matters, is an immutable export id column, not a cleverer matcher.

**A route collision is separate from a slug collision.** A record whose slug is new but whose
`namespace + route` is taken is refused with the conflict named, rather than silently re-routed.

## Prior art: ACF and Rank Math, read rather than remembered

### ACF — entities, matched on an immutable key

`includes/admin/tools/class-acf-admin-tool-import.php`. The whole design is two lines:

```php
$post = acf_get_internal_post_type_post( $to_import['key'], $post_type );
if ( $post ) { $to_import['ID'] = $post->ID; }
```

Identity is a `key` (`group_5f8a…`), never the title and never the post ID. Four things copied:
accepting a single object as well as an array; four cheap validation checks rather than a schema;
specific failure messages ("Incorrect file type", "Import file empty"); and a `modified` timestamp
inside the file, which `class-acf-ajax-local-json-diff.php` compares against the database to offer
a sync.

Its **denylist** is the one thing not copied — see *What must never leave the site*.

### Rank Math — settings, with an automatic safety net

`includes/admin/class-import-export.php`. The most valuable line in this document:

```php
$this->action( 'rank_math/import/settings/pre_import', 'run_backup', 10, 0 );
```

Every import snapshots the current configuration first. No button, no remembering. The backup *is*
an export — `run_backup()` calls the same `get_export_data()` — so there is one serialiser and
restore is the import path.

Two flaws fixed rather than inherited: `rank_math_backups` has **no retention cap** and grows a
full snapshot per import forever, and it is a single option read on every page load.

Also taken: export selectable by group, and `do_filter( 'export/settings', $data, $panel )` so
modules contribute their own blocks.

**Neither has a preview or a dry run.** Both parse and write, then report. That matches the
overwrite-by-slug decision here — and both pair it with a net. "No preview" is only coherent
*with* the net.

## Design decisions

| Question | Answer |
| --- | --- |
| Scope of one file | Servers and their configuration. Site-wide settings are a later block under the same envelope |
| Slug collision | **Overwrite** the matching server |
| Block choice | **Per server**, declared in each record |
| Transport | Download a `.json`; upload one back |
| Free or Pro | **Free**, with the fleet multiplier in Pro — see below |

### Where it lives, and the vendor it renders inside

`?page=acrossai-settings` belongs to `acrossai-co/main-menu`, whose `TabbedPageRenderer` wraps
every tab in `<form action="options.php">` with a Save button and offers **no filter over either**.
An export is a download, not an option write, and a nested form is invalid HTML.

So the buttons carry `formaction` + `formmethod` (and `formenctype` for the upload, since the
vendor's form has no enctype and a file input in a urlencoded form posts only its filename). HTML5
defines these as overriding the owning form's target for that one submit. No nested form, no
JavaScript, no bundle.

The vendor's Save button is hidden on this tab with scoped CSS, because there is nothing to save
and a button reporting "Settings saved" after changing nothing reads as a bug. **That is a
workaround for a missing extension point** — worth raising upstream, and worth deleting the day a
filter exists.

### Snapshots

Automatic before every import, capped at **five**, stored in a non-autoloaded option. A snapshot
covers **every** server and every block, not the ones being imported: a narrowed snapshot is not a
restore point, because the blocks it left out are exactly the ones nothing else would put back.

Restoring is importing — same validator, same writer — which also means a restore takes its own
snapshot first, so an accidental restore is itself undoable.

### Status is gated

Switching **off** always applies; that direction cannot expose anything. Switching **on** requires
the operator to have ticked "let this file switch servers on". A file must not publish an endpoint
by itself — the same reasoning that ships the Fleet server disabled.

Enablement goes through `ServerEnablement::set()`, never a direct `update_item()`. It enforces the
server-type requirement on off → on, so a file claiming "enabled" for a type whose add-on is absent
is refused rather than publishing an endpoint that can only answer with a setup diagnostic.

### Free, with the fleet multiplier in Pro

The split this ecosystem already draws is **single site = free, many sites = Pro**, plus content
and the publishing surface.

Export and import of your own configuration belongs in **free**: it is the user's own data, gating
it reads as lock-in, it is how a bug report gets made ("send me your export"), and the snapshot is
a safety net. The feature is also small — six scalars and a rule per server is not enough to anchor
a paid tier.

What is worth money is **applying one configuration across a fleet**, and Pro already owns that
machinery. A free user who exports once and then sees "apply this to all your sites" has just
learned what it is worth. Free building the format is what makes the Pro feature possible.

## What was built

| | |
| --- | --- |
| `Utilities/ExportConstants.php` | format, version, six block keys, origin and tool-state vocabularies |
| `Export/ServerExporter.php` | one row → its portable record, block by block |
| `Export/Envelope.php` | the wrapper, `build_everything()`, filename, JSON |
| `Export/ImportValidator.php` | everything refused, before anything is written |
| `Export/Snapshots.php` | the automatic restore point |
| `Export/ServerImporter.php` | the write path and its fixed ordering |
| `Admin/Partials/ImportExportTab.php` | the tab and three `admin-post` handlers |
| `Connectors/ConnectorSettings.php` | `peek_server_settings()` — a read that does not write |
| `tests/phpunit/ExportDb/` | 47 tests in a new `export-db` suite, wired into CI |

Not built: the extensibility filters (`acrossai_mcp_export_blocks` / `…_export_block_{slug}`) have
one half in place — `acrossai_mcp_export_server` lets a companion add a block — and the registry
half waits for a second consumer.

## Five bugs — four found by measuring, one reported from the screen

**The export was writing to the database.** `ConnectorSettings::get_server_settings()` persists a
computed seed on first read — correct for the admin screens, wrong for anything that must not
mutate the site. A single export run created settings rows on two servers that had never been
configured. An operator pressing "Show JSON" would have changed their site.
`peek_server_settings()` is the side-effect-free read, and an unset server now reports
`stored: false` rather than today's computed defaults, so an import leaves an unconfigured server
unconfigured.

**`absint( '-3' )` is 3.** Posting a negative server id silently resolved to a real server the
operator never ticked. Not privilege escalation — the caller already holds `manage_options` — but
it exported a row nobody asked for.

**`is_enabled` was being written directly.** The F021 `F090 single is_enabled writer` gate failed
the build and named the line. See *Status is gated* above. That gate earned its keep.

**Overrides were dropped under Enable All.** The one a test could not catch, because the test
asserted the wrong behaviour: it *required* overrides to be absent under `expose`. That belief
came from reading the Tools docblock rather than `ExposureResolver::resolve_effective()`. Reported
from a real export → change → import cycle on the Abilities tab. Both the exporter and the
importer now carry overrides whatever the policy is, and the test is inverted to reproduce the
report.

**The upload must not go through `wp_handle_upload()`.** An earlier draft of this document
recommended it as the canonical, safer path. It moves the upload into `wp-content/uploads`, which
is served over HTTP — and an export lists every server, its routes and its connector configuration.
It now reads the temp file directly, guarded by `is_uploaded_file()` so a crafted `tmp_name` cannot
turn this into an arbitrary file read.

Also aligned while here: `phpcs.xml.dist` declared `testVersion 7.4-` while `composer.json` says
`">=8.1"` and the plugin header says `Requires PHP: 8.1`. Three declarations, three answers. Now
`8.1-`; the full 278-file sweep is clean, so nothing was hiding behind it.

## The check that would have caught the abilities bug

The mistake was not a forgotten field. It was exporting from a **two-tier model when the resolver
has three**. So the check to run against every block is: *enumerate every input to the code that
reads it, and confirm each one is either exported or genuinely runtime.*

| block | what reads it | inputs | all carried? |
| --- | --- | --- | --- |
| **tools** | `ToolPolicy::compose_for_row()` | 3 `COLUMN_MAP` columns, `get_added_slugs()` | yes — both travel |
| **abilities** | `ExposureResolver::resolve_effective()` | **1.** override row **2.** server policy **3.** the ability's own `meta.mcp.public` | 1 and 2 travel; 3 is the ability's own default and belongs to the ability, not the server |
| **status** | `MCP\Controller`, adapter filter | `is_enabled` | yes |
| **message** | `Controller::default_instructions_for()` vs the meta | computed default, `_server_instructions` | the meta travels; the default is recomputed per release on purpose |
| **access** | `user_has_access()` | rule key, options | yes, minus user ids by design |
| **connectors** | `ConnectorSettings::get_server_settings()` | three fields | yes, minus approvals by design |

Tools was then round-tripped through every shape it can hold — `type-defaults`, `none`, `all`,
`custom +1`, `custom −1`, `custom +1/−1`, and a single-tool server — with the protocol columns set
three ways. All seven restore exactly, and a `@dataProvider` test pins five of them.

Two cases where the source and destination legitimately differ were also measured, because the
delta is relative and that is the point:

- **The destination's type declares one more tool than the source's.** The operator's own addition
  survives, their removal is still honoured, and the new tool IS picked up. That is the
  version-proofing the state+delta design exists for, now demonstrated rather than asserted.
- **The destination does not have the type at all.** The server is created, its rows are written,
  and `compose_effective_tools_for_row()` returns `["toolset/setup-required"]` — the documented
  behaviour, arrived at without new code, and the server stays disabled.

## Verification

Done, on `wordpress-7-0.local`:

- Export all four servers → 5,893 bytes, and none of eleven forbidden strings present.
- Export twice → meta row count unchanged, so the export is read-only.
- Export a configured server, mutate it hard (policy flipped, stale override added, message and
  tools replaced, disabled), import it back → all seven assertions pass, including the stale
  override being gone and `toolset/invented` surviving as a row that waits.
- `enable = false` leaves a disabled server off; `enable = true` restores it.
- Snapshot stored, readable, capped, not autoloaded.
- The tab renders Export, Import and Snapshots with no stray Save button.

Still outstanding:

- **Nobody has pressed Download and then Import in a browser.** Every layer and the round trip are
  verified programmatically; the end-to-end click-through is not.
- A site with a non-empty access-control rule has not been exercised — this site has zero rows, so
  the `role` / `capability` paths are covered by tests only.
- Restoring a snapshot has not been exercised against a site that changed in between.

## Effort, and where the risk actually was

Smaller than F104 — no new table, no migration, no React. The classification was the risk, not the
code, and that held: the only security-shaped finding was the uploads-directory one, caught by
asking where `wp_handle_upload()` puts the file rather than by trusting the earlier draft.

The surprise was how little the tools block carries on a default site. The feature is justified by
the settings that *are* hand-made — connector enablement, access rules, custom messages, the
protocol columns — not by the tool list it was originally designed around.
