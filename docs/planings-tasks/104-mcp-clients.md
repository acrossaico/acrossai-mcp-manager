# Planning: Clients — a durable record of who connects (Feature 104)

## In plain English

This plugin can tell you, in detail, what happened on any single request. It cannot tell you who
has been connecting to it.

Ask "which AI clients have access to this site?" and there is no screen that answers. The
activity log knows, but only for thirty days. The OAuth client table knows who registered, but
not whether they ever called. The AI Connectors tab has a panel literally called "Connections",
and it shows live OAuth grants whose "last activity" is really the last token refresh.

This feature adds the missing join: one row per client per server, with first seen, last used and
a request count, in a table that survives log pruning. It is surfaced the way Logs already is —
one screen, mounted globally and per server.

## The gap, precisely

| Source | Identity | Behaviour | Durable |
| --- | --- | --- | --- |
| `acrossai_mcp_activity_log` | ✅ | ✅ | ❌ 30-day retention + 50k ceiling (`ActivityLog/Cleanup.php`) |
| `acrossai_mcp_oauth_clients` | ✅ OAuth only | ❌ no last-seen, no counter | ✅ |
| AI Connectors `?panel=connections` | ✅ OAuth only | ❌ "last activity" = last refresh | ❌ vanishes on revoke |

**Identity is durable, behaviour is in a pruned log, and nothing joins them.** No MCP client
carries a last-seen or a request count anywhere — only Fleet peers (`Fleet/Hosts.php`) and
WP-core application passwords do.

### The existing panel does not merely omit this — it states the opposite

Opened on this site, server 5 → Connect → Connectors → **Connections** reads:

> **0 active connections**
> No AI clients have connected to this server yet.

Server 5 has taken **450+ MCP requests**. `claude-code` alone accounts for 256 of them, the most
recent **today**. The panel is not empty because nothing connected; it is empty because it counts
only live OAuth token families (`WHERE revoked = 0`) and `oauth_tokens` is `0`. Every real caller
on this site authenticates with an application password, which that query cannot see.

A screen that says "no clients have connected" to an operator whose server is being called every
day is worse than no screen. That is the strongest argument for this feature, and it is why the
new screen must cover every route rather than one.

### The whole AI Connectors tab is an OAuth control panel

Walked all eight panels on the running site. They divide cleanly:

| Panel | Answers | Scope |
| --- | --- | --- |
| Claude · ChatGPT · Gemini · Grok · Cursor | *how do I connect this?* | setup instructions only — 1-click, manual steps, and a collapsed "Use a manual OAuth client instead" |
| **Connections** | *who is connected?* | **OAuth only — and currently answers "none" while 450+ requests land** |

Three further ways that panel misleads, beyond being empty:

- **"Active connections" counts unrevoked rows, not active ones.** The query filters
  `revoked = 0` only, so an **expired** token still counts until the daily cleanup removes it.
  The headline number overstates reality.
- **"last activity" is token issuance, not use.** It is `MAX(created_at)` across the family — the
  last refresh rotation. A client that refreshes but never calls a tool looks busy; one doing
  heavy traffic on a long-lived access token looks idle.
- **n8n rows are silently filtered out**, so it is not even a complete list of who holds tokens
  on the server.
| Approved Users | *who may connect?* | OAuth only |
| Settings | *which connectors are allowed, is approval required?* | OAuth only |

Settings is explicit about its reach: the enabled-connector checkboxes gate `/authorize`, and
"Require admin approval for new connections" gates "the OAuth consent flow". Neither touches an
application password, an npm bridge or WP-CLI — which is **all** of the real traffic on this
server.

So the tab is correct within OAuth and silent outside it. **Clients supersedes exactly one panel
of the eight** — Connections — and the other seven stay. The right treatment of that one is not
deletion but honesty: rename it to what it actually lists (live OAuth grants), and change its
empty state from "No AI clients have connected to this server yet" to something true, pointing at
Clients for the full picture. It keeps a job Clients does not do — revoking a live grant.
Replacing the whole tab would lose the setup instructions, the per-connector enable/disable and
the approval gate, none of which a traffic list can provide.

### Three of the four connect routes are untracked

The Connect tab's own sub-nav names the taxonomy:

> Connectors/Integrations/Plugins · MCP Client via config file · npm · WP-CLI

Only the first has any connection record at all. The Approved Users panel beside it is OAuth-only
too — it gates "the OAuth consent flow", so config-file, npm and WP-CLI clients bypass approval
entirely and appear nowhere.

