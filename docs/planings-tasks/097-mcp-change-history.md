# Planning: MCP Change History (Feature 097)

> **Status:** Planned · **Date:** 2026-10-09 · **Revised:** 2026-10-16 ·
> **Owner plugin:** `acrossai-mcp-manager`
> **Depends on:** [096 — MCP Activity Log](096-mcp-activity-log.md) — for the
> `activity_id` this table points at, and for `CaptureContext`, the bracket this
> feature arms its recorder from. 096 ships first.
>
> **Revision of 2026-10-16.** An intermediate draft moved this table and its
> recorder into 096, leaving only the UI here. That was reversed: 096 is **log
> tables only**, so 097 owns change history end to end again — the table, the hook
> capture, the REST routes and the screen. What 096 does supply is the one seam
> this feature needs and should not duplicate.
>
> **Scope fixed by the author on 2026-10-09: tier 1 only.** WordPress-hook capture,
> readable before/after diffs, a Changes tab. **No SQL interceptor, no
> rebuild/restore planner, no live-state staleness checks, no WooCommerce or
> BuddyPress coverage.** Read §"What tier 1 cannot see" before agreeing to this
> scope — the omission is real and it is load-bearing.

---

## In plain English

Feature 096 answers *"what did the AI client ask for?"* — a tree of requests with
the abilities that ran behind them, each with a status and a duration. It does not
answer the question an operator actually panics about: **"what did it change?"**

This feature records that. When a tool call edits a post, flips an option, renames
a term or updates a user, we write down what the value was before and what it is
now, and we link that row back to the ability in 096 that caused it. The screen is
a second tab next to Activity showing a readable diff per change.

The reference is easy-mcp-ai's **Activity → Change history**
(`admin.php?page=easy-mcp-ai-activity&section=changes`), which describes itself
honestly in its own UI: *"Before/after snapshots of every write performed through
the MCP server. Direct wp-admin edits are not recorded here."* That caveat applies
to us identically and must appear in our UI too.

---

## What 096 gives us, and what not to rebuild

| From 096 | Use it for |
|---|---|
| `wp_acrossai_mcp_activity_log` rows | `activity_id` points at an **ability** row. Walk up its `parent_id` to name the call |
| `includes/Observability/CaptureContext.php` | **The arming bracket.** Already wrapped around `Base_Toolset_Ability::do_execute()` and `Abilities\Execute` in `try`/`finally`, with a public reader for the current activity id. 097 **adds listeners to it; it does not add a second bracket** |
| `McpObservabilityHelperTrait::is_sensitive_key()` | Redaction. 24 patterns already recognised. Call it — do not grow a second list |
| The recorder re-entry guard and shared ignore-list constant | Add this table to the constant. 096 built it expecting a third recorder |
| `admin/Partials/ActivityLogPage.php`, `src/js/activity-log/` | The DataViews shell, the period picker, the keyset-pagination helper, the detail panel with a row left free for "Changes (N)" |
| `admin/Partials/Settings.php` | A settings block laid out to take a third toggle row |

**If implementation finds itself editing `CaptureContext` to add a second
arm/disarm, stop.** That is the interface, and 096 has a success criterion
asserting it is usable from outside the ability recorder. Fix the seam, don't fork
it.

---

## The reference: easy-mcp-ai's change history

Nine files, ~7,600 lines — the largest subsystem in that plugin.

| File | Lines | Role | Us |
|---|---:|---|---|
| `class-change-recorder.php` | 3,114 | **Tier 1** — WordPress hook capture | ← build, far smaller |
| `class-rebuild-planner.php` | 1,614 | emits MCP tool calls that would restore prior state | **rejected** |
| `class-change-db-interceptor.php` | 1,195 | **Tier 2** — raw SQL interception | **rejected** |
| `class-live-state.php` | 558 | "has this been overwritten since?" staleness checks | **rejected** (only the planner needs it) |
| `class-change-redactor.php` | 373 | sensitive-column redaction | reuse 096's shared helper instead |
| `class-change-log-repository.php` | 306 | filters, pagination, reads | ← build |
| `class-change-context.php` | 188 | the ambient "we are inside a tool call" context | **096 already ships this** |
| `class-change-external-intent.php` | 178 | distinguishes intentional external writes | out of scope |
| `class-change-log-schema.php` | 121 | table + migrations | ← build |

