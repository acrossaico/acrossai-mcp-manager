# Planning: export and import a server's configuration (Feature 105)

## In plain English

Setting a server up is a dozen small decisions — which tools it serves, which AI connectors may
reach it, whether abilities are all exposed or picked individually. Making those decisions twice,
on a staging site and then again on the live one, is the kind of work nobody should do by hand.

So: an **Import / Export** tab on **AcrossAI → Settings** that writes your servers to a JSON file
and reads one back.

The original sketch for this had one rule the live data contradicts, and it is worth stating up
front because it inverts the feature:

> if enable all is selected then do not export the abilities; if that is Customise then export
> the abilities into json

"Enable all" and the list of tools a server actually serves are **two separate controls**, and it
is the second one that defines a server. Skipping the tool list whenever a server says "enable
all" would export this site's main server as a name and a flag, and the import would produce
something that serves nothing like the original.

The rule inverts: **the curated tool list is always exported.** The "enable all" flag is the
cheap part that rides along beside it.

## What the live site shows

Four servers, measured before anything was designed:

| server | `abilities_default_policy` | curated `server_tools` rows | `server_abilities` overrides |
| --- | --- | --- | --- |
| `acrossai-mcp-server` | **expose** ("enable all") | **15** | 0 |
| `acrossai-fleet-server` | **expose** | **14** | 0 |
| `my-own` | per-ability | 1 | 0 |
| `mcp-adapter-default-server` | per-ability | 1 | 0 |

Two things fall out of that table:

1. The two servers that say "enable all" are also the two with real curation. The flag and the
   tool list are not alternatives; they coexist.
2. **`server_abilities` is empty on every server.** The per-ability override path — the thing the
   sketch called "Customise" — is unused in practice. Every real decision on this site lives in
   `server_tools`.

## The two axes, precisely

Verified in `ToolPolicy::compose_effective_tools_for_row()`, which is what decides what a server
advertises. It returns exactly two things: the enabled protocol columns, and the curated
`server_tools` rows. Its own docblock rules abilities out —

> **NOT included** (F026 scope revert, 2026-07-15): abilities where `meta.mcp.public = true` (or
> `is_exposed = 1` per-server override) are NOT included.

So:

| axis | storage | what it controls | "all" looks like |
| --- | --- | --- | --- |
| **Tools** (Tools tab) | `server_tools` presence rows + 3 `tool_*` columns | what the server advertises to clients | **rows**, not a flag — "Enable All" materialises one per pool slug |
| **Abilities** (Abilities tab) | `abilities_default_policy` + `server_abilities` | what the discover/execute meta-tools may reach, via `AbilityExposureGate` | the flag `expose` |

`per-ability` is not "Customise" either — with no override rows it means "fall back to each
ability's own `meta.mcp.public`".

Both axes export. Neither is derivable from the other.

## Decisions taken

| Question | Answer |
| --- | --- |
| Scope of one file | **Servers and their configuration only.** No site-wide settings in v1 |
| Slug collision | **Overwrite the matching server.** A slug match means "same server"; its config is replaced wholesale |
| Transport | **Download a `.json`; upload one back** |

The envelope is versioned anyway, so site-wide settings can be added later without a second
format. See *Designed for the settings that are coming*.

## The format

```json
{
  "format": "acrossai-mcp",
  "version": 1,
  "generated_at": "2026-11-13T09:00:00Z",
  "source": { "site_url": "https://staging.example.com", "plugin_version": "0.4.8" },
  "servers": [
    {
      "slug": "acrossai-mcp-server",
      "name": "AcrossAI",
      "description": "",
      "type": "acrossai",
      "enabled": true,
      "route": { "namespace": "acrossai-mcp", "route": "mcp" },
      "abilities_policy": "expose",
      "protocol_tools": { "discover": true, "get_info": true, "execute": true },
      "tools": ["toolset/content", "toolset/users", "…"],
      "ability_overrides": [{ "slug": "toolset/database", "exposed": false }],
      "connectors": {
        "enabled_slugs": ["claude", "chatgpt", "gemini", "grok", "cursor"],
        "allow_other": false,
        "require_admin_approval": false
      },
      "embeds": { "enabled": true, "clients": { "mcp-client": ["claude-desktop"], "npm": 1 } }
    }
  ],
  "skipped": [{ "slug": "mcp-adapter-default-server", "reason": "registered_from=plugin" }]
}
```

`source` is diagnostic only — never read on import. It is there so a file found in six months can
be identified.

`skipped` is in the FILE, not just the UI. A plugin-registered server cannot be exported as a
database row, and recording why inside the artefact is what stops the next reader concluding the
export was incomplete.