Those four labels are the plugin's existing vocabulary for *how you set a client up*, so the
Clients screen should reuse them verbatim rather than inventing its own words for the same thing.

## What the live site proved

Every design decision below came from running the proposed queries against this dev site's 564
log rows, not from reading the schema.

**The grouping is right.** The backfill query produced **16 rows** — a readable list, confirming
one-row-per-client-per-server rather than per-session.

**The naive identity key was wrong three ways.**

1. 94 requests carry an empty `client_name`, and they are **not one anonymous client** — the user
   agent separates them cleanly into `curl/8.7.1`, `claude-code/…/0.5.4`, `claude-code/…/0.5.5`,
   `Python-urllib/3.14`, `WP CLI 2.12.0`. Keying on `client_name` merges five callers into one.
2. The same client **split in two** when its UA version moved `0.5.4` → `0.5.5`. The key must be
   the product token, not the raw UA.
3. One fleet peer appeared as `wordpress 7.0` and then `wordpress-7-0.local` **33 seconds apart**,
   same user, same method — site name, then hostname. Keying fleet on `fleet_peer` fragments it.

**Traffic alone misses registered clients.** `oauth_clients` holds **Claude** and **ChatGPT**,
registered 31 Oct, with **zero** OAuth requests in the log and `oauth_tokens = 0`. They are set
up but have never completed an authorization — and they are exactly the two names an operator
would look for first.

## Design

### Identity

First match wins:

| `connection_type` | Identity |
| --- | --- |
| `ai-connector`, `oauth` | `oauth_clients.client_id` (already unique per server) |
| `fleet` | the `_fleet_hosts` **host key**, not `fleet_peer` |
| `app-password`, `browser` | `client_name` when present, else the **normalised UA product token** |
| none of the above | `user-{user_id}`, else `unknown` |

Store the raw `identity` **and** `identity_hash = sha1( server_id|connection_type|identity )`,
with `UNIQUE(server_id, identity_hash)`.

`Clients\Identity::ua_token()` is the one piece of real logic here and needs its own test table.
It is where this feature is most likely to go quietly wrong, and because the table is durable,
a bad key persists.

### Table — `includes/Database/Clients/`

`Schema` / `Table` / `Row` / `Query` / `Recorder`, following the existing conventions: stem
`acrossai_mcp_clients`, `db_version_key` `acrossai_mcp_clients_db_version`, `$global = false`,
the phantom-version guard in `maybe_upgrade()`, `DatetimeColumn::validate_utc` on every datetime.

```
id, server_id, identity_hash char(40), identity varchar(191), connection_type varchar(32),
client_name varchar(191), client_version varchar(64), connector_slug varchar(64),
route varchar(32), credential_id varchar(36), credential_hint varchar(32),
user_id bigint, last_ip varchar(45), last_user_agent varchar(255),
request_count bigint unsigned, first_seen_at datetime, last_seen_at datetime NULL,
revoked_at datetime NULL
```

Indexes: `primary(id)`, `UNIQUE(server_id, identity_hash)`, `KEY(server_id, last_seen_at)`,
`KEY(connector_slug)`, `KEY(last_seen_at)`. Constants in `includes/Utilities/ClientsConstants.php`.

### Write path — two sources, because neither alone is enough

**Traffic** gives last-seen and a count for every connection type, including the ones with no
hooks at all. **Hooks** give what traffic cannot infer — a grant, a refusal, a revocation.

The constraint that shapes this: `Observability/HandlerResolver.php` hands the adapter
`NullMcpObservabilityHandler` whenever request logging is off, and says the recording path is
*"never reached at all"*. So the client record **must not live inside
`ActivityObservabilityHandler`** — switching activity logging off would silently stop the client
registry.

Split it: `ConnectionObservabilityHandler` (always installed, records only the client row) and
`ActivityObservabilityHandler extends` it (adds the log row). `HandlerResolver` returns the
subclass when logging is on, the parent when off.

Hooks worth subscribing to — all four currently have **zero listeners**:

| Hook | Use |
| --- | --- |
| `acrossai_mcp_manager_oauth_token_issued` | upsert-on-see; `client_id` + `connector_slug` + `user_id`. Fires on refresh too, so it is "is active", not "is new" |
| `acrossai_mcp_connector_user_approval_revoked` | best-shaped hook in the plugin — `(server_id, connector_slug, user_id, revoked_by)` |
| `acrossai_mcp_oauth_client_revoked_across_all_servers` | carries `client_id`; the clean "this is gone" signal |
| `acrossai_mcp_manager_oauth_authorization_denied` | the one negative outcome that emits anything |