Their table `wp_easy_mcp_ai_change_log`, verified at
`includes/history/class-change-log-schema.php:74`:

```
id, audit_id, auth_source, token_id, oauth_client_id, wp_user_id,
tool_name, action, object_type, object_id, object_subtype,
before_value (longtext), after_value (longtext), changed_fields (text),
revision_id, truncated, capture_mode, ip_address, created_at
KEY object_lookup (object_type, object_id(100), created_at)
KEY tool_name (tool_name(150), created_at)   KEY wp_user_id (wp_user_id, created_at)
KEY audit_id   KEY created_at   KEY capture_mode (capture_mode, created_at)
```

Three design choices worth adopting wholesale:

1. **`audit_id` ties each change to the call that caused it.** This is what makes
   the feature debuggable rather than just an activity feed — you click a failing
   tool call and see the four rows it mangled. We mirror it as `activity_id`, and
   improve on it: because 096 put requests and abilities in one table, ours can
   point at the **ability** row and name *which* ability wrote, where theirs can
   only name the call.
2. **`capture_mode`** records *which tier* caught the write. Even shipping one
   tier, keep the column: it is how tier 2 lands later without a reshape, and it is
   how the UI tells a precise diff from a coarse one.
3. **`truncated`** is an explicit flag, not an inference. A 4 MB post body is
   stored clipped and the row says so, instead of silently lying about the diff.

Two patterns from their read layer:

- **`before_id` keyset pagination** — the same approach 096's controller uses.
  Reuse that helper rather than writing a second one.
- **`distinct()` for dropdowns**, transient-cached with explicit invalidation.

And one choice to **reject**: tier 2, the `add_filter( 'query', …, PHP_INT_MAX )`
interceptor. It classifies every SQL statement issued during a tool call, then
issues its own `SELECT` to snapshot the row before the write. It is the single
riskiest thing in that plugin — a parser bug, an extra query per write, and a
filter that runs on *all* SQL while armed. Excluded here by the scope decision,
with its cost stated above rather than hidden.

### What the rebuild planner actually does (and why skipping it is right)

`Rebuild_Planner` does **not** roll the database back. It emits a plan of up to 50
MCP tool calls (`MAX_CALLS = 50`, with a `{new_id}` placeholder threaded between
steps) that an AI client re-executes to restore the prior state, each call running
under its normal permission checks. Their `wp_history_apply` tool exposes it with a
`dry_run` mode and per-entry statuses (`restored` / `partial` / `refused` /
`skipped` / `incidental` / `failed` / `needs_approval` / `not_run`), refusing an
entry whose object has drifted since it was recorded.

That is a genuinely safer design than a raw `UPDATE`, and it is also a whole
feature of its own — 1,614 + 558 lines before the tool wrapper. Not in 097. If we
ever build it, build it on a change log that has been running in production first.

---

## Our seam is better than theirs

easy-mcp-ai arms and disarms its recorder around its own tool dispatcher. 096
already bracketed two choke points that are plugin-owned and narrower:

- **`Includes\Abilities\Toolset\Base_Toolset_Ability::do_execute()`**
  (`includes/Abilities/Toolset/Base_Toolset_Ability.php:1069`) — every toolset
  ability funnels through it. One `try`/`finally` brackets the entire toolset
  surface.
- **`Includes\Abilities\Execute`** — the vendor `mcp-adapter/execute-ability` tool,
  callback-swapped to us by `CallbackReplacer`.

Both are already wrapped by 096's `CaptureContext`. **This feature registers hooks
when that context arms and unregisters when it disarms.** No new bracket, no second
`try`/`finally`, no change to either method.

And a third seam, if per-ability granularity is ever wanted: `CallbackReplacer`
already hooks `wp_register_ability_args`, a WordPress **core** filter firing for
*every* ability registration, not just the vendor three. Wrapping any ability's
`execute_callback` in a capture decorator is a seam easy-mcp-ai does not have.

The `finally` is non-negotiable and 096 proves it in its Phase 0: an ability that
throws still has to disarm, or every subsequent write on that request gets
attributed to an ability that already ended.