## What must never leave the site

The classification matters more than the format. Per-server state splits three ways:

| | |
| --- | --- |
| **Portable** | the server row (name, slug, description, type, route, `is_enabled`, three `tool_*` columns, `abilities_default_policy`), `server_tools` rows, `server_abilities` overrides, `_acrossai_mcp_server_settings` (connector enablement), `_embeds_enabled` / `_embeds_clients` |
| **Never — secret** | `_fleet_security_id` (a live pairing credential), `acrossai_mcp_oauth_clients`, `acrossai_mcp_oauth_tokens`, `acrossai_mcp_oauth_auth_codes` |
| **Never — site-bound** | `_fleet_hosts` (paired hubs' host keys and public keys; exporting would let an import inherit another site's hubs), `connector_approved_users` and `_acrossai_mcp_pending_users` (local user IDs, which name a different person on another site), `acrossai_mcp_clients` (a record of who connected *here*), every `*_db_version` / `*_done` / fingerprint option |

The site-bound column is the one to be strict about. A redacted fleet block would be worse than
none: it invites the reader to assume the import restores a working pairing, which it cannot.

## Edge cases, and one that is already solved

**An unknown tool slug is already safe.** Curated rows deliberately outlive the plugin that
registered the ability — deactivate ACF and the `toolset/acf` row stays, filtered out at compose
time by `ServerTypes::registered_only()` and returning intact on reactivation. So the import does
**not** need to validate slugs against the destination, strip unknown ones, or warn about them.
The existing design already handles it and the row waits. This is the single biggest simplifier
in the feature.

**An unknown `server_type` is not safe.** `ToolPolicy::compose_effective_tools_for_row()` returns
`array( SetupRequired::SLUG )` when `ServerTypes::is_available()` is false — a server whose type
is not registered serves one diagnostic tool and nothing else. That is the correct behaviour and
needs no new code, but the import preview must say so, or an operator sees a server that imported
"successfully" and serves a repair notice.

**A policy change wipes override rows.** `PolicyTransition::apply()` updates
`abilities_default_policy` and then calls `MCPServerAbilityQuery::delete_items_for_server()` —
last-write-wins per its Clarifications Q5. It also *skips* the delete when the policy is
unchanged. Both halves are traps for an importer:

- Write overrides first, then the policy → the overrides are deleted.
- Import a server whose policy already matches → the destination's stale overrides survive, which
  contradicts overwrite semantics.

So the import sequence per server is fixed: **set the policy, then unconditionally clear
overrides, then write the imported ones.** Not "clear if changed".

**`registered_from`** is provenance, never imported. An imported server is always
`registered_from = 'database'`, because that is what it now is.

**The slug is both the match key and the route**, which is the one place this design is weaker
than ACF's. ACF matches on an immutable `key` precisely so renaming cannot orphan a file; our
slug is operator-editable and doubles as the endpoint. Overwrite-by-slug therefore means: rename
a server and an older export of it imports as a NEW server rather than updating it. Acceptable
for v1 and worth stating in the UI; the fix, if it ever matters, is an immutable export id
column, not a cleverer matcher.

**Route collisions** are separate from slug collisions. Two servers cannot share
`namespace + route`. Overwriting by slug handles the common case; a file whose server has a
*different* slug but a colliding route must be refused with the conflict named, not silently
re-routed.

## Prior art: ACF and Rank Math, read rather than remembered

Two plugins on disk solve this, differently enough to be worth both.

### ACF — entities, matched on an immutable key

`includes/admin/tools/class-acf-admin-tool-import.php`. The import loop is twelve lines and the
whole design is in the first two:

```php
$post_type = acf_determine_internal_post_type( $to_import['key'] );
$post      = acf_get_internal_post_type_post( $to_import['key'], $post_type );
if ( $post ) { $to_import['ID'] = $post->ID; }
```

**Identity is a `key` (`group_5f8a…`), never the title and never the post ID.** Found → update;
not found → create. The key is minted once and travels with the export forever, so renaming a
field group does not orphan an old export file.

Four more things it does that are worth copying:

- **Accepts one object or an array.** `if ( isset( $json['key'] ) ) { $json = array( $json ); }`
  — a single exported item pastes in without being wrapped.
- **Validation is four cheap checks**: upload size present, `$file['error']`, extension is
  `json`, decode is a non-empty array. No schema validation at all.
- **Export strips three fields and keeps the rest**: `acf_extract_vars( $post, array( 'ID',
  'local', '_valid' ) )` — a DENYLIST, filterable via `acf/prepare_{type}_for_export`.