Two hooks are **not** usable and nothing should lean on them. `oauth_token_revoked` carries only
`token_id` — no client, no user, no server — and by fire time the row may be deleted; it also
fires with reason `refresh_rotation` on every refresh. `oauth_token_validated` carries a name and
slug but no id.

The write is one atomic statement: `INSERT … ON DUPLICATE KEY UPDATE last_seen_at = VALUES(…),
request_count = request_count + 1`. **No throttle.** `Fleet\Hosts::touch()` throttles to one
write per minute because `_fleet_hosts` is a JSON blob needing read-modify-write; a dedicated row
with a unique key has no such cost, and throttling would make `request_count` count active
minutes rather than requests — a number that looks like a request count and is not.

Guarded like `ActivityLog\Recorder`: re-entry flag, try/catch, never throws on the request path.

### Backfill — from both sources

| Source | Gives | `last_seen_at` |
| --- | --- | --- |
| `oauth_clients` | every registered client, even silent ones | `NULL` |
| `activity_log` | everything that has actually called | the real timestamp |

`last_seen_at IS NULL` therefore reads as **"registered, never used"** — the state Claude and
ChatGPT are in right now, and the difference between "set up wrong" and "not set up yet".

```sql
SELECT server_id, connection_type, client_name, connector_slug, fleet_peer, user_id,
       COUNT(*) AS requests, MIN(created_at) AS first_seen, MAX(created_at) AS last_seen
FROM {prefix}acrossai_mcp_activity_log
WHERE event_type = 'request'
GROUP BY server_id, connection_type, client_name, connector_slug, fleet_peer, user_id
```

History reaches back only as far as retention has left — which is the whole argument for the
table.

### UI — `WP_List_Table`, not React

Logs is React because it filters a large, fast-moving table. This is a short, slow-moving list.
Following `CliAuthLogListTable` (already rendered inside a per-server context) avoids a webpack
entry, a REST controller, a second `@wordpress/dataviews` app, and the committed-bundle gate —
roughly half the work, and no `npm run build` in the loop.

**Columns.** The brief asked for connector / MCP client / npm / WP-CLI as separate columns. They
are one axis — *how it got in* — and as four columns they would be four empty cells per row: on
this site every request row has an empty `connector_slug` and an empty `invoked_via`. The user
agent already carries both halves:

| Observed UA | Client | Route |
| --- | --- | --- |
| `claude-code/2.1.236 mcp-wordpress-remote/0.5.4` | Claude Code | npm bridge |
| `WP CLI 2.12.0` | WP-CLI | — |
| `AcrossAI Fleet/0.11.0 (https://…)` | Fleet | peer in the parens |
| `curl/8.7.1` | curl | direct |

| Column | Source |
| --- | --- |
| Client | `client_name`, else the UA product token |
| How it connects | the Connect tab's own four labels — **Connector** (+ slug) · **MCP client (config file)** · **npm** · **WP-CLI** — plus *Fleet* and *direct*. Derived from `connector_slug` when OAuth, else from the UA |
| Credential | `credential_hint` — a short non-secret fragment, shown as `<code>` |
| User | `user_id` → display name (`—` when 0) |
| First connected | `first_seen_at` |
| Last used | `last_seen_at`, or **Never** |
| Requests | `request_count` |
| Status | Active / Revoked / Never used |
| Actions | Revoke · Forget |

Server is a column on the global screen only, hidden on the per-server tab — the `showServer`
idea `ActivityLogPage` already uses.

- `admin/Partials/ClientsListTable.php`
- `admin/Partials/ClientsPage.php` — `PAGE_SLUG = 'acrossai_mcp_clients'`, registered at position
  9, just after Logs. Menu label **Clients**; "Connections" would collide with the existing
  "Connect" wizard item.
- `admin/Partials/ServerTabs/ClientsSeenTab.php` — slug `clients-seen` (the slug `clients` is
  taken by the config-snippet tab), priority 75.

### Revoke is not universal, and needs one more column

| Route | Revoke does | Available? |
| --- | --- | --- |
| OAuth / connector | revoke the token family | ⚠️ the endpoint exists; **the join key does not** — see below |
| Fleet | `Hosts::remove()` | ✅ exists |
| **Application password** | revoke that application password | ❌ **the UUID is never stored** |
| Browser / unknown | nothing to revoke | — |