> **Boundary note.** The abilities that perform writes are published by the sibling
> `acrossai-abilities-manager`; the toolset dispatchers they travel through live
> here. 097 hooks only WordPress core actions and our own context — it imports
> nothing from the sibling and names none of its vocabulary, so the F090 contract
> is untouched.

---

## What tier 1 cannot see

**Read this before accepting the scope.** Tier 1 is WordPress-hook capture. It is
blind to any write that does not go through a WordPress API.

`includes/Abilities/Toolset/Database.php:50` describes its own group as offering
*"direct SELECT, INSERT, UPDATE and DELETE queries or a search and replace"*, and
warns that *"several abilities here operate on live data with wide blast radius."*
**Those are exactly the writes tier 1 will not record.** A `toolset/database`
`UPDATE` across 10,000 rows produces one request row and one ability row in 096,
and **zero** change rows here.

This is precisely why easy-mcp-ai built tier 2. Shipping tier 1 alone is a
defensible call — the hook tier produces readable field-level diffs, which is what
operators actually read, and the SQL tier produces raw row snapshots — but it is
only defensible if the gap is **stated in the UI**, not buried in this document.

Required mitigations, all cheap, all non-optional:

1. The Changes tab carries a **permanent, non-dismissible** notice above the table,
   in the plain style of the reference:

   > Before/after snapshots of writes made through WordPress APIs during an MCP
   > tool call. Direct wp-admin edits are not recorded here — see Activity for the
   > per-call trace. Raw SQL executed by the database toolset is not recorded here
   > either.

2. When an activity row's ability is a known raw-SQL one and it produced zero change
   rows, the "Changes (N)" line in 096's detail panel reads **"not captured"**
   rather than `0`, and this tab's empty state says the same. Blank or zero reads as
   "changed nothing", which is a dangerous lie.
3. The empty state must distinguish **"change recording is switched off"** from
   **"nothing changed in this period"**. Conflating those is the same class of lie.
4. `capture_mode` ships from day one with the single value `hook`, so tier 2 can
   land later as `db_row` / `db_sql` without a row reshape.

---

## Schema — `wp_acrossai_mcp_change_log`

BerlinDB module `includes/Database/ChangeLog/`, five files in the `CliAuthLog`
shape (`Schema`, `Table`, `Row`, `Query`, `Recorder`), table version `1.0.0`,
phantom-version guard in `Table::maybe_upgrade()`.

| Column | Type | Why |
|---|---|---|
| `id` | bigint(20) unsigned AI | PK |
| `activity_id` | bigint(20) unsigned, default 0 | → the **ability** row in 096's `activity_log`, falling back to the request row when no ability row exists. The join that makes this debuggable. 0 when activity logging is off |
| `server_id` | bigint(20) unsigned, default 0 | denormalized so the per-server tab filters without a join |
| `ability_slug` | varchar(191), default `''` | the ability that ran, e.g. `content/update-post` |
| `tool_name` | varchar(191), default `''` | the toolset dispatcher, e.g. `toolset/content` |
| `action` | varchar(16), default `''` | `create` \| `update` \| `delete` \| `trash` |
| `object_type` | varchar(64), default `''` | `post` \| `postmeta` \| `term` \| `user` \| `usermeta` \| `option` \| `comment` |
| `object_id` | varchar(191), default `''` | varchar, not bigint — an option's id is its name |
| `object_subtype` | varchar(64), default `''` | post type, taxonomy, meta key |
| `before_value` | longtext, nullable | JSON; null for `create` |
| `after_value` | longtext, nullable | JSON; null for `delete` |
| `changed_fields` | text, nullable | JSON list — what the UI renders without parsing the blobs |
| `revision_id` | bigint(20) unsigned, default 0 | WP revision when one exists; a free restore path for posts |
| `truncated` | tinyint(1), default 0 | explicit, per the reference |
| `capture_mode` | varchar(16), default `'hook'` | reserved for tier 2 |
| `user_id` | bigint(20) unsigned, default 0 | |
| `created_at` | datetime, `created`, `date_query`, `sortable` | |

Indexes:

```
primary                (id)
activity               (activity_id)
object_lookup          (object_type, object_id(100), created_at)
server_created         (server_id, created_at)
created_at             (created_at)
user_created           (user_id, created_at)
```

`ability_slug` and `tool_name` are denormalized even though `activity_id` could
supply them. Reason: the two tables have **independent retention** — 30 days here,
and 096 prunes its own rows per `event_type` — so a change row must stay readable
after its activity row is gone. easy-mcp-ai denormalizes `tool_name` for the same
reason.

`Recorder` builds its insert from a **declarative column map**, as in 096 — tier
2's columns must be additive.

### Size is the real constraint

`before_value` + `after_value` are longtext and posts are large. Three rules,
enforced in `Recorder`, not left to callers:

- Cap each blob (start at **64 KB**) and set `truncated = 1` when clipped.
- Store **only the changed fields**, never the whole object. `post_updated` hands
  us `$post_before` and `$post_after`; diff them and persist the delta.
- Retention defaults to **30 days**, not "forever". The reference ships 30 and its
  UI says so; a change log grows far faster than an activity log and has a much
  shorter useful life.

---

## Tier 1 hook table

Each pair is (before-source, after-source). All are WordPress core; the plugin's
6.9 floor covers every signature below. Registered when `CaptureContext` arms,
unregistered when it disarms.

| Object | Hooks | Before/after |
|---|---|---|
| post (update) | `post_updated` ( `$post_ID`, `$post_after`, `$post_before` ) | both in one callback — no pre-pass needed |
| post (create) | `wp_insert_post` with `$update === false` | after only |
| post (status) | `transition_post_status` | catches publish/trash transitions the update hook flattens |
| post (delete) | `before_delete_post` (before) + `deleted_post` (after) | snapshot in the `before_` pass |
| post (trash) | `wp_trash_post` / `trashed_post` | `action = trash`, distinct from delete |
| postmeta | `update_postmeta` / `added_post_meta` / `delete_post_meta` + `deleted_post_meta` | the `update_*` action fires **before** the write, so `get_post_meta()` there yields the old value |
| term | `edit_terms` (before) + `edited_term` (after); `created_term`; `pre_delete_term` + `delete_term` | |
| user | `profile_update` ( `$user_id`, `$old_user_data`, `$userdata` ); `user_register`; `delete_user` + `deleted_user` | |
| usermeta | `update_user_meta` / `added_user_meta` / `delete_user_meta` | same before/after shape as postmeta |
| option | `update_option` ( `$option`, `$old_value`, `$value` ); `add_option`; `delete_option` + `deleted_option` | both values in one callback |
| comment | `wp_update_comment_data` (filter: `$data`, `$comment`, `$commentarr`); `wp_insert_comment`; `transition_comment_status`; `trashed_comment`; `deleted_comment` | |

Two filters are needed so a site can shrink or extend this:

- `acrossai_mcp_change_log_object_types` — which object types are captured
  (default: all of the above; an operator drowning in postmeta can drop it).
- `acrossai_mcp_change_log_ignored_keys` — meta keys and option names never
  recorded. Seeded with transients (`_transient_*`, `_site_transient_*`), cron
  (`cron`), and anything matching the adapter's sensitive-key patterns.

**Redaction reuses 096's path.** `McpObservabilityHelperTrait::is_sensitive_key()`
already recognises 24 patterns; `ChangeLog\Recorder` calls the same helper rather
than growing a second list that drifts from the first.

### Recursion and self-capture

The recorder writes rows while armed. It must ignore its own table and 096's
activity table — adding itself to the shared ignore-list constant 096 established —
and must guard re-entry with a static flag. The reference needs
`class-change-context.php` for exactly this and it is not optional.

Every hook callback body is wrapped so a capture bug can never break the write it
is observing. Same non-negotiable rule as 096's recorders, same dedicated test.

---

## Files

**New — `includes/Database/ChangeLog/`** — `Schema`, `Table`, `Row`, `Query`,
`Recorder` (five files, `CliAuthLog` shape). `Query` exposes `summary()`,
`filter_options()` (distinct `object_type`, `action`, `ability_slug`, `tool_name`),
`for_activity( int $activity_id )`, and `count_for_activity()` — the last is what
096's detail panel calls.