- **A `modified` unix timestamp lives inside the file.** `local-json.php` writes it and
  `class-acf-ajax-local-json-diff.php` compares it against `get_post_modified_time()` to decide
  whether a sync is available, with a side-by-side diff of the two versions.

The denylist is the one thing **not** to copy. ACF can afford it because a field group holds no
secrets; strip the local ids and everything left is portable. A server row sits beside a live
fleet pairing credential and OAuth client secrets, so ours must be an allowlist — see *What must
never leave the site*. The contrast is the argument, not a counter-example.

### Rank Math — settings, with an automatic safety net

`includes/admin/class-import-export.php`. Three ideas, and the first is the most valuable thing
in this whole document:

```php
$this->action( 'rank_math/import/settings/pre_import', 'run_backup', 10, 0 );
```

**Every import automatically snapshots the current configuration first.** No button, no
remembering. The backup *is* an export — `run_backup()` calls the same `get_export_data()` — so
there is one serialiser and the restore path is the import path. The confirmation text says so
out loud: *"Don't worry, your current configuration will be saved as a backup."* There is a
Backups panel with restore and delete.

One flaw to fix rather than inherit: `update_option( 'rank_math_backups', $backups, false )` has
**no retention cap**. The array grows by a full settings snapshot on every import, forever. Same
class of bug as the CLI auth log's missing retention.

- **Export is selectable by panel** — checkboxes over `general`, `titles`, `sitemap`,
  `role-manager`, `redirections` — and extensible: `do_filter( 'export/settings', $data, $panel )`
  lets a module contribute its own block.
- **Upload goes through `wp_handle_upload()`** with temporary `upload_mimes` filters, rather than
  reading `$_FILES['tmp_name']` directly as ACF does. That is the canonical path and it is the one
  to use.
- Download sends `Cache-Control: no-cache, no-store, must-revalidate` + `Pragma` + `Expires`,
  which ACF omits.

### What both of them do NOT do

**Neither has a preview or a dry run.** Both parse the file and write, then report what happened.
That matches the overwrite-by-slug decision taken above — and both pair it with a safety net:
ACF's JSON lives in version control, Rank Math takes the automatic backup. So "no preview" is
only coherent *with* the net. Taking the decision without the net would be strictly worse than
either precedent.

## Design

### Where it lives

`?page=acrossai-settings` is vendor-owned (`acrossai-co/main-menu`) and takes tabs through the
`acrossai_settings_tabs` filter — the same route `SettingsMenu::register_tab()` already uses for
the MCP tab at priority 20. This adds one entry at priority 30 plus a render callback. No new
menu item.

### Write path: through the existing services, never raw SQL

The import must go through `MCPServerQuery`, `MCPServerToolQuery::replace_set()`,
`PolicyTransition::apply()` and `ConnectorSettings`, because each carries invariants a direct
INSERT would skip — `replace_set()`'s whole-set semantics, the `transition_mcp_server_{column}`
actions that invalidate the discovery cache, and the policy/override interaction above.

### Overwrite is destructive, so snapshot first — automatically

The chosen semantics replace a destination server's curation wholesale, and neither precedent
offers a preview either. Both pair that with a net, and Rank Math's is the better one because it
needs nothing from the operator:

**Take a full snapshot before any import writes, without being asked.** The snapshot is an
export — same serialiser, one code path — stored in a non-autoloaded option and listed on the tab
with a Restore button. Restore is the import path, pointed at a snapshot instead of a file.

Two things to do better than the precedent:

- **Cap it.** Keep the most recent N (5 is plenty) and drop the rest. Rank Math's option grows a
  full settings snapshot per import forever, which is how an option becomes a performance
  problem.
- **Say so in the confirmation.** "Your current configuration is saved first and can be restored"
  is what makes an operator willing to press the button, and it is only honest if the cap means
  the snapshot is definitely there.

### A `modified` stamp, so a stale file cannot silently win

ACF carries `modified` inside each record and diffs it against the database. The same stamp here
costs one column read — `acrossai_mcp_servers` has no `updated_at`, so the honest version is to
stamp the ENVELOPE at export and compare against the destination's own last export, or add an
`updated_at` column later.

Worth having because the failure it prevents is quiet: a file exported from staging three weeks
ago, imported onto a live site that has been curated since, replacing newer work with older. The
backup makes that recoverable; the stamp makes it visible before it happens.

### Not a React app

Two forms and a preview table. `admin-post.php` handlers with nonces and `manage_options`,
rendered server-side — the same reasoning that kept the Clients screen off React in F104. The
export is a `Content-Disposition: attachment` response; no bundle, no REST controller.