**The OAuth row needs a key the activity log does not have.** `ActivityLog\Schema` carries
`client_name`, `client_version`, `connector_slug`, `user_id` and `server_id` — but **no
`client_id` and no `token_family_id`**. `ConnectorAdminController`'s revoke endpoints take one or
the other, so a purely traffic-derived row cannot drive Revoke at all. This plan previously
listed OAuth revoke as "already works"; it does not.

It is recoverable, because the id exists at request time and is thrown away:
`OAuth/TokenValidator.php:138` holds the resolved client row and fires
`acrossai_mcp_oauth_token_validated` with only `connector_slug` and `client_name`. Widening that
hook to pass `client_id` as a third argument is backwards-compatible — its single listener
(`AuthContextHolder::capture_oauth_context`, registered with 2 args) is unaffected — and lets
`Clients\Recorder` store `client_id` on the row. The `oauth_token_issued` hook already carries
it for the issuance path.

So: add `client_id varchar(64)` to the schema, widen that one hook, and Revoke becomes real for
OAuth clients. Without both, the OAuth rows are read-only.

`$GLOBALS['wp_rest_application_password_uuid']` **is** available on the request —
`ConnectionContext::resolve_type()` reads it today purely to decide the type, then discards it.
Capturing it as `credential_id` is what makes Revoke real for app-password clients, which on this
site is most of them.

Where no revoke is possible the action is absent, not disabled-and-unexplained. Revoke is a
**soft** revoke (`revoked_at`), so the row keeps its history. **Forget** deletes the row and is
the answer to test clutter — the backfill surfaced `sweep2`, `ab`, `z`, `verify268`.

## Prior art: what `easy-mcp-ai` does better

Its self-service key list (`includes/admin/views/self-service-keys.php`) renders
**Name · Token prefix · Last used · Expires · Status · Revoke**. Four of its choices are better
than this plan's first draft and have been folded in above:

1. **A credential hint** — `token_prefix` in a `<code>`, enough to match a row to the credential
   in your hand while revealing nothing. Matters most for app passwords, where one user holds
   several.
2. **Soft revoke** (`is_active`) rather than delete, so a revoked row keeps its history.
3. **Three computed states** — Active / Revoked / Expired — rather than inferring from a blank
   cell.
4. **Idle as a query** — `COALESCE(last_used_at, created_at) < NOW() - INTERVAL n DAY`
   (`class-oauth-site-move.php:408`) gives a "not seen in 90 days" filter for free, and
   independently validates falling back to first-seen when last-seen is null.

Its schema also carries `software_id` / `software_version` (RFC 7591 DCR metadata) and
`created_by_ip`. This plugin's DCR discards both — capturing `software_id` would give OAuth
clients a real identity instead of one inferred from a user agent. **Follow-up, not this
feature.**

It stamps `last_used_at` on token *validation*, a genuine last-call timestamp. That is precisely
what the existing Connections panel lacks, and why its "last activity" is really the last
refresh.

## Retention

None. Rows are never pruned — durability is the whole point, and a pruned registry is the log
again. Soft-revoked rows stay. Forget is the manual escape hatch; uninstall drops the table.

## Scope — what this does not answer

Two questions hide behind "who connects to us", and only one is cheap:

- **"Who has connected?"** — this feature.
- **"Who asked and was refused?"** — currently unanswerable. An admin denying a pending user is a
  silent array-element removal with no row, no timestamp and no hook; the Access Control gate
  tells a user to "request access" and creates no request. Fixing that means making a refusal
  durable where it happens — `AuthorizationController` and `ConnectorSettings` — not a column
  here.

Ship the first; treat the second as its own feature. Together they would be one table holding two
unrelated lifecycles, and the second needs decisions this one does not (how long to keep a
refusal, whether the denied user is told).

## Dead surface area on the Connectors tab — report separately

Found while mapping, not this feature's job to fix, but it is live attack surface and someone
should decide about it.

**Eight JS handlers in `src/js/ai-connectors.js` are bound to selectors no PHP renders any more**
— `__approve-btn`, `__deny-btn`, `__revoke-approval-btn`, `__revoke-btn`, `__delete-btn`,
`__revoke-all-btn`, `__settings-form`, `__nuclear-btn`. The last one *is* rendered, on the same
element as `__server-revoke-all-btn`, but `handleClick` matches that first and returns — so it is
unreachable by construction.

**Six REST routes nothing calls, all still registered and permission-gated:**
`/oauth/connector-settings`, `/oauth/revoke-connector-tokens`, `/oauth/approve-pending-consent`,
`/oauth/deny-pending-consent`, `/oauth/revoke-user-approval`,
`/oauth/revoke-client-tokens-all-servers`. Two more — `/oauth/revoke-client-tokens` and
`/oauth/delete-client` — are reachable only from legacy pre-`token_family_id` rows, so on a clean
install no button reaches them either.