**New — `includes/History/`** (created by 097; 096's `CaptureContext` lives in
`includes/Observability/`)
- `HookRecorder.php` — the tier-1 hook table above; registers nothing until
  `CaptureContext` arms, unregisters when it disarms
- `ValueDiffer.php` — field-level delta, the 64 KB cap, the `truncated` flag, the
  redaction call

**New — `includes/REST/ChangeLogController.php`** — namespace
`acrossai-mcp-manager/v1`:

| Route | Does |
|---|---|
| `GET /changes` | list, filtered and paginated. **Does not return the blobs** |
| `GET /changes/{id}` | one row, full `before_value` / `after_value` |
| `GET /changes/summary` | counts by action and object type for the period |
| `GET /changes/filters` | distinct values for the chips |
| `DELETE /changes` | **cleanup older than N days**, matching the reference's explicit cleanup rather than a blind purge; returns the count deleted |

`permission_callback` = `manage_options`, matching 096's controller — confirm
against `AccessControl` before coding. Collection params: `activity_id`,
`object_type`, `object_id`, `object_subtype`, `action`, `ability_slug`, `tool_name`,
`user_id`, `server_id`, `capture_mode`, `date_from`, `date_to`, `search`, `page`,
`per_page`, `orderby`, `order`, `cursor_id`, `cursor_timestamp`. Reuse 096's
keyset-pagination helper.

**New — `src/js/change-history/`** — a Changes tab beside 096's Activity view, same
DataViews shell and period picker, mounted on the global page and on the per-server
tab:
- `index.js`, `fields.js`
- `DiffRenderer.js` — the one genuinely new component. Drives off `changed_fields`,
  fetches full blobs on demand from `GET /changes/{id}`, honours `truncated` with an
  explicit "clipped at 64 KB" marker rather than a silently short diff
- `ScopeNotice.js` — the permanent notice
- `CleanupBox.js` — the N-days cleanup, surfacing the current retention setting so
  an operator who needs longer finds out *before* the data is gone

**Modified**
- `admin/Partials/ActivityLogPage.php` — register the Changes tab
- `admin/Partials/ServerTabs/McpTrackerTab.php` — the same tab, per server
- 096's activity detail panel — fill the row 096 left free with **"Changes (N)"**,
  linking into this tab filtered by `activity_id`, and the **"not captured"** state
- `includes/Activator.php` — install the change table
- `includes/Loader.php` / `includes/Main.php` — wire the controller, the recorder,
  the retention cron
- `admin/Partials/Settings.php` — the third toggle row 096 left room for, plus a
  change-retention setting (default 30 days)
- `uninstall.php` — drop the change table under the existing opt-in
- `webpack.config.js`, `docs/planings-tasks/README.md`

**Not touched:** `includes/Observability/CaptureContext.php` and the two bracketed
methods. 097 adds listeners to an existing seam.

---

## Phases

**Phase 0 — prove the seam without building anything.** 096's bracket already
exists; confirm from outside it that `CaptureContext` can be armed-detected and
read. Register a throwaway listener that `error_log`s `post_updated` only, run one
content-editing tool call from a real client, and confirm: the hook fires inside the
bracket, the activity id resolves to an **ability** row, and a *deliberately thrown*
ability still leaves the context disarmed. Do not build the table until that is
proven.

**Phase 1 — storage.** The five `ChangeLog/` files, `Activator`, `uninstall`.
Tests: schema contract (columns + widths, the F029 drift class), `Recorder`
round-trip, the 64 KB cap sets `truncated`, `count_for_activity()`.

**Phase 2 — capture.** `HookRecorder`, `ValueDiffer`, registration driven by
`CaptureContext`. Tests: one row per object type; `create` has null `before_value`;
`delete` has null `after_value`; `activity_id` points at the ability row, not the
request row; the recorder ignores its own table and 096's; re-entry does not
double-record; a sensitive option name is not recorded; writes made **outside** a
tool call record nothing at all; a thrown `Recorder` exception does not break the
write it observed.