Upload handling goes through `wp_handle_upload()` with a temporary `upload_mimes` filter for
`.json`, as Rank Math does — not a direct read of `$_FILES['tmp_name']`. On top of that: a size
cap, a decode depth limit, and a schema pass before anything is written. The file is untrusted
input from an administrator, which is a low threat but not no threat — a 50 MB JSON array should
be refused, not parsed.

Two ergonomics borrowed from ACF: accept a bare single server object as well as an envelope, and
keep the failure messages specific ("Incorrect file type", "Import file empty") rather than one
generic error.

## Designed for the settings that are coming

The envelope carries `format` and `version` so a v2 can add blocks without breaking a v1 reader.
The natural next blocks, deliberately out of scope here:

- `site_settings` — logging toggles, retention days, npm login, discover page size. Six real
  settings among the 27 `acrossai_mcp_*` options on this site; the rest is bookkeeping.
- `access_control` — the vendor `wp_mcp_access_control` table (namespace / key / value, 0 rows
  here). Portable in shape, but it stores roles and user IDs, so it needs the same site-bound
  scrutiny as connector approvals.

Rule for both: a v1 importer must ignore blocks it does not recognise, and must not treat a
missing block as "set it to empty".

**And do not enumerate the blocks in one function.** Rank Math's `do_filter( 'export/settings',
$data, $panel )` is the pattern: each block registers itself, so adding settings later is a new
listener rather than an edit to the exporter. Two filters carry it —
`acrossai_mcp_export_blocks` to declare what can be exported (which also populates the
checkboxes), and `acrossai_mcp_export_block_{slug}` to produce one block's payload. acrossai-pro
can then export its own embeds settings without this plugin knowing they exist, which is exactly
the boundary the two plugins already keep everywhere else.

## Tasks

1. `includes/Export/ServerExporter.php` — one server row → the portable array. The classification
   table above is the contract; everything not listed as portable is absent by construction, not
   filtered afterwards.
2. `includes/Export/Envelope.php` — `format` / `version` / `generated_at` / `source`, plus the
   `skipped` list.
3. `includes/Export/ServerImporter.php` — validate, then per server: resolve the slug, set policy,
   clear overrides, write tools, write connector settings. Returns a per-server report.
4. `includes/Export/ImportValidator.php` — size cap, decode depth, schema shape, unknown-version
   refusal. Runs before any write.
5. `admin/Partials/ImportExportTab.php` + registration on `acrossai_settings_tabs` at priority 30.
6. `admin-post.php` handlers: `acrossai_mcp_export_servers`, `acrossai_mcp_import_servers`.
7. `includes/Export/Snapshots.php` — the automatic pre-import snapshot, capped at 5, in a
   non-autoloaded option, plus Restore (which is the importer pointed at a snapshot).
8. The two extensibility filters, with acrossai-pro's embeds block as the first consumer and the
   proof the seam works.
9. Tests: round-trip (export → import into an empty site → re-export → identical), the
   policy/override sequencing trap, plugin-owned servers skipped, unknown tool slug preserved,
   unknown server type imported with the diagnostic, route collision refused, and **a test that
   no secret or site-bound key appears in an export of a fully configured server** — that is the
   regression this classification exists to prevent.
10. Planning doc + README row.

Gates: PHPCS, PHPStan L8, PHPUnit pure + integration, F021 grep gates. No JS, so no ESLint change
and no bundle rebuild.

## Verification

- Export this site's four servers; confirm `mcp-adapter-default-server` appears in `skipped` with
  its reason, and the other three carry 15, 14 and 1 curated tools.
- `grep` the exported file for `_fleet_security_id`, `_fleet_hosts`, `client_secret`, `pubkey` and
  any user ID — all absent.
- Import into a site with no servers; confirm the three arrive enabled, with their tool lists and
  connector enablement intact, and `registered_from = 'database'`.
- Re-export and diff against the original file; only `generated_at` and `source` differ.
- Import a file whose server sets `abilities_policy` to the value the destination already has, on
  a destination carrying override rows; confirm the destination's overrides are gone rather than
  merged.
- Deactivate the plugin owning one curated ability, import, reactivate; confirm the tool returns.
- Import a server whose type is not registered; confirm it serves the setup diagnostic and the
  preview said it would.

## Effort

Smaller than F104 — no new table, no migration, no React. The exporter and envelope are an
afternoon; the importer's sequencing is the part that needs care, and upload validation is the
part that is easy to under-build.

**Biggest risk: the classification, not the code.** An export that leaks `_fleet_security_id` or a
hub's public key is a security incident in a file someone emails around, and unlike a bad database
row it cannot be recalled. Build the exporter so portable fields are listed explicitly and
everything else is absent by construction, and make the "no secrets in an export" test a
merge-blocker.