Plus vestigial PHP (`AbstractConnectorProfile::render_url_row()`,
`ClientRepository::find_admin_for_server_connector()`, the whole per-slug `ConnectorSettings`
facade) and orphan CSS in `src/scss/ai-connectors.scss`.

## Three bugs found while mapping this — report separately

Not caused by this feature and not to be fixed inside it, but anyone building on these tables
will hit them.

1. **The CLI auth log loses its `success` rows.** `CliAuthLog\Recorder::record_approved()` and
   `record_success()` both INSERT with the same `auth_code_hash`, and the schema declares
   `UNIQUE KEY auth_code_hash`. The second insert fails and `Recorder` swallows it to
   `error_log()` — so on most installs a completed pairing records only `approved`. There is no
   `update_item()` in that module.
2. **That table grows forever.** `Query::delete_expired_oauth_codes()` is documented as the
   retention bulk delete and has **zero callers**; no cron references it, no cap, no option, no
   purge button. Only uninstall removes rows.
3. **Four dead columns and a dead vocabulary.** `redirect_uri`, `code_challenge`,
   `code_challenge_method`, `scope` are never written. `failure_code` is never written by
   anything, yet `CliAuthLogListTable` renders a "Failed" status from it — a label no row can
   carry.

Also: `acrossai_mcp_oauth_legacy_dcr_purged` appears in four Table docblocks and throughout
`specs/032-*` but is **never fired**. Do not design against it.

## Tasks

1. `includes/Database/Clients/{Schema,Table,Row,Query}.php` + `includes/Utilities/ClientsConstants.php`.
2. `includes/Fleet/../Clients/Identity.php` — the key resolver and `ua_token()` normaliser,
   **with its test table, before anything else**.
3. Wire the table into all three boot points — `Main::bootstrap_database_tables()`,
   `Activator::activate()`, `Main::reconcile_database_schemas()` — and add the stem to
   `uninstall.php`.
4. `Clients\Recorder` — the guarded upsert; capture `credential_id` from the app-password global.
5. Split `ConnectionObservabilityHandler` out of `ActivityObservabilityHandler`; update
   `HandlerResolver::class_string()`.
6. Subscribe to the four lifecycle hooks.
7. Backfill in `upgrade_to_1_0_0()` from both `activity_log` and `oauth_clients`.
8. `Query::summary()` + filters (including "not seen in N days").
9. `ClientsListTable`, `ClientsPage`, `ClientsSeenTab`; register the tab in `ServerTabs/Registry`.
10. Revoke + Forget handlers, per-route.
11. Tests — add the stem to `tests/phpunit/Database/SchemaManifestTest.php::MANIFEST`, which pulls
    in the whole parity family (`SchemaParityTest`, `ColumnWidthInvariantTest`,
    `RowDefaultParityTest`, `PhantomVersionGuardTest`, `ReconcileWiringTest`,
    `UpgradeReturnHonestyTest`, `UninstallSweepBoundaryTest`). Plus: identity per connection type,
    the three fragmentation cases above, upsert idempotency, counter increment, backfill from both
    sources, and **the registry still recording with `request_logging` off** — the regression this
    design exists to prevent.

Gates: PHPCS, PHPStan L8 (clear the result cache first), PHPUnit pure + integration, F021 grep
gates, package hierarchy. No JS, so no ESLint change and **no bundle rebuild**.

## Verification

- Connect over OAuth, over an application password, and pair a fleet host; confirm three rows with
  the right route and distinct identities.
- Call repeatedly; confirm `request_count` rises and `last_seen_at` moves.
- Turn `acrossai_mcp_activity_request_logging` **off**, call again, confirm the row still updates
  while no log row is written.
- Run the activity-log cleanup with retention at 0 days; confirm Clients rows survive.
- Confirm Claude and ChatGPT appear as **Never used** before either has called.
- Revoke an app-password client and confirm the underlying application password is gone.
- Confirm the per-server tab shows only that server's clients and the global page shows all.

## Effort

Roughly **half of Feature 096 (Logs)** — no React app, no REST controller, no bundle. The table
and recorder are a day; the handler split is small but needs care; list table, page and tab are
mostly boilerplate against existing precedents.

**Biggest risk — evidenced, not theoretical.** The identity key broke three different ways
against real data before any code was written. Build `Clients\Identity` and its tests first, and
re-run the backfill query against production-shaped data before writing a line of UI.