**Phase 3 — REST.** `ChangeLogController`, collection params, keyset pagination
reused from 096, transient-cached summary and filters. Tests: subscriber denied
every route; each filter narrows; cursor and offset return the same rows;
`?activity_id=` returns only that ability's changes; the list route omits the blobs
and `GET /changes/{id}` includes them; `DELETE /changes` with `days=30` deletes only
older rows, returns the count, and **leaves the activity table alone**; 096's "Purge
All Logs" leaves this table alone.

**Phase 4 — UI.** Changes tab, diff renderer, the permanent scope notice, the
cleanup box, the "Changes (N)" link and "not captured" state in 096's panel, the
two empty states, the per-server mount, the third settings toggle, the retention
cron.

**Phase 5 — polish.** PHPStan clean (**clear the result cache first** — a warm
cache invents errors CI never sees), `npm run build` green, the four pre-ship
validate scripts.

---

## Risks

| Risk | Answer |
|---|---|
| **Tier 1 blindness to raw SQL.** The headline gap. | Four mitigations in §"What tier 1 cannot see", all mandatory. Tier 2 remains a future option and `capture_mode` keeps the door open. |
| **Table growth.** Posts are big; meta writes are frequent. | Changed-fields-only, 64 KB cap, 30-day default retention, an ignored-keys filter seeded with transients. |
| **Hook storms.** One `wp_insert_post` can cascade into dozens of meta writes. | They are genuinely separate changes and should be separate rows; the UI groups by `activity_id`. Revisit only if a real install shows a problem. |
| **Recursion.** The recorder writes rows while armed. | 096's shared ignore-list constant plus a static re-entry guard, both tested. |
| **Secrets in `before_value`.** Options and meta hold tokens. | Shared redaction with 096 via `is_sensitive_key()`, plus the ignored-keys filter. One list, not two. |
| **A capture bug breaking a write.** Hooks run inside the real write path. | Every callback body wrapped; failures swallowed. Same non-negotiable rule as 096's recorders, same dedicated test. |
| **Forking the bracket.** It is tempting to add a second `try`/`finally` rather than read 096's context. | 096 has a success criterion asserting `CaptureContext` is usable from outside the ability recorder. If it is not, fix it there — two brackets around the same method is how double-recording bugs are born. |
| **A big diff locking up the browser.** Blobs are up to 64 KB each. | The list route never returns them; the renderer drives off `changed_fields` and fetches on demand. |
| **Two purge paths, two tables.** 096's "Purge All Logs" and this tab's cleanup. | Each touches only its own table and says which. Tested both directions. |
| **Retention already ate the history you want.** 30 days by default. | The cleanup box states the current retention, so the limit is discoverable before it bites. |
| **Scope creep toward restore.** "We have `before_value`, why not undo?" | Out of scope, deliberately — the reference's answer is 1,614 lines of planner plus 558 of staleness checking. `revision_id` gives posts a free WordPress-native restore path; anything beyond that is a separate feature built on a change log that has already run in production. |

---

## Speckit Workflow

```markdown
# 1. Branch (after 096 has merged)
/speckit.git.feature "mcp-change-history"

# 2. Specify
/speckit.specify "Add a change history to acrossai-mcp-manager: before/after
snapshots of WordPress writes performed during an MCP tool call, linked to the
Feature 096 activity row that caused them, plus a Changes tab beside 096's Activity
view. Modelled on easy-mcp-ai's Activity > Change history, but TIER 1 ONLY.

SCOPE: WordPress-hook capture only. Do NOT implement a 'query' filter SQL
interceptor, a rebuild/restore planner, live-state staleness checks, or
WooCommerce/BuddyPress coverage. Those are all present in the reference and all
explicitly out of scope.

ARMING — READ THIS BEFORE WRITING ANY HOOK CODE. Feature 096 already brackets the
two plugin-owned choke points, Base_Toolset_Ability::do_execute() and
Includes\\Abilities\\Execute, in try/finally via
includes/Observability/CaptureContext.php, which holds the ambient 'inside ability X of
request N' state with a public reader. This feature REGISTERS ITS HOOKS WHEN THAT
CONTEXT ARMS and unregisters when it disarms. Do NOT add a second try/finally, do
NOT modify either bracketed method, and do NOT fork the context — two brackets
around the same method is how double-recording bugs are born. Nothing is recorded
outside the bracket, so direct wp-admin edits are never captured; that is intended.

STORAGE: BerlinDB module includes/Database/ChangeLog/ with Schema, Table, Row,
Query, Recorder, in the same five-file shape as includes/Database/CliAuthLog/
including the phantom-version guard override of maybe_upgrade(). Table
acrossai_mcp_change_log, version 1.0.0, per-site prefix. Columns: id, activity_id
(points at the ABILITY row in acrossai_mcp_activity_log, falling back to the
request row; walk up its parent_id to reach the call; 0 when activity logging is
off), server_id, ability_slug, tool_name, action, object_type, object_id (varchar
191 — an option's id is its name), object_subtype, before_value (longtext),
after_value (longtext), changed_fields (text), revision_id, truncated, capture_mode
(default 'hook', reserved so a SQL tier can land later as db_row/db_sql without
reshaping the row), user_id, created_at. Indexes: primary(id),
activity(activity_id), object_lookup(object_type, object_id(100), created_at),
server_created(server_id, created_at), created_at(created_at),
user_created(user_id, created_at). ability_slug and tool_name are denormalized on
purpose: the two tables have independent retention, so a change row must stay
readable after its activity row is purged. Recorder builds its insert from a
declarative column map, not a hardcoded positional \$wpdb->insert(). Query exposes
summary(), filter_options(), for_activity(int \$activity_id) and
count_for_activity(int \$activity_id) — the last is what 096's detail panel calls.

CAPTURE in includes/History/ (a directory 097 creates; 096's CaptureContext lives in
includes/Observability/ and is only read from here) — HookRecorder.php
(the hook table) and ValueDiffer.php (field-level delta, size cap, truncated flag,
redaction). Hooks: post_updated (gives \$post_after AND \$post_before in one
callback), wp_insert_post for creates, transition_post_status, before_delete_post +
deleted_post, wp_trash_post + trashed_post;
update_postmeta/added_post_meta/delete_post_meta+deleted_post_meta (the update_*
ACTION fires before the write, so get_post_meta() there yields the old value);
edit_terms + edited_term, created_term, pre_delete_term + delete_term;
profile_update (\$user_id, \$old_user_data, \$userdata), user_register, delete_user +
deleted_user; update_user_meta/added_user_meta/delete_user_meta; update_option
(\$option, \$old_value, \$value), add_option, delete_option + deleted_option;
wp_update_comment_data (filter), wp_insert_comment, transition_comment_status,
trashed_comment, deleted_comment.

SIZE: store ONLY changed fields, never whole objects. Cap each blob at 64KB and set
truncated=1 when clipped. Retention defaults to 30 DAYS (not forever — a change log
grows much faster than an activity log), independent of 096's per-event_type
cutoffs.

REDACTION: reuse 096's path — call
WP\\MCP\\Infrastructure\\Observability\\McpObservabilityHelperTrait::is_sensitive_key()
rather than growing a second pattern list. Add two filters:
'acrossai_mcp_change_log_object_types' (which types are captured) and
'acrossai_mcp_change_log_ignored_keys' (seeded with _transient_*, _site_transient_*,
cron).

RECURSION: the Recorder must ignore its own table and 096's activity table — add
itself to the shared ignore-list constant 096 established rather than writing a
second literal — and must guard re-entry with a static flag. Every hook callback
body is wrapped so a capture bug can never break the write it is observing.

KNOWN GAP, must be surfaced in the UI, not just in code comments: tier 1 cannot see
writes that bypass WordPress APIs, and includes/Abilities/Toolset/Database.php
advertises direct INSERT/UPDATE/DELETE and search-replace abilities. So (1) the
Changes tab carries a PERMANENT, non-dismissible notice above the table naming both
exclusions — direct wp-admin edits AND raw SQL from the database toolset; (2) when
an activity row's ability is a known raw-SQL one and produced zero change rows,
096's detail panel must read 'not captured' and this tab's empty state must say the
same, never blank or '0', because those read as 'changed nothing'; and (3) the empty
state must distinguish 'change recording is switched off' from 'nothing changed in
this period'.

REST: includes/REST/ChangeLogController.php in acrossai-mcp-manager/v1 with
GET /changes (list — must NOT return the before_value/after_value blobs),
GET /changes/{id} (the only route returning them), GET /changes/summary,
GET /changes/filters, and DELETE /changes as an EXPLICIT CLEANUP taking a day count
and returning the number deleted — not a blind purge. permission_callback
manage_options, matching 096's controller; confirm against AccessControl first.
Collection params: activity_id, object_type, object_id, object_subtype, action,
ability_slug, tool_name, user_id, server_id, capture_mode, date_from, date_to,
search, page, per_page, orderby, order, cursor_id, cursor_timestamp. REUSE 096's
keyset-pagination helper. DELETE /changes must not touch the activity table, and
096's Purge All Logs must not touch this one — assert both with tests.

UI: src/js/change-history/ — a Changes tab beside 096's Activity view using the same
DataViews shell and period picker, mounted on the global page
(admin/Partials/ActivityLogPage.php) and on the per-server tab
(admin/Partials/ServerTabs/McpTrackerTab.php). Components index.js, fields.js,
DiffRenderer.js, ScopeNotice.js, CleanupBox.js. The diff renderer drives off
changed_fields and fetches full blobs on demand; a create row renders as added with
no left-hand side, a delete row as removed with no right-hand side, and a
truncated=1 row shows an explicit 'clipped at 64 KB' marker rather than a silently
short diff. Fill the detail-panel row 096 left free with 'Changes (N)', linking into
this tab filtered by activity_id. The cleanup box states the current retention
setting so an operator who needs longer finds out before the data is gone.

SETTINGS: ship DISABLED by default. Add the third toggle row 096 left room for in
admin/Partials/Settings.php plus a change-retention setting defaulting to 30 days,
and drop the table in uninstall.php under the existing opt-in.

DO NOT TOUCH includes/Observability/CaptureContext.php or either bracketed method. This
feature adds listeners to an existing seam."

# 3. Clarify / Plan / Tasks / Implement
/speckit.clarify
/speckit.plan
/speckit.tasks
/speckit.implement
```

**Success criteria:**
- [ ] Phase 0 proved `CaptureContext` is readable from outside the ability recorder, and that the `finally` disarms on a thrown ability
- [ ] No second `try`/`finally` was added, and neither bracketed method was modified
- [ ] A content-editing tool call writes change rows whose `activity_id` is the **ability** row, not the request row
- [ ] `create` rows have null `before_value`; `delete` rows have null `after_value`
- [ ] A write made outside a tool call (a wp-admin edit) records nothing
- [ ] A >64 KB value is clipped and `truncated = 1`
- [ ] A sensitive option name is not recorded
- [ ] The recorder does not record its own writes or 096's, and re-entry does not double-record
- [ ] A thrown `Recorder` exception does not surface to the MCP client or break the write
- [ ] The list route omits the blobs; `GET /changes/{id}` returns them
- [ ] `DELETE /changes` with a day count deletes only older rows, returns the count, and leaves the activity table untouched
- [ ] 096's "Purge All Logs" leaves the change table untouched
- [ ] Cursor and offset pagination return the same rows; a subscriber is denied every route
- [ ] A raw-SQL `toolset/database` call shows **"not captured"** in both 096's panel and this tab, never `0` or blank
- [ ] The empty state distinguishes "recording off" from "nothing changed"
- [ ] The Changes tab's scope notice names both exclusions and cannot be dismissed
- [ ] A `truncated = 1` row shows the clipped marker
- [ ] Change rows survive an activity-log purge and stay readable (`ability_slug` / `tool_name` denormalized)
- [ ] 30-day retention prunes change rows; `uninstall.php` drops the table
- [ ] `npm run build` exits 0; PHPStan clean (cache cleared first); all PHPUnit suites pass

## Pre-ship Scripts (run after everything)

```bash
node .agents/skills/wp-plugin-development/scripts/validate-structure.mjs --dir=.
node .agents/skills/wp-plugin-development/scripts/validate-security.mjs --dir=.
node .agents/skills/wp-plugin-development/scripts/detect-deprecations.mjs --dir=.
node .agents/skills/wp-plugin-development/scripts/detect-rest-endpoints.mjs --dir=.
```
